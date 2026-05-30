<?php

declare(strict_types=1);

namespace App\Arbitrage\Onboarding;

use App\Models\ArbitrageSetting;
use App\Models\SimulationRun;
use App\Models\WalletBalance;

/**
 * Provisión demo de un clic: deja a un usuario nuevo listo para ver el motor
 * operar de inmediato. Crea/actualiza su configuración con los símbolos del
 * trío triangular, abre wallets y saldos de TODAS las divisas (USDT, USD, BTC,
 * ETH) según el quote de cada exchange y levanta un SimulationRun activo.
 *
 * El "simulador de oportunidades" (deriva sintética de precios) queda APAGADO;
 * lo enciende el usuario desde la alerta SimulatorInvite. Es idempotente: una
 * segunda llamada no duplica wallets ni runs.
 */
class DemoProvisioner
{
    /**
     * Pares que habilitan arbitraje de 2 patas y ciclos triangulares
     * intra-exchange. Los pares con quote USDT aplican a binance/bybit/okx/bitget;
     * los pares con quote USD a kraken/coinbase (que publican en USD).
     *
     * @var list<string>
     */
    private const SYMBOLS = ['BTC/USDT', 'ETH/USDT', 'ETH/BTC', 'BTC/USD', 'ETH/USD'];

    /** Exchanges cuyo activo de cotización (y de partida triangular) es USD. */
    private const USD_QUOTE_EXCHANGES = ['kraken', 'coinbase'];

    private const QUOTE_BALANCE = 100000.0;

    private const BTC_BALANCE = 2.0;

    private const ETH_BALANCE = 30.0;

    /**
     * @return array{setting: ArbitrageSetting, run: SimulationRun}
     */
    public function provision(int $userId): array
    {
        $setting = $this->provisionSetting($userId);
        $this->provisionWallets($userId);
        $run = $this->provisionRun($userId, $setting);

        return ['setting' => $setting, 'run' => $run];
    }

    private function provisionSetting(int $userId): ArbitrageSetting
    {
        $defaults = (array) config('arbitrage');
        $thresholds = (array) ($defaults['thresholds'] ?? []);

        $setting = ArbitrageSetting::firstOrNew(['user_id' => $userId]);
        $setting->fill([
            'symbols' => self::SYMBOLS,
            'min_net_profit' => $thresholds['min_net_profit'] ?? 1,
            'min_net_margin' => $thresholds['min_net_margin'] ?? 0.0005,
            'min_base_volume' => $thresholds['min_base_volume'] ?? 0.0001,
            'max_base_volume' => $thresholds['max_base_volume'] ?? 1,
            'freshness_ms' => $defaults['freshness_ms'] ?? 2000,
            'latency_max_ms' => $defaults['latency']['max_ms'] ?? 1500,
            'circuit_breaker_enabled' => $defaults['circuit_breaker']['enabled'] ?? true,
            'onboarded' => true,
            'autopilot_enabled' => false,
            'simulation_enabled' => false,
            'simulation_max_drift_pct' => 0.5,
            'simulation_max_exec_drift_pct' => 0.4,
        ]);
        $setting->save();

        return $setting;
    }

    /**
     * Restaura las wallets del usuario a su saldo inicial de demo (idempotente).
     * Útil para el reinicio "todo a empezar".
     */
    public function resetWallets(int $userId): void
    {
        $this->provisionWallets($userId);
    }

    private function provisionWallets(int $userId): void
    {
        $exchanges = array_values((array) config('marketdata.exchanges', []));

        foreach ($exchanges as $exchange) {
            $exchange = strtolower((string) $exchange);
            if ($exchange === '') {
                continue;
            }

            $quote = in_array($exchange, self::USD_QUOTE_EXCHANGES, true) ? 'USD' : 'USDT';
            $balances = [
                $quote => self::QUOTE_BALANCE,
                'BTC' => self::BTC_BALANCE,
                'ETH' => self::ETH_BALANCE,
            ];

            foreach ($balances as $asset => $amount) {
                WalletBalance::updateOrCreate(
                    ['user_id' => $userId, 'exchange' => $exchange, 'asset' => $asset],
                    ['available' => $amount],
                );
            }
        }
    }

    private function provisionRun(int $userId, ArbitrageSetting $setting): SimulationRun
    {
        $run = SimulationRun::where('user_id', $userId)
            ->where('status', SimulationRun::STATUS_ACTIVE)
            ->latest('id')
            ->first();

        if ($run !== null) {
            return $run;
        }

        return SimulationRun::create([
            'user_id' => $userId,
            'status' => SimulationRun::STATUS_ACTIVE,
            'config_snapshot' => $setting->toEngineConfig((array) config('arbitrage')),
            'started_at' => now(),
        ]);
    }
}
