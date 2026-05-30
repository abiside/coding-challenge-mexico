<?php

declare(strict_types=1);

namespace App\Arbitrage\Support;

use App\Arbitrage\Engine\ArbitrageEngine;
use App\Arbitrage\Execution\WalletManager;
use App\Arbitrage\MarketData\MarketPerturbator;
use App\Arbitrage\Persistence\PersistenceBuffer;
use App\Arbitrage\Realtime\MetricsAggregator;
use App\Arbitrage\Triangular\Engine\CycleEngine;

/**
 * Contenedor de los componentes vivos del engine, para que el runner
 * (comando) gestione ciclo de vida: procesar, vaciar buffer, métricas.
 *
 * Cada estrategia activa (champion + N challengers shadow) tiene su propio
 * EngineRuntime aislado, con su wallet sandbox y su recorder tagueado.
 */
final class EngineRuntime
{
    public function __construct(
        public readonly ArbitrageEngine $engine,
        public readonly WalletManager $wallets,
        public readonly ?PersistenceBuffer $buffer,
        public readonly MetricsAggregator $metrics,
        public readonly ?int $userId = null,
        public readonly ?int $strategyId = null,
        // Solo el champion escribe wallet_balances; los challengers usan
        // wallet sandbox en memoria que no se persiste.
        public readonly bool $persistWallet = true,
        // Hash del config que ensambló este engine, para detectar cambios y
        // disparar hot-reload sin comparar el árbol completo.
        public readonly ?string $configHash = null,
        // Perturbador de precios para el modo simulación. Null = books reales.
        public readonly ?MarketPerturbator $perturbator = null,
        // Engine de ciclos triangulares (opcional). Si null, el runtime solo
        // procesa oportunidades de 2 patas.
        public readonly ?CycleEngine $cycleEngine = null,
    ) {}
}
