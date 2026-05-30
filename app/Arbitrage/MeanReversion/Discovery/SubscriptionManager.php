<?php

declare(strict_types=1);

namespace App\Arbitrage\MeanReversion\Discovery;

use Closure;
use Psr\Log\LoggerInterface;

/**
 * Decide qué streams de profundidad mantener abiertos y los reconcilia contra
 * el set activo del hub.
 *
 *   deseado = inventario_de_TODOS_los_usuarios  ∪  top_N_volátiles  (capado)
 *
 * La discovery (ranking) y los books son COMPARTIDOS por todos los engines de
 * usuario; por eso el inventario "obligatorio" es la unión de las posiciones de
 * cada sesión activa (lo provee un resolver inyectado por el worker).
 *
 * Invariantes:
 *  - Nunca cierra el stream de una moneda con saldo de algún usuario (se
 *    necesita para vender / stop-loss).
 *  - Si no hay consumidores (ninguna sesión activa), no abre streams de
 *    profundidad (solo se mantiene la discovery !miniTicker@arr).
 *  - Histéresis: una moneda recién suscrita no se cierra antes de
 *    `minSubscriptionMs`, para evitar churn de sockets.
 */
final class SubscriptionManager
{
    /** @var array<string, int>  símbolo => epoch ms en que se suscribió */
    private array $subscribedAtMs = [];

    /** @var Closure(): array<string, bool> */
    private readonly Closure $heldSymbolsResolver;

    /**
     * @param  callable(): array<string, bool>  $heldSymbolsResolver  unión de
     *         símbolos con inventario ("ASSET/QUOTE" => true) de todas las sesiones.
     */
    public function __construct(
        private readonly VolatilityRanker $ranker,
        callable $heldSymbolsResolver,
        private readonly BinanceStreamHub $hub,
        private readonly LoggerInterface $logger,
        private readonly int $topN,
        private readonly int $maxSubscriptions,
        private readonly int $minSubscriptionMs,
        private readonly bool $diagnostics = false,
    ) {
        $this->heldSymbolsResolver = Closure::fromCallable($heldSymbolsResolver);
    }

    public function reconcile(int $nowMs, bool $hasConsumers = true): void
    {
        $held = ($this->heldSymbolsResolver)();

        // Sin consumidores: no abrir profundidad; el inventario debería estar
        // vacío también, pero respetamos held por seguridad (no cerrar con saldo).
        $movers = $hasConsumers ? $this->ranker->topN($this->topN) : [];

        // Inventario primero (obligatorio), luego rellenar con movers hasta el tope.
        $desired = $held;
        foreach ($movers as $symbol) {
            if (count($desired) >= $this->maxSubscriptions && ! isset($desired[$symbol])) {
                break;
            }
            $desired[$symbol] = true;
        }

        $active = [];
        foreach ($this->hub->activeSymbols() as $symbol) {
            $active[$symbol] = true;
        }

        $toAdd = [];
        foreach ($desired as $symbol => $_) {
            if (! isset($active[$symbol])) {
                $toAdd[] = $symbol;
            }
        }

        $toRemove = [];
        foreach ($active as $symbol => $_) {
            if (isset($desired[$symbol]) || isset($held[$symbol])) {
                continue;
            }
            // Histéresis: respetar tiempo mínimo suscrito.
            $since = $this->subscribedAtMs[$symbol] ?? 0;
            if ($nowMs - $since < $this->minSubscriptionMs) {
                continue;
            }
            $toRemove[] = $symbol;
        }

        if ($toAdd !== []) {
            $this->hub->subscribeSymbols($toAdd);
            foreach ($toAdd as $symbol) {
                $this->subscribedAtMs[$symbol] = $nowMs;
            }
        }

        if ($toRemove !== []) {
            $this->hub->unsubscribeSymbols($toRemove);
            foreach ($toRemove as $symbol) {
                unset($this->subscribedAtMs[$symbol]);
            }
        }

        if ($this->diagnostics) {
            $this->logger->debug('[meanrev][subscriptions] reconcile', [
                'movers_ranked' => count($movers),
                'held' => count($held),
                'desired' => count($desired),
                'added' => $toAdd,
                'removed' => $toRemove,
                'active' => count($this->hub->activeSymbols()),
                'connected' => $this->hub->isConnected(),
                'consumers' => $hasConsumers,
            ]);
        }
    }
}
