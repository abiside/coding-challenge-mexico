<?php

declare(strict_types=1);

namespace App\Arbitrage\Support;

use App\Arbitrage\Contracts\DashboardPublisherInterface;
use App\Arbitrage\Contracts\OpportunityRecorderInterface;
use App\Arbitrage\Engine\ArbitrageEngine;
use App\Arbitrage\Engine\ArbitrageScanner;
use App\Arbitrage\Engine\FeeSchedule;
use App\Arbitrage\Engine\LiquidityCalculator;
use App\Arbitrage\Engine\ProfitabilityCalculator;
use App\Arbitrage\Execution\ExecutionSimulator;
use App\Arbitrage\Execution\WalletManager;
use App\Arbitrage\Execution\WalletValidator;
use App\Arbitrage\MarketData\MarketPerturbator;
use App\Arbitrage\MarketData\OrderBookStore;
use App\Arbitrage\Persistence\BufferedOpportunityRecorder;
use App\Arbitrage\Persistence\NullOpportunityRecorder;
use App\Arbitrage\Persistence\PersistenceBuffer;
use App\Arbitrage\Realtime\MetricsAggregator;
use App\Arbitrage\Realtime\NullDashboardPublisher;
use App\Arbitrage\Realtime\ReverbDashboardPublisher;
use App\Arbitrage\Risk\CircuitBreaker;
use App\Arbitrage\Risk\Guards\FreshnessGuard;
use App\Arbitrage\Risk\Guards\LatencyGuard;
use App\Arbitrage\Risk\Guards\MinProfitGuard;
use App\Arbitrage\Risk\Guards\MinVolumeGuard;
use App\Arbitrage\Risk\RiskManager;
use App\Support\ArbitrageCacheKeys;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;

/**
 * Ensambla el ArbitrageEngine y sus dependencias a partir de config/arbitrage.
 * Mantiene el wiring en un solo lugar y permite activar/desactivar persistencia
 * y dashboard.
 */
final class EngineFactory
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly Dispatcher $events,
        private readonly Cache $cache,
    ) {}

    /**
     * @param  array<string, mixed>  $config  config('arbitrage')
     */
    public function make(
        array $config,
        bool $withPersistence = true,
        bool $withDashboard = true,
        ?int $userId = null,
        ?DashboardPublisherInterface $dashboardOverride = null,
        ?int $strategyId = null,
        bool $persistWallet = true,
        ?string $configHash = null,
    ): EngineRuntime {
        $freshnessMs = (int) ($config['freshness_ms'] ?? 2000);
        $thresholds = (array) ($config['thresholds'] ?? []);
        $minVolume = (float) ($thresholds['min_base_volume'] ?? 0.0001);
        $maxVolume = (float) ($thresholds['max_base_volume'] ?? 1.0);
        // El toggle de diagnóstico se respeta aunque la config de la estrategia
        // (variant config en modo multi-usuario) no lo incluya: cae al global.
        $diagnostics = (bool) (($config['diagnostics']['enabled']) ?? config('arbitrage.diagnostics.enabled', false));

        // El agregador de métricas es también el sink del embudo de descartes:
        // misma instancia para scanner, engine y runtime (heartbeat/dashboard).
        $metrics = new MetricsAggregator;

        $store = new OrderBookStore;
        $scanner = new ArbitrageScanner($store, $freshnessMs, $this->logger, $diagnostics, $metrics);

        $feesConfig = (array) ($config['fees'] ?? []);
        $fees = new FeeSchedule(
            feesByExchange: array_map('floatval', array_filter(
                $feesConfig,
                static fn (string $key): bool => $key !== 'default',
                ARRAY_FILTER_USE_KEY,
            )),
            default: (float) ($feesConfig['default'] ?? 0.001),
        );

        $liquidity = new LiquidityCalculator;
        $profitability = new ProfitabilityCalculator(
            fees: $fees,
            latencyPenaltyPerMs: (float) (($config['latency']['penalty_per_ms']) ?? 0.0),
            fixedCost: (float) ($config['fixed_cost'] ?? 0.0),
        );

        $wallets = new WalletManager((array) ($config['initial_balances'] ?? []));
        $walletValidator = new WalletValidator($wallets);

        $cb = $config['circuit_breaker'] ?? [];
        $circuitBreaker = new CircuitBreaker(
            enabled: (bool) ($cb['enabled'] ?? true),
            failureThreshold: (int) ($cb['failure_threshold'] ?? 10),
            cooldownMs: (int) ($cb['cooldown_ms'] ?? 5000),
        );

        $guards = [
            new FreshnessGuard($freshnessMs),
            new MinVolumeGuard($minVolume),
            new LatencyGuard((int) (($config['latency']['max_ms']) ?? 1500)),
            new MinProfitGuard(
                minNetProfit: (float) ($thresholds['min_net_profit'] ?? 0.0),
                minNetMargin: (float) ($thresholds['min_net_margin'] ?? 0.0),
            ),
        ];

        $riskManager = new RiskManager($guards, $circuitBreaker);

        // Slippage de ejecución (solo modo simulación): el precio de fill se
        // desvía respecto al evaluado al momento del trade.
        $simulation = (array) ($config['simulation'] ?? []);
        $execDriftPct = (bool) ($simulation['enabled'] ?? false)
            ? (float) ($simulation['max_exec_drift_pct'] ?? 0.0)
            : 0.0;
        $simulator = new ExecutionSimulator($wallets, $execDriftPct);

        $buffer = null;
        $recorder = $this->makeRecorder($config, $withPersistence, $buffer, $userId, $strategyId);
        // Los challengers shadow no publican al dashboard del usuario para no
        // contaminar el feed; el champion sí. El override sigue funcionando.
        $dashboardForVariant = ($strategyId !== null && ! $persistWallet) ? false : $withDashboard;
        $dashboard = $dashboardOverride ?? $this->makeDashboard($config, $dashboardForVariant, $userId);

        $engine = new ArbitrageEngine(
            store: $store,
            scanner: $scanner,
            liquidity: $liquidity,
            profitability: $profitability,
            walletValidator: $walletValidator,
            riskManager: $riskManager,
            simulator: $simulator,
            fees: $fees,
            recorder: $recorder,
            dashboard: $dashboard,
            maxBaseVolume: $maxVolume,
            minBaseVolume: $minVolume,
            logger: $this->logger,
            diagnostics: $diagnostics,
            discards: $metrics,
        );

        return new EngineRuntime(
            engine: $engine,
            wallets: $wallets,
            buffer: $buffer,
            metrics: $metrics,
            userId: $userId,
            strategyId: $strategyId,
            persistWallet: $persistWallet,
            configHash: $configHash,
            perturbator: MarketPerturbator::fromConfig($config),
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function makeRecorder(array $config, bool $withPersistence, ?PersistenceBuffer &$buffer, ?int $userId, ?int $strategyId = null): OpportunityRecorderInterface
    {
        $persistence = (array) ($config['persistence'] ?? []);
        if (! $withPersistence || ! (bool) ($persistence['enabled'] ?? true)) {
            return new NullOpportunityRecorder;
        }

        $buffer = new PersistenceBuffer(
            logger: $this->logger,
            flushSize: (int) ($persistence['flush_size'] ?? 50),
        );

        return new BufferedOpportunityRecorder(
            buffer: $buffer,
            recordDecisions: (array) ($persistence['record_decisions'] ?? ['execute', 'reject']),
            userId: $userId,
            strategyId: $strategyId,
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function makeDashboard(array $config, bool $withDashboard, ?int $userId): DashboardPublisherInterface
    {
        $dashboard = (array) ($config['dashboard'] ?? []);
        if (! $withDashboard || ! (bool) ($dashboard['enabled'] ?? true)) {
            return new NullDashboardPublisher;
        }

        // En modo multi-usuario publicamos a un canal privado y cacheamos el
        // snapshot con prefijo por usuario para que la API REST lo recupere.
        $channelName = $userId !== null
            ? ArbitrageCacheKeys::dashboardChannel($userId)
            : (string) ($dashboard['channel'] ?? 'arbitrage-dashboard');

        $snapshotPrefix = $userId !== null
            ? ArbitrageCacheKeys::snapshotPrefix($userId)
            : (string) ($dashboard['snapshot_cache_prefix'] ?? 'arbitrage:snapshot');

        return new ReverbDashboardPublisher(
            events: $this->events,
            cache: $this->cache,
            channelName: $channelName,
            maxBroadcastsPerSecond: (int) ($dashboard['max_broadcasts_per_second'] ?? 5),
            snapshotCachePrefix: $snapshotPrefix,
            snapshotTtlSeconds: (int) ($dashboard['snapshot_ttl_seconds'] ?? 30),
            privateChannel: $userId !== null,
        );
    }
}
