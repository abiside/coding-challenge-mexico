<?php

declare(strict_types=1);

namespace Tests\Unit\Arbitrage;

use App\Arbitrage\Engine\DTO\EvaluatedOpportunity;
use App\Arbitrage\Engine\DTO\LiquidityResult;
use App\Arbitrage\Engine\DTO\OpportunityCandidate;
use App\Arbitrage\Engine\DTO\ProfitabilityResult;
use App\Arbitrage\MarketData\BookState;
use App\Arbitrage\Risk\CircuitBreaker;
use App\Arbitrage\Risk\Decision;
use App\Arbitrage\Risk\Guards\FreshnessGuard;
use App\Arbitrage\Risk\Guards\LatencyGuard;
use App\Arbitrage\Risk\Guards\MinProfitGuard;
use App\Arbitrage\Risk\Guards\MinVolumeGuard;
use App\Arbitrage\Risk\RiskManager;
use PHPUnit\Framework\TestCase;

class RiskManagerTest extends TestCase
{
    use ArbitrageTestFactory;

    private function evaluated(float $netProfit, float $volume, int $bookAgeMs = 0, int $nowMs = 1_000): EvaluatedOpportunity
    {
        $received = $nowMs - $bookAgeMs;
        $buyBook = BookState::fromSnapshot($this->snapshot('binance', 'BTC/USDT', [[99, 5]], [[100, 5]]), $received);
        $sellBook = BookState::fromSnapshot($this->snapshot('kraken', 'BTC/USDT', [[110, 5]], [[111, 5]]), $received);
        $candidate = new OpportunityCandidate('BTC/USDT', $buyBook, $sellBook, 100.0, 110.0, $nowMs);

        $liquidity = new LiquidityResult($volume, 100.0, 110.0, 100.0 * $volume, 110.0 * $volume, false);
        $profit = new ProfitabilityResult($volume, $netProfit, 0.0, 0.0, 0.0, 0.0, $netProfit, 100.0 * $volume);

        return new EvaluatedOpportunity($candidate, $liquidity, $profit);
    }

    private function manager(): RiskManager
    {
        $guards = [
            new FreshnessGuard(2_000),
            new MinVolumeGuard(0.001),
            new LatencyGuard(3_000),
            new MinProfitGuard(1.0, 0.0005),
        ];

        return new RiskManager($guards, new CircuitBreaker(true, 3, 5_000));
    }

    public function test_executes_profitable_fresh_opportunity(): void
    {
        $decision = $this->manager()->assess($this->evaluated(netProfit: 9.0, volume: 1.0), nowMs: 1_000);

        $this->assertSame(Decision::Execute, $decision->decision);
        $this->assertEqualsWithDelta(1.0, $decision->finalVolume, 1e-9);
    }

    public function test_rejects_low_profit(): void
    {
        $decision = $this->manager()->assess($this->evaluated(netProfit: 0.2, volume: 1.0), nowMs: 1_000);

        $this->assertSame(Decision::Reject, $decision->decision);
    }

    public function test_ignores_tiny_volume(): void
    {
        $decision = $this->manager()->assess($this->evaluated(netProfit: 9.0, volume: 0.0000001), nowMs: 1_000);

        $this->assertSame(Decision::Ignore, $decision->decision);
    }

    public function test_rejects_stale_book(): void
    {
        $decision = $this->manager()->assess(
            $this->evaluated(netProfit: 9.0, volume: 1.0, bookAgeMs: 5_000),
            nowMs: 1_000_000,
        );

        $this->assertSame(Decision::Reject, $decision->decision);
    }

    public function test_circuit_breaker_opens_after_repeated_failures(): void
    {
        $manager = $this->manager();

        // 3 rechazos consecutivos abren el breaker para ese par.
        for ($i = 0; $i < 3; $i++) {
            $manager->assess($this->evaluated(netProfit: 0.1, volume: 1.0), nowMs: 1_000);
        }

        $decision = $manager->assess($this->evaluated(netProfit: 9.0, volume: 1.0), nowMs: 1_000);
        $this->assertSame(Decision::Ignore, $decision->decision);
        $this->assertStringContainsString('circuit_breaker_open', $decision->reasons[0]);
    }
}
