<?php

declare(strict_types=1);

namespace App\Arbitrage\MeanReversion\Discovery;

use App\Arbitrage\Execution\WalletManager;
use Psr\Log\LoggerInterface;

/**
 * Decide qué streams de profundidad mantener abiertos y los reconcilia contra
 * el set activo del hub.
 *
 *   deseado = monedas_con_inventario  ∪  top_N_volátiles  (capado a max_subs)
 *
 * Invariantes:
 *  - Nunca cierra el stream de una moneda con saldo (se necesita para vender /
 *    stop-loss). Las monedas en inventario siempre entran al deseado, incluso
 *    por encima del tope de suscripciones.
 *  - Histéresis: una moneda recién suscrita no se cierra antes de
 *    `minSubscriptionMs`, para evitar churn de sockets.
 */
final class SubscriptionManager
{
    /** @var array<string, int>  símbolo => epoch ms en que se suscribió */
    private array $subscribedAtMs = [];

    public function __construct(
        private readonly VolatilityRanker $ranker,
        private readonly WalletManager $wallets,
        private readonly BinanceStreamHub $hub,
        private readonly LoggerInterface $logger,
        private readonly string $exchange,
        private readonly string $quoteAsset,
        private readonly int $topN,
        private readonly int $maxSubscriptions,
        private readonly int $minSubscriptionMs,
        private readonly bool $diagnostics = false,
        private readonly float $dustThreshold = 1e-8,
    ) {
    }

    public function reconcile(int $nowMs): void
    {
        $held = $this->heldSymbols();
        $movers = $this->ranker->topN($this->topN);

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
            ]);
        }
    }

    /**
     * Símbolos con saldo de inventario (> dust), mapeados a par contra la quote.
     *
     * @return array<string, bool>
     */
    private function heldSymbols(): array
    {
        $held = [];
        $assets = $this->wallets->snapshot()[strtolower($this->exchange)] ?? [];
        foreach ($assets as $asset => $amount) {
            $asset = strtoupper((string) $asset);
            if ($asset === strtoupper($this->quoteAsset)) {
                continue;
            }
            if ((float) $amount > $this->dustThreshold) {
                $held[$asset.'/'.strtoupper($this->quoteAsset)] = true;
            }
        }

        return $held;
    }
}
