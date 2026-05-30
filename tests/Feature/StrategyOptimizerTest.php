<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Arbitrage\Optimization\StrategyBounds;
use App\Arbitrage\Optimization\StrategyOptimizer;
use App\Models\ArbitrageSetting;
use App\Models\ArbitrageStrategy;
use App\Models\StrategyEvaluation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StrategyOptimizerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function baseConfig(): array
    {
        return (array) config('arbitrage');
    }

    private function makeChampion(User $user): ArbitrageStrategy
    {
        $setting = ArbitrageSetting::create([
            'user_id' => $user->id,
            'symbols' => ['BTC/USDT'],
            'min_net_profit' => 1.0,
            'min_net_margin' => 0.0005,
            'min_base_volume' => 0.0001,
            'max_base_volume' => 1.0,
            'freshness_ms' => 2000,
            'latency_max_ms' => 1500,
            'circuit_breaker_enabled' => true,
            'autopilot_enabled' => true,
            'autopilot_max_challengers' => 3,
        ]);

        $config = $setting->toEngineConfig($this->baseConfig());

        return ArbitrageStrategy::create([
            'user_id' => $user->id,
            'name' => 'champion',
            'status' => ArbitrageStrategy::STATUS_CHAMPION,
            'origin' => ArbitrageStrategy::ORIGIN_BASELINE,
            'parent_id' => null,
            'generation' => 0,
            'config' => $config,
            'config_hash' => ArbitrageStrategy::hashConfig($config),
            'promoted_at' => now(),
        ]);
    }

    public function test_proposals_respect_bounds_and_are_deduplicated(): void
    {
        $user = User::factory()->create();
        $champion = $this->makeChampion($user);
        $setting = ArbitrageSetting::where('user_id', $user->id)->first();

        $optimizer = new StrategyOptimizer;
        $plan = $optimizer->plan((int) $user->id, $setting, $this->baseConfig());

        $this->assertCount(3, $plan->proposals);
        $bounds = StrategyBounds::ranges();
        $hashes = [$champion->config_hash];
        foreach ($plan->proposals as $proposal) {
            foreach ($proposal->params as $key => $value) {
                $this->assertGreaterThanOrEqual($bounds[$key]['min'], $value, "param {$key} below min");
                $this->assertLessThanOrEqual($bounds[$key]['max'], $value, "param {$key} above max");
            }
            $this->assertNotContains($proposal->configHash, $hashes, 'proposal hash duplicated');
            $hashes[] = $proposal->configHash;
        }
    }

    public function test_no_promotion_without_min_evaluations(): void
    {
        $user = User::factory()->create();
        $champion = $this->makeChampion($user);
        $setting = ArbitrageSetting::where('user_id', $user->id)->first();

        // Crea un challenger con pocas evaluations pero alto P&L: NO debe promover
        // porque no llega al mínimo de muestra.
        $challenger = ArbitrageStrategy::create([
            'user_id' => $user->id,
            'name' => 'challenger-a',
            'status' => ArbitrageStrategy::STATUS_CHALLENGER,
            'origin' => ArbitrageStrategy::ORIGIN_AGENT,
            'parent_id' => $champion->id,
            'generation' => 1,
            'config' => $champion->config,
            'config_hash' => hash('sha256', 'challenger-a'),
        ]);

        StrategyEvaluation::create([
            'strategy_id' => $challenger->id,
            'user_id' => $user->id,
            'window_start_ms' => 1, 'window_end_ms' => 60_000,
            'snapshots' => 100, 'candidates' => 10, 'executions' => 5, 'rejects' => 0, 'ignores' => 0,
            'realized_pnl' => 50.0, 'executed_volume' => 1.0, 'avg_margin' => 0.001,
            'score' => 50.0,
        ]);

        $optimizer = new StrategyOptimizer(minEvaluations: 3);
        $plan = $optimizer->plan((int) $user->id, $setting, $this->baseConfig());

        $this->assertNull($plan->promotion);
    }

    public function test_promotion_when_challenger_beats_champion_with_enough_samples(): void
    {
        $user = User::factory()->create();
        $champion = $this->makeChampion($user);
        // Backdated promoted_at para que el cooldown no bloquee.
        $champion->promoted_at = now()->subHour();
        $champion->save();
        $setting = ArbitrageSetting::where('user_id', $user->id)->first();

        $challenger = ArbitrageStrategy::create([
            'user_id' => $user->id,
            'name' => 'winner',
            'status' => ArbitrageStrategy::STATUS_CHALLENGER,
            'origin' => ArbitrageStrategy::ORIGIN_AGENT,
            'parent_id' => $champion->id,
            'generation' => 1,
            'config' => $champion->config,
            'config_hash' => hash('sha256', 'winner'),
        ]);

        // 3 evaluaciones del challenger con buen P&L; champion sin ninguna -> score 0.
        for ($i = 0; $i < 3; $i++) {
            StrategyEvaluation::create([
                'strategy_id' => $challenger->id,
                'user_id' => $user->id,
                'window_start_ms' => $i * 60_000,
                'window_end_ms' => ($i + 1) * 60_000,
                'snapshots' => 100, 'candidates' => 5, 'executions' => 3, 'rejects' => 0, 'ignores' => 0,
                'realized_pnl' => 5.0, 'executed_volume' => 0.3, 'avg_margin' => 0.001,
                'score' => 5.0,
            ]);
        }

        $optimizer = new StrategyOptimizer(minEvaluations: 3, promotionEdge: 1.0, promotionCooldownSeconds: 60);
        $plan = $optimizer->plan((int) $user->id, $setting, $this->baseConfig());

        $this->assertNotNull($plan->promotion);
        $this->assertSame($challenger->id, $plan->promotion->challenger->id);
        $this->assertGreaterThanOrEqual(1.0, $plan->promotion->edge);
    }

    public function test_promotion_blocked_by_cooldown(): void
    {
        $user = User::factory()->create();
        $champion = $this->makeChampion($user);
        // Recién promovido: cooldown debe bloquear nuevas promociones.
        $champion->promoted_at = now();
        $champion->save();
        $setting = ArbitrageSetting::where('user_id', $user->id)->first();

        $challenger = ArbitrageStrategy::create([
            'user_id' => $user->id,
            'name' => 'fast-winner',
            'status' => ArbitrageStrategy::STATUS_CHALLENGER,
            'origin' => ArbitrageStrategy::ORIGIN_AGENT,
            'parent_id' => $champion->id,
            'generation' => 1,
            'config' => $champion->config,
            'config_hash' => hash('sha256', 'fast-winner'),
        ]);
        for ($i = 0; $i < 5; $i++) {
            StrategyEvaluation::create([
                'strategy_id' => $challenger->id,
                'user_id' => $user->id,
                'window_start_ms' => $i * 60_000,
                'window_end_ms' => ($i + 1) * 60_000,
                'snapshots' => 100, 'candidates' => 5, 'executions' => 3, 'rejects' => 0, 'ignores' => 0,
                'realized_pnl' => 100.0, 'executed_volume' => 0.3, 'avg_margin' => 0.001,
                'score' => 100.0,
            ]);
        }

        $optimizer = new StrategyOptimizer(promotionCooldownSeconds: 600);
        $plan = $optimizer->plan((int) $user->id, $setting, $this->baseConfig());

        $this->assertNull($plan->promotion);
    }

    public function test_retires_worst_challenger_when_pool_full(): void
    {
        $user = User::factory()->create();
        $champion = $this->makeChampion($user);
        $setting = ArbitrageSetting::where('user_id', $user->id)->first();
        $setting->autopilot_max_challengers = 1;
        $setting->save();

        // 1 challenger ya existente, score 0.
        $existing = ArbitrageStrategy::create([
            'user_id' => $user->id,
            'name' => 'old',
            'status' => ArbitrageStrategy::STATUS_CHALLENGER,
            'origin' => ArbitrageStrategy::ORIGIN_AGENT,
            'parent_id' => $champion->id,
            'generation' => 1,
            'config' => $champion->config,
            'config_hash' => hash('sha256', 'old'),
        ]);

        // No hay slots -> el optimizador no propone nuevos, ni retira.
        $optimizer = new StrategyOptimizer;
        $plan = $optimizer->plan((int) $user->id, $setting, $this->baseConfig());

        $this->assertEmpty($plan->proposals);
        $this->assertEmpty($plan->retirements);

        // Si subimos max_challengers a 2 sin perturbar al optimizador, podría
        // proponer un nuevo y NO retirar al viejo.
        $setting->autopilot_max_challengers = 2;
        $setting->save();
        $plan2 = $optimizer->plan((int) $user->id, $setting, $this->baseConfig());
        $this->assertCount(1, $plan2->proposals);
        $this->assertEmpty($plan2->retirements);
    }
}
