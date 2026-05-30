<?php

declare(strict_types=1);

namespace App\Arbitrage\Engine;

use App\Arbitrage\Contracts\DiscardRecorderInterface;
use App\Arbitrage\Contracts\OrderBookStoreInterface;
use App\Arbitrage\Engine\DTO\OpportunityCandidate;
use App\Arbitrage\MarketData\BookState;
use Psr\Log\LoggerInterface;

/**
 * Detecta candidatos de arbitraje cuando un book se actualiza.
 *
 * Para el book recién actualizado compara contra los books frescos de los
 * demás exchanges del mismo símbolo, en ambas direcciones, y genera un
 * candidato por cada cruce donde buy_ask < sell_bid. No decide ejecución.
 *
 * Con `diagnostics` activo emite un log `debug` por cada comparativa que se
 * descarta (book stale, sin liquidez o spread no cruzado) y un resumen por
 * scan, para entender por qué no se está disparando ninguna evaluación.
 */
final class ArbitrageScanner
{
    public function __construct(
        private readonly OrderBookStoreInterface $store,
        private readonly int $freshnessMs,
        private readonly ?LoggerInterface $logger = null,
        private readonly bool $diagnostics = false,
        private readonly ?DiscardRecorderInterface $discards = null,
    ) {
    }

    /**
     * @return array<int, OpportunityCandidate>
     */
    public function scan(BookState $updated, ?int $nowMs = null): array
    {
        $nowMs ??= (int) (microtime(true) * 1000);

        if (! $updated->isFresh($this->freshnessMs, $nowMs)) {
            $this->diag('skip_updated_stale', [
                'exchange' => $updated->exchange,
                'symbol' => $updated->symbol,
                'age_ms' => $updated->ageMs($nowMs),
                'freshness_ms' => $this->freshnessMs,
            ], discardKey: 'updated_stale');

            return [];
        }

        if (! $updated->hasLiquidity()) {
            $this->diag('skip_updated_no_liquidity', [
                'exchange' => $updated->exchange,
                'symbol' => $updated->symbol,
            ], discardKey: 'updated_no_liquidity');

            return [];
        }

        $candidates = [];
        $others = $this->store->freshExcept($updated->symbol, $updated->exchange, $this->freshnessMs, $nowMs);

        $comparisons = 0;
        foreach ($others as $other) {
            if (! $other->hasLiquidity()) {
                $this->diag('skip_other_no_liquidity', [
                    'exchange' => $other->exchange,
                    'symbol' => $other->symbol,
                ], discardKey: 'other_no_liquidity');

                continue;
            }

            // Dirección 1: comprar en el book actualizado, vender en el otro.
            $comparisons++;
            $candidate = $this->tryCross($updated, $other, $updated->symbol, $nowMs);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }

            // Dirección 2: comprar en el otro, vender en el book actualizado.
            $comparisons++;
            $candidate = $this->tryCross($other, $updated, $updated->symbol, $nowMs);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        $this->diag('scan_summary', [
            'updated_exchange' => $updated->exchange,
            'symbol' => $updated->symbol,
            'fresh_others' => count($others),
            'comparisons' => $comparisons,
            'candidates' => count($candidates),
        ]);

        return $candidates;
    }

    private function tryCross(BookState $buyBook, BookState $sellBook, string $symbol, int $nowMs): ?OpportunityCandidate
    {
        $buyAsk = $buyBook->bestAsk();
        $sellBid = $sellBook->bestBid();

        if ($buyAsk === null || $sellBid === null) {
            $this->diag('discard_no_best_quote', [
                'symbol' => $symbol,
                'buy_exchange' => $buyBook->exchange,
                'sell_exchange' => $sellBook->exchange,
                'has_buy_ask' => $buyAsk !== null,
                'has_sell_bid' => $sellBid !== null,
            ], discardKey: 'no_best_quote');

            return null;
        }

        $spreadBps = $buyAsk->price > 0.0
            ? (($sellBid->price - $buyAsk->price) / $buyAsk->price) * 10000.0
            : 0.0;

        if ($buyAsk->price >= $sellBid->price) {
            $this->diag('discard_not_crossed', [
                'symbol' => $symbol,
                'buy_exchange' => $buyBook->exchange,
                'sell_exchange' => $sellBook->exchange,
                'buy_ask' => $buyAsk->price,
                'sell_bid' => $sellBid->price,
                'gross_spread_bps' => round($spreadBps, 4),
            ], discardKey: 'not_crossed');

            return null;
        }

        $this->diag('cross_detected', [
            'symbol' => $symbol,
            'buy_exchange' => $buyBook->exchange,
            'sell_exchange' => $sellBook->exchange,
            'buy_ask' => $buyAsk->price,
            'sell_bid' => $sellBid->price,
            'gross_spread_bps' => round($spreadBps, 4),
        ]);

        return new OpportunityCandidate(
            symbol: $symbol,
            buyBook: $buyBook,
            sellBook: $sellBook,
            buyAsk: $buyAsk->price,
            sellBid: $sellBid->price,
            detectedAtMs: $nowMs,
        );
    }

    /**
     * Cuenta la razón de descarte (clave limpia, barato y siempre) y, si el
     * diagnóstico está activo, emite además el log `debug` detallado de la
     * comparativa con el nombre de evento descriptivo.
     *
     * @param  array<string, mixed>  $context
     * @param  string|null  $discardKey  clave normalizada del embudo; null si el
     *                                    evento no es un descarte (cross/summary)
     */
    private function diag(string $event, array $context, ?string $discardKey = null): void
    {
        if ($discardKey !== null) {
            $this->discards?->recordDiscard($discardKey);
        }

        if (! $this->diagnostics || $this->logger === null) {
            return;
        }

        $this->logger->debug('[arbitrage][scan] '.$event, $context);
    }
}
