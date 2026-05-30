<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SimulationRun;
use App\Models\User;
use App\Models\WalletBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OnboardingDemoTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_demo_endpoint_requires_authentication(): void
    {
        $this->postJson('/api/v1/arbitrage/onboarding/demo')->assertUnauthorized();
    }

    public function test_demo_provisions_settings_wallets_and_active_run(): void
    {
        config()->set('marketdata.exchanges', ['binance', 'kraken']);
        $user = $this->actingUser();

        $response = $this->postJson('/api/v1/arbitrage/onboarding/demo');

        $response->assertCreated()
            ->assertJsonPath('settings.onboarded', true)
            ->assertJsonPath('settings.simulation_enabled', false)
            ->assertJsonPath('simulation.active', true);

        $symbols = $response->json('settings.symbols');
        foreach (['BTC/USDT', 'ETH/USDT', 'ETH/BTC', 'BTC/USD', 'ETH/USD'] as $symbol) {
            $this->assertContains($symbol, $symbols);
        }

        // Exchange con quote USDT: abre USDT + BTC + ETH.
        foreach (['USDT', 'BTC', 'ETH'] as $asset) {
            $this->assertDatabaseHas('wallet_balances', [
                'user_id' => $user->id, 'exchange' => 'binance', 'asset' => $asset,
            ]);
        }
        // Exchange con quote USD (kraken): abre USD + BTC + ETH, sin USDT.
        foreach (['USD', 'BTC', 'ETH'] as $asset) {
            $this->assertDatabaseHas('wallet_balances', [
                'user_id' => $user->id, 'exchange' => 'kraken', 'asset' => $asset,
            ]);
        }
        $this->assertDatabaseMissing('wallet_balances', [
            'user_id' => $user->id, 'exchange' => 'kraken', 'asset' => 'USDT',
        ]);

        $this->assertSame(6, WalletBalance::where('user_id', $user->id)->count());
        $this->assertSame(
            1,
            SimulationRun::where('user_id', $user->id)->where('status', SimulationRun::STATUS_ACTIVE)->count(),
        );
    }

    public function test_demo_is_idempotent(): void
    {
        config()->set('marketdata.exchanges', ['binance', 'kraken']);
        $user = $this->actingUser();

        $this->postJson('/api/v1/arbitrage/onboarding/demo')->assertCreated();
        $this->postJson('/api/v1/arbitrage/onboarding/demo')->assertCreated();

        $this->assertSame(6, WalletBalance::where('user_id', $user->id)->count());
        $this->assertSame(
            1,
            SimulationRun::where('user_id', $user->id)->where('status', SimulationRun::STATUS_ACTIVE)->count(),
        );
    }
}
