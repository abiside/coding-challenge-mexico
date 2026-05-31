<?php

declare(strict_types=1);

namespace App\Strategies\Execution;

/**
 * Billetera simulada del módulo de estrategias: una sola caja en USDT. A
 * diferencia del arbitraje (balances multi-activo), aquí las posiciones long y
 * short se modelan en USDT (notional + margen), así que basta llevar el saldo
 * libre de USDT; el capital comprometido vive en el PositionBook.
 */
final class StrategyWallet
{
    private float $freeUsdt;

    private readonly float $initialUsdt;

    public function __construct(float $initialUsdt)
    {
        $this->initialUsdt = $initialUsdt;
        $this->freeUsdt = $initialUsdt;
    }

    public function available(): float
    {
        return $this->freeUsdt;
    }

    public function debit(float $amount): void
    {
        $this->freeUsdt -= $amount;
    }

    public function credit(float $amount): void
    {
        $this->freeUsdt += $amount;
    }

    public function reset(float $initialUsdt): void
    {
        $this->freeUsdt = $initialUsdt;
    }

    public function initial(): float
    {
        return $this->initialUsdt;
    }

    /**
     * @return array<string, float>
     */
    public function snapshot(): array
    {
        return ['USDT' => round($this->freeUsdt, 8)];
    }

    /**
     * @param  array<string, float>  $snapshot
     */
    public function restore(array $snapshot): void
    {
        if (isset($snapshot['USDT'])) {
            $this->freeUsdt = (float) $snapshot['USDT'];
        }
    }
}
