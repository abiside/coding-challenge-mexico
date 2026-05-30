<?php

declare(strict_types=1);

namespace App\Domain\MarketData\DTO;

final class OrderBookLevel
{
    public function __construct(
        public readonly string $price,
        public readonly string $size,
    ) {
    }

    /**
     * @return array{0: string, 1: string}
     */
    public function toArray(): array
    {
        return [$this->price, $this->size];
    }
}
