<?php

declare(strict_types=1);

namespace App\Arbitrage\MeanReversion\Execution;

/**
 * Lleva el costo base (cost basis) por activo, que el `WalletManager` no
 * conoce (solo guarda balances). Necesario para calcular P&L realizado en las
 * ventas y para evaluar take-profit / stop-loss contra el precio promedio de
 * entrada.
 */
final class PositionBook
{
    /**
     * @var array<string, array{qty: float, cost: float, opened_at_ms: int}>
     *   asset => { cantidad, costo total en USDT, primer apertura }
     */
    private array $positions = [];

    /**
     * Registra una compra: aumenta cantidad y costo base (USDT gastado, fee
     * incluido).
     */
    public function applyBuy(string $asset, float $quantity, float $costUsdt, int $nowMs): void
    {
        $asset = strtoupper($asset);
        if ($quantity <= 0.0) {
            return;
        }

        $current = $this->positions[$asset] ?? ['qty' => 0.0, 'cost' => 0.0, 'opened_at_ms' => $nowMs];
        $current['qty'] += $quantity;
        $current['cost'] += $costUsdt;
        if (($this->positions[$asset]['qty'] ?? 0.0) <= 0.0) {
            $current['opened_at_ms'] = $nowMs;
        }

        $this->positions[$asset] = $current;
    }

    /**
     * Registra una venta: reduce cantidad y costo base proporcionalmente al
     * costo promedio, y devuelve el P&L realizado (proceeds netos - costo de lo
     * vendido).
     */
    public function applySell(string $asset, float $quantity, float $proceedsUsdt): float
    {
        $asset = strtoupper($asset);
        $current = $this->positions[$asset] ?? null;
        if ($current === null || $current['qty'] <= 0.0 || $quantity <= 0.0) {
            return 0.0;
        }

        $sellQty = min($quantity, $current['qty']);
        $avgCost = $current['cost'] / $current['qty'];
        $costOfSold = $avgCost * $sellQty;
        $realizedPnl = $proceedsUsdt - $costOfSold;

        $current['qty'] -= $sellQty;
        $current['cost'] -= $costOfSold;
        if ($current['qty'] <= 1e-12) {
            unset($this->positions[$asset]);
        } else {
            $this->positions[$asset] = $current;
        }

        return $realizedPnl;
    }

    /** Vacía todas las posiciones (reinicio del ejercicio). */
    public function reset(): void
    {
        $this->positions = [];
    }

    public function quantity(string $asset): float
    {
        return $this->positions[strtoupper($asset)]['qty'] ?? 0.0;
    }

    public function costBasis(string $asset): float
    {
        return $this->positions[strtoupper($asset)]['cost'] ?? 0.0;
    }

    /** Costo promedio por unidad (USDT) o 0 si no hay posición. */
    public function avgCost(string $asset): float
    {
        $pos = $this->positions[strtoupper($asset)] ?? null;
        if ($pos === null || $pos['qty'] <= 0.0) {
            return 0.0;
        }

        return $pos['cost'] / $pos['qty'];
    }

    public function openedAtMs(string $asset): ?int
    {
        return $this->positions[strtoupper($asset)]['opened_at_ms'] ?? null;
    }

    /** Suma del costo base desplegado en todas las posiciones (USDT). */
    public function totalCostBasis(): float
    {
        $total = 0.0;
        foreach ($this->positions as $pos) {
            $total += $pos['cost'];
        }

        return $total;
    }

    public function openCount(): int
    {
        return count($this->positions);
    }

    public function hasPosition(string $asset): bool
    {
        return ($this->positions[strtoupper($asset)]['qty'] ?? 0.0) > 0.0;
    }

    /**
     * @return array<int, string>  activos con posición abierta
     */
    public function heldAssets(): array
    {
        return array_keys($this->positions);
    }

    /**
     * Rehidrata posiciones desde un snapshot persistido (continuidad tras
     * reinicios del worker). Acepta la forma producida por snapshot().
     *
     * @param  array<int, array{asset?: string, quantity?: float|string, cost_basis?: float|string, opened_at_ms?: int}>  $rows
     */
    public function restore(array $rows): void
    {
        foreach ($rows as $row) {
            $asset = strtoupper((string) ($row['asset'] ?? ''));
            $qty = (float) ($row['quantity'] ?? 0.0);
            if ($asset === '' || $qty <= 0.0) {
                continue;
            }
            $this->positions[$asset] = [
                'qty' => $qty,
                'cost' => (float) ($row['cost_basis'] ?? 0.0),
                'opened_at_ms' => (int) ($row['opened_at_ms'] ?? (int) (microtime(true) * 1000)),
            ];
        }
    }

    /**
     * Detalle de las posiciones abiertas para el panel del dashboard.
     *
     * @return array<int, array{asset: string, quantity: float, cost_basis: float, avg_cost: float, opened_at_ms: int}>
     */
    public function snapshot(): array
    {
        $out = [];
        foreach ($this->positions as $asset => $pos) {
            if ($pos['qty'] <= 0.0) {
                continue;
            }
            $out[] = [
                'asset' => $asset,
                'quantity' => $pos['qty'],
                'cost_basis' => $pos['cost'],
                'avg_cost' => $pos['cost'] / $pos['qty'],
                'opened_at_ms' => $pos['opened_at_ms'],
            ];
        }

        return $out;
    }
}
