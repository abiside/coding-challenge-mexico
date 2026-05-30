<?php

declare(strict_types=1);

namespace App\Domain\MarketData\DTO;

final class OrderBookSnapshot
{
    /**
     * @param  array<int, OrderBookLevel>  $bids
     * @param  array<int, OrderBookLevel>  $asks
     */
    public function __construct(
        public readonly string $exchange,
        public readonly string $symbol,
        public readonly array $bids,
        public readonly array $asks,
        public readonly int $timestampMs,
        public readonly bool $isSnapshot = true,
        public readonly ?int $sequence = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => 'orderbook',
            'exchange' => $this->exchange,
            'symbol' => $this->symbol,
            'bids' => array_map(fn (OrderBookLevel $level): array => $level->toArray(), $this->bids),
            'asks' => array_map(fn (OrderBookLevel $level): array => $level->toArray(), $this->asks),
            'timestamp_ms' => $this->timestampMs,
            'is_snapshot' => $this->isSnapshot,
            'sequence' => $this->sequence,
        ];
    }
}
