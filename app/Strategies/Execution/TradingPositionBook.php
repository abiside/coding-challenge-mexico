<?php

declare(strict_types=1);

namespace App\Strategies\Execution;

use App\Strategies\DTO\Side;

/**
 * Libro de posiciones simuladas abiertas (long y short). Cada posición guarda
 * su notional, margen, precio de entrada, reglas de salida y costos. La
 * valuación marca a mercado contra el último precio observado del símbolo.
 *
 * Para long: P&L = (precio - entry)/entry * notional.
 * Para short: P&L = (entry - precio)/entry * notional.
 */
final class TradingPositionBook
{
    /**
     * @var array<string, array{
     *   key: string, symbol: string, side: string, entry_price: float, size: float,
     *   notional: float, margin: float, leverage: float, take_profit: float,
     *   stop_loss: float, fee_open: float, opened_at_ms: int, max_holding_seconds: int,
     *   signal_id: int|null, open_reason: string
     * }>
     */
    private array $positions = [];

    public function open(array $position): void
    {
        $this->positions[$position['key']] = $position;
    }

    public function close(string $key): ?array
    {
        $pos = $this->positions[$key] ?? null;
        if ($pos !== null) {
            unset($this->positions[$key]);
        }

        return $pos;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return array_values($this->positions);
    }

    public function count(): int
    {
        return count($this->positions);
    }

    public function hasSymbol(string $symbol): bool
    {
        foreach ($this->positions as $pos) {
            if ($pos['symbol'] === $symbol) {
                return true;
            }
        }

        return false;
    }

    /** Suma de notionals desplegados (exposición). */
    public function totalNotional(): float
    {
        $total = 0.0;
        foreach ($this->positions as $pos) {
            $total += $pos['notional'];
        }

        return $total;
    }

    /** Suma de márgenes comprometidos (capital bloqueado en USDT). */
    public function totalMargin(): float
    {
        $total = 0.0;
        foreach ($this->positions as $pos) {
            $total += $pos['margin'];
        }

        return $total;
    }

    /** P&L no realizado de una posición marcada al precio dado. */
    public function unrealized(array $pos, float $price): float
    {
        if ($pos['entry_price'] <= 0.0) {
            return 0.0;
        }
        $move = $pos['side'] === Side::Long->value
            ? ($price - $pos['entry_price'])
            : ($pos['entry_price'] - $price);

        return ($move / $pos['entry_price']) * $pos['notional'];
    }

    public function reset(): void
    {
        $this->positions = [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function snapshot(): array
    {
        return array_values($this->positions);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function restore(array $rows): void
    {
        foreach ($rows as $row) {
            $key = (string) ($row['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $this->positions[$key] = $row;
        }
    }
}
