<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ArbitrageSetting;
use App\Models\ArbitrageStrategy;
use App\Models\BotEvent;
use App\Models\StrategyEvaluation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OptimizeStrategiesTest extends TestCase
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
        ArbitrageSetting::create([
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

        $setting = ArbitrageSetting::where('user_id', $user->id)->first();
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
            // Backdated para que el cooldown del fallback cuantitativo no bloquee.
            'promoted_at' => now()->subHour(),
        ]);
    }

    public function test_promotion_resets_cohort_and_regenerates_challengers(): void
    {
        // Sin LLM: el juez degrada al veredicto cuantitativo del optimizador.
        config()->set('ai.autopilot.enabled', false);

        $user = User::factory()->create();
        $champion = $this->makeChampion($user);

        $winner = ArbitrageStrategy::create([
            'user_id' => $user->id,
            'name' => 'winner',
            'status' => ArbitrageStrategy::STATUS_CHALLENGER,
            'origin' => ArbitrageStrategy::ORIGIN_AGENT,
            'parent_id' => $champion->id,
            'generation' => 1,
            'config' => $champion->config,
            'config_hash' => hash('sha256', 'winner'),
        ]);

        // Un segundo challenger flojo que también debe reiniciarse.
        $loser = ArbitrageStrategy::create([
            'user_id' => $user->id,
            'name' => 'loser',
            'status' => ArbitrageStrategy::STATUS_CHALLENGER,
            'origin' => ArbitrageStrategy::ORIGIN_AGENT,
            'parent_id' => $champion->id,
            'generation' => 1,
            'config' => $champion->config,
            'config_hash' => hash('sha256', 'loser'),
        ]);

        for ($i = 0; $i < 3; $i++) {
            StrategyEvaluation::create([
                'strategy_id' => $winner->id,
                'user_id' => $user->id,
                'window_start_ms' => $i * 60_000,
                'window_end_ms' => ($i + 1) * 60_000,
                'snapshots' => 100, 'candidates' => 5, 'executions' => 3, 'rejects' => 0, 'ignores' => 0,
                'realized_pnl' => 25.0, 'executed_volume' => 0.3, 'avg_margin' => 0.001,
                'score' => 25.0,
            ]);
        }

        $this->artisan('arbitrage:optimize', ['--user' => (int) $user->id])
            ->assertSuccessful();

        // El setting tomó los thresholds del ganador (acá iguales al champion).
        $setting = ArbitrageSetting::where('user_id', $user->id)->first();
        $this->assertEqualsWithDelta(1.0, (float) $setting->min_net_profit, 1e-9);

        // La cohorte se reinició: winner y loser archivados.
        $this->assertSame(
            ArbitrageStrategy::STATUS_ARCHIVED,
            $winner->fresh()->status,
        );
        $this->assertSame(
            ArbitrageStrategy::STATUS_ARCHIVED,
            $loser->fresh()->status,
        );

        // Se regeneró una cohorte fresca de challengers (generación nueva).
        $fresh = ArbitrageStrategy::where('user_id', $user->id)
            ->where('status', ArbitrageStrategy::STATUS_CHALLENGER)
            ->get();
        $this->assertGreaterThanOrEqual(1, $fresh->count());
        foreach ($fresh as $c) {
            $this->assertSame((int) $winner->generation + 1, (int) $c->generation);
            $this->assertNotSame((string) $winner->config_hash, (string) $c->config_hash);
        }

        // Se registró el veredicto del juez y la promoción para trazabilidad.
        $this->assertDatabaseHas('bot_events', [
            'user_id' => $user->id,
            'type' => 'autopilot.judge',
        ]);

        // El evento de promoción apunta al NUEVO champion (registro nuevo que
        // arranca en cero); el challenger ganador queda en payload.challenger_id.
        // Así promotions()/series() pueden sumar el P&L del champion por strategy_id.
        $newChampion = ArbitrageStrategy::where('user_id', $user->id)
            ->where('status', ArbitrageStrategy::STATUS_CHAMPION)
            ->firstOrFail();
        $promotionEvent = BotEvent::where('user_id', $user->id)
            ->where('type', 'autopilot.promotion')
            ->firstOrFail();
        $this->assertSame((int) $newChampion->id, (int) $promotionEvent->strategy_id);
        $this->assertSame($winner->id, (int) ($promotionEvent->payload['challenger_id'] ?? null));
    }

    private function makeWinner(User $user, ArbitrageStrategy $champion): ArbitrageStrategy
    {
        $winner = ArbitrageStrategy::create([
            'user_id' => $user->id,
            'name' => 'winner',
            'status' => ArbitrageStrategy::STATUS_CHALLENGER,
            'origin' => ArbitrageStrategy::ORIGIN_AGENT,
            'parent_id' => $champion->id,
            'generation' => 1,
            'config' => $champion->config,
            'config_hash' => hash('sha256', 'winner'),
        ]);

        for ($i = 0; $i < 3; $i++) {
            StrategyEvaluation::create([
                'strategy_id' => $winner->id,
                'user_id' => $user->id,
                'window_start_ms' => $i * 60_000,
                'window_end_ms' => ($i + 1) * 60_000,
                'snapshots' => 100, 'candidates' => 5, 'executions' => 3, 'rejects' => 0, 'ignores' => 0,
                'realized_pnl' => 25.0, 'executed_volume' => 0.3, 'avg_margin' => 0.001,
                'score' => 25.0,
            ]);
        }

        return $winner;
    }

    public function test_auto_promote_disabled_blocks_promotion(): void
    {
        config()->set('ai.autopilot.enabled', false);

        $user = User::factory()->create();
        $champion = $this->makeChampion($user);
        $winner = $this->makeWinner($user, $champion);

        // Apaga la promoción automática: el juez recomienda pero no se aplica.
        $setting = ArbitrageSetting::where('user_id', $user->id)->first();
        $setting->autopilot_auto_promote = false;
        $setting->save();

        $this->artisan('arbitrage:optimize', ['--user' => (int) $user->id])->assertSuccessful();

        // El ganador sigue como challenger (no se promovió ni reinició la cohorte).
        $this->assertSame(ArbitrageStrategy::STATUS_CHALLENGER, $winner->fresh()->status);
        $this->assertDatabaseMissing('bot_events', [
            'user_id' => $user->id,
            'type' => 'autopilot.promotion',
        ]);
    }

    public function test_interval_not_elapsed_blocks_promotion(): void
    {
        config()->set('ai.autopilot.enabled', false);

        $user = User::factory()->create();
        $champion = $this->makeChampion($user);
        // Champion recién promovido: dentro del periodo configurado.
        $champion->promoted_at = now();
        $champion->save();
        $winner = $this->makeWinner($user, $champion);

        $setting = ArbitrageSetting::where('user_id', $user->id)->first();
        $setting->autopilot_auto_promote = true;
        $setting->autopilot_interval_minutes = 30;
        $setting->save();

        $this->artisan('arbitrage:optimize', ['--user' => (int) $user->id])->assertSuccessful();

        $this->assertSame(ArbitrageStrategy::STATUS_CHALLENGER, $winner->fresh()->status);
        $this->assertDatabaseMissing('bot_events', [
            'user_id' => $user->id,
            'type' => 'autopilot.promotion',
        ]);
    }

    public function test_no_promotion_runs_normal_exploration(): void
    {
        config()->set('ai.autopilot.enabled', false);

        $user = User::factory()->create();
        $this->makeChampion($user);

        // Sin challengers y con slots libres: debe proponer/crear sin promover.
        $this->artisan('arbitrage:optimize', ['--user' => (int) $user->id])
            ->assertSuccessful();

        $challengers = ArbitrageStrategy::where('user_id', $user->id)
            ->where('status', ArbitrageStrategy::STATUS_CHALLENGER)
            ->count();
        $this->assertGreaterThanOrEqual(1, $challengers);

        // No hubo promoción.
        $this->assertDatabaseMissing('bot_events', [
            'user_id' => $user->id,
            'type' => 'autopilot.promotion',
        ]);
    }
}
