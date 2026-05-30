<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ArbitrageSetting;
use App\Models\ArbitrageStrategy;
use App\Models\BotEvent;
use App\Models\Opportunity;
use App\Models\StrategyEvaluation;
use App\Models\Trade;
use App\Models\TradeFill;
use App\Models\User;
use App\Models\WalletBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ResetProcessTest extends TestCase
{
    use RefreshDatabase;

    private function seedUserData(User $user): ArbitrageStrategy
    {
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

        ArbitrageStrategy::create([
            'user_id' => $user->id,
            'name' => 'challenger',
            'status' => ArbitrageStrategy::STATUS_CHALLENGER,
            'origin' => ArbitrageStrategy::ORIGIN_AGENT,
            'parent_id' => $strategy->id,
            'generation' => 1,
            'config' => ['x' => 2],
            'config_hash' => hash('sha256', 'challenger'),
        ]);

        $opp = Opportunity::create([
            'user_id' => $user->id,
            'symbol' => 'BTC/USDT', 'buy_exchange' => 'binance', 'sell_exchange' => 'kraken',
            'buy_ask' => 100, 'sell_bid' => 101, 'gross_spread_bps' => 10, 'base_volume' => 0.1,
            'weighted_buy_price' => 100, 'weighted_sell_price' => 101,
            'gross_profit' => 1, 'net_profit' => 0.5, 'net_margin' => 0.001,
            'total_costs' => 0.5, 'partial_fill' => false, 'decision' => 'execute', 'detected_at_ms' => 1,
        ]);

        $trade = Trade::create([
            'user_id' => $user->id, 'opportunity_id' => $opp->id,
            'symbol' => 'BTC/USDT', 'buy_exchange' => 'binance', 'sell_exchange' => 'kraken',
            'base_volume' => 0.1, 'realized_pnl' => 0.5, 'status' => 'simulated',
            'idempotency_key' => 'k1-'.$user->id, 'executed_at_ms' => 1,
        ]);
        TradeFill::create([
            'trade_id' => $trade->id, 'side' => 'buy', 'exchange' => 'binance',
            'symbol' => 'BTC/USDT', 'base_volume' => 0.1, 'price' => 100, 'notional' => 10, 'fee' => 0.01,
        ]);

        StrategyEvaluation::create([
            'strategy_id' => $strategy->id, 'user_id' => $user->id,
            'window_start_ms' => 0, 'window_end_ms' => 60000,
            'snapshots' => 1, 'candidates' => 1, 'executions' => 1, 'rejects' => 0, 'ignores' => 0,
            'realized_pnl' => 1.0, 'executed_volume' => 0.1, 'avg_margin' => 0.001, 'score' => 1.0,
        ]);

        BotEvent::create(['user_id' => $user->id, 'type' => 'autopilot.judge', 'level' => 'info']);

        // Wallet con saldo "gastado" (debe restaurarse al inicial de demo).
        WalletBalance::updateOrCreate(
            ['user_id' => $user->id, 'exchange' => 'binance', 'asset' => 'USDT'],
            ['available' => 5.0],
        );

        ArbitrageSetting::create(['user_id' => $user->id, 'symbols' => ['BTC/USDT'], 'onboarded' => true]);

        return $strategy;
    }

    public function test_reset_requires_auth(): void
    {
        $this->postJson('/api/v1/arbitrage/onboarding/reset')->assertUnauthorized();
    }

    public function test_reset_wipes_transactions_and_strategies(): void
    {
        $user = User::factory()->create();
        $this->seedUserData($user);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/arbitrage/onboarding/reset')
            ->assertOk()
            ->assertJsonPath('reset', true);

        $this->assertSame(0, Opportunity::where('user_id', $user->id)->count());
        $this->assertSame(0, Trade::where('user_id', $user->id)->count());
        $this->assertSame(0, TradeFill::count());
        $this->assertSame(0, StrategyEvaluation::where('user_id', $user->id)->count());
        $this->assertSame(0, BotEvent::where('user_id', $user->id)->count());
        $this->assertSame(0, ArbitrageStrategy::where('user_id', $user->id)->count());

        // La configuración se conserva.
        $this->assertSame(1, ArbitrageSetting::where('user_id', $user->id)->count());

        // Las wallets se restauran al saldo inicial de demo (> 5 que tenía).
        $usdt = WalletBalance::where('user_id', $user->id)->where('exchange', 'binance')->where('asset', 'USDT')->first();
        $this->assertNotNull($usdt);
        $this->assertGreaterThan(5.0, (float) $usdt->available);
    }

    public function test_reset_only_affects_requesting_user(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $this->seedUserData($me);
        $this->seedUserData($other);
        Sanctum::actingAs($me);

        $this->postJson('/api/v1/arbitrage/onboarding/reset')->assertOk();

        $this->assertSame(0, Trade::where('user_id', $me->id)->count());
        // La data del otro usuario queda intacta.
        $this->assertGreaterThan(0, Trade::where('user_id', $other->id)->count());
        $this->assertGreaterThan(0, ArbitrageStrategy::where('user_id', $other->id)->count());
    }
}
