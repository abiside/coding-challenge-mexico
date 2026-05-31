<?php

declare(strict_types=1);

namespace App\Strategies\DTO;

/**
 * Señal generada por una estrategia. Es una intención de abrir una posición
 * (long o short) con sus reglas de salida obligatorias (TP/SL/timeout). El Risk
 * Manager decide si se aprueba; el simulador la convierte en posición.
 */
final class StrategySignal
{
    /**
     * @param  array<int, string>  $reasons    por qué se disparó (explicabilidad)
     * @param  array<int, string>  $riskFlags  banderas de riesgo detectadas
     */
    public function __construct(
        public readonly string $strategyName,
        public readonly string $algorithm,
        public readonly string $exchange,
        public readonly string $symbol,
        public readonly Side $side,
        public readonly float $confidenceScore,
        public readonly float $entryPrice,
        public readonly float $takeProfit,
        public readonly float $stopLoss,
        public readonly int $maxHoldingSeconds,
        public readonly array $reasons,
        public readonly array $riskFlags,
        public readonly int $createdAtMs,
    ) {
    }

    public function baseAsset(): string
    {
        $parts = explode('/', $this->symbol);

        return $parts[0] ?? $this->symbol;
    }

    public function key(): string
    {
        return $this->algorithm.'|'.$this->symbol.'|'.$this->side->value;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'strategy_name' => $this->strategyName,
            'algorithm' => $this->algorithm,
            'exchange' => $this->exchange,
            'symbol' => $this->symbol,
            'side' => $this->side->value,
            'confidence_score' => round($this->confidenceScore, 4),
            'entry_price' => $this->entryPrice,
            'take_profit' => $this->takeProfit,
            'stop_loss' => $this->stopLoss,
            'max_holding_time' => $this->maxHoldingSeconds,
            'reasons' => $this->reasons,
            'risk_flags' => $this->riskFlags,
            'created_at_ms' => $this->createdAtMs,
        ];
    }
}
