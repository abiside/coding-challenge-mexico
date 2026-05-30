<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Arbitrage\Optimization\PromotionDecision;
use App\Arbitrage\Optimization\StrategyJudge;
use App\Arbitrage\Optimization\StrategyPerformance;
use App\Models\ArbitrageStrategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * Garantiza que el nuevo champion SIEMPRE haya sido un challenger que superó al
 * proceso actual: la guarda dura del juez veta cualquier promoción (LLM o
 * cuantitativa) cuyo candidato no supere el P&L del champion en el periodo.
 */
class StrategyJudgeGuardTest extends TestCase
{
    use RefreshDatabase;

    private function strategy(User $user, string $name, string $status): ArbitrageStrategy
    {
        return ArbitrageStrategy::create([
            'user_id' => $user->id,
            'name' => $name,
            'status' => $status,
            'origin' => ArbitrageStrategy::ORIGIN_AGENT,
            'parent_id' => null,
            'generation' => 1,
            'config' => ['x' => 1],
            'config_hash' => hash('sha256', $name),
        ]);
    }

    private function perf(int $strategyId, float $pnl): StrategyPerformance
    {
        return new StrategyPerformance(
            strategyId: $strategyId,
            windows: 3,
            pnlSum: $pnl,
            cumulativeFinal: $pnl,
            slope: 0.0,
            maxDrawdown: 0.0,
            positiveRatio: 1.0,
            executions: 3,
            rejects: 0,
            avgMargin: 0.001,
            executedVolume: 0.3,
            pnlWindows: [],
        );
    }

    private function judge(): StrategyJudge
    {
        return new StrategyJudge($this->app->make(LoggerInterface::class));
    }

    public function test_promotion_is_vetoed_when_challenger_does_not_beat_champion(): void
    {
        // Sin LLM: el juez usa el veredicto cuantitativo (fallback) que propone
        // promover al challenger. La guarda debe vetarlo porque NO supera al champion.
        config()->set('ai.autopilot.enabled', false);

        $user = User::factory()->create();
        $champion = $this->strategy($user, 'champion', ArbitrageStrategy::STATUS_CHAMPION);
        $challenger = $this->strategy($user, 'challenger', ArbitrageStrategy::STATUS_CHALLENGER);

        $metrics = [
            $champion->id => $this->perf($champion->id, 100.0),
            $challenger->id => $this->perf($challenger->id, 50.0),
        ];

        $fallback = new PromotionDecision(
            challenger: $challenger,
            championScore: 100.0,
            challengerScore: 50.0,
            edge: -50.0,
        );

        $verdict = $this->judge()->decide(
            $champion,
            new Collection([$challenger]),
            $metrics,
            $fallback,
        );

        $this->assertNull($verdict->promoteStrategyId, 'No debe promover a un challenger que no supera al champion.');
        $this->assertStringContainsString('veto', $verdict->rationale);
    }

    public function test_promotion_proceeds_when_challenger_beats_champion(): void
    {
        config()->set('ai.autopilot.enabled', false);

        $user = User::factory()->create();
        $champion = $this->strategy($user, 'champion', ArbitrageStrategy::STATUS_CHAMPION);
        $challenger = $this->strategy($user, 'challenger', ArbitrageStrategy::STATUS_CHALLENGER);

        $metrics = [
            $champion->id => $this->perf($champion->id, 80.0),
            $challenger->id => $this->perf($challenger->id, 140.0),
        ];

        $fallback = new PromotionDecision(
            challenger: $challenger,
            championScore: 80.0,
            challengerScore: 140.0,
            edge: 60.0,
        );

        $verdict = $this->judge()->decide(
            $champion,
            new Collection([$challenger]),
            $metrics,
            $fallback,
        );

        $this->assertSame((int) $challenger->id, $verdict->promoteStrategyId);
    }

    public function test_tie_does_not_displace_incumbent(): void
    {
        config()->set('ai.autopilot.enabled', false);

        $user = User::factory()->create();
        $champion = $this->strategy($user, 'champion', ArbitrageStrategy::STATUS_CHAMPION);
        $challenger = $this->strategy($user, 'challenger', ArbitrageStrategy::STATUS_CHALLENGER);

        $metrics = [
            $champion->id => $this->perf($champion->id, 100.0),
            $challenger->id => $this->perf($challenger->id, 100.0),
        ];

        $fallback = new PromotionDecision(
            challenger: $challenger,
            championScore: 100.0,
            challengerScore: 100.0,
            edge: 0.0,
        );

        $verdict = $this->judge()->decide(
            $champion,
            new Collection([$challenger]),
            $metrics,
            $fallback,
        );

        $this->assertNull($verdict->promoteStrategyId, 'Un empate no debe desplazar al champion vigente.');
    }
}
