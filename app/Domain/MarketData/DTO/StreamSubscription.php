<?php

declare(strict_types=1);

namespace App\Domain\MarketData\DTO;

use App\Domain\MarketData\Enums\StreamType;

final class StreamSubscription
{
    /**
     * @param  string  $symbol  Símbolo normalizado tipo "BTC/USDT".
     */
    public function __construct(
        public readonly StreamType $type,
        public readonly string $symbol,
    ) {
    }

    public function key(): string
    {
        return $this->type->value.':'.strtoupper($this->symbol);
    }
}
