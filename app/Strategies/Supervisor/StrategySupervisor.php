<?php

declare(strict_types=1);

namespace App\Strategies\Supervisor;

use App\Models\AiRecommendation;
use App\Models\SimulatedPosition;
use App\Models\Strategy;
use App\Models\StrategySignal;
use App\Models\User;
use App\Strategies\Engine\StrategyFactory;
use App\Support\StrategyCacheKeys;
use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * AI Supervisor del módulo de Estrategias. Reúne un resumen agregado por usuario
 * (régimen de mercado, top señales, performance reciente y salud del engine), lo
 * envía a un LLM OpenAI-compatible y persiste recomendaciones AUDITABLES en
 * `ai_recommendations`.
 *
 * Reglas de oro (igual que el StrategyJudge): NUNCA ejecuta nada, no abre/cierra
 * posiciones ni toca balances. Solo opina. Aplica guardas deterministas sobre la
 * salida del LLM (algoritmos/estrategias/params válidos) y degrada a un resumen
 * cuantitativo si no hay API key o el LLM falla. Corre FUERA del loop ReactPHP,
 * desde el comando programado `strategies:supervise`.
 */
final class StrategySupervisor
{
    /** Parámetros que el LLM puede sugerir ajustar (allowlist; nunca se aplican solos). */
    private const TUNABLE_PARAMS = [
        'slice_usdt', 'take_profit_pct', 'stop_loss_pct', 'max_holding_seconds',
        'max_open_positions', 'leverage', 'min_confidence', 'max_spread_pct',
        'entry_z', 'exit_z',
    ];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Genera y persiste recomendaciones para un usuario. Devuelve un resumen de
     * lo creado (para logging del comando).
     *
     * @return array<string, mixed>
     */
    public function supervise(User $user): array
    {
        $userId = (int) $user->id;

        $strategies = Strategy::where('user_id', $userId)
            ->where('type', Strategy::TYPE_TRADING)
            ->get();

        if ($strategies->isEmpty()) {
            return ['skipped' => true, 'reason' => 'sin estrategias de trading'];
        }

        $context = $this->buildContext($userId, $strategies);

        $config = (array) config('ai.supervisor', []);
        $apiKey = (string) ($config['api_key'] ?? '');
        $enabled = (bool) ($config['enabled'] ?? false);

        $parsed = null;
        $source = 'degraded';

        if ($enabled && $apiKey !== '') {
            try {
                $parsed = $this->callLlm($context, $config);
                $source = 'llm';
            } catch (Throwable $e) {
                $this->logger->warning('[strategies][supervisor] LLM falló, degradando a resumen cuantitativo', [
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($parsed === null) {
            $parsed = $this->deterministicSummary($context);
        }

        $clean = $this->sanitize($parsed, $strategies);

        return $this->persist($userId, $strategies, $clean, $source);
    }

    /**
     * Entrada agregada para el LLM: régimen, top señales, performance y salud.
     *
     * @param  \Illuminate\Support\Collection<int, Strategy>  $strategies
     * @return array<string, mixed>
     */
    private function buildContext(int $userId, $strategies): array
    {
        $sinceMs = (int) ((microtime(true) - 6 * 3600) * 1000);
        $topN = (int) (config('ai.supervisor.top_signals', 12));

        $strategyRows = $strategies->map(function (Strategy $s) {
            $metrics = cache()->get(StrategyCacheKeys::metrics((int) $s->id));

            return [
                'id' => (int) $s->id,
                'name' => $s->name,
                'algorithm' => $s->algorithm,
                'active' => $s->isActive(),
                'running' => is_array($metrics),
                'realized_pnl' => is_array($metrics) ? ($metrics['realized_pnl'] ?? (float) $s->realized_pnl) : (float) $s->realized_pnl,
                'unrealized_pnl' => is_array($metrics) ? ($metrics['unrealized_pnl'] ?? 0.0) : 0.0,
                'win_rate' => is_array($metrics) ? ($metrics['win_rate'] ?? null) : null,
                'open_positions' => is_array($metrics) ? ($metrics['open_positions'] ?? 0) : 0,
                'loss_streak' => is_array($metrics) ? ($metrics['loss_streak'] ?? 0) : 0,
                'daily_pnl' => is_array($metrics) ? ($metrics['daily_pnl'] ?? 0.0) : 0.0,
                'circuit_breaker' => is_array($metrics) ? ($metrics['circuit_breaker'] ?? null) : null,
                'config' => $this->tunableConfig((array) $s->config),
            ];
        })->values()->all();

        $strategyIds = $strategies->pluck('id')->all();

        $topSignals = StrategySignal::whereIn('strategy_id', $strategyIds)
            ->where('detected_at_ms', '>=', $sinceMs)
            ->orderByDesc('confidence_score')
            ->limit($topN)
            ->get()
            ->map(static fn (StrategySignal $sig): array => [
                'symbol' => $sig->symbol,
                'side' => $sig->side,
                'algorithm' => $sig->algorithm,
                'confidence' => round((float) $sig->confidence_score, 3),
                'status' => $sig->status,
                'reasons' => array_slice((array) $sig->reasons, 0, 3),
            ])->all();

        $closed = SimulatedPosition::whereIn('strategy_id', $strategyIds)
            ->where('status', '!=', SimulatedPosition::STATUS_OPEN)
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $wins = $closed->filter(static fn ($p): bool => (float) $p->net_pnl > 0)->count();
        $perfByAlgo = [];
        foreach ($closed as $p) {
            $algo = $p->algorithm ?: 'unknown';
            $perfByAlgo[$algo] ??= ['trades' => 0, 'net_pnl' => 0.0, 'wins' => 0];
            $perfByAlgo[$algo]['trades']++;
            $perfByAlgo[$algo]['net_pnl'] += (float) $p->net_pnl;
            if ((float) $p->net_pnl > 0) {
                $perfByAlgo[$algo]['wins']++;
            }
        }
        foreach ($perfByAlgo as &$row) {
            $row['net_pnl'] = round($row['net_pnl'], 4);
            $row['win_rate'] = $row['trades'] > 0 ? round($row['wins'] / $row['trades'], 3) : 0.0;
        }
        unset($row);

        return [
            'generated_at' => now()->toIso8601String(),
            'strategies' => $strategyRows,
            'top_signals' => $topSignals,
            'recent_performance' => [
                'closed_positions' => $closed->count(),
                'win_rate' => $closed->count() > 0 ? round($wins / $closed->count(), 3) : 0.0,
                'net_pnl' => round((float) $closed->sum('net_pnl'), 4),
                'by_algorithm' => $perfByAlgo,
            ],
            'engine_health' => [
                'active_strategies' => $strategies->filter(static fn (Strategy $s): bool => $s->isActive())->count(),
                'running_engines' => collect($strategyRows)->filter(static fn (array $r): bool => (bool) $r['running'])->count(),
                'circuit_breakers' => collect($strategyRows)->filter(static fn (array $r): bool => $r['circuit_breaker'] !== null)->pluck('circuit_breaker')->all(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function tunableConfig(array $config): array
    {
        $defaults = (array) config('strategies.defaults', []);
        $out = [];
        foreach (self::TUNABLE_PARAMS as $key) {
            if (array_key_exists($key, $config)) {
                $out[$key] = $config[$key];
            } elseif (array_key_exists($key, $defaults)) {
                $out[$key] = $defaults[$key];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function callLlm(array $context, array $config): array
    {
        $url = rtrim((string) ($config['base_url'] ?? 'https://api.openai.com/v1'), '/').'/chat/completions';
        $timeout = (int) ($config['timeout_seconds'] ?? 25);

        $payload = [
            'model' => (string) ($config['model'] ?? 'gpt-4o-mini'),
            'temperature' => 0.3,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => $this->systemPrompt()],
                ['role' => 'user', 'content' => (string) json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)],
            ],
        ];

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.(string) $config['api_key'],
            'Content-Type' => 'application/json',
        ])->timeout($timeout)->post($url, $payload);

        if (! $response->successful()) {
            throw new \RuntimeException('LLM HTTP '.$response->status().': '.$response->body());
        }

        $content = (string) ($response->json('choices.0.message.content') ?? '');
        $parsed = json_decode($content, true);
        if (! is_array($parsed)) {
            throw new \RuntimeException('LLM devolvió JSON inválido');
        }

        return $parsed;
    }

    private function systemPrompt(): string
    {
        $algos = implode(', ', StrategyFactory::algorithms());

        return <<<PROMPT
Eres el "AI Supervisor" de un simulador de estrategias de trading crypto (long spot + short SIMULADO con USDT). NO ejecutas nada: solo analizas y recomiendas en lenguaje claro para un usuario que está aprendiendo.

Recibirás un JSON con: estrategias del usuario (algoritmo, métricas, params), top señales recientes, performance reciente (por algoritmo) y salud del engine.

Algoritmos válidos: {$algos}.

Tu tarea:
1. summary: 2-4 frases en español resumiendo el estado general (qué funciona, qué no).
2. market_regime: uno de trending | ranging | volatile | calm.
3. recommended_focus: lista de algoritmos (de los válidos) en los que conviene enfocarse ahora.
4. avoid: lista de algoritmos a evitar por mal desempeño o condiciones adversas.
5. parameter_suggestions: ajustes concretos por estrategia (usa el "name" exacto de la estrategia recibida), solo sobre estos params: slice_usdt, take_profit_pct, stop_loss_pct, max_holding_seconds, max_open_positions, leverage, min_confidence, max_spread_pct, entry_z, exit_z. Incluye current y suggested numéricos y una razón breve.
6. alerts: avisos accionables con severity info|warning|critical (p. ej. racha de pérdidas, circuit breaker, win rate bajo).

Devuelve EXCLUSIVAMENTE JSON con este shape:
{
  "summary": "...",
  "market_regime": "trending|ranging|volatile|calm",
  "recommended_focus": ["..."],
  "avoid": ["..."],
  "parameter_suggestions": [ { "strategy": "<name>", "param": "<param>", "current": 0, "suggested": 0, "reason": "..." } ],
  "alerts": [ { "severity": "info|warning|critical", "message": "...", "strategy": "<name opcional>" } ]
}
PROMPT;
    }

    /**
     * Resumen determinista (sin LLM): se construye solo con los números, para que
     * el supervisor sea útil aun sin API key o ante un fallo del modelo.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function deterministicSummary(array $context): array
    {
        $perf = $context['recent_performance'];
        $health = $context['engine_health'];
        $byAlgo = $perf['by_algorithm'] ?? [];

        $focus = [];
        $avoid = [];
        foreach ($byAlgo as $algo => $row) {
            if (($row['trades'] ?? 0) < 3) {
                continue;
            }
            if (($row['net_pnl'] ?? 0) > 0 && ($row['win_rate'] ?? 0) >= 0.5) {
                $focus[] = $algo;
            } elseif (($row['net_pnl'] ?? 0) < 0) {
                $avoid[] = $algo;
            }
        }

        $alerts = [];
        foreach ((array) $context['strategies'] as $s) {
            if (($s['loss_streak'] ?? 0) >= 4) {
                $alerts[] = ['severity' => 'warning', 'message' => sprintf('La estrategia "%s" acumula %d pérdidas seguidas; revisa stop-loss y confianza mínima.', $s['name'], $s['loss_streak']), 'strategy' => $s['name']];
            }
            if (! empty($s['circuit_breaker'])) {
                $alerts[] = ['severity' => 'critical', 'message' => sprintf('Circuit breaker activo en "%s" (%s): el engine pausó nuevas entradas.', $s['name'], $s['circuit_breaker']), 'strategy' => $s['name']];
            }
        }

        $summary = sprintf(
            'Resumen automático: %d posiciones cerradas, win rate %.0f%%, P&L neto %s USDT. %d/%d engines activos.',
            (int) ($perf['closed_positions'] ?? 0),
            (float) ($perf['win_rate'] ?? 0) * 100,
            number_format((float) ($perf['net_pnl'] ?? 0), 2),
            (int) ($health['running_engines'] ?? 0),
            (int) ($health['active_strategies'] ?? 0),
        );

        return [
            'summary' => $summary,
            'market_regime' => 'unknown',
            'recommended_focus' => $focus,
            'avoid' => $avoid,
            'parameter_suggestions' => [],
            'alerts' => $alerts,
        ];
    }

    /**
     * Guardas deterministas sobre la salida (LLM o determinista): filtra a
     * algoritmos válidos, estrategias existentes del usuario y params permitidos.
     *
     * @param  array<string, mixed>  $parsed
     * @param  \Illuminate\Support\Collection<int, Strategy>  $strategies
     * @return array<string, mixed>
     */
    private function sanitize(array $parsed, $strategies): array
    {
        $validAlgos = StrategyFactory::algorithms();
        $byName = $strategies->keyBy('name');

        $filterAlgos = static fn ($list): array => collect(is_array($list) ? $list : [])
            ->filter(static fn ($a): bool => is_string($a) && in_array($a, $validAlgos, true))
            ->unique()->values()->all();

        $regime = is_string($parsed['market_regime'] ?? null) ? (string) $parsed['market_regime'] : 'unknown';
        if (! in_array($regime, ['trending', 'ranging', 'volatile', 'calm', 'unknown'], true)) {
            $regime = 'unknown';
        }

        $suggestions = [];
        foreach ((array) ($parsed['parameter_suggestions'] ?? []) as $sug) {
            if (! is_array($sug)) {
                continue;
            }
            $name = is_string($sug['strategy'] ?? null) ? trim((string) $sug['strategy']) : '';
            $param = is_string($sug['param'] ?? null) ? (string) $sug['param'] : '';
            $strategy = $byName->get($name);
            if ($strategy === null || ! in_array($param, self::TUNABLE_PARAMS, true)) {
                continue;
            }
            if (! is_numeric($sug['suggested'] ?? null)) {
                continue;
            }
            $suggestions[] = [
                'strategy_id' => (int) $strategy->id,
                'strategy' => $name,
                'param' => $param,
                'current' => is_numeric($sug['current'] ?? null) ? (float) $sug['current'] : null,
                'suggested' => (float) $sug['suggested'],
                'reason' => is_string($sug['reason'] ?? null) ? (string) $sug['reason'] : '',
            ];
        }

        $alerts = [];
        foreach ((array) ($parsed['alerts'] ?? []) as $alert) {
            if (! is_array($alert)) {
                continue;
            }
            $sev = is_string($alert['severity'] ?? null) ? (string) $alert['severity'] : 'info';
            if (! in_array($sev, ['info', 'warning', 'critical'], true)) {
                $sev = 'info';
            }
            $msg = is_string($alert['message'] ?? null) ? trim((string) $alert['message']) : '';
            if ($msg === '') {
                continue;
            }
            $name = is_string($alert['strategy'] ?? null) ? trim((string) $alert['strategy']) : '';
            $alerts[] = [
                'severity' => $sev,
                'message' => $msg,
                'strategy_id' => $byName->has($name) ? (int) $byName->get($name)->id : null,
            ];
        }

        return [
            'summary' => is_string($parsed['summary'] ?? null) ? trim((string) $parsed['summary']) : 'Sin resumen.',
            'market_regime' => $regime,
            'recommended_focus' => $filterAlgos($parsed['recommended_focus'] ?? []),
            'avoid' => $filterAlgos($parsed['avoid'] ?? []),
            'parameter_suggestions' => $suggestions,
            'alerts' => $alerts,
        ];
    }

    /**
     * Persiste: 1 market_summary + N alerts + N parameter_suggestions. Marca las
     * recomendaciones activas previas del mismo tipo como obsoletas (dismissed)
     * para no acumular ruido entre corridas.
     *
     * @param  \Illuminate\Support\Collection<int, Strategy>  $strategies
     * @param  array<string, mixed>  $clean
     * @return array<string, mixed>
     */
    private function persist(int $userId, $strategies, array $clean, string $source): array
    {
        // Obsoleta las activas anteriores para que el panel muestre la corrida actual.
        AiRecommendation::where('user_id', $userId)
            ->where('status', 'active')
            ->update(['status' => 'dismissed']);

        AiRecommendation::create([
            'user_id' => $userId,
            'strategy_id' => null,
            'type' => 'market_summary',
            'summary' => $clean['summary'],
            'payload' => [
                'market_regime' => $clean['market_regime'],
                'recommended_focus' => $clean['recommended_focus'],
                'avoid' => $clean['avoid'],
            ],
            'severity' => 'info',
            'status' => 'active',
            'source' => $source,
        ]);

        $created = 1;
        foreach ($clean['alerts'] as $alert) {
            AiRecommendation::create([
                'user_id' => $userId,
                'strategy_id' => $alert['strategy_id'],
                'type' => 'alert',
                'summary' => $alert['message'],
                'payload' => null,
                'severity' => $alert['severity'],
                'status' => 'active',
                'source' => $source,
            ]);
            $created++;
        }

        foreach ($clean['parameter_suggestions'] as $sug) {
            AiRecommendation::create([
                'user_id' => $userId,
                'strategy_id' => $sug['strategy_id'],
                'type' => 'parameter_suggestion',
                'summary' => sprintf('Ajustar %s de %s → %s (%s)', $sug['param'], $sug['current'] ?? '—', $sug['suggested'], $sug['reason']),
                'payload' => $sug,
                'severity' => 'info',
                'status' => 'active',
                'source' => $source,
            ]);
            $created++;
        }

        return [
            'skipped' => false,
            'source' => $source,
            'created' => $created,
            'alerts' => count($clean['alerts']),
            'suggestions' => count($clean['parameter_suggestions']),
        ];
    }
}
