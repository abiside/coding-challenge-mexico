<?php

declare(strict_types=1);

namespace Tests\Unit\Arbitrage;

use App\Arbitrage\Engine\DTO\EvaluatedOpportunity;
use App\Arbitrage\Engine\DTO\LiquidityResult;
use App\Arbitrage\Engine\DTO\OpportunityCandidate;
use App\Arbitrage\Engine\DTO\ProfitabilityResult;
use App\Arbitrage\Execution\DTO\SimulatedFill;
use App\Arbitrage\Execution\DTO\SimulationResult;
use App\Arbitrage\MarketData\BookState;
use App\Arbitrage\Persistence\BufferedOpportunityRecorder;
use App\Arbitrage\Persistence\PersistenceBuffer;
use App\Arbitrage\Risk\RiskDecision;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

class BufferedOpportunityRecorderTest extends TestCase
{
    use ArbitrageTestFactory;

    private function evaluated(): EvaluatedOpportunity
    {
        $buyBook = BookState::fromSnapshot($this->snapshot('binance', 'BTC/USDT', [[99, 5]], [[100, 5]]), 1_000);
        $sellBook = BookState::fromSnapshot($this->snapshot('kraken', 'BTC/USDT', [[110, 5]], [[111, 5]]), 1_000);
        $candidate = new OpportunityCandidate('BTC/USDT', $buyBook, $sellBook, 100.0, 110.0, 1_000);

        $liquidity = new LiquidityResult(1.0, 100.0, 110.0, 100.0, 110.0, false);
        $profit = new ProfitabilityResult(1.0, 10.0, 0.1, 0.22, 0.0, 0.0, 9.68, 100.0);

        return new EvaluatedOpportunity($candidate, $liquidity, $profit);
    }

    private function simulation(): SimulationResult
    {
        $buy = new SimulatedFill('buy', 'binance', 'BTC/USDT', 1.0, 100.0, 100.0, 0.1);
        $sell = new SimulatedFill('sell', 'kraken', 'BTC/USDT', 1.0, 110.0, 110.0, 0.22);

        return new SimulationResult('op-1', 'BTC/USDT', $buy, $sell, 9.68, 1_000);
    }

    /**
     * Lee el array privado $items del buffer sin pasar por DB.
     *
     * @return array<int, array{opportunity: array<string, mixed>, trade: array<string, mixed>|null, fills: array<int, array<string, mixed>>}>
     */
    private function bufferItems(PersistenceBuffer $buffer): array
    {
        $ref = new ReflectionClass($buffer);

        return (array) $ref->getProperty('items')->getValue($buffer);
    }

    public function test_recorder_tags_rows_with_user_and_strategy_id(): void
    {
        // flushSize alto para no disparar transacciones a DB durante el test.
        $buffer = new PersistenceBuffer(new NullLogger, flushSize: 9_999);

        $recorder = new BufferedOpportunityRecorder(
            buffer: $buffer,
            recordDecisions: ['execute'],
            userId: 42,
            strategyId: 7,
        );

        $recorder->record($this->evaluated(), RiskDecision::execute(1.0), $this->simulation());

        $items = $this->bufferItems($buffer);
        $this->assertCount(1, $items);
        $this->assertSame(42, $items[0]['opportunity']['user_id']);
        $this->assertSame(7, $items[0]['opportunity']['strategy_id']);
        $this->assertNotNull($items[0]['trade']);
        $this->assertSame(42, $items[0]['trade']['user_id']);
        $this->assertSame(7, $items[0]['trade']['strategy_id']);
        $this->assertCount(2, $items[0]['fills']);
    }

    public function test_recorder_skips_decisions_outside_filter(): void
    {
        $buffer = new PersistenceBuffer(new NullLogger, flushSize: 9_999);

        $recorder = new BufferedOpportunityRecorder(
            buffer: $buffer,
            recordDecisions: ['execute'],
            userId: 1,
            strategyId: 9,
        );

        $recorder->record($this->evaluated(), RiskDecision::reject('low_profit'), null);
        $this->assertCount(0, $this->bufferItems($buffer));
    }

    public function test_recorder_persists_opportunity_only_when_no_simulation(): void
    {
        $buffer = new PersistenceBuffer(new NullLogger, flushSize: 9_999);

        $recorder = new BufferedOpportunityRecorder(
            buffer: $buffer,
            recordDecisions: ['execute', 'reject'],
            userId: 1,
            strategyId: 9,
        );

        $recorder->record($this->evaluated(), RiskDecision::reject('low_profit'), null);

        $items = $this->bufferItems($buffer);
        $this->assertCount(1, $items);
        $this->assertNull($items[0]['trade']);
        $this->assertSame('reject', $items[0]['opportunity']['decision']);
        $this->assertSame(9, $items[0]['opportunity']['strategy_id']);
    }
}
