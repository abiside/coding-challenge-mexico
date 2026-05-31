<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Arbitrage\MeanReversion\Discovery\BinanceStreamHub;
use App\Arbitrage\MeanReversion\Discovery\SubscriptionManager;
use App\Arbitrage\MeanReversion\Discovery\VolatilityRanker;
use App\Arbitrage\MeanReversion\Stats\PriceWindowStore;
use App\Events\StrategyMetrics as StrategyMetricsEvent;
use App\Infrastructure\MarketData\Supervisor\BackoffStrategy;
use App\Infrastructure\MarketData\WebSocket\PawlWebSocketClient;
use App\Models\Strategy;
use App\Strategies\Engine\StrategyEngine;
use App\Strategies\Engine\StrategyFactory;
use App\Strategies\Engine\StrategyMetrics;
use App\Strategies\Execution\StrategyWallet;
use App\Strategies\Execution\TradingExecutionSimulator;
use App\Strategies\Execution\TradingPositionBook;
use App\Strategies\Features\FeatureEngine;
use App\Strategies\Features\VolumeTracker;
use App\Strategies\Realtime\StrategyRecorder;
use App\Strategies\Risk\CircuitBreaker;
use App\Strategies\Risk\RiskManager;
use App\Support\StrategyCacheKeys;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;
use Throwable;

/**
 * Worker MULTI-TENANT del módulo de Estrategias. Una sola conexión a Binance
 * (discovery !miniTicker@arr + books de profundidad + velas 1m compartidos) y N
 * engines AISLADOS, uno por cada instancia `strategies` de tipo trading activa:
 * cada uno con su billetera USDT, posiciones long/short, métricas y panel.
 *
 * Sustituye a `meanrev:run`: la reversión a la media es ahora una estrategia
 * más (algorithm=mean_reversion_long/short).
 */
class RunStrategiesWorker extends Command
{
    protected $signature = 'strategies:run
        {--duration= : Detener el worker tras N segundos (smoke tests)}';

    protected $description = 'Worker multi-usuario del módulo de estrategias (long/short simulado, Binance, USDT).';

    /**
     * @var array<int, array{user_id: int, engine: StrategyEngine, wallet: StrategyWallet, positions: TradingPositionBook, metrics: StrategyMetrics}>
     */
    private array $contexts = [];

    /** @var array<string, mixed> */
    private array $config = [];

    /** @var array<string, mixed> */
    private array $defaults = [];

    /** @var array<string, mixed> */
    private array $dashboard = [];

    private ?LoggerInterface $logger = null;

    private string $exchange = 'binance';

    private string $quote = 'USDT';

    private bool $diagnostics = false;

    private ?VolumeTracker $volume = null;

    public function handle(): int
    {
        $this->config = (array) config('strategies');

        if (! (bool) ($this->config['enabled'] ?? false)) {
            $this->warn('strategies:run está deshabilitado (STRATEGIES_ENABLED=false).');

            return self::SUCCESS;
        }

        $this->logger = Log::channel((string) ($this->config['log_channel'] ?? 'meanrev'));
        $this->exchange = (string) ($this->config['exchange'] ?? 'binance');
        $this->quote = strtoupper((string) ($this->config['quote'] ?? 'USDT'));
        $this->diagnostics = (bool) ($this->config['diagnostics'] ?? false);
        $this->defaults = (array) ($this->config['defaults'] ?? []);
        $this->dashboard = (array) ($this->config['dashboard'] ?? []);
        $discovery = (array) ($this->config['discovery'] ?? []);

        $loop = Loop::get();
        $client = new PawlWebSocketClient($loop);

        $hub = new BinanceStreamHub(
            loop: $loop,
            client: $client,
            endpoint: (string) ($this->config['endpoint'] ?? 'wss://stream.binance.com:9443/stream'),
            backoff: new BackoffStrategy(),
            logger: $this->logger,
            orderBookDepth: (int) ($discovery['orderbook_depth'] ?? 20),
            orderBookSpeed: (string) ($discovery['orderbook_speed'] ?? '100ms'),
            exchange: $this->exchange,
            subscribeKlines: (bool) ($discovery['subscribe_klines'] ?? true),
            klineInterval: (string) ($discovery['kline_interval'] ?? '1m'),
        );

        $ranker = new VolatilityRanker(
            windowMs: (int) ($discovery['window_seconds'] ?? 3600) * 1000,
            minVolatilityPct: (float) ($discovery['min_volatility_pct'] ?? 0.3),
            minSamples: (int) ($discovery['min_samples'] ?? 20),
            quote: $this->quote,
            excludeLeveraged: (bool) ($discovery['exclude_leveraged'] ?? true),
        );

        $this->volume = new VolumeTracker($this->quote);

        $subscriptions = new SubscriptionManager(
            ranker: $ranker,
            heldSymbolsResolver: fn (): array => $this->unionHeldSymbols(),
            hub: $hub,
            logger: $this->logger,
            topN: (int) ($discovery['top_n'] ?? 15),
            maxSubscriptions: (int) ($discovery['max_subscriptions'] ?? 40),
            minSubscriptionMs: (int) ($discovery['min_subscription_ms'] ?? 30000),
            diagnostics: $this->diagnostics,
        );

        $hub->onAllTickers(function (array $tickers, int $nowMs) use ($ranker): void {
            $ranker->ingest($tickers, $nowMs);
        });
        $hub->onKline(function (array $data): void {
            $this->volume?->ingest($data);
        });
        $hub->onDepth(function ($snapshot): void {
            foreach ($this->contexts as $ctx) {
                $ctx['engine']->onOrderBook($snapshot);
            }
        });

        $hub->start();

        $refreshMs = max(500, (int) ($discovery['refresh_ms'] ?? 4000));
        $loop->addPeriodicTimer($refreshMs / 1000, function () use ($subscriptions): void {
            $subscriptions->reconcile((int) (microtime(true) * 1000), $this->contexts !== []);
        });

        $loop->addPeriodicTimer(3.0, function (): void {
            $this->reconcileInstances();
        });
        $this->reconcileInstances();

        $loop->addPeriodicTimer(2.0, function (): void {
            $this->processResetRequests();
        });

        // Barrido de timeouts (cierra posiciones vencidas aunque el símbolo no tickee).
        $loop->addPeriodicTimer(5.0, function (): void {
            $nowMs = (int) (microtime(true) * 1000);
            foreach ($this->contexts as $ctx) {
                $ctx['engine']->sweepTimeouts($nowMs);
            }
        });

        $this->scheduleHeartbeat();
        $this->registerSignalHandlers();
        $this->scheduleDuration();

        $this->info('strategies:run iniciado (multi-tenant).');
        $this->info(sprintf('Exchange=%s quote=%s · instancias activas=%d', $this->exchange, $this->quote, count($this->contexts)));

        $loop->run();

        return self::SUCCESS;
    }

    private function reconcileInstances(): void
    {
        try {
            $active = Strategy::query()
                ->where('type', Strategy::TYPE_TRADING)
                ->where('status', Strategy::STATUS_ACTIVE)
                ->get();
        } catch (Throwable $e) {
            $this->logger?->warning('[strategies][reconcile] error leyendo instancias', ['error' => $e->getMessage()]);

            return;
        }

        $activeIds = [];
        foreach ($active as $strategy) {
            $activeIds[(int) $strategy->id] = true;
            if (! isset($this->contexts[(int) $strategy->id])) {
                $this->openContext($strategy);
            }
        }

        foreach (array_keys($this->contexts) as $strategyId) {
            if (! isset($activeIds[$strategyId])) {
                $this->closeContext($strategyId);
            }
        }
    }

    private function openContext(Strategy $strategy): void
    {
        $strategyId = (int) $strategy->id;
        $userId = (int) $strategy->user_id;
        $params = array_merge($this->defaults, (array) ($strategy->config ?? []));
        $algorithm = (string) ($strategy->algorithm ?? 'mean_reversion_long');

        $initialUsdt = (float) ($strategy->initial_usdt ?: ($this->config['initial_balances']['USDT'] ?? 10000.0));

        $wallet = new StrategyWallet($initialUsdt);
        if (! empty($strategy->wallet_snapshot)) {
            $wallet->restore((array) $strategy->wallet_snapshot);
        }

        $positions = new TradingPositionBook();
        if (! empty($strategy->position_snapshot)) {
            $positions->restore((array) $strategy->position_snapshot);
        }

        $metrics = new StrategyMetrics((float) $strategy->realized_pnl);

        $windows = new PriceWindowStore(
            windowMs: (int) ($params['window_seconds'] ?? 3600) * 1000,
            minIntervalMs: (int) ($params['sample_interval_ms'] ?? 1000),
        );

        $simulator = new TradingExecutionSimulator(
            wallet: $wallet,
            positions: $positions,
            feeRate: (float) ($params['fee_rate'] ?? 0.001),
            fundingFeePct: (float) ($params['funding_fee_pct'] ?? 0.0),
        );

        $features = new FeatureEngine(
            exchange: $this->exchange,
            targetSizeUsdt: (float) ($params['slice_usdt'] ?? 200.0),
        );

        $risk = new RiskManager(
            minConfidence: (float) ($params['min_confidence'] ?? 0.55),
            maxSpreadPct: (float) ($params['max_spread_pct'] ?? 0.15),
            minLiquidityUsdt: (float) ($params['min_liquidity_usdt'] ?? 2000.0),
            maxBookAgeMs: (int) ($params['max_book_age_ms'] ?? 5000),
            maxOpenPositions: (int) ($params['max_open_positions'] ?? 10),
            feeRate: (float) ($params['fee_rate'] ?? 0.001),
        );

        $breaker = new CircuitBreaker(
            maxLossStreak: (int) ($params['max_loss_streak'] ?? 5),
            maxDailyDrawdownUsdt: (float) ($params['max_daily_drawdown_usdt'] ?? 1000.0),
        );

        $recorder = new StrategyRecorder(
            cache: app(Cache::class),
            events: app(Dispatcher::class),
            strategyId: $strategyId,
            userId: $userId,
            algorithm: $algorithm,
            channelName: StrategyCacheKeys::channel($userId),
            maxBroadcastsPerSecond: (int) ($this->dashboard['max_broadcasts_per_second'] ?? 5),
            snapshotTtlSeconds: (int) ($this->dashboard['snapshot_ttl_seconds'] ?? 30),
            recentLimit: (int) ($this->dashboard['recent_signals'] ?? 40),
            persistSignals: (bool) ($this->dashboard['persist_signals'] ?? true),
            persistPositions: (bool) ($this->dashboard['persist_positions'] ?? true),
            logger: $this->logger,
        );

        $engine = new StrategyEngine(
            features: $features,
            strategy: StrategyFactory::make($algorithm, $params),
            risk: $risk,
            breaker: $breaker,
            simulator: $simulator,
            wallet: $wallet,
            positions: $positions,
            metrics: $metrics,
            recorder: $recorder,
            windows: $windows,
            volume: $this->volume,
            sliceUsdt: (float) ($params['slice_usdt'] ?? 200.0),
            maxPositionUsdt: (float) ($params['max_position_usdt'] ?? 1000.0),
            maxTotalUsdt: (float) ($params['max_total_usdt'] ?? 8000.0),
            maxOpenPositions: (int) ($params['max_open_positions'] ?? 10),
            perSymbolCooldownMs: (int) ($params['per_symbol_cooldown_ms'] ?? 15000),
            evaluationIntervalMs: (int) ($params['evaluation_interval_ms'] ?? 1000),
            leverage: (float) ($params['leverage'] ?? 1.0),
            liquidationBufferPct: (float) ($params['liquidation_buffer_pct'] ?? 90.0),
            logger: $this->logger,
        );

        $this->contexts[$strategyId] = [
            'user_id' => $userId,
            'engine' => $engine,
            'wallet' => $wallet,
            'positions' => $positions,
            'metrics' => $metrics,
        ];

        $this->logger?->info('[strategies][context] instancia iniciada', [
            'strategy_id' => $strategyId,
            'algorithm' => $algorithm,
            'initial_usdt' => $initialUsdt,
        ]);
    }

    private function closeContext(int $strategyId): void
    {
        $this->persistContext($strategyId);
        unset($this->contexts[$strategyId]);
        $this->logger?->info('[strategies][context] instancia detenida', ['strategy_id' => $strategyId]);
    }

    private function processResetRequests(): void
    {
        foreach (array_keys($this->contexts) as $strategyId) {
            $key = StrategyCacheKeys::resetRequest($strategyId);
            try {
                if (app(Cache::class)->get($key) === null) {
                    continue;
                }
                app(Cache::class)->forget($key);
            } catch (Throwable $e) {
                continue;
            }
            $this->resetContext($strategyId);
        }
    }

    private function resetContext(int $strategyId): void
    {
        $ctx = $this->contexts[$strategyId] ?? null;
        if ($ctx === null) {
            return;
        }

        $initialUsdt = (float) ($this->config['initial_balances']['USDT'] ?? 10000.0);
        try {
            $strategy = Strategy::find($strategyId);
            if ($strategy !== null && (float) $strategy->initial_usdt > 0.0) {
                $initialUsdt = (float) $strategy->initial_usdt;
            }
        } catch (Throwable $e) {
            // Usa el default si la DB falla.
        }

        $ctx['engine']->reset($initialUsdt);
        $this->persistContext($strategyId);
        $this->emitContext($strategyId);

        $this->logger?->info('[strategies][reset] ejercicio reiniciado', ['strategy_id' => $strategyId]);
    }

    /**
     * @return array<string, bool>
     */
    private function unionHeldSymbols(): array
    {
        $held = [];
        foreach ($this->contexts as $ctx) {
            foreach ($ctx['positions']->all() as $pos) {
                $held[$pos['symbol']] = true;
            }
        }

        return $held;
    }

    private function scheduleHeartbeat(): void
    {
        $interval = (int) ($this->config['heartbeat_interval_seconds'] ?? 15);
        if ($interval <= 0) {
            return;
        }

        Loop::get()->addPeriodicTimer($interval, function (): void {
            foreach (array_keys($this->contexts) as $strategyId) {
                $this->emitContext($strategyId);
                $this->persistContext($strategyId);
            }
        });
    }

    private function emitContext(int $strategyId): void
    {
        $ctx = $this->contexts[$strategyId] ?? null;
        if ($ctx === null) {
            return;
        }

        $valuation = $ctx['engine']->valuation();

        $payload = array_merge($ctx['metrics']->toArray(), [
            'strategy_id' => $strategyId,
            'user_id' => $ctx['user_id'],
            'exchange' => $this->exchange,
            'quote' => $this->quote,
            'open_positions' => $ctx['positions']->count(),
            'usdt_balance' => round($ctx['wallet']->available(), 4),
            'deployed_value' => $valuation['deployed_value'],
            'deployed_cost' => $valuation['deployed_cost'],
            'unrealized_pnl' => $valuation['unrealized_pnl'],
            'equity_value' => $valuation['equity_value'],
            'positions' => $valuation['positions'],
            'circuit_breaker' => $ctx['engine']->circuitBreakerReason(),
            'server_time_ms' => (int) (microtime(true) * 1000),
            'updated_at' => now()->toIso8601String(),
        ]);

        try {
            app(Cache::class)->put(
                StrategyCacheKeys::metrics($strategyId),
                $payload,
                max(5, (int) ($this->dashboard['snapshot_ttl_seconds'] ?? 30)),
            );
            app(Dispatcher::class)->dispatch(
                new StrategyMetricsEvent($payload, StrategyCacheKeys::channel((int) $ctx['user_id'])),
            );
        } catch (Throwable $e) {
            $this->logger?->warning('[strategies][dashboard] no se pudo publicar métricas', [
                'strategy_id' => $strategyId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function persistContext(int $strategyId): void
    {
        $ctx = $this->contexts[$strategyId] ?? null;
        if ($ctx === null) {
            return;
        }

        try {
            Strategy::where('id', $strategyId)->update([
                'wallet_snapshot' => $ctx['wallet']->snapshot(),
                'position_snapshot' => $ctx['positions']->snapshot(),
                'realized_pnl' => round($ctx['metrics']->realizedPnl(), 8),
            ]);
        } catch (Throwable $e) {
            $this->logger?->warning('[strategies][persist] no se pudo guardar snapshot', [
                'strategy_id' => $strategyId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function persistAll(): void
    {
        foreach (array_keys($this->contexts) as $strategyId) {
            $this->persistContext($strategyId);
        }
    }

    private function registerSignalHandlers(): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }

        $handler = function (int $signal): void {
            $this->warn(sprintf('Señal %d recibida, cerrando worker...', $signal));
            $this->persistAll();
            Loop::stop();
        };

        $loop = Loop::get();
        $loop->addSignal(SIGINT, $handler);
        $loop->addSignal(SIGTERM, $handler);
    }

    private function scheduleDuration(): void
    {
        $duration = (int) ($this->option('duration') ?? 0);
        if ($duration <= 0) {
            return;
        }

        Loop::get()->addTimer($duration, function (): void {
            $this->warn('Duration alcanzada, deteniendo worker...');
            $this->persistAll();
            Loop::stop();
        });
    }
}
