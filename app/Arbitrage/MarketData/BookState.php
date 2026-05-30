<?php

declare(strict_types=1);

namespace App\Arbitrage\MarketData;

use App\Domain\MarketData\DTO\OrderBookLevel;
use App\Domain\MarketData\DTO\OrderBookSnapshot;

/**
 * Estado inmutable del último order book válido de un exchange/símbolo.
 *
 * Mantiene bids ordenados de mayor a menor precio y asks de menor a mayor,
 * normalizados a float, además de metadatos de frescura/latencia. Es el
 * value object que el engine evalúa en memoria, sin tocar DB.
 */
final class BookState
{
    /**
     * @param  array<int, PriceLevel>  $bids  ordenados desc por precio
     * @param  array<int, PriceLevel>  $asks  ordenados asc por precio
     */
    private function __construct(
        public readonly string $exchange,
        public readonly string $symbol,
        public readonly array $bids,
        public readonly array $asks,
        public readonly int $exchangeTimestampMs,
        public readonly int $receivedAtMs,
    ) {
    }

    public static function fromSnapshot(OrderBookSnapshot $snapshot, ?int $receivedAtMs = null): self
    {
        $bids = self::mapLevels($snapshot->bids);
        $asks = self::mapLevels($snapshot->asks);

        usort($bids, static fn (PriceLevel $a, PriceLevel $b): int => $b->price <=> $a->price);
        usort($asks, static fn (PriceLevel $a, PriceLevel $b): int => $a->price <=> $b->price);

        return new self(
            exchange: $snapshot->exchange,
            symbol: $snapshot->symbol,
            bids: $bids,
            asks: $asks,
            exchangeTimestampMs: $snapshot->timestampMs,
            receivedAtMs: $receivedAtMs ?? self::nowMs(),
        );
    }

    public function bestBid(): ?PriceLevel
    {
        return $this->bids[0] ?? null;
    }

    public function bestAsk(): ?PriceLevel
    {
        return $this->asks[0] ?? null;
    }

    public function hasLiquidity(): bool
    {
        return $this->bestBid() !== null && $this->bestAsk() !== null;
    }

    /**
     * Antigüedad del book respecto a "ahora" (o un instante dado) en ms,
     * usando el timestamp con que el engine lo recibió.
     */
    public function ageMs(?int $nowMs = null): int
    {
        return max(0, ($nowMs ?? self::nowMs()) - $this->receivedAtMs);
    }

    public function isFresh(int $maxAgeMs, ?int $nowMs = null): bool
    {
        return $this->ageMs($nowMs) <= $maxAgeMs;
    }

    /**
     * @param  array<int, OrderBookLevel>  $levels
     * @return array<int, PriceLevel>
     */
    private static function mapLevels(array $levels): array
    {
        $mapped = [];
        foreach ($levels as $level) {
            $price = (float) $level->price;
            $size = (float) $level->size;
            if ($price <= 0.0 || $size <= 0.0) {
                continue;
            }
            $mapped[] = new PriceLevel($price, $size);
        }

        return $mapped;
    }

    private static function nowMs(): int
    {
        return (int) (microtime(true) * 1000);
    }
}
