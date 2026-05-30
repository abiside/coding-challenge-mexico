<?php

declare(strict_types=1);

namespace Tests\Unit\Arbitrage;

use App\Arbitrage\Execution\WalletManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class WalletManagerTest extends TestCase
{
    public function test_apply_deltas_updates_balances_and_versions(): void
    {
        $wallets = new WalletManager(['binance' => ['USDT' => 1000.0, 'BTC' => 1.0]]);

        $wallets->applyDeltas([
            ['exchange' => 'binance', 'asset' => 'USDT', 'delta' => -100.0, 'reason' => 'buy', 'ref' => 'r1'],
            ['exchange' => 'binance', 'asset' => 'BTC', 'delta' => 0.5, 'reason' => 'buy', 'ref' => 'r1'],
        ]);

        $this->assertEqualsWithDelta(900.0, $wallets->available('binance', 'USDT'), 1e-9);
        $this->assertEqualsWithDelta(1.5, $wallets->available('binance', 'BTC'), 1e-9);
        $this->assertSame(1, $wallets->version('binance', 'USDT'));
    }

    public function test_rejects_atomically_when_any_balance_would_go_negative(): void
    {
        $wallets = new WalletManager(['binance' => ['USDT' => 50.0, 'BTC' => 1.0]]);

        try {
            $wallets->applyDeltas([
                ['exchange' => 'binance', 'asset' => 'BTC', 'delta' => 0.5, 'reason' => 'buy', 'ref' => 'r1'],
                ['exchange' => 'binance', 'asset' => 'USDT', 'delta' => -100.0, 'reason' => 'buy', 'ref' => 'r1'],
            ]);
            $this->fail('Se esperaba RuntimeException por balance insuficiente.');
        } catch (RuntimeException) {
            // El delta válido de BTC no debe haberse aplicado (atomicidad).
            $this->assertEqualsWithDelta(1.0, $wallets->available('binance', 'BTC'), 1e-9);
            $this->assertEqualsWithDelta(50.0, $wallets->available('binance', 'USDT'), 1e-9);
        }
    }

    public function test_ledger_listener_receives_entries(): void
    {
        $wallets = new WalletManager(['binance' => ['USDT' => 1000.0]]);
        $entries = [];
        $wallets->onLedgerEntry(function (array $entry) use (&$entries): void {
            $entries[] = $entry;
        });

        $wallets->applyDeltas([
            ['exchange' => 'binance', 'asset' => 'USDT', 'delta' => -100.0, 'reason' => 'buy', 'ref' => 'r1'],
        ]);

        $this->assertCount(1, $entries);
        $this->assertEqualsWithDelta(900.0, $entries[0]['balance_after'], 1e-9);
    }
}
