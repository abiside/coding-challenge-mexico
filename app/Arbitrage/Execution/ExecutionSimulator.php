<?php

declare(strict_types=1);

namespace App\Arbitrage\Execution;

use App\Arbitrage\Contracts\ExecutionSimulatorInterface;
use App\Arbitrage\Engine\DTO\EvaluatedOpportunity;
use App\Arbitrage\Execution\DTO\SimulatedFill;
use App\Arbitrage\Execution\DTO\SimulationResult;

/**
 * Simula la compra y venta simultánea de una oportunidad aprobada.
 *
 * Es el ÚNICO escritor de balances: aplica los deltas correspondientes a
 * través del WalletManager de forma atómica. Garantiza idempotencia por
 * clave para que reintentos no dupliquen ejecuciones.
 */
final class ExecutionSimulator implements ExecutionSimulatorInterface
{
    /**
     * @var array<string, SimulationResult>
     */
    private array $executed = [];

    public function __construct(
        private readonly WalletManager $wallets,
        // Solo modo simulación: deriva máxima (%) del precio de fill respecto al
        // precio evaluado, al momento del trade. 0 = ejecutar al precio evaluado.
        private readonly float $execDriftPct = 0.0,
    ) {
    }

    public function simulate(EvaluatedOpportunity $opportunity, string $idempotencyKey): SimulationResult
    {
        if (isset($this->executed[$idempotencyKey])) {
            $prior = $this->executed[$idempotencyKey];

            return new SimulationResult(
                idempotencyKey: $prior->idempotencyKey,
                symbol: $prior->symbol,
                buyFill: $prior->buyFill,
                sellFill: $prior->sellFill,
                realizedPnl: $prior->realizedPnl,
                executedAtMs: $prior->executedAtMs,
                duplicate: true,
            );
        }

        $candidate = $opportunity->candidate;
        $liquidity = $opportunity->liquidity;
        $profit = $opportunity->profitability;
        $assets = SymbolAssets::fromSymbol($candidate->symbol);

        $volume = $liquidity->executableBaseVolume;
        $buyExchange = $candidate->buyExchange();
        $sellExchange = $candidate->sellExchange();

        // Precios/notionals/fees evaluados. En modo simulación los desplazamos
        // al "ejecutar" para modelar el movimiento de precio entre la decisión
        // y el trade. La deriva es relativa al precio evaluado (no un valor
        // aleatorio absoluto) y se aplica de forma independiente a cada pata.
        $buyPrice = $liquidity->weightedBuyPrice;
        $sellPrice = $liquidity->weightedSellPrice;
        $buyNotional = $liquidity->buyNotional;
        $sellNotional = $liquidity->sellNotional;
        $buyFee = $profit->buyFee;
        $sellFee = $profit->sellFee;
        $realizedPnl = $profit->netProfit;

        if ($this->execDriftPct > 0.0) {
            $buyFactor = 1.0 + $this->driftFor($opportunity, 'buy');
            $sellFactor = 1.0 + $this->driftFor($opportunity, 'sell');

            $buyPrice *= $buyFactor;
            $sellPrice *= $sellFactor;
            // El notional y el fee (taker = rate × notional) escalan con el precio.
            $buyNotional *= $buyFactor;
            $sellNotional *= $sellFactor;
            $buyFee *= $buyFactor;
            $sellFee *= $sellFactor;

            // P&L realizado recomputado desde los fills reales de ejecución,
            // conservando costos no ligados al precio (latencia, fijo).
            $grossProfit = $sellNotional - $buyNotional;
            $realizedPnl = $grossProfit - $buyFee - $sellFee
                - $profit->latencyPenalty - $profit->fixedCost;
        }

        // Single-writer: mutación atómica de ambas patas.
        $this->wallets->applyDeltas([
            [
                'exchange' => $buyExchange,
                'asset' => $assets->quote,
                'delta' => -($buyNotional + $buyFee),
                'reason' => 'arbitrage_buy',
                'ref' => $idempotencyKey,
            ],
            [
                'exchange' => $buyExchange,
                'asset' => $assets->base,
                'delta' => $volume,
                'reason' => 'arbitrage_buy',
                'ref' => $idempotencyKey,
            ],
            [
                'exchange' => $sellExchange,
                'asset' => $assets->base,
                'delta' => -$volume,
                'reason' => 'arbitrage_sell',
                'ref' => $idempotencyKey,
            ],
            [
                'exchange' => $sellExchange,
                'asset' => $assets->quote,
                'delta' => ($sellNotional - $sellFee),
                'reason' => 'arbitrage_sell',
                'ref' => $idempotencyKey,
            ],
        ]);

        $buyFill = new SimulatedFill(
            side: 'buy',
            exchange: $buyExchange,
            symbol: $candidate->symbol,
            baseVolume: $volume,
            price: $buyPrice,
            notional: $buyNotional,
            fee: $buyFee,
        );

        $sellFill = new SimulatedFill(
            side: 'sell',
            exchange: $sellExchange,
            symbol: $candidate->symbol,
            baseVolume: $volume,
            price: $sellPrice,
            notional: $sellNotional,
            fee: $sellFee,
        );

        $result = new SimulationResult(
            idempotencyKey: $idempotencyKey,
            symbol: $candidate->symbol,
            buyFill: $buyFill,
            sellFill: $sellFill,
            realizedPnl: $realizedPnl,
            executedAtMs: (int) (microtime(true) * 1000),
        );

        $this->executed[$idempotencyKey] = $result;

        return $result;
    }

    /**
     * Deriva de ejecución determinista en [-execDriftPct, +execDriftPct] como
     * fracción. Se siembra con la IDENTIDAD del momento de mercado (par, par de
     * exchanges, timestamps de exchange de ambos books) + la pata, de modo que
     * dos estrategias que ejecutan la MISMA oportunidad en el mismo instante
     * sufren exactamente la misma desviación de precio. Cada pata (buy/sell)
     * recibe su propio factor independiente vía el discriminador `$leg`.
     */
    private function driftFor(EvaluatedOpportunity $opportunity, string $leg): float
    {
        $candidate = $opportunity->candidate;
        $seed = implode('|', [
            $candidate->symbol,
            $candidate->buyExchange(),
            $candidate->sellExchange(),
            $candidate->buyBook->exchangeTimestampMs,
            $candidate->sellBook->exchangeTimestampMs,
            $this->execDriftPct,
            $leg,
        ]);

        $unit = (crc32($seed) / 4294967295.0) * 2.0 - 1.0;

        return $unit * ($this->execDriftPct / 100.0);
    }
}
