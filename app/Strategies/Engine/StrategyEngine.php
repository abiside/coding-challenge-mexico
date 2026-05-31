<?php

declare(strict_types=1);

namespace App\Strategies\Engine;

use App\Arbitrage\MeanReversion\Stats\PriceWindowStore;
use App\Domain\MarketData\DTO\OrderBookSnapshot;
use App\Strategies\DTO\MarketContext;
use App\Strategies\DTO\Side;
use App\Strategies\DTO\StrategySignal;
use App\Strategies\Execution\StrategyWallet;
use App\Strategies\Execution\TradingExecutionSimulator;
use App\Strategies\Execution\TradingPositionBook;
use App\Strategies\Features\FeatureEngine;
use App\Strategies\Features\VolumeTracker;
use App\Strategies\Realtime\StrategyRecorder;
use App\Strategies\Risk\CircuitBreaker;
use App\Strategies\Risk\PortfolioState;
use App\Strategies\Risk\RiskManager;
use Psr\Log\LoggerInterface;

/**
 * Orquestador de una instancia de estrategia. Por cada update de order book:
 * actualiza la serie, monitorea salidas (TP/SL/timeout/liquidación) de las
 * posiciones abiertas del símbolo, y (con throttle) construye features, evalúa
 * la estrategia, dimensiona, pasa por el Risk Manager + circuit breaker y abre
 * la posición simulada. Marca a mercado el equity en tiempo real.
 */
final class StrategyEngine
{
    /** @var array<string, float>  symbol => último mid observado */
    private array $lastMid = [];

    /** @var array<string, int>  symbol => última evaluación (throttle) */
    private array $lastEvalMs = [];

    /** @var array<string, int>  symbol => epoch ms del último trade */
    private array $lastTradeMs = [];

    public function __construct(
        private readonly FeatureEngine $features,
        private readonly TradingStrategy $strategy,
        private readonly RiskManager $risk,
        private readonly CircuitBreaker $breaker,
        private readonly TradingExecutionSimulator $simulator,
        private readonly StrategyWallet $wallet,
        private readonly TradingPositionBook $positions,
        private readonly StrategyMetrics $metrics,
        private readonly StrategyRecorder $recorder,
        private readonly PriceWindowStore $windows,
        private readonly VolumeTracker $volume,
        private readonly float $sliceUsdt,
        private readonly float $maxPositionUsdt,
        private readonly float $maxTotalUsdt,
        private readonly int $maxOpenPositions,
        private readonly int $perSymbolCooldownMs,
        private readonly int $evaluationIntervalMs,
        private readonly float $leverage,
        private readonly float $liquidationBufferPct,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function onOrderBook(OrderBookSnapshot $snapshot): void
    {
        $nowMs = (int) (microtime(true) * 1000);
        $symbol = $snapshot->symbol;

        $this->metrics->recordSnapshot();
        $window = $this->windows->record($symbol, $nowMs, $this->approxMid($snapshot));
        $mid = $window->latest();
        if ($mid <= 0.0) {
            return;
        }
        $this->lastMid[$symbol] = $mid;

        // 1) Monitoreo de salidas para las posiciones de ESTE símbolo.
        $this->monitorSymbol($symbol, $mid, $nowMs);

        // 2) Evaluación de entradas (con throttle por símbolo).
        $last = $this->lastEvalMs[$symbol] ?? 0;
        if ($nowMs - $last < $this->evaluationIntervalMs) {
            return;
        }
        $this->lastEvalMs[$symbol] = $nowMs;

        $context = $this->features->build($snapshot, $window, $this->volume, $nowMs);
        if ($context === null) {
            return;
        }

        $signal = $this->strategy->evaluate($context);
        if ($signal === null) {
            return;
        }

        $this->metrics->recordDetected();
        $this->handleSignal($signal, $context, $nowMs);
    }

    private function handleSignal(StrategySignal $signal, MarketContext $context, int $nowMs): void
    {
        $symbol = $signal->symbol;

        // Una sola posición por símbolo por instancia.
        if ($this->positions->hasSymbol($symbol)) {
            return;
        }

        // Cooldown anti-churn por símbolo.
        if ($this->perSymbolCooldownMs > 0) {
            $lastTrade = $this->lastTradeMs[$symbol] ?? 0;
            if ($nowMs - $lastTrade < $this->perSymbolCooldownMs) {
                return;
            }
        }

        // Circuit breaker: corta NUEVAS entradas (las salidas nunca se pausan).
        $portfolio = $this->portfolioState($symbol);
        $this->breaker->evaluate($portfolio, $context->bookAgeMs > 10000, $nowMs);
        if ($this->breaker->isTripped()) {
            $this->metrics->recordRejected();
            $this->recorder->feed($this->signalPayload($signal, 'rejected', 'circuit_breaker: '.$this->breaker->reason()), $symbol);

            return;
        }

        $assessment = $this->risk->assess($signal, $context, $portfolio);
        if (! $assessment->approved) {
            $this->metrics->recordRejected();
            $this->recorder->feed($this->signalPayload($signal, 'rejected', $assessment->reason), $symbol);

            return;
        }

        $this->metrics->recordApproved();

        $notional = $this->sizeNotional();
        if ($notional <= 0.0) {
            $this->recorder->feed($this->signalPayload($signal, 'rejected', 'insufficient_capital'), $symbol);

            return;
        }

        $key = $signal->key().'|'.$signal->createdAtMs;
        $position = $this->simulator->open($signal, $notional, $this->leverage, $key, null);
        if ($position === null) {
            $this->recorder->feed($this->signalPayload($signal, 'rejected', 'open_failed'), $symbol);

            return;
        }

        $this->lastTradeMs[$symbol] = $nowMs;
        $this->metrics->recordOpen();
        $this->recorder->persistOpen($position, $signal);
        $this->recorder->feed($this->openPayload($signal, $position), $symbol);
    }

    /**
     * Revisa las posiciones abiertas del símbolo y cierra por take-profit,
     * stop-loss, timeout o liquidación simulada.
     */
    private function monitorSymbol(string $symbol, float $price, int $nowMs): void
    {
        foreach ($this->positions->all() as $pos) {
            if ($pos['symbol'] !== $symbol) {
                continue;
            }

            $isLong = $pos['side'] === Side::Long->value;
            $reason = null;
            $status = null;

            if ($isLong) {
                if ($pos['take_profit'] > 0.0 && $price >= $pos['take_profit']) {
                    $reason = 'take_profit';
                    $status = 'take_profit_hit';
                } elseif ($pos['stop_loss'] > 0.0 && $price <= $pos['stop_loss']) {
                    $reason = 'stop_loss';
                    $status = 'stopped_out';
                }
            } else {
                if ($pos['take_profit'] > 0.0 && $price <= $pos['take_profit']) {
                    $reason = 'take_profit';
                    $status = 'take_profit_hit';
                } elseif ($pos['stop_loss'] > 0.0 && $price >= $pos['stop_loss']) {
                    $reason = 'stop_loss';
                    $status = 'stopped_out';
                }
            }

            // Liquidación simulada (apalancado): pérdida no realizada consume el margen.
            if ($reason === null && $pos['leverage'] > 1.0) {
                $unrealized = $this->positions->unrealized($pos, $price);
                if ($unrealized < 0.0 && abs($unrealized) >= $pos['margin'] * ($this->liquidationBufferPct / 100.0)) {
                    $reason = 'liquidation';
                    $status = 'liquidated_simulated';
                }
            }

            // Timeout.
            if ($reason === null && $pos['max_holding_seconds'] > 0) {
                if (($nowMs - $pos['opened_at_ms']) >= $pos['max_holding_seconds'] * 1000) {
                    $reason = 'timeout';
                    $status = 'expired';
                }
            }

            if ($reason === null) {
                continue;
            }

            $close = $this->simulator->close($pos['key'], $price, $reason, $status);
            if ($close === null) {
                continue;
            }

            $this->metrics->recordClose((float) $close['net_pnl']);
            $this->recorder->persistClose($close);
            $this->recorder->feed($this->closePayload($close), $symbol);
        }
    }

    /** Cierra posiciones vencidas usando el último precio observado (barrido periódico). */
    public function sweepTimeouts(int $nowMs): void
    {
        foreach ($this->positions->all() as $pos) {
            if ($pos['max_holding_seconds'] <= 0) {
                continue;
            }
            if (($nowMs - $pos['opened_at_ms']) < $pos['max_holding_seconds'] * 1000) {
                continue;
            }
            $price = $this->lastMid[$pos['symbol']] ?? $pos['entry_price'];
            $close = $this->simulator->close($pos['key'], $price, 'timeout', 'expired');
            if ($close === null) {
                continue;
            }
            $this->metrics->recordClose((float) $close['net_pnl']);
            $this->recorder->persistClose($close);
            $this->recorder->feed($this->closePayload($close), $pos['symbol']);
        }
    }

    private function sizeNotional(): float
    {
        $room = $this->maxTotalUsdt - $this->positions->totalNotional();
        $notional = min($this->sliceUsdt, $this->maxPositionUsdt, $room);
        // El margen requerido (notional/leverage) + fee debe caber en el saldo libre.
        $maxByCapital = ($this->wallet->available() * 0.99) * max(1.0, $this->leverage);
        $notional = min($notional, $maxByCapital);

        return $notional >= 10.0 ? $notional : 0.0;
    }

    private function portfolioState(string $symbol): PortfolioState
    {
        return new PortfolioState(
            openPositions: $this->positions->count(),
            lossStreak: $this->metrics->lossStreak(),
            dailyPnl: $this->metrics->dailyPnl(),
            freeUsdt: $this->wallet->available(),
            deployedUsdt: $this->positions->totalMargin(),
            hasPositionForSymbol: $this->positions->hasSymbol($symbol),
        );
    }

    /**
     * Valuación marcada a mercado: equity = caja libre + margen + P&L no
     * realizado de cada posición abierta.
     *
     * @return array{positions: array<int, array<string, mixed>>, deployed_value: float, deployed_cost: float, unrealized_pnl: float, equity_value: float}
     */
    public function valuation(): array
    {
        $positions = [];
        $deployedValue = 0.0;
        $deployedCost = 0.0;
        $unrealizedTotal = 0.0;

        foreach ($this->positions->all() as $pos) {
            $price = $this->lastMid[$pos['symbol']] ?? $pos['entry_price'];
            $unrealized = $this->positions->unrealized($pos, $price);
            $value = $pos['margin'] + $unrealized;

            $positions[] = [
                'symbol' => $pos['symbol'],
                'side' => $pos['side'],
                'entry_price' => $pos['entry_price'],
                'last_price' => round($price, 8),
                'size' => round($pos['size'], 8),
                'notional' => round($pos['notional'], 4),
                'margin' => round($pos['margin'], 4),
                'leverage' => $pos['leverage'],
                'take_profit' => $pos['take_profit'],
                'stop_loss' => $pos['stop_loss'],
                'unrealized_pnl' => round($unrealized, 4),
                'unrealized_pct' => $pos['notional'] > 0.0 ? round($unrealized / $pos['notional'] * 100.0, 4) : 0.0,
                'opened_at_ms' => $pos['opened_at_ms'],
            ];

            $deployedValue += $value;
            $deployedCost += $pos['margin'];
            $unrealizedTotal += $unrealized;
        }

        return [
            'positions' => $positions,
            'deployed_value' => round($deployedValue, 4),
            'deployed_cost' => round($deployedCost, 4),
            'unrealized_pnl' => round($unrealizedTotal, 4),
            'equity_value' => round($this->wallet->available() + $deployedValue, 4),
        ];
    }

    public function circuitBreakerReason(): ?string
    {
        return $this->breaker->isTripped() ? $this->breaker->reason() : null;
    }

    /**
     * Reinicia el ejercicio (billetera, posiciones, métricas, simulador,
     * cooldowns) conservando las ventanas de precio y el último mid observado.
     */
    public function reset(float $initialUsdt): void
    {
        $this->wallet->reset($initialUsdt);
        $this->positions->reset();
        $this->simulator->reset();
        $this->metrics->reset();
        $this->breaker->reset();
        $this->lastTradeMs = [];
    }

    private function approxMid(OrderBookSnapshot $snapshot): float
    {
        $bestBid = 0.0;
        foreach ($snapshot->bids as $level) {
            $price = (float) $level->price;
            if ($price > $bestBid) {
                $bestBid = $price;
            }
        }
        $bestAsk = 0.0;
        foreach ($snapshot->asks as $level) {
            $price = (float) $level->price;
            if ($price > 0.0 && ($bestAsk === 0.0 || $price < $bestAsk)) {
                $bestAsk = $price;
            }
        }
        if ($bestBid <= 0.0 || $bestAsk <= 0.0) {
            return 0.0;
        }

        return ($bestBid + $bestAsk) / 2.0;
    }

    /**
     * @return array<string, mixed>
     */
    private function signalPayload(StrategySignal $signal, string $status, ?string $reason): array
    {
        return [
            'kind' => 'signal',
            'status' => $status,
            'reject_reason' => $reason,
            'signal' => $signal->toArray(),
        ];
    }

    /**
     * @param  array<string, mixed>  $position
     * @return array<string, mixed>
     */
    private function openPayload(StrategySignal $signal, array $position): array
    {
        return [
            'kind' => 'open',
            'status' => 'executed',
            'signal' => $signal->toArray(),
            'position' => [
                'symbol' => $position['symbol'],
                'side' => $position['side'],
                'entry_price' => $position['entry_price'],
                'size' => round($position['size'], 8),
                'notional' => round($position['notional'], 4),
                'leverage' => $position['leverage'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $close
     * @return array<string, mixed>
     */
    private function closePayload(array $close): array
    {
        return [
            'kind' => 'close',
            'status' => $close['status'],
            'close_reason' => $close['close_reason'],
            'position' => [
                'symbol' => $close['symbol'],
                'side' => $close['side'],
                'entry_price' => $close['entry_price'],
                'exit_price' => $close['exit_price'],
                'net_pnl' => $close['net_pnl'],
                'gross_pnl' => $close['gross_pnl'],
                'fees' => $close['fees'],
            ],
        ];
    }
}
