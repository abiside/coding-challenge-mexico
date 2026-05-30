<?php

declare(strict_types=1);

namespace App\Arbitrage\Contracts;

/**
 * Interfaz de SOLO LECTURA de balances simulados.
 *
 * El resto del engine (RiskManager, dashboard) depende de esta interfaz para
 * consultar saldos disponibles. La mutación de balances está reservada al
 * ExecutionSimulator (patrón single-writer) y no se expone aquí.
 */
interface WalletRepositoryInterface
{
    public function available(string $exchange, string $asset): float;

    /**
     * Snapshot de todos los balances disponibles.
     *
     * @return array<string, array<string, float>>  exchange => asset => available
     */
    public function snapshot(): array;
}
