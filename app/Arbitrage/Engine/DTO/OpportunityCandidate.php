<?php

declare(strict_types=1);

namespace App\Arbitrage\Engine\DTO;

use App\Arbitrage\MarketData\BookState;

/**
 * Candidato detectado por el scanner: comprar en un exchange (ask bajo) y
 * vender en otro (bid alto). Solo expresa la oportunidad cruda; no decide
 * volumen final ni rentabilidad neta.
 */
final class OpportunityCandidate
{
    public function __construct(
        public readonly string $symbol,
        public readonly BookState $buyBook,
        public readonly BookState $sellBook,
        public readonly float $buyAsk,
        public readonly float $sellBid,
        public readonly int $detectedAtMs,
    ) {
    }

    public function buyExchange(): string
    {
        return $this->buyBook->exchange;
    }

    public function sellExchange(): string
    {
        return $this->sellBook->exchange;
    }

    /**
     * Spread bruto en puntos básicos sobre el precio de compra.
     */
    public function grossSpreadBps(): float
    {
        if ($this->buyAsk <= 0.0) {
            return 0.0;
        }

        return (($this->sellBid - $this->buyAsk) / $this->buyAsk) * 10000.0;
    }
}
