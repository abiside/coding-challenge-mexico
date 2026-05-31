<?php

declare(strict_types=1);

namespace App\Arbitrage\MeanReversion\Engine;

use App\Arbitrage\Execution\WalletManager;
use App\Arbitrage\MeanReversion\Contracts\SignalRecorderInterface;
use App\Arbitrage\MeanReversion\DTO\EvaluatedSignal;
use App\Arbitrage\MeanReversion\DTO\MeanReversionCandidate;
use App\Arbitrage\MeanReversion\DTO\ProcessedSignal;
use App\Arbitrage\MeanReversion\DTO\Side;
use App\Arbitrage\MeanReversion\Execution\MeanReversionExecutionSimulator;
use App\Arbitrage\MeanReversion\Execution\PositionBook;
use App\Arbitrage\MeanReversion\Stats\PriceWindowStore;
use App\Arbitrage\Realtime\MetricsAggregator;
use App\Arbitrage\Risk\Decision;
use App\Arbitrage\Risk\RiskDecision;
use App\Domain\MarketData\DTO\OrderBookSnapshot;
use Psr\Log\LoggerInterface;

/**
 * Orquesta el pipeline de la estrategia disparado por cada update de order
 * book: mid -> ventana 1h -> señal (z-score / stop / take-profit) -> sizing y
 * riesgo de cartera -> ejecución simulada -> registro/métricas.
 *
 * Es el único consumidor de los books del worker meanrev y comparte el
 * `WalletManager` y el `PositionBook` con el simulador (single-writer).
 */
final class MeanReversionEngine
{
    private const MIN_NOTIONAL_USDT = 5.0;

    /** @var array<string, int>  symbol => epoch ms del último trade */
    private array $lastTradeMs = [];

    /** @var array<string, float>  symbol => último mid observado (para marcar a mercado) */
    private array $lastMid = [];

    public function __construct(
        private readonly PriceWindowStore $windows,
        private readonly SignalEvaluator $evaluator,
        private readonly PositionBook $positions,
        private readonly WalletManager $wallets,
        private readonly MeanReversionExecutionSimulator $simulator,
        private readonly SignalRecorderInterface $recorder,
        private readonly MetricsAggregator $metrics,
        private readonly string $exchange,
        private readonly string $quoteAsset,
        private readonly float $sliceUsdt,
        private readonly float $maxPositionUsdt,
        private readonly float $maxTotalUsdt,
        private readonly int $maxOpenPositions,
        private readonly int $perSymbolCooldownMs,
        private readonly float $minRoundtripMargin,
        private readonly float $feeRate,
        private readonly ?LoggerInterface $logger = null,
        private readonly bool $diagnostics = false,
    ) {
    }

    public function onOrderBook(OrderBookSnapshot $snapshot): ?ProcessedSignal
    {
        $mid = $this->midPrice($snapshot);
        if ($mid <= 0.0) {
            return null;
        }

        $symbol = $snapshot->symbol;
        $this->lastMid[$symbol] = $mid;
        $nowMs = (int) (microtime(true) * 1000);

        $this->metrics->recordSnapshot();
        $window = $this->windows->record($symbol, $nowMs, $mid);

        $baseAsset = $this->baseAsset($symbol);
        $positionQty = $this->positions->quantity($baseAsset);
        $avgCost = $this->positions->avgCost($baseAsset);

        $candidate = $this->evaluator->evaluate(
            exchange: $this->exchange,
            symbol: $symbol,
            price: $mid,
            window: $window,
            positionQty: $positionQty,
            avgCost: $avgCost,
            nowMs: $nowMs,
        );

        if ($candidate === null) {
            return null;
        }

        $this->metrics->recordCandidate();

        [$decision, $signal] = $this->assess($candidate, $nowMs);

        $simulation = null;
        if ($decision->shouldExecute() && $signal !== null) {
            try {
                $simulation = $this->simulator->simulate($signal, $this->idempotencyKey($candidate));
                $this->lastTradeMs[$symbol] = $nowMs;
                if (! $simulation->duplicate) {
                    $margin = $simulation->quoteAmount > 0.0
                        ? $simulation->realizedPnl / $simulation->quoteAmount
                        : 0.0;
                    $this->metrics->recordExecution($simulation->realizedPnl, $simulation->baseQuantity, $margin);
                }
            } catch (\RuntimeException $e) {
                $decision = RiskDecision::reject('execution_failed: '.$e->getMessage());
            }
        }

        $this->metrics->recordDecision($decision->decision);

        $processed = new ProcessedSignal($candidate, $decision, $simulation);
        $this->recorder->record($processed);
        $this->diag($candidate, $decision, $simulation);

        return $processed;
    }

    /**
     * Sizing + riesgo de cartera. Devuelve la decisión y, si procede, la señal
     * dimensionada lista para ejecutar.
     *
     * @return array{0: RiskDecision, 1: ?EvaluatedSignal}
     */
    private function assess(MeanReversionCandidate $candidate, int $nowMs): array
    {
        $baseAsset = $candidate->baseAsset();
        $price = $candidate->price;

        // Cooldown anti-churn (el stop-loss lo ignora: cortar pérdidas es prioritario).
        $isStop = $candidate->reason === 'stop_loss';
        if (! $isStop && $this->perSymbolCooldownMs > 0) {
            $last = $this->lastTradeMs[$candidate->symbol] ?? 0;
            if ($nowMs - $last < $this->perSymbolCooldownMs) {
                return [RiskDecision::ignore('cooldown'), null];
            }
        }

        if ($candidate->side === Side::Sell) {
            return $this->assessSell($candidate, $baseAsset, $price, $isStop);
        }

        return $this->assessBuy($candidate, $baseAsset, $price);
    }

    /**
     * @return array{0: RiskDecision, 1: ?EvaluatedSignal}
     */
    private function assessSell(MeanReversionCandidate $candidate, string $baseAsset, float $price, bool $isStop): array
    {
        $available = $this->wallets->available($this->exchange, $baseAsset);
        $sellQty = min($this->positions->quantity($baseAsset), $available);
        if ($sellQty <= 0.0 || ($sellQty * $price) < self::MIN_NOTIONAL_USDT) {
            return [RiskDecision::ignore('no_inventory'), null];
        }

        // Salidas por señal/objetivo deben cubrir el margen mínimo del round-trip.
        // El stop-loss se exime: su objetivo es cortar la pérdida, no ganar.
        if (! $isStop) {
            $avgCost = $this->positions->avgCost($baseAsset);
            $proceedsNet = $sellQty * $price * (1.0 - $this->feeRate);
            $costOfSold = $avgCost * $sellQty;
            $margin = $costOfSold > 0.0 ? ($proceedsNet - $costOfSold) / $costOfSold : 0.0;
            if ($margin < $this->minRoundtripMargin) {
                return [RiskDecision::reject(sprintf('below_min_margin: margin=%.6f min=%.6f', $margin, $this->minRoundtripMargin)), null];
            }
        }

        $signal = new EvaluatedSignal($candidate, $sellQty * $price, $sellQty);

        return [RiskDecision::execute($sellQty), $signal];
    }

    /**
     * @return array{0: RiskDecision, 1: ?EvaluatedSignal}
     */
    private function assessBuy(MeanReversionCandidate $candidate, string $baseAsset, float $price): array
    {
        $hasPosition = $this->positions->hasPosition($baseAsset);
        if (! $hasPosition && $this->positions->openCount() >= $this->maxOpenPositions) {
            return [RiskDecision::reject('max_open_positions'), null];
        }

        $positionRoom = $this->maxPositionUsdt - $this->positions->costBasis($baseAsset);
        $totalRoom = $this->maxTotalUsdt - $this->positions->totalCostBasis();
        $available = $this->wallets->available($this->exchange, $this->quoteAsset);

        $quoteAmount = min($this->sliceUsdt, $available, $positionRoom, $totalRoom);

        if ($quoteAmount < self::MIN_NOTIONAL_USDT) {
            $reason = $positionRoom <= self::MIN_NOTIONAL_USDT
                ? 'max_position'
                : ($totalRoom <= self::MIN_NOTIONAL_USDT ? 'max_total' : 'insufficient_funds');

            return [RiskDecision::reject($reason), null];
        }

        $signal = new EvaluatedSignal($candidate, $quoteAmount, $quoteAmount / $price);

        return [RiskDecision::execute($quoteAmount / $price), $signal];
    }

    /**
     * Reinicia el "ejercicio": billetera a su saldo inicial, posiciones,
     * métricas, idempotencia del simulador y cooldowns. CONSERVA a propósito las
     * ventanas de precio (PriceWindowStore) y el último precio observado, que son
     * el histórico usado para evaluar monedas y no deben perderse ni re-calentar.
     *
     * @param  array<string, array<string, float>>  $walletInit  exchange => asset => amount
     */
    public function reset(array $walletInit): void
    {
        $this->wallets->reset($walletInit);
        $this->positions->reset();
        $this->simulator->reset();
        $this->metrics->drain();
        $this->lastTradeMs = [];
    }

    /**
     * Valuación de la cartera marcada a mercado: cada posición se valora al
     * último mid observado de su símbolo (no a su costo de entrada), para que el
     * dashboard refleje el valor real del capital desplegado y el P&L NO
     * realizado en tiempo real. Si aún no se observó precio para un símbolo
     * (p. ej. recién rehidratado), esa posición cae al costo base para no romper
     * el equity total.
     *
     * @return array{
     *   positions: array<int, array{asset: string, quantity: float, cost_basis: float, avg_cost: float, opened_at_ms: int, last_price: float|null, market_value: float|null, unrealized_pnl: float|null, unrealized_pct: float|null}>,
     *   deployed_value: float,
     *   deployed_cost: float,
     *   unrealized_pnl: float
     * }
     */
    public function valuation(): array
    {
        $positions = [];
        $marketValue = 0.0;
        $costBasis = 0.0;

        foreach ($this->positions->snapshot() as $pos) {
            $symbol = $pos['asset'].'/'.$this->quoteAsset;
            $price = $this->lastMid[$symbol] ?? null;
            $value = $price !== null ? $pos['quantity'] * $price : null;
            $unrealized = $value !== null ? $value - $pos['cost_basis'] : null;

            $positions[] = $pos + [
                'last_price' => $price,
                'market_value' => $value !== null ? round($value, 4) : null,
                'unrealized_pnl' => $unrealized !== null ? round($unrealized, 4) : null,
                'unrealized_pct' => ($unrealized !== null && $pos['cost_basis'] > 0.0)
                    ? round($unrealized / $pos['cost_basis'] * 100.0, 4)
                    : null,
            ];

            // Sin precio aún: usa el costo base como mejor estimación del valor.
            $marketValue += $value ?? $pos['cost_basis'];
            $costBasis += $pos['cost_basis'];
        }

        return [
            'positions' => $positions,
            'deployed_value' => round($marketValue, 4),
            'deployed_cost' => round($costBasis, 4),
            'unrealized_pnl' => round($marketValue - $costBasis, 4),
        ];
    }

    private function midPrice(OrderBookSnapshot $snapshot): float
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

    private function baseAsset(string $symbol): string
    {
        $parts = explode('/', $symbol);

        return $parts[0] ?? $symbol;
    }

    private function idempotencyKey(MeanReversionCandidate $candidate): string
    {
        return $candidate->key().'|'.$candidate->detectedAtMs;
    }

    private function diag(MeanReversionCandidate $candidate, RiskDecision $decision, $simulation): void
    {
        if (! $this->diagnostics || $this->logger === null) {
            return;
        }

        $this->logger->debug('[meanrev][engine] decision_'.$decision->decision->value, [
            'symbol' => $candidate->symbol,
            'side' => $candidate->side->value,
            'reason' => $candidate->reason,
            'z_score' => round($candidate->zScore, 4),
            'volatility_pct' => round($candidate->volatilityPct, 4),
            'reasons' => $decision->reasons,
            'executed' => $simulation !== null && ! $simulation->duplicate,
        ]);
    }
}
