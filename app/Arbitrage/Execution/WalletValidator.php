<?php

declare(strict_types=1);

namespace App\Arbitrage\Execution;

use App\Arbitrage\Contracts\WalletRepositoryInterface;
use App\Arbitrage\Engine\DTO\OpportunityCandidate;

/**
 * Calcula, en modo solo-lectura, el volumen base máximo ejecutable según los
 * balances disponibles: USDT (quote) en el exchange comprador y BTC (base) en
 * el exchange vendedor. No muta balances.
 */
final class WalletValidator
{
    public function __construct(
        private readonly WalletRepositoryInterface $wallets,
    ) {
    }

    /**
     * Volumen base máximo soportado por balances para esta oportunidad,
     * asumiendo un precio de compra aproximado por unidad (best ask o
     * weighted buy).
     */
    public function maxExecutableVolume(OpportunityCandidate $candidate, float $approxBuyPrice, float $buyFeeRate): float
    {
        $assets = SymbolAssets::fromSymbol($candidate->symbol);

        $quoteAvailable = $this->wallets->available($candidate->buyExchange(), $assets->quote);
        $baseAvailable = $this->wallets->available($candidate->sellExchange(), $assets->base);

        if ($approxBuyPrice <= 0.0) {
            return 0.0;
        }

        // El costo por unidad incluye fee de compra.
        $costPerUnit = $approxBuyPrice * (1.0 + $buyFeeRate);
        $volumeByQuote = $costPerUnit > 0.0 ? $quoteAvailable / $costPerUnit : 0.0;

        return max(0.0, min($volumeByQuote, $baseAvailable));
    }
}
