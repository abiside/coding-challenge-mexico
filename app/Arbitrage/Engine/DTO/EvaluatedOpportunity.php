<?php

declare(strict_types=1);

namespace App\Arbitrage\Engine\DTO;

/**
 * Oportunidad totalmente evaluada: candidato + liquidez + rentabilidad.
 * Es la entrada del RiskManager, que decidirá ejecutar/rechazar/ignorar.
 */
final class EvaluatedOpportunity
{
    public function __construct(
        public readonly OpportunityCandidate $candidate,
        public readonly LiquidityResult $liquidity,
        public readonly ProfitabilityResult $profitability,
    ) {
    }

    public function symbol(): string
    {
        return $this->candidate->symbol;
    }

    public function buyExchange(): string
    {
        return $this->candidate->buyExchange();
    }

    public function sellExchange(): string
    {
        return $this->candidate->sellExchange();
    }

    /**
     * Antigüedad combinada de ambos books (suma) en ms, para penalización
     * y guard de latencia.
     */
    public function combinedAgeMs(?int $nowMs = null): int
    {
        return $this->candidate->buyBook->ageMs($nowMs)
            + $this->candidate->sellBook->ageMs($nowMs);
    }

    /**
     * Profit bruto "teórico" usando los mejores precios (top of book), antes de
     * que la profundidad (slippage) erosione el spread. Sirve como base para
     * reconciliar el desglose de costos: neto = teórico − slippage − fees −
     * latencia − fijo.
     */
    public function theoreticalGrossProfit(): float
    {
        return ($this->candidate->sellBid - $this->candidate->buyAsk)
            * $this->liquidity->executableBaseVolume;
    }

    /**
     * Costo de slippage por profundidad: cuánto profit bruto se pierde al
     * ejecutar a precios VWAP en lugar de al mejor precio. Ya está reflejado en
     * los precios ponderados, así que aquí lo exponemos como línea explícita.
     */
    public function slippageCost(): float
    {
        return max(0.0, $this->theoreticalGrossProfit() - $this->profitability->grossProfit);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'symbol' => $this->symbol(),
            'buy_exchange' => $this->buyExchange(),
            'sell_exchange' => $this->sellExchange(),
            'buy_ask' => $this->candidate->buyAsk,
            'sell_bid' => $this->candidate->sellBid,
            'gross_spread_bps' => round($this->candidate->grossSpreadBps(), 4),
            'base_volume' => $this->liquidity->executableBaseVolume,
            'weighted_buy_price' => $this->liquidity->weightedBuyPrice,
            'weighted_sell_price' => $this->liquidity->weightedSellPrice,
            'partial_fill' => $this->liquidity->partial,
            'gross_profit' => round($this->profitability->grossProfit, 8),
            'net_profit' => round($this->profitability->netProfit, 8),
            'net_margin' => round($this->profitability->netMargin(), 8),
            'total_costs' => round($this->profitability->totalCosts(), 8),
            // Desglose de costos para validar que cada componente tiene sentido.
            'theoretical_gross_profit' => round($this->theoreticalGrossProfit(), 8),
            'buy_fee' => round($this->profitability->buyFee, 8),
            'sell_fee' => round($this->profitability->sellFee, 8),
            'slippage_cost' => round($this->slippageCost(), 8),
            'latency_penalty' => round($this->profitability->latencyPenalty, 8),
            'fixed_cost' => round($this->profitability->fixedCost, 8),
            'detected_at_ms' => $this->candidate->detectedAtMs,
        ];
    }
}
