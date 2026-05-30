<?php

declare(strict_types=1);

namespace App\Arbitrage\Triangular\Graph;

use App\Arbitrage\Contracts\OrderBookStoreInterface;
use App\Arbitrage\Engine\FeeSchedule;
use App\Arbitrage\Execution\SymbolAssets;
use App\Arbitrage\MarketData\BookState;
use App\Arbitrage\Triangular\DTO\AssetNode;
use App\Arbitrage\Triangular\DTO\ConversionEdge;
use App\Arbitrage\Triangular\DTO\EdgeKind;

/**
 * Construye un grafo de conversiones a partir del estado actual del
 * `OrderBookStore`, filtrando por frescura.
 *
 * - Por cada book fresco `BASE/QUOTE` en un exchange E, añade dos aristas:
 *   - BUY:  (E,QUOTE) -> (E,BASE) con rate = 1/bestAsk, fee del exchange.
 *   - SELL: (E,BASE)  -> (E,QUOTE) con rate = bestBid,  fee del exchange.
 * - Si `crossExchange` está activo, por cada par de exchanges que tienen el
 *   mismo asset en sus books añade una arista de equivalencia de inventario
 *   con tasa 1 - transferCost. Modela mantener saldo en ambos wallets.
 */
final class GraphBuilder
{
    public function __construct(
        private readonly OrderBookStoreInterface $store,
        private readonly FeeSchedule $fees,
        private readonly int $freshnessMs,
        private readonly bool $crossExchange = true,
        private readonly float $transferCost = 0.0,
    ) {
    }

    public function build(?int $nowMs = null): ConversionGraph
    {
        $nowMs ??= (int) (microtime(true) * 1000);
        $graph = new ConversionGraph;

        // Mapa exchange -> set de assets, para luego construir aristas de
        // equivalencia entre exchanges que comparten activos.
        /** @var array<string, array<string, bool>> $assetsByExchange */
        $assetsByExchange = [];

        foreach ($this->store->symbols() as $symbol) {
            try {
                $assets = SymbolAssets::fromSymbol($symbol);
            } catch (\InvalidArgumentException) {
                continue;
            }

            foreach ($this->store->allForSymbol($symbol) as $book) {
                if (! $book->isFresh($this->freshnessMs, $nowMs) || ! $book->hasLiquidity()) {
                    continue;
                }

                $this->addTradeEdges($graph, $book, $assets);

                $assetsByExchange[$book->exchange][$assets->base] = true;
                $assetsByExchange[$book->exchange][$assets->quote] = true;
            }
        }

        if ($this->crossExchange) {
            $this->addTransferEdges($graph, $assetsByExchange);
        }

        return $graph;
    }

    private function addTradeEdges(ConversionGraph $graph, BookState $book, SymbolAssets $assets): void
    {
        $bestBid = $book->bestBid();
        $bestAsk = $book->bestAsk();
        if ($bestBid === null || $bestAsk === null) {
            return;
        }

        $feeRate = $this->fees->for($book->exchange);

        $baseNode = new AssetNode($book->exchange, $assets->base);
        $quoteNode = new AssetNode($book->exchange, $assets->quote);

        if ($bestAsk->price > 0.0) {
            // BUY: gasto QUOTE, recibo BASE. rate bruta = BASE por unidad de QUOTE.
            $graph->addEdge(new ConversionEdge(
                from: $quoteNode,
                to: $baseNode,
                kind: EdgeKind::TradeBuy,
                grossRate: 1.0 / $bestAsk->price,
                feeRate: $feeRate,
                book: $book,
                symbol: $book->symbol,
            ));
        }

        if ($bestBid->price > 0.0) {
            // SELL: gasto BASE, recibo QUOTE. rate bruta = QUOTE por unidad de BASE.
            $graph->addEdge(new ConversionEdge(
                from: $baseNode,
                to: $quoteNode,
                kind: EdgeKind::TradeSell,
                grossRate: $bestBid->price,
                feeRate: $feeRate,
                book: $book,
                symbol: $book->symbol,
            ));
        }
    }

    /**
     * @param  array<string, array<string, bool>>  $assetsByExchange
     */
    private function addTransferEdges(ConversionGraph $graph, array $assetsByExchange): void
    {
        $exchanges = array_keys($assetsByExchange);
        $count = count($exchanges);
        if ($count < 2) {
            return;
        }

        $transferRate = max(0.0, 1.0 - $this->transferCost);

        for ($i = 0; $i < $count; $i++) {
            for ($j = 0; $j < $count; $j++) {
                if ($i === $j) {
                    continue;
                }
                $ex1 = $exchanges[$i];
                $ex2 = $exchanges[$j];
                $common = array_intersect_key($assetsByExchange[$ex1], $assetsByExchange[$ex2]);
                foreach (array_keys($common) as $asset) {
                    $graph->addEdge(new ConversionEdge(
                        from: new AssetNode($ex1, (string) $asset),
                        to: new AssetNode($ex2, (string) $asset),
                        kind: EdgeKind::Transfer,
                        grossRate: $transferRate,
                        feeRate: 0.0,
                        book: null,
                        symbol: null,
                    ));
                }
            }
        }
    }
}
