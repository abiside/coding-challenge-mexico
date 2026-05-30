<?php

declare(strict_types=1);

namespace App\Arbitrage\MeanReversion\Execution;

use App\Arbitrage\Execution\WalletManager;
use App\Arbitrage\MeanReversion\DTO\EvaluatedSignal;
use App\Arbitrage\MeanReversion\DTO\MeanReversionSimulationResult;
use App\Arbitrage\MeanReversion\DTO\Side;

/**
 * Único escritor de balances para la estrategia de reversión a la media.
 *
 * Arma los deltas de cada operación y los aplica atómicamente con
 * `WalletManager::applyDeltas` (todo o nada), y mantiene el `PositionBook` para
 * el costo base. Garantiza idempotencia por `idempotencyKey`.
 *
 * - BUY:  USDT -quoteAmount, COIN +baseNet   (fee descontado en base)
 * - SELL: COIN -baseQty,     USDT +quoteNet  (fee descontado en quote)
 */
final class MeanReversionExecutionSimulator
{
    /** @var array<string, MeanReversionSimulationResult> */
    private array $executed = [];

    public function __construct(
        private readonly WalletManager $wallets,
        private readonly PositionBook $positions,
        private readonly string $quoteAsset = 'USDT',
        private readonly float $feeRate = 0.001,
        // Slippage de ejecución (% máx): deriva del precio de fill. 0 = off.
        private readonly float $execDriftPct = 0.0,
    ) {
    }

    public function simulate(EvaluatedSignal $signal, string $idempotencyKey): MeanReversionSimulationResult
    {
        if (isset($this->executed[$idempotencyKey])) {
            $prior = $this->executed[$idempotencyKey];

            return new MeanReversionSimulationResult(
                idempotencyKey: $prior->idempotencyKey,
                exchange: $prior->exchange,
                symbol: $prior->symbol,
                side: $prior->side,
                price: $prior->price,
                baseQuantity: $prior->baseQuantity,
                quoteAmount: $prior->quoteAmount,
                fee: $prior->fee,
                realizedPnl: $prior->realizedPnl,
                executedAtMs: $prior->executedAtMs,
                duplicate: true,
            );
        }

        $candidate = $signal->candidate;
        $exchange = $candidate->exchange;
        $baseAsset = $candidate->baseAsset();
        $price = $this->applyDrift($candidate->price, $idempotencyKey);
        $nowMs = (int) (microtime(true) * 1000);

        if ($signal->side() === Side::Buy) {
            $quoteSpent = $signal->quoteAmount;
            $baseGross = $price > 0.0 ? $quoteSpent / $price : 0.0;
            $feeBase = $baseGross * $this->feeRate;
            $baseNet = $baseGross - $feeBase;

            $this->wallets->applyDeltas([
                ['exchange' => $exchange, 'asset' => $this->quoteAsset, 'delta' => -$quoteSpent, 'reason' => 'meanrev_buy', 'ref' => $idempotencyKey],
                ['exchange' => $exchange, 'asset' => $baseAsset, 'delta' => $baseNet, 'reason' => 'meanrev_buy', 'ref' => $idempotencyKey],
            ]);

            $this->positions->applyBuy($baseAsset, $baseNet, $quoteSpent, $nowMs);

            $result = new MeanReversionSimulationResult(
                idempotencyKey: $idempotencyKey,
                exchange: $exchange,
                symbol: $candidate->symbol,
                side: Side::Buy,
                price: $price,
                baseQuantity: $baseNet,
                quoteAmount: $quoteSpent,
                fee: $feeBase * $price,
                realizedPnl: 0.0,
                executedAtMs: $nowMs,
            );
        } else {
            $baseQty = $signal->baseQuantity;
            $quoteGross = $baseQty * $price;
            $feeQuote = $quoteGross * $this->feeRate;
            $quoteNet = $quoteGross - $feeQuote;

            $this->wallets->applyDeltas([
                ['exchange' => $exchange, 'asset' => $baseAsset, 'delta' => -$baseQty, 'reason' => 'meanrev_sell', 'ref' => $idempotencyKey],
                ['exchange' => $exchange, 'asset' => $this->quoteAsset, 'delta' => $quoteNet, 'reason' => 'meanrev_sell', 'ref' => $idempotencyKey],
            ]);

            $realizedPnl = $this->positions->applySell($baseAsset, $baseQty, $quoteNet);

            $result = new MeanReversionSimulationResult(
                idempotencyKey: $idempotencyKey,
                exchange: $exchange,
                symbol: $candidate->symbol,
                side: Side::Sell,
                price: $price,
                baseQuantity: $baseQty,
                quoteAmount: $quoteNet,
                fee: $feeQuote,
                realizedPnl: $realizedPnl,
                executedAtMs: $nowMs,
            );
        }

        $this->executed[$idempotencyKey] = $result;

        return $result;
    }

    /**
     * Deriva determinista en [-execDriftPct, +execDriftPct] sembrada por la
     * idempotencyKey, para que reintentos del mismo trade no cambien el fill.
     */
    private function applyDrift(float $price, string $idempotencyKey): float
    {
        if ($this->execDriftPct <= 0.0 || $price <= 0.0) {
            return $price;
        }

        $unit = (crc32($idempotencyKey) / 4294967295.0) * 2.0 - 1.0;
        $factor = 1.0 + ($unit * ($this->execDriftPct / 100.0));

        return $price * $factor;
    }
}
