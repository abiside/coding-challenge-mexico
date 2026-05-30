<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Opportunity;
use App\Models\Trade;
use App\Models\User;
use App\Models\WalletBalance;
use App\Support\ArbitrageCacheKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ArbitrageApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_arbitrage_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/arbitrage/wallets')->assertUnauthorized();
    }

    public function test_snapshot_index_returns_cached_state(): void
    {
        config()->set('arbitrage.symbols', ['BTC/USDT']);
        $user = $this->actingUser();

        Cache::put(
            ArbitrageCacheKeys::snapshot((int) $user->id, 'BTC/USDT'),
            ['decision' => 'execute', 'net_profit' => 12.3],
            60,
        );

        $response = $this->getJson('/api/v1/arbitrage');

        $response
            ->assertOk()
            ->assertJsonPath('snapshots.BTC/USDT.decision', 'execute');
    }

    public function test_opportunities_endpoint_filters_by_decision_and_user(): void
    {
        $user = $this->actingUser();
        $other = User::factory()->create();

        Opportunity::create($this->opportunityRow('execute', (int) $user->id));
        Opportunity::create($this->opportunityRow('reject', (int) $user->id));
        Opportunity::create($this->opportunityRow('execute', (int) $other->id));

        $response = $this->getJson('/api/v1/arbitrage/opportunities?decision=execute');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('execute', $response->json('data.0.decision'));
    }

    public function test_trades_endpoint_returns_pnl_total_scoped_to_user(): void
    {
        $user = $this->actingUser();
        $other = User::factory()->create();

        Trade::create($this->tradeRow((int) $user->id, 9.68, 'op-1'));
        Trade::create($this->tradeRow((int) $other->id, 100.0, 'op-2'));

        $response = $this->getJson('/api/v1/arbitrage/trades');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('realized_pnl_total', 9.68);
    }

    public function test_wallets_endpoint_lists_only_own_balances(): void
    {
        $user = $this->actingUser();
        $other = User::factory()->create();

        WalletBalance::create(['user_id' => $user->id, 'exchange' => 'binance', 'asset' => 'USDT', 'available' => 1000, 'locked' => 0, 'version' => 1]);
        WalletBalance::create(['user_id' => $other->id, 'exchange' => 'kraken', 'asset' => 'USDT', 'available' => 5000, 'locked' => 0, 'version' => 1]);

        $response = $this->getJson('/api/v1/arbitrage/wallets');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.exchange', 'binance');
    }

    public function test_user_can_fund_a_wallet(): void
    {
        $this->actingUser();

        $this->postJson('/api/v1/arbitrage/wallets', [
            'exchange' => 'Binance',
            'asset' => 'usdt',
            'available' => 2500,
        ])->assertCreated()
            ->assertJsonPath('data.exchange', 'binance')
            ->assertJsonPath('data.asset', 'USDT');
    }

    public function test_settings_can_be_updated(): void
    {
        $this->actingUser();

        $response = $this->putJson('/api/v1/arbitrage/settings', [
            'symbols' => ['BTC/USDT'],
            'min_net_profit' => 5,
            'onboarded' => true,
        ])->assertOk()
            ->assertJsonPath('data.onboarded', true);

        $this->assertEqualsWithDelta(5.0, (float) $response->json('data.min_net_profit'), 0.0001);
    }

    public function test_simulation_requires_onboarding_and_funds(): void
    {
        $user = $this->actingUser();

        $this->postJson('/api/v1/arbitrage/simulation/start')->assertStatus(422);

        $this->putJson('/api/v1/arbitrage/settings', ['onboarded' => true])->assertOk();
        WalletBalance::create(['user_id' => $user->id, 'exchange' => 'binance', 'asset' => 'USDT', 'available' => 1000, 'locked' => 0, 'version' => 1]);

        $this->postJson('/api/v1/arbitrage/simulation/start')
            ->assertCreated()
            ->assertJsonPath('active', true);

        $this->getJson('/api/v1/arbitrage/simulation')->assertOk()->assertJsonPath('active', true);

        $this->postJson('/api/v1/arbitrage/simulation/stop')->assertOk()->assertJsonPath('active', false);
    }

    /**
     * @return array<string, mixed>
     */
    private function opportunityRow(string $decision, int $userId): array
    {
        return [
            'user_id' => $userId,
            'symbol' => 'BTC/USDT',
            'buy_exchange' => 'binance',
            'sell_exchange' => 'kraken',
            'buy_ask' => 100,
            'sell_bid' => 110,
            'gross_spread_bps' => 100,
            'base_volume' => 1,
            'weighted_buy_price' => 100,
            'weighted_sell_price' => 110,
            'gross_profit' => 10,
            'net_profit' => 9,
            'net_margin' => 0.09,
            'total_costs' => 1,
            'partial_fill' => false,
            'decision' => $decision,
            'reasons' => [],
            'detected_at_ms' => 1_700_000_000_000,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tradeRow(int $userId, float $pnl, string $key): array
    {
        return [
            'user_id' => $userId,
            'symbol' => 'BTC/USDT',
            'buy_exchange' => 'binance',
            'sell_exchange' => 'kraken',
            'base_volume' => 1.0,
            'realized_pnl' => $pnl,
            'status' => 'simulated',
            'idempotency_key' => $key,
            'executed_at_ms' => 1_700_000_000_000,
        ];
    }
}
