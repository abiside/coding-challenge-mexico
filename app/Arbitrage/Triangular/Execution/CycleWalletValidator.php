<?php

declare(strict_types=1);

namespace App\Arbitrage\Triangular\Execution;

use App\Arbitrage\Contracts\WalletRepositoryInterface;
use App\Arbitrage\Triangular\DTO\CycleCandidate;

/**
 * Calcula, en modo solo-lectura, la cantidad máxima del activo de partida que
 * el wallet soporta para ejecutar un ciclo. La restricción única es el
 * balance del `(start_exchange, start_asset)`: el resto del flujo se compra
 * y vende dentro del ciclo (no requiere balance previo en los activos
 * intermedios, salvo si esos balances ya están en cero y el ciclo necesita
 * gastarlos para una pata de venta — pero el flujo siempre arranca con el
 * activo inicial, así que el cuello de botella inicial es ese).
 */
final class CycleWalletValidator
{
    public function __construct(
        private readonly WalletRepositoryInterface $wallets,
    ) {
    }

    public function maxStartAmount(CycleCandidate $candidate): float
    {
        $start = $candidate->start();

        return max(0.0, $this->wallets->available($start->exchange, $start->asset));
    }
}
