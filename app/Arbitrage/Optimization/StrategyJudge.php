<?php

declare(strict_types=1);

namespace App\Arbitrage\Optimization;

use App\Models\ArbitrageStrategy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * "Juez" del autopilot: evalúa al champion y a TODOS los challengers vivos en
 * paralelo, usando las métricas reales del periodo (P&L, tendencia/slope,
 * consistencia y drawdown), y decide a quién promover a champion.
 *
 * A diferencia del StrategyAdvisor (que solo opina sobre challengers a CREAR),
 * el Judge decide la PROMOCIÓN comparando desempeño realizado + promesa de
 * crecimiento. El LLM decide directamente; el código solo aplica una guarda de
 * suficiencia de datos (mínimo de ventanas) y de seguridad (nunca "promueve" al
 * propio champion ni a una estrategia inexistente).
 *
 * Diseño defensivo:
 *  - Sin API key / deshabilitado -> fallback al veredicto cuantitativo del
 *    optimizador (PromotionDecision), si existe.
 *  - Cualquier error del LLM -> mismo fallback (o sin cambios).
 */
final class StrategyJudge
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param  Collection<int, ArbitrageStrategy>  $challengers
     * @param  array<int, StrategyPerformance>  $metrics  por strategy_id (incluye champion)
     */
    public function decide(
        ArbitrageStrategy $champion,
        Collection $challengers,
        array $metrics,
        ?PromotionDecision $quantFallback,
    ): JudgeVerdict {
        $config = (array) config('ai.autopilot', []);
        $apiKey = (string) ($config['api_key'] ?? '');
        $enabled = (bool) ($config['enabled'] ?? false);
        $minWindows = (int) ($config['min_judge_windows'] ?? 2);

        if ($challengers->isEmpty()) {
            return JudgeVerdict::noChange('quant_fallback', 'Sin challengers vivos que evaluar.');
        }

        if (! $enabled || $apiKey === '') {
            $verdict = $this->fromFallback($quantFallback, $minWindows, 'advisor deshabilitado; veredicto cuantitativo');

            return $this->enforceBeatsChampion($verdict, $champion, $metrics);
        }

        try {
            $parsed = $this->callLlm($champion, $challengers, $metrics, $config);
            $verdict = $this->fromLlm($parsed, $champion, $challengers, $minWindows);
        } catch (Throwable $e) {
            $this->logger->warning('[autopilot][judge] LLM falló, degradando a cuantitativo', [
                'error' => $e->getMessage(),
            ]);

            if (! (bool) ($config['fallback_to_optimizer'] ?? true)) {
                return JudgeVerdict::noChange('quant_fallback', 'LLM falló y fallback deshabilitado.');
            }

            $verdict = $this->fromFallback($quantFallback, $minWindows, 'fallback por error LLM: '.$e->getMessage());
        }

        return $this->enforceBeatsChampion($verdict, $champion, $metrics);
    }

    /**
     * Guarda dura e independiente de la fuente (LLM o cuantitativa): el nuevo
     * champion SIEMPRE debe haber sido un challenger que superó al proceso actual
     * (el champion vigente) en P&L realizado sobre el MISMO periodo y bajo el
     * mismo feed compartido. Si el candidato no supera al champion, se veta la
     * promoción y el incumbente se mantiene. Esto blinda contra errores de juicio
     * del LLM y promociones por ruido.
     *
     * @param  array<int, StrategyPerformance>  $metrics  por strategy_id (incluye champion)
     */
    private function enforceBeatsChampion(JudgeVerdict $verdict, ArbitrageStrategy $champion, array $metrics): JudgeVerdict
    {
        if ($verdict->promoteStrategyId === null) {
            return $verdict;
        }

        $championPnl = ($metrics[(int) $champion->id] ?? null)?->pnlSum ?? 0.0;
        $candidatePerf = $metrics[$verdict->promoteStrategyId] ?? null;
        $candidatePnl = $candidatePerf?->pnlSum;

        // Estrictamente mejor: empatar no desplaza al incumbente (sesgo de
        // incumbencia a favor de la estabilidad).
        if ($candidatePnl !== null && $candidatePnl > $championPnl) {
            return $verdict;
        }

        $this->logger->info('[autopilot][judge] promoción vetada: el challenger no supera al champion', [
            'candidate_id' => $verdict->promoteStrategyId,
            'candidate_pnl' => $candidatePnl,
            'champion_id' => (int) $champion->id,
            'champion_pnl' => $championPnl,
        ]);

        return new JudgeVerdict(
            promoteStrategyId: null,
            bestPerformanceId: $verdict->bestPerformanceId,
            bestGrowthId: $verdict->bestGrowthId,
            rationale: trim($verdict->rationale.sprintf(
                ' [veto: el challenger #%d no superó el P&L del champion en el periodo (%.4f <= %.4f); se mantiene el champion actual]',
                $verdict->promoteStrategyId,
                (float) ($candidatePnl ?? 0.0),
                (float) $championPnl,
            )),
            source: $verdict->source,
            ranking: $verdict->ranking,
        );
    }

    /**
     * Construye el veredicto a partir de la decisión cuantitativa previa.
     */
    private function fromFallback(?PromotionDecision $fallback, int $minWindows, string $note): JudgeVerdict
    {
        if ($fallback === null) {
            return JudgeVerdict::noChange('quant_fallback', 'Ningún challenger supera al champion. '.$note);
        }

        return new JudgeVerdict(
            promoteStrategyId: (int) $fallback->challenger->id,
            bestPerformanceId: (int) $fallback->challenger->id,
            bestGrowthId: (int) $fallback->challenger->id,
            rationale: sprintf(
                'Promoción cuantitativa: challenger #%d supera al champion por edge=%.4f. %s',
                (int) $fallback->challenger->id,
                $fallback->edge,
                $note,
            ),
            source: 'quant_fallback',
        );
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  Collection<int, ArbitrageStrategy>  $challengers
     */
    private function fromLlm(
        array $parsed,
        ArbitrageStrategy $champion,
        Collection $challengers,
        int $minWindows,
    ): JudgeVerdict {
        $byName = $challengers->keyBy(static fn (ArbitrageStrategy $s): string => $s->name);

        $resolve = function (mixed $name) use ($byName, $champion): ?int {
            $name = is_string($name) ? trim($name) : '';
            if ($name === '') {
                return null;
            }
            if ($name === $champion->name) {
                return (int) $champion->id;
            }
            $match = $byName->get($name);

            return $match !== null ? (int) $match->id : null;
        };

        $promoteId = null;
        $promoteName = $parsed['promote'] ?? null;
        if (is_string($promoteName) && trim($promoteName) !== '' && strtolower(trim($promoteName)) !== 'null') {
            $candidate = $byName->get(trim($promoteName));
            // Guarda: solo challengers con datos suficientes; nunca el champion.
            if ($candidate !== null
                && \App\Models\StrategyEvaluation::where('strategy_id', $candidate->id)->count() >= $minWindows) {
                $promoteId = (int) $candidate->id;
            } else {
                $this->logger->info('[autopilot][judge] LLM propuso promover pero no pasa la guarda de datos', [
                    'name' => $promoteName,
                ]);
            }
        }

        $ranking = [];
        if (isset($parsed['ranking']) && is_array($parsed['ranking'])) {
            foreach ($parsed['ranking'] as $row) {
                if (is_array($row)) {
                    $ranking[] = $row;
                }
            }
        }

        return new JudgeVerdict(
            promoteStrategyId: $promoteId,
            bestPerformanceId: $resolve($parsed['best_performance'] ?? null),
            bestGrowthId: $resolve($parsed['best_growth'] ?? null),
            rationale: trim((string) ($parsed['rationale'] ?? 'Veredicto del LLM sin rationale.')),
            source: 'llm',
            ranking: $ranking,
        );
    }

    /**
     * @param  Collection<int, ArbitrageStrategy>  $challengers
     * @param  array<int, StrategyPerformance>  $metrics
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function callLlm(
        ArbitrageStrategy $champion,
        Collection $challengers,
        array $metrics,
        array $config,
    ): array {
        $url = rtrim((string) ($config['base_url'] ?? 'https://api.openai.com/v1'), '/').'/chat/completions';
        $timeout = (int) ($config['timeout_seconds'] ?? 20);
        $apiKey = (string) $config['api_key'];

        $payload = [
            'model' => (string) ($config['model'] ?? 'gpt-4o-mini'),
            'temperature' => 0.2,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => $this->systemPrompt()],
                ['role' => 'user', 'content' => $this->userPrompt($champion, $challengers, $metrics)],
            ],
        ];

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$apiKey,
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
        return <<<'PROMPT'
Eres el "Strategy Judge" de un bot de arbitraje crypto simulado con autopilot champion-challenger.

Recibirás, para un mismo periodo y bajo EXACTAMENTE el mismo feed de mercado (perturbación compartida), las métricas reales del champion y de cada challenger:
- pnl_sum / cumulative_final: P&L realizado del periodo.
- slope_per_window: pendiente del P&L acumulado (promesa de crecimiento: positiva = gana cada vez más).
- positive_window_ratio: consistencia (fracción de ventanas en verde).
- max_drawdown: peor caída pico-valle (riesgo de la curva).
- executions / rejects / avg_margin / executed_volume.

Tu tarea: comparar a los TRES (o los que haya) en paralelo y decidir:
1. best_performance: quién rindió mejor en P&L del periodo.
2. best_growth: quién tiene mejor PROMESA de crecimiento (tendencia sostenida + consistencia, penalizando drawdown alto).
3. promote: el CHALLENGER que debe convertirse en nuevo champion, o null si ninguno justifica reemplazar al champion actual. Promueve solo a un challenger (nunca al champion). Prioriza challengers que combinen buen P&L con tendencia positiva y consistente; desconfía de picos con poca muestra o drawdown grande.

Devuelve EXCLUSIVAMENTE JSON con este shape:
{
  "promote": "<nombre del challenger a promover o null>",
  "best_performance": "<nombre>",
  "best_growth": "<nombre>",
  "rationale": "2-4 frases comparando a los candidatos y justificando la decisión",
  "ranking": [ { "name": "<nombre>", "verdict": "<frase corta>" } ]
}
PROMPT;
    }

    /**
     * @param  Collection<int, ArbitrageStrategy>  $challengers
     * @param  array<int, StrategyPerformance>  $metrics
     */
    private function userPrompt(ArbitrageStrategy $champion, Collection $challengers, array $metrics): string
    {
        $describe = function (ArbitrageStrategy $s, string $role) use ($metrics): array {
            $perf = $metrics[(int) $s->id] ?? null;

            return [
                'name' => $s->name,
                'role' => $role,
                'generation' => (int) $s->generation,
                'params' => StrategyBounds::extract((array) $s->config),
                'metrics' => $perf?->toArray() ?? ['windows' => 0],
            ];
        };

        $payload = [
            'objective' => 'maximize_sustained_net_pnl',
            'champion' => $describe($champion, 'champion'),
            'challengers' => $challengers
                ->map(static fn (ArbitrageStrategy $s): array => $describe($s, 'challenger'))
                ->values()
                ->all(),
        ];

        return (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}
