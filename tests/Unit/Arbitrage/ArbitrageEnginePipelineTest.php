<?php

declare(strict_types=1);

namespace Tests\Unit\Arbitrage;

use App\Arbitrage\Engine\ArbitrageEngine;
use App\Arbitrage\Engine\ArbitrageScanner;
use App\Arbitrage\Engine\FeeSchedule;
use App\Arbitrage\Engine\LiquidityCalculator;
use App\Arbitrage\Engine\ProfitabilityCalculator;
use App\Arbitrage\Execution\ExecutionSimulator;
use App\Arbitrage\Execution\WalletManager;
use App\Arbitrage\Execution\WalletValidator;
use App\Arbitrage\MarketData\OrderBookStore;
use App\Arbitrage\Persistence\NullOpportunityRecorder;
use App\Arbitrage\Realtime\NullDashboardPublisher;
use App\Arbitrage\Risk\CircuitBreaker;
use App\Arbitrage\Risk\Decision;
use App\Arbitrage\Risk\Guards\FreshnessGuard;
use App\Arbitrage\Risk\Guards\LatencyGuard;
use App\Arbitrage\Risk\Guards\MinProfitGuard;
use App\Arbitrage\Risk\Guards\MinVolumeGuard;
use App\Arbitrage\Risk\RiskManager;
use PHPUnit\Framework\TestCase;

class ArbitrageEnginePipelineTest extends TestCase
{
    use ArbitrageTestFactory;

    private function buildEngine(WalletManager $wallets, float $maxVolume = 1.0): ArbitrageEngine
    {
        $store = new OrderBookStore();
        $scanner = new ArbitrageScanner($store, freshnessMs: 5_000);
        $fees = new FeeSchedule([], 0.0);
        $liquidity = new LiquidityCalculator();
        $profitability = new ProfitabilityCalculator($fees, 0.0, 0.0);
        $walletValidator = new WalletValidator($wallets);

        $guards = [
            new FreshnessGuard(5_000),
            new MinVolumeGuard(0.001),
            new LatencyGuard(10_000),
            new MinProfitGuard(1.0, 0.0001),
        ];
        $risk = new RiskManager($guards, new CircuitBreaker(true, 5, 5_000));
        $simulator = new ExecutionSimulator($wallets);

        return new ArbitrageEngine(
            store: $store,
            scanner: $scanner,
            liquidity: $liquidity,
            profitability: $profitability,
            walletValidator: $walletValidator,
            riskManager: $risk,
            simulator: $simulator,
            fees: $fees,
            recorder: new NullOpportunityRecorder(),
            dashboard: new NullDashboardPublisher(),
            maxBaseVolume: $maxVolume,
            minBaseVolume: 0.001,
        );
    }

    public function test_full_pipeline_executes_profitable_opportunity(): void
    {
        $wallets = new WalletManager([
            'binance' => ['USDT' => 100000.0, 'BTC' => 0.0],
            'kraken' => ['USDT' => 0.0, 'BTC' => 5.0],
        ]);
        $engine = $this->buildEngine($wallets);
        $now = 1_000;

        // Sembrar binance barato, luego kraken caro dispara la evaluación.
        $engine->onSnapshot($this->snapshot('binance', 'BTC/USDT', [[99, 5]], [[100, 5]]), receivedAtMs: $now);
        $processed = $engine->onSnapshot($this->snapshot('kraken', 'BTC/USDT', [[110, 5]], [[111, 5]]), receivedAtMs: $now);

        $this->assertNotEmpty($processed);
        $executed = array_values(array_filter(
            $processed,
            static fn ($p) => $p->decision->decision === Decision::Execute,
        ));
        $this->assertCount(1, $executed);

        $outcome = $executed[0];
        $this->assertSame('binance', $outcome->opportunity->buyExchange());
        $this->assertSame('kraken', $outcome->opportunity->sellExchange());
        $this->assertNotNull($outcome->simulation);
        $this->assertGreaterThan(0.0, $outcome->simulation->realizedPnl);

        // Single-writer movió balances.
        $this->assertGreaterThan(0.0, $wallets->available('binance', 'BTC'));
        $this->assertLessThan(5.0, $wallets->available('kraken', 'BTC'));
    }

    public function test_pipeline_rejects_when_balance_insufficient(): void
    {
        // Sin USDT en binance para comprar, ni BTC en kraken para vender.
        $wallets = new WalletManager([
            'binance' => ['USDT' => 0.0, 'BTC' => 0.0],
            'kraken' => ['USDT' => 0.0, 'BTC' => 0.0],
        ]);
        $engine = $this->buildEngine($wallets);
        $now = 1_000;

        $engine->onSnapshot($this->snapshot('binance', 'BTC/USDT', [[99, 5]], [[100, 5]]), receivedAtMs: $now);
        $processed = $engine->onSnapshot($this->snapshot('kraken', 'BTC/USDT', [[110, 5]], [[111, 5]]), receivedAtMs: $now);

        $this->assertNotEmpty($processed);
        $rejected = array_values(array_filter(
            $processed,
            static fn ($p) => $p->decision->decision === Decision::Reject,
        ));
        $this->assertNotEmpty($rejected);
        $this->assertStringContainsString('insufficient_balance', $rejected[0]->decision->reasons[0]);
    }
}
