<?php

declare(strict_types=1);

namespace App\Arbitrage\Contracts;

use App\Arbitrage\MarketData\BookState;
use App\Domain\MarketData\DTO\OrderBookSnapshot;

/**
 * Almacén en memoria del último order book válido por (exchange, symbol).
 * Es la fuente de verdad del camino crítico; no depende de DB.
 */
interface OrderBookStoreInterface
{
    public function apply(OrderBookSnapshot $snapshot, ?int $receivedAtMs = null): BookState;

    public function get(string $exchange, string $symbol): ?BookState;

    /**
     * Books frescos de otros exchanges para el mismo símbolo, excluyendo el origen.
     *
     * @return array<int, BookState>
     */
    public function freshExcept(string $symbol, string $excludeExchange, int $maxAgeMs, ?int $nowMs = null): array;

    /**
     * @return array<int, BookState>
     */
    public function allForSymbol(string $symbol): array;

    /**
     * @return array<int, string>
     */
    public function symbols(): array;
}
