<?php

declare(strict_types=1);

namespace App\Domain\MarketData\DTO;

final class MarketTick
{
    public function __construct(
        public readonly string $exchange,
        public readonly string $symbol,
        public readonly string $price,
        public readonly ?string $bid,
        public readonly ?string $ask,
        public readonly ?string $volume24h,
        public readonly int $timestampMs,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => 'ticker',
            'exchange' => $this->exchange,
            'symbol' => $this->symbol,
            'price' => $this->price,
            'bid' => $this->bid,
            'ask' => $this->ask,
            'volume_24h' => $this->volume24h,
            'timestamp_ms' => $this->timestampMs,
        ];
    }
}
