<?php

declare(strict_types=1);

namespace App\Arbitrage\Triangular\Graph;

use App\Arbitrage\Triangular\DTO\AssetNode;
use App\Arbitrage\Triangular\DTO\ConversionEdge;

/**
 * Grafo dirigido de conversiones entre nodos `(exchange, asset)`.
 *
 * Es un value object inmutable que se reconstruye cada vez que el scanner
 * dispara una evaluación: tiene tamaño acotado (cantidad de exchanges x
 * activos cubiertos por books frescos) y se vuelca a memoria local en el
 * camino crítico.
 */
final class ConversionGraph
{
    /**
     * @var array<string, array<int, ConversionEdge>>  node_key => edges salientes
     */
    private array $adjacency = [];

    /**
     * @var array<string, AssetNode>  node_key => AssetNode
     */
    private array $nodes = [];

    public function addEdge(ConversionEdge $edge): void
    {
        $this->registerNode($edge->from);
        $this->registerNode($edge->to);
        $this->adjacency[$edge->from->key()][] = $edge;
    }

    /**
     * @return array<int, ConversionEdge>
     */
    public function edgesFrom(AssetNode $node): array
    {
        return $this->adjacency[$node->key()] ?? [];
    }

    /**
     * @return array<int, AssetNode>
     */
    public function nodes(): array
    {
        return array_values($this->nodes);
    }

    public function hasNode(AssetNode $node): bool
    {
        return isset($this->nodes[$node->key()]);
    }

    /**
     * Devuelve todos los nodos cuyo asset coincida con el dado (sin importar
     * el exchange). Útil para enumerar puntos de partida del DFS desde una
     * whitelist de activos (USDT, USD, ...).
     *
     * @return array<int, AssetNode>
     */
    public function nodesForAsset(string $asset): array
    {
        $needle = strtoupper(trim($asset));
        $out = [];
        foreach ($this->nodes as $node) {
            if ($node->asset === $needle) {
                $out[] = $node;
            }
        }

        return $out;
    }

    private function registerNode(AssetNode $node): void
    {
        $this->nodes[$node->key()] = $node;
    }
}
