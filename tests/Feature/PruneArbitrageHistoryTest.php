<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ArbitrageStrategy;
use App\Models\BotEvent;
use App\Models\Opportunity;
use App\Models\StrategyEvaluation;
use App\Models\Trade;
use App\Models\TradeFill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PruneArbitrageHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function ageRecord(string $table, int $id, Carbon $at): void
    {
        DB::table($table)->where('id', $id)->update(['created_at' => $at]);
    }

    public function test_prune_removes_records_older_than_window_and_keeps_recent(): void
    {
        $user = User::factory()->create();
        $strategy = ArbitrageStrategy::create([
            'user_id' => $user->id,
            'name' => 'champion',
            'status' => ArbitrageStrategy::STATUS_CHAMPION,
            'origin' => ArbitrageStrategy::ORIGIN_BASELINE,
            'parent_id' => null,
            'generation' => 0,
            'config' => ['x' => 1],
            'config_hash' => hash('sha256', 'champion'),
        ]);
        // La estrategia es config/estado: aunque sea "vieja", NO debe purgarse.
        $this->ageRecord('arbitrage_strategies', (int) $strategy->id, Carbon::now()->subDays(2));

        $old = Carbon::now()->subHours(10);
        $recent = Carbon::now()->subHours(2);

        $mkOpp = function (Carbon $at) use ($user): int {
            $opp = Opportunity::create([
                'user_id' => $user->id,
                'symbol' => 'BTC/USDT',
                'buy_exchange' => 'binance',
                'sell_exchange' => 'kraken',
                'buy_ask' => 100, 'sell_bid' => 101,
                'gross_spread_bps' => 10, 'base_volume' => 0.1,
                'weighted_buy_price' => 100, 'weighted_sell_price' => 101,
                'gross_profit' => 1, 'net_profit' => 0.5, 'net_margin' => 0.001,
                'total_costs' => 0.5, 'partial_fill' => false,
                'decision' => 'execute', 'detected_at_ms' => 1,
            ]);
            $this->ageRecord('opportunities', (int) $opp->id, $at);

            return (int) $opp->id;
        };

        $oldOpp = $mkOpp($old);
        $recentOpp = $mkOpp($recent);

        // Trade viejo con un fill hijo: el fill debe caer (cascada y/o created_at).
        $oldTrade = Trade::create([
            'user_id' => $user->id,
            'symbol' => 'BTC/USDT', 'buy_exchange' => 'binance', 'sell_exchange' => 'kraken',
            'base_volume' => 0.1, 'realized_pnl' => 0.5, 'status' => 'simulated',
            'idempotency_key' => 'old-1', 'executed_at_ms' => 1,
        ]);
        $oldFill = TradeFill::create([
            'trade_id' => $oldTrade->id, 'side' => 'buy', 'exchange' => 'binance',
            'symbol' => 'BTC/USDT', 'base_volume' => 0.1, 'price' => 100, 'notional' => 10, 'fee' => 0.01,
        ]);
        $this->ageRecord('trades', (int) $oldTrade->id, $old);
        $this->ageRecord('trade_fills', (int) $oldFill->id, $old);

        $recentTrade = Trade::create([
            'user_id' => $user->id,
            'symbol' => 'BTC/USDT', 'buy_exchange' => 'binance', 'sell_exchange' => 'kraken',
            'base_volume' => 0.1, 'realized_pnl' => 0.5, 'status' => 'simulated',
            'idempotency_key' => 'recent-1', 'executed_at_ms' => 1,
        ]);
        $this->ageRecord('trades', (int) $recentTrade->id, $recent);

        $mkEval = function (Carbon $at) use ($user, $strategy): int {
            $eval = StrategyEvaluation::create([
                'strategy_id' => $strategy->id, 'user_id' => $user->id,
                'window_start_ms' => 0, 'window_end_ms' => 60000,
                'snapshots' => 1, 'candidates' => 1, 'executions' => 1, 'rejects' => 0, 'ignores' => 0,
                'realized_pnl' => 1.0, 'executed_volume' => 0.1, 'avg_margin' => 0.001, 'score' => 1.0,
            ]);
            $this->ageRecord('strategy_evaluations', (int) $eval->id, $at);

            return (int) $eval->id;
        };
        $oldEval = $mkEval($old);
        $recentEval = $mkEval($recent);

        $oldEvent = BotEvent::create(['type' => 'x', 'level' => 'info', 'created_at' => $old]);
        $recentEvent = BotEvent::create(['type' => 'x', 'level' => 'info', 'created_at' => $recent]);

        $this->artisan('arbitrage:prune', ['--hours' => 8])->assertSuccessful();

        // Viejos borrados.
        $this->assertDatabaseMissing('opportunities', ['id' => $oldOpp]);
        $this->assertDatabaseMissing('trades', ['id' => $oldTrade->id]);
        $this->assertDatabaseMissing('trade_fills', ['id' => $oldFill->id]);
        $this->assertDatabaseMissing('strategy_evaluations', ['id' => $oldEval]);
        $this->assertDatabaseMissing('bot_events', ['id' => $oldEvent->id]);

        // Recientes conservados.
        $this->assertDatabaseHas('opportunities', ['id' => $recentOpp]);
        $this->assertDatabaseHas('trades', ['id' => $recentTrade->id]);
        $this->assertDatabaseHas('strategy_evaluations', ['id' => $recentEval]);
        $this->assertDatabaseHas('bot_events', ['id' => $recentEvent->id]);

        // Config/estado intacto pese a ser viejo.
        $this->assertDatabaseHas('arbitrage_strategies', ['id' => $strategy->id]);
    }

    public function test_dry_run_reports_without_deleting(): void
    {
        $user = User::factory()->create();
        $opp = Opportunity::create([
            'user_id' => $user->id,
            'symbol' => 'BTC/USDT', 'buy_exchange' => 'binance', 'sell_exchange' => 'kraken',
            'buy_ask' => 100, 'sell_bid' => 101, 'gross_spread_bps' => 10, 'base_volume' => 0.1,
            'weighted_buy_price' => 100, 'weighted_sell_price' => 101,
            'gross_profit' => 1, 'net_profit' => 0.5, 'net_margin' => 0.001,
            'total_costs' => 0.5, 'partial_fill' => false, 'decision' => 'execute', 'detected_at_ms' => 1,
        ]);
        $this->ageRecord('opportunities', (int) $opp->id, Carbon::now()->subHours(10));

        $this->artisan('arbitrage:prune', ['--dry-run' => true])->assertSuccessful();

        $this->assertDatabaseHas('opportunities', ['id' => $opp->id]);
    }
}
