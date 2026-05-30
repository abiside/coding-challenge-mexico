<?php

declare(strict_types=1);

namespace App\Arbitrage\Optimization;

use App\Models\ArbitrageStrategy;
use App\Models\StrategyEvaluation;
use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Capa LLM del autopilot: recibe el estado del champion y las propuestas del
 * optimizador, y devuelve aprobación + rationale + (opcional) ajustes a los
 * parámetros propuestos.
 *
 * Diseño defensivo:
 *  - Sin API key configurada -> degrada a "aprueba todo con rationale local".
 *  - Cualquier excepción HTTP -> fallback a la propuesta original.
 *  - El output del LLM SIEMPRE pasa por StrategyBounds::clamp(); el LLM nunca
 *    puede saltar los rangos que valida SettingsController.
 *  - La promoción del champion la decide el código (StrategyOptimizer), no el
 *    LLM: éste solo opina sobre los challengers que se crean.
 */
final class StrategyAdvisor
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Revisa un lote de propuestas y devuelve la versión validada (posiblemente
     * ajustada por el LLM, siempre dentro de bounds).
     *
     * @param  array<int, ProposedStrategy>  $proposals
     * @return array<int, ProposedStrategy>
     */
    public function review(ArbitrageStrategy $champion, array $proposals): array
    {
        if ($proposals === []) {
            return [];
        }

        $config = (array) config('ai.autopilot', []);
        $apiKey = (string) ($config['api_key'] ?? '');
        $enabled = (bool) ($config['enabled'] ?? false);

        if (! $enabled || $apiKey === '') {
            // Modo degradado: anota en el rationale por qué no hubo LLM.
            return array_map(
                static fn (ProposedStrategy $p): ProposedStrategy => self::withRationale(
                    $p,
                    $p->rationale.' [advisor: deshabilitado, usando optimizador puro]',
                ),
                $proposals,
            );
        }

        try {
            $response = $this->callLlm($champion, $proposals, $config);
        } catch (Throwable $e) {
            $this->logger->warning('[autopilot][advisor] LLM falló, degradando a optimizador', [
                'error' => $e->getMessage(),
            ]);
            if (! (bool) ($config['fallback_to_optimizer'] ?? true)) {
                return [];
            }

            return array_map(
                static fn (ProposedStrategy $p): ProposedStrategy => self::withRationale(
                    $p,
                    $p->rationale.' [advisor: fallback por error LLM]',
                ),
                $proposals,
            );
        }

        return $this->merge($proposals, $response);
    }

    /**
     * @param  array<int, ProposedStrategy>  $proposals
     * @param  array<string, mixed>  $config
     * @return array<int, array<string, mixed>> respuestas por propuesta
     */
    private function callLlm(ArbitrageStrategy $champion, array $proposals, array $config): array
    {
        $provider = (string) ($config['provider'] ?? 'openai');
        $url = rtrim((string) ($config['base_url'] ?? 'https://api.openai.com/v1'), '/').'/chat/completions';
        $timeout = (int) ($config['timeout_seconds'] ?? 20);
        $apiKey = (string) $config['api_key'];

        $request = Http::withHeaders([
            'Authorization' => 'Bearer '.$apiKey,
            'Content-Type' => 'application/json',
        ])->timeout($timeout);

        $payload = [
            'model' => (string) ($config['model'] ?? 'gpt-4o-mini'),
            'temperature' => 0.2,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => $this->systemPrompt()],
                ['role' => 'user', 'content' => $this->userPrompt($champion, $proposals)],
            ],
        ];

        // Mismo shape para Anthropic vía proxy compatible OpenAI; si en el
        // futuro se quiere ruta nativa, se ramifica por $provider aquí.
        $response = $request->post($url, $payload);
        if (! $response->successful()) {
            throw new \RuntimeException('LLM HTTP '.$response->status().': '.$response->body());
        }

        $content = (string) ($response->json('choices.0.message.content') ?? '');
        $parsed = json_decode($content, true);
        if (! is_array($parsed) || ! isset($parsed['proposals']) || ! is_array($parsed['proposals'])) {
            throw new \RuntimeException('LLM devolvió JSON inválido');
        }

        return $parsed['proposals'];
    }

    /**
     * @param  array<int, ProposedStrategy>  $proposals
     * @param  array<int, array<string, mixed>>  $llmResponse
     * @return array<int, ProposedStrategy>
     */
    private function merge(array $proposals, array $llmResponse): array
    {
        $byName = [];
        foreach ($llmResponse as $entry) {
            if (! is_array($entry) || ! isset($entry['name'])) {
                continue;
            }
            $byName[(string) $entry['name']] = $entry;
        }

        $out = [];
        foreach ($proposals as $proposal) {
            $entry = $byName[$proposal->name] ?? null;
            if ($entry === null) {
                // El LLM no opinó: la dejamos como venía pero anotamos.
                $out[] = self::withRationale($proposal, $proposal->rationale.' [advisor: sin opinión]');

                continue;
            }

            // El LLM puede rechazar la propuesta; respetamos pero registramos.
            $approved = (bool) ($entry['approve'] ?? true);
            if (! $approved) {
                $this->logger->info('[autopilot][advisor] propuesta rechazada por LLM', [
                    'name' => $proposal->name,
                    'reason' => (string) ($entry['rationale'] ?? ''),
                ]);

                continue;
            }

            $params = is_array($entry['params'] ?? null) ? $entry['params'] : [];
            $clamped = StrategyBounds::clamp(array_merge($proposal->params, $params));
            // Si el LLM ajustó params, recalculamos config y hash.
            $newConfig = StrategyBounds::apply($proposal->config, $clamped);
            $newHash = ArbitrageStrategy::hashConfig($newConfig);

            $out[] = new ProposedStrategy(
                userId: $proposal->userId,
                name: $proposal->name,
                config: $newConfig,
                configHash: $newHash,
                parentId: $proposal->parentId,
                generation: $proposal->generation,
                rationale: trim((string) ($entry['rationale'] ?? $proposal->rationale)),
                params: $clamped,
            );
        }

        return $out;
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
Eres el "Strategy Advisor" de un bot de arbitraje crypto simulado. Recibirás:
- El champion actual con sus parámetros y P&L reciente.
- Una lista de challengers propuestos por un optimizador estadístico, con sus parámetros y la razón de su perturbación.

Tu tarea: para cada challenger, decide si tiene sentido probarlo (approve: true/false) y, si ves desbalances obvios (ej. min > max, latency_max_ms demasiado bajo para los mercados), ajusta sus params dentro de límites razonables. NO promueves estrategias a champion (eso lo decide el código por evidencia cuantitativa); solo opinas sobre challengers a explorar.

Devuelve EXCLUSIVAMENTE JSON con este shape:
{
  "proposals": [
    {
      "name": "<challenger name del input>",
      "approve": true,
      "rationale": "explicación breve (1-2 frases) de por qué tiene sentido",
      "params": { "min_net_profit": 1.2, "min_net_margin": 0.0008, ... }
    }
  ]
}

Reglas duras:
- "params" es opcional; si no incluyes uno, mantenemos el original.
- Cualquier valor fuera de rango será clamped por el código; no te preocupes por límites exactos pero mantén los valores plausibles.
- Sé conservador: aprueba solo si la perturbación es razonable.
PROMPT;
    }

    /**
     * @param  array<int, ProposedStrategy>  $proposals
     */
    private function userPrompt(ArbitrageStrategy $champion, array $proposals): string
    {
        $championStats = $this->recentStats((int) $champion->id);

        $payload = [
            'objective' => 'maximize_net_pnl',
            'bounds' => StrategyBounds::ranges(),
            'champion' => [
                'id' => (int) $champion->id,
                'params' => StrategyBounds::extract((array) $champion->config),
                'recent_stats' => $championStats,
            ],
            'proposals' => array_map(static fn (ProposedStrategy $p): array => [
                'name' => $p->name,
                'parent_id' => $p->parentId,
                'params' => $p->params,
                'optimizer_rationale' => $p->rationale,
            ], $proposals),
        ];

        return (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string, mixed>
     */
    private function recentStats(int $strategyId): array
    {
        $evals = StrategyEvaluation::where('strategy_id', $strategyId)
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        if ($evals->isEmpty()) {
            return ['windows' => 0];
        }

        return [
            'windows' => $evals->count(),
            'realized_pnl_sum' => round((float) $evals->sum('realized_pnl'), 6),
            'executions_sum' => (int) $evals->sum('executions'),
            'avg_margin' => round((float) $evals->avg('avg_margin'), 8),
            'rejects_sum' => (int) $evals->sum('rejects'),
        ];
    }

    private static function withRationale(ProposedStrategy $proposal, string $rationale): ProposedStrategy
    {
        return new ProposedStrategy(
            userId: $proposal->userId,
            name: $proposal->name,
            config: $proposal->config,
            configHash: $proposal->configHash,
            parentId: $proposal->parentId,
            generation: $proposal->generation,
            rationale: $rationale,
            params: $proposal->params,
        );
    }
}
