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
use App\Arbitrage\Triangular\Contracts\CycleDashboardPublisherInterface;
use App\Arbitrage\Triangular\Contracts\CycleRecorderInterface;
use App\Arbitrage\Triangular\Engine\CycleEngine;
use App\Arbitrage\Triangular\Engine\CycleLiquidityCalculator;
use App\Arbitrage\Triangular\Engine\CycleProfitabilityCalculator;
use App\Arbitrage\Triangular\Engine\CycleScanner;
use App\Arbitrage\Triangular\Execution\CycleExecutionSimulator;
use App\Arbitrage\Triangular\Execution\CycleWalletValidator;
use App\Arbitrage\Triangular\Graph\GraphBuilder;
use App\Arbitrage\Triangular\Persistence\BufferedCycleRecorder;
use App\Arbitrage\Triangular\Persistence\NullCycleRecorder;
use App\Arbitrage\Triangular\Realtime\NullCycleDashboardPublisher;
use App\Arbitrage\Triangular\Realtime\ReverbCycleDashboardPublisher;
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

        $cycleEngine = $this->makeCycleEngine(
            config: $config,
            store: $store,
            fees: $fees,
            wallets: $wallets,
            metrics: $metrics,
            diagnostics: $diagnostics,
            freshnessMs: $freshnessMs,
            buffer: $buffer,
            withPersistence: $withPersistence,
            withDashboard: $dashboardForVariant,
            userId: $userId,
            strategyId: $strategyId,
            dashboardOverride: $dashboardOverride,
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
            cycleEngine: $cycleEngine,
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
     * Ensambla el `CycleEngine` si la configuración lo habilita. Comparte
     * `store`, `wallets` y `fees` con el engine de 2 patas para operar sobre
     * la misma fuente de verdad. Los guards/risk manager se construyen aparte
     * con los umbrales específicos del módulo triangular (`thresholds`) pero
     * reutilizan las mismas implementaciones.
     *
     * @param  array<string, mixed>  $config
     */
    private function makeCycleEngine(
        array $config,
        \App\Arbitrage\MarketData\OrderBookStore $store,
        \App\Arbitrage\Engine\FeeSchedule $fees,
        \App\Arbitrage\Execution\WalletManager $wallets,
        \App\Arbitrage\Realtime\MetricsAggregator $metrics,
        bool $diagnostics,
        int $freshnessMs,
        ?\App\Arbitrage\Persistence\PersistenceBuffer $buffer,
        bool $withPersistence,
        bool $withDashboard,
        ?int $userId,
        ?int $strategyId,
        ?DashboardPublisherInterface $dashboardOverride,
    ): ?CycleEngine {
        $triangular = (array) ($config['triangular'] ?? []);
        if (! (bool) ($triangular['enabled'] ?? false)) {
            return null;
        }

        $startAssets = array_values(array_filter(array_map('trim', (array) ($triangular['start_assets'] ?? ['USDT', 'USD']))));
        if ($startAssets === []) {
            return null;
        }

        $thresholds = (array) ($triangular['thresholds'] ?? []);
        $minStart = (float) ($thresholds['min_start_amount'] ?? 10.0);
        $maxStart = (float) ($thresholds['max_start_amount'] ?? 10000.0);

        $builder = new GraphBuilder(
            store: $store,
            fees: $fees,
            freshnessMs: $freshnessMs,
            crossExchange: (bool) ($triangular['cross_exchange'] ?? true),
            transferCost: (float) ($triangular['transfer_cost'] ?? 0.0),
        );

        $scanner = new CycleScanner(
            builder: $builder,
            startAssets: $startAssets,
            maxCycleLength: max(2, (int) ($triangular['max_cycle_length'] ?? 3)),
            logger: $this->logger,
            diagnostics: $diagnostics,
            discards: $metrics,
        );

        $liquidity = new CycleLiquidityCalculator;
        $profitability = new CycleProfitabilityCalculator(
            latencyPenaltyPerMs: (float) (($config['latency']['penalty_per_ms']) ?? 0.0),
            fixedCost: (float) ($config['fixed_cost'] ?? 0.0),
        );

        $walletValidator = new CycleWalletValidator($wallets);

        $cb = $config['circuit_breaker'] ?? [];
        $circuitBreaker = new \App\Arbitrage\Risk\CircuitBreaker(
            enabled: (bool) ($cb['enabled'] ?? true),
            failureThreshold: (int) ($cb['failure_threshold'] ?? 10),
            cooldownMs: (int) ($cb['cooldown_ms'] ?? 5000),
        );

        $guards = [
            new FreshnessGuard($freshnessMs),
            new MinVolumeGuard($minStart),
            new LatencyGuard((int) (($config['latency']['max_ms']) ?? 1500)),
            new MinProfitGuard(
                minNetProfit: (float) ($thresholds['min_net_profit'] ?? 0.0),
                minNetMargin: (float) ($thresholds['min_net_margin'] ?? 0.0),
            ),
        ];
        $riskManager = new RiskManager($guards, $circuitBreaker);

        $simulation = (array) ($config['simulation'] ?? []);
        $execDriftPct = (bool) ($simulation['enabled'] ?? false)
            ? (float) ($simulation['max_exec_drift_pct'] ?? 0.0)
            : 0.0;
        $simulator = new CycleExecutionSimulator($wallets, $execDriftPct);

        $recorder = $this->makeCycleRecorder($config, $withPersistence, $buffer, $userId, $strategyId);
        $dashboard = $this->makeCycleDashboard($config, $withDashboard, $userId);

        return new CycleEngine(
            store: $store,
            scanner: $scanner,
            liquidity: $liquidity,
            profitability: $profitability,
            walletValidator: $walletValidator,
            riskManager: $riskManager,
            simulator: $simulator,
            recorder: $recorder,
            dashboard: $dashboard,
            maxStartAmount: $maxStart,
            minStartAmount: $minStart,
            logger: $this->logger,
            diagnostics: $diagnostics,
            discards: $metrics,
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function makeCycleRecorder(
        array $config,
        bool $withPersistence,
        ?\App\Arbitrage\Persistence\PersistenceBuffer $buffer,
        ?int $userId,
        ?int $strategyId,
    ): CycleRecorderInterface {
        $persistence = (array) ($config['persistence'] ?? []);
        if (! $withPersistence || ! (bool) ($persistence['enabled'] ?? true) || $buffer === null) {
            return new NullCycleRecorder;
        }

        return new BufferedCycleRecorder(
            buffer: $buffer,
            recordDecisions: (array) ($persistence['record_decisions'] ?? ['execute', 'reject']),
            userId: $userId,
            strategyId: $strategyId,
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function makeCycleDashboard(array $config, bool $withDashboard, ?int $userId): CycleDashboardPublisherInterface
    {
        $dashboard = (array) ($config['dashboard'] ?? []);
        if (! $withDashboard || ! (bool) ($dashboard['enabled'] ?? true)) {
            return new NullCycleDashboardPublisher;
        }

        $channelName = $userId !== null
            ? ArbitrageCacheKeys::dashboardChannel($userId)
            : (string) ($dashboard['channel'] ?? 'arbitrage-dashboard');

        $snapshotPrefix = $userId !== null
            ? ArbitrageCacheKeys::snapshotPrefix($userId)
            : (string) ($dashboard['snapshot_cache_prefix'] ?? 'arbitrage:snapshot');

        return new ReverbCycleDashboardPublisher(
            events: $this->events,
            cache: $this->cache,
            channelName: $channelName,
            maxBroadcastsPerSecond: (int) ($dashboard['max_broadcasts_per_second'] ?? 5),
            snapshotCachePrefix: $snapshotPrefix,
            snapshotTtlSeconds: (int) ($dashboard['snapshot_ttl_seconds'] ?? 30),
            privateChannel: $userId !== null,
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
