<?php

declare(strict_types=1);

namespace App\Arbitrage\Execution;

use App\Arbitrage\Contracts\WalletRepositoryInterface;
use RuntimeException;

/**
 * Autoridad in-memory de balances simulados.
 *
 * Expone lectura vía WalletRepositoryInterface a todo el engine, pero la
 * mutación (applyDelta / transfer) está pensada para ser invocada únicamente
 * por el ExecutionSimulator (single-writer). Cada cambio incrementa una
 * versión por (exchange, asset) y opcionalmente notifica un ledger.
 */
final class WalletManager implements WalletRepositoryInterface
{
    /**
     * @var array<string, array<string, float>>  exchange => asset => available
     */
    private array $balances = [];

    /**
     * @var array<string, int>  "exchange:asset" => version
     */
    private array $versions = [];

    /** @var (callable(array<string, mixed>): void)|null */
    private $ledgerListener = null;

    /**
     * @param  array<string, array<string, float>>  $initial  exchange => asset => amount
     */
    public function __construct(array $initial = [])
    {
        foreach ($initial as $exchange => $assets) {
            foreach ($assets as $asset => $amount) {
                $this->balances[strtolower($exchange)][strtoupper($asset)] = (float) $amount;
            }
        }
    }

    /**
     * Registra un listener para asentar movimientos en un ledger externo.
     *
     * @param  callable(array<string, mixed>): void  $listener
     */
    public function onLedgerEntry(callable $listener): void
    {
        $this->ledgerListener = $listener;
    }

    /**
     * Reinicia los balances a un estado inicial (todo o nada), descartando el
     * estado actual. Usado para "reiniciar el ejercicio" sin recrear el objeto,
     * de modo que todos los colaboradores que tienen la referencia vean el reset.
     *
     * @param  array<string, array<string, float>>  $initial  exchange => asset => amount
     */
    public function reset(array $initial = []): void
    {
        $this->balances = [];
        $this->versions = [];
        foreach ($initial as $exchange => $assets) {
            foreach ($assets as $asset => $amount) {
                $this->balances[strtolower($exchange)][strtoupper($asset)] = (float) $amount;
            }
        }
    }

    public function available(string $exchange, string $asset): float
    {
        return $this->balances[strtolower($exchange)][strtoupper($asset)] ?? 0.0;
    }

    public function snapshot(): array
    {
        return $this->balances;
    }

    public function version(string $exchange, string $asset): int
    {
        return $this->versions[$this->key($exchange, $asset)] ?? 0;
    }

    /**
     * Aplica varios deltas de forma atómica (todo o nada). Valida que ningún
     * balance quede negativo antes de mutar.
     *
     * @param  array<int, array{exchange: string, asset: string, delta: float, reason: string, ref: string}>  $deltas
     */
    public function applyDeltas(array $deltas): void
    {
        // Fase de validación: calcular saldos resultantes sin mutar.
        $projected = [];
        foreach ($deltas as $d) {
            $key = $this->key($d['exchange'], $d['asset']);
            $current = $projected[$key] ?? $this->available($d['exchange'], $d['asset']);
            $next = $current + $d['delta'];
            if ($next < -1e-9) {
                throw new RuntimeException(sprintf(
                    'Balance insuficiente en %s %s: disponible %.8f, delta %.8f',
                    $d['exchange'],
                    $d['asset'],
                    $this->available($d['exchange'], $d['asset']),
                    $d['delta'],
                ));
            }
            $projected[$key] = $next;
        }

        // Fase de commit: ya validado, mutar y notificar ledger.
        foreach ($deltas as $d) {
            $exchange = strtolower($d['exchange']);
            $asset = strtoupper($d['asset']);
            $this->balances[$exchange][$asset] = ($this->balances[$exchange][$asset] ?? 0.0) + $d['delta'];
            $this->versions[$this->key($exchange, $asset)] = $this->version($exchange, $asset) + 1;

            if ($this->ledgerListener !== null) {
                ($this->ledgerListener)([
                    'exchange' => $exchange,
                    'asset' => $asset,
                    'delta' => $d['delta'],
                    'balance_after' => $this->balances[$exchange][$asset],
                    'reason' => $d['reason'],
                    'ref' => $d['ref'],
                ]);
            }
        }
    }

    private function key(string $exchange, string $asset): string
    {
        return strtolower($exchange).':'.strtoupper($asset);
    }
}
