<?php

declare(strict_types=1);

namespace App\Arbitrage\Triangular\Engine;

use App\Arbitrage\Contracts\DiscardRecorderInterface;
use App\Arbitrage\MarketData\BookState;
use App\Arbitrage\Triangular\DTO\AssetNode;
use App\Arbitrage\Triangular\DTO\ConversionEdge;
use App\Arbitrage\Triangular\DTO\CycleCandidate;
use App\Arbitrage\Triangular\Graph\ConversionGraph;
use App\Arbitrage\Triangular\Graph\GraphBuilder;
use Psr\Log\LoggerInterface;

/**
 * Detecta ciclos rentables en el grafo de conversiones, disparado por cada
 * actualización de book.
 *
 * Estrategia:
 *  - Construye el grafo de conversiones a partir del store actual (filtrado
 *    por frescura).
 *  - Para cada activo de partida configurado en `startAssets`, ejecuta un DFS
 *    acotado por `maxCycleLength` desde cada nodo `(exchange, startAsset)` que
 *    aparezca en el grafo.
 *  - Solo emite ciclos cuyo producto de tasas netas supere 1 (+ tolerancia).
 *  - Para evitar trabajo redundante por snapshot, exige que el ciclo pase por
 *    al menos una arista de trade asociada al book recién actualizado (ancla).
 *
 * Cada descarte se contabiliza con razón normalizada `cycle:*` (`cycle:no_anchor`,
 * `cycle:not_profitable`, `cycle:no_start_node`) para que el embudo del
 * dashboard explique por qué no se dispararon ciclos.
 */
final class CycleScanner
{
    /**
     * Tolerancia mínima para considerar un ciclo "rentable bruto". 1 bp = 1e-4.
     */
    private const PROFIT_EPSILON = 1.0 + 1e-9;

    /**
     * @param  array<int, string>  $startAssets  whitelist de activos de partida
     */
    public function __construct(
        private readonly GraphBuilder $builder,
        private readonly array $startAssets,
        private readonly int $maxCycleLength = 3,
        private readonly ?LoggerInterface $logger = null,
        private readonly bool $diagnostics = false,
        private readonly ?DiscardRecorderInterface $discards = null,
    ) {
    }

    /**
     * Escanea ciclos disparados por el book recién actualizado.
     *
     * @return array<int, CycleCandidate>
     */
    public function scan(BookState $updated, ?int $nowMs = null): array
    {
        $nowMs ??= (int) (microtime(true) * 1000);

        $startAssets = $this->normalizedStartAssets();
        if ($startAssets === []) {
            $this->diag('skip_no_start_assets', [], discardKey: 'cycle:no_start_assets');

            return [];
        }

        $graph = $this->builder->build($nowMs);

        // Ancla: solo emitimos ciclos que pasen por una arista del book
        // recién actualizado. Si el book no produjo ninguna arista (stale o
        // sin liquidez), no hay nada que evaluar.
        $anchorKeys = $this->anchorEdgeKeys($graph, $updated);
        if ($anchorKeys === []) {
            $this->diag('skip_no_anchor_edges', [
                'updated_exchange' => $updated->exchange,
                'updated_symbol' => $updated->symbol,
            ], discardKey: 'cycle:no_anchor');

            return [];
        }

        $candidates = [];
        $scanned = 0;
        $rejectedNotProfitable = 0;

        foreach ($startAssets as $asset) {
            foreach ($graph->nodesForAsset($asset) as $startNode) {
                $this->dfs(
                    graph: $graph,
                    start: $startNode,
                    current: $startNode,
                    path: [],
                    visited: [$startNode->key() => true],
                    netProduct: 1.0,
                    grossProduct: 1.0,
                    anchorHit: false,
                    anchorKeys: $anchorKeys,
                    nowMs: $nowMs,
                    candidates: $candidates,
                    scanned: $scanned,
                    rejectedNotProfitable: $rejectedNotProfitable,
                );
            }
        }

        $this->diag('scan_summary', [
            'updated_exchange' => $updated->exchange,
            'updated_symbol' => $updated->symbol,
            'start_assets' => $startAssets,
            'anchor_edges' => count($anchorKeys),
            'paths_scanned' => $scanned,
            'rejected_not_profitable' => $rejectedNotProfitable,
            'candidates' => count($candidates),
        ]);

        return $candidates;
    }

    /**
     * @param  array<int, ConversionEdge>  $path
     * @param  array<string, bool>  $visited
     * @param  array<string, bool>  $anchorKeys
     * @param  array<int, CycleCandidate>  $candidates
     */
    private function dfs(
        ConversionGraph $graph,
        AssetNode $start,
        AssetNode $current,
        array $path,
        array $visited,
        float $netProduct,
        float $grossProduct,
        bool $anchorHit,
        array $anchorKeys,
        int $nowMs,
        array &$candidates,
        int &$scanned,
        int &$rejectedNotProfitable,
    ): void {
        if (count($path) >= $this->maxCycleLength) {
            return;
        }

        foreach ($graph->edgesFrom($current) as $edge) {
            $next = $edge->to;
            $nextKey = $next->key();
            $edgeKey = $this->edgeKey($edge);
            $hitsAnchor = $anchorHit || isset($anchorKeys[$edgeKey]);

            $newPath = $path;
            $newPath[] = $edge;
            $newNet = $netProduct * $edge->netRate();
            $newGross = $grossProduct * $edge->grossRate;

            if ($next->equals($start)) {
                $scanned++;

                if (! $hitsAnchor) {
                    $this->discards?->recordDiscard('cycle:no_anchor_in_cycle');

                    continue;
                }

                if ($newNet <= self::PROFIT_EPSILON) {
                    $rejectedNotProfitable++;
                    $this->discards?->recordDiscard('cycle:not_profitable');

                    continue;
                }

                $candidates[] = new CycleCandidate(
                    edges: $newPath,
                    netRateProduct: $newNet,
                    detectedAtMs: $nowMs,
                );

                continue;
            }

            if (isset($visited[$nextKey])) {
                continue;
            }

            $newVisited = $visited;
            $newVisited[$nextKey] = true;

            $this->dfs(
                $graph,
                $start,
                $next,
                $newPath,
                $newVisited,
                $newNet,
                $newGross,
                $hitsAnchor,
                $anchorKeys,
                $nowMs,
                $candidates,
                $scanned,
                $rejectedNotProfitable,
            );
        }
    }

    /**
     * Devuelve las claves de aristas asociadas al book actualizado: las dos
     * aristas de trade (BUY y SELL) en `(exchange, symbol)` cuando estén
     * presentes en el grafo.
     *
     * @return array<string, bool>
     */
    private function anchorEdgeKeys(ConversionGraph $graph, BookState $updated): array
    {
        $keys = [];
        foreach ($graph->nodes() as $node) {
            if ($node->exchange !== strtolower($updated->exchange)) {
                continue;
            }
            foreach ($graph->edgesFrom($node) as $edge) {
                if (! $edge->isTrade()) {
                    continue;
                }
                if ($edge->symbol === $updated->symbol && $edge->book !== null
                    && strtolower($edge->book->exchange) === strtolower($updated->exchange)) {
                    $keys[$this->edgeKey($edge)] = true;
                }
            }
        }

        return $keys;
    }

    private function edgeKey(ConversionEdge $edge): string
    {
        return $edge->from->key().'->'.$edge->to->key().'#'.$edge->kind->value;
    }

    /**
     * @return array<int, string>
     */
    private function normalizedStartAssets(): array
    {
        $out = [];
        foreach ($this->startAssets as $asset) {
            $norm = strtoupper(trim((string) $asset));
            if ($norm !== '') {
                $out[$norm] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function diag(string $event, array $context, ?string $discardKey = null): void
    {
        if ($discardKey !== null) {
            $this->discards?->recordDiscard($discardKey);
        }

        if (! $this->diagnostics || $this->logger === null) {
            return;
        }

        $this->logger->debug('[arbitrage][cycle-scan] '.$event, $context);
    }
}
