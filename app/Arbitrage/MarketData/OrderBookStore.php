<?php

declare(strict_types=1);

namespace App\Arbitrage\MarketData;

use App\Arbitrage\Contracts\OrderBookStoreInterface;
use App\Domain\MarketData\DTO\OrderBookSnapshot;

/**
 * Implementación in-memory del store de order books.
 *
 * Indexa por símbolo y luego por exchange para resolver rápido las
 * comparaciones cross-exchange que dispara cada update.
 */
final class OrderBookStore implements OrderBookStoreInterface
{
    /**
     * @var array<string, array<string, BookState>>  symbol => exchange => BookState
     */
    private array $books = [];

    public function apply(OrderBookSnapshot $snapshot, ?int $receivedAtMs = null): BookState
    {
        $state = BookState::fromSnapshot($snapshot, $receivedAtMs);
        $this->books[$state->symbol][$state->exchange] = $state;

        return $state;
    }

    public function get(string $exchange, string $symbol): ?BookState
    {
        return $this->books[$symbol][$exchange] ?? null;
    }

    public function freshExcept(string $symbol, string $excludeExchange, int $maxAgeMs, ?int $nowMs = null): array
    {
        $result = [];
        foreach ($this->books[$symbol] ?? [] as $exchange => $state) {
            if ($exchange === $excludeExchange) {
                continue;
            }
            if (! $state->isFresh($maxAgeMs, $nowMs)) {
                continue;
            }
            $result[] = $state;
        }

        return $result;
    }

    public function allForSymbol(string $symbol): array
    {
        return array_values($this->books[$symbol] ?? []);
    }

    public function symbols(): array
    {
        return array_keys($this->books);
    }
}
