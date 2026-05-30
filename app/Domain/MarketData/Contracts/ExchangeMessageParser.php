<?php

declare(strict_types=1);

namespace App\Domain\MarketData\Contracts;

interface ExchangeMessageParser
{
    /**
     * Recibe un mensaje crudo del WebSocket y devuelve los DTOs normalizados.
     * Puede emitir cero, uno o varios DTOs (snapshot + actualizaciones).
     *
     * @return iterable<int, \App\Domain\MarketData\DTO\MarketTick|\App\Domain\MarketData\DTO\OrderBookSnapshot>
     */
    public function parse(string $rawMessage): iterable;
}
