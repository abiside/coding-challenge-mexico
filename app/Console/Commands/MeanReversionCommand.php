<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Arbitrage\Execution\WalletManager;
use App\Arbitrage\MeanReversion\Contracts\SignalRecorderInterface;
use App\Arbitrage\MeanReversion\Discovery\BinanceStreamHub;
use App\Arbitrage\MeanReversion\Discovery\SubscriptionManager;
use App\Arbitrage\MeanReversion\Discovery\VolatilityRanker;
use App\Arbitrage\MeanReversion\Engine\MeanReversionEngine;
use App\Arbitrage\MeanReversion\Engine\SignalEvaluator;
use App\Arbitrage\MeanReversion\Execution\MeanReversionExecutionSimulator;
use App\Arbitrage\MeanReversion\Execution\PositionBook;
use App\Arbitrage\MeanReversion\Persistence\CompositeSignalRecorder;
use App\Arbitrage\MeanReversion\Persistence\DatabaseSignalRecorder;
use App\Arbitrage\MeanReversion\Persistence\LoggerSignalRecorder;
use App\Arbitrage\MeanReversion\Realtime\BroadcastSignalRecorder;
use App\Arbitrage\MeanReversion\Stats\PriceWindowStore;
use App\Arbitrage\Realtime\MetricsAggregator;
use App\Events\MeanReversionMetrics;
use App\Infrastructure\MarketData\Supervisor\BackoffStrategy;
use App\Infrastructure\MarketData\WebSocket\PawlWebSocketClient;
use App\Models\MeanReversionSession;
use App\Support\MeanReversionCacheKeys;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;
use Throwable;

/**
 * Worker MULTI-TENANT de la estrategia de reversión a la media.
 *
 * Un solo proceso y UNA sola conexión a Binance (discovery !miniTicker@arr +
 * books de profundidad compartidos), pero N engines AISLADOS por usuario: cada
 * sesión activa (tabla mean_reversion_sessions) tiene su propia billetera,
 * posiciones, métricas, persistencia (user_id) y canal privado. El worker
 * reconcilia las sesiones en caliente: levanta un engine cuando un usuario
 * inicia su modo y lo derriba cuando lo detiene.
 *
 * Los books se reenvían (fan-out) a todos los engines activos; el set de
 * streams deseado es top-N volátiles ∪ inventario de TODOS los usuarios.
 */
class MeanReversionCommand extends Command
{
    protected $signature = 'meanrev:run
        {--duration= : Detener el worker tras N segundos (smoke tests)}';

    protected $description = 'Worker multi-usuario de reversión a la media (Binance spot, USDT) con sockets dinámicos.';

    /**
     * Contextos activos por usuario.
     *
     * @var array<int, array{session_id: int, engine: MeanReversionEngine, wallets: WalletManager, positions: PositionBook, metrics: MetricsAggregator}>
     */
    private array $contexts = [];

    /** @var array<string, mixed> */
    private array $config = [];

    /** @var array<string, mixed> */
    private array $strategyDefaults = [];

    /** @var array<string, mixed> */
    private array $dashboard = [];

    private ?LoggerInterface $logger = null;

    private string $exchange = 'binance';

    private string $quote = 'USDT';

    private bool $diagnostics = false;

    public function handle(): int
    {
        $this->config = (array) config('meanreversion');

        if (! (bool) ($this->config['enabled'] ?? false)) {
            $this->warn('meanrev:run está deshabilitado (MEANREV_ENABLED=false).');

            return self::SUCCESS;
        }

        $this->logger = Log::channel((string) ($this->config['log_channel'] ?? 'meanrev'));
        $this->exchange = (string) ($this->config['exchange'] ?? 'binance');
        $this->quote = strtoupper((string) ($this->config['quote'] ?? 'USDT'));
        $this->diagnostics = (bool) ($this->config['diagnostics'] ?? false);
        $this->strategyDefaults = (array) ($this->config['strategy'] ?? []);
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
        );

        $ranker = new VolatilityRanker(
            windowMs: (int) ($discovery['window_seconds'] ?? 3600) * 1000,
            minVolatilityPct: (float) ($discovery['min_volatility_pct'] ?? 0.3),
            minSamples: (int) ($discovery['min_samples'] ?? 20),
            quote: $this->quote,
            excludeLeveraged: (bool) ($discovery['exclude_leveraged'] ?? true),
        );

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
        // Fan-out: cada book llega a TODOS los engines de usuario activos.
        $hub->onDepth(function ($snapshot): void {
            foreach ($this->contexts as $ctx) {
                $ctx['engine']->onOrderBook($snapshot);
            }
        });

        $hub->start();

        // Reconciliación de suscripciones (top-N ∪ inventario de todos).
        $refreshMs = max(500, (int) ($discovery['refresh_ms'] ?? 4000));
        $loop->addPeriodicTimer($refreshMs / 1000, function () use ($subscriptions): void {
            $subscriptions->reconcile((int) (microtime(true) * 1000), $this->contexts !== []);
        });

        // Reconciliación de sesiones por usuario (levantar/derribar engines).
        $loop->addPeriodicTimer(3.0, function (): void {
            $this->reconcileSessions();
        });
        $this->reconcileSessions();

        $this->scheduleHeartbeat();
        $this->registerSignalHandlers();
        $this->scheduleDuration();

        $this->info('meanrev:run iniciado (multi-tenant).');
        $this->info(sprintf('Exchange=%s quote=%s · sesiones activas=%d', $this->exchange, $this->quote, count($this->contexts)));

        $loop->run();

        return self::SUCCESS;
    }

    /**
     * Levanta engines para sesiones activas nuevas y derriba los de sesiones
     * detenidas/eliminadas. Tolerante a fallos de DB (no debe tumbar el loop).
     */
    private function reconcileSessions(): void
    {
        try {
            $active = MeanReversionSession::query()
                ->where('status', MeanReversionSession::STATUS_ACTIVE)
                ->get();
        } catch (Throwable $e) {
            $this->logger?->warning('[meanrev][reconcile] error leyendo sesiones', ['error' => $e->getMessage()]);

            return;
        }

        $activeIds = [];
        foreach ($active as $session) {
            $activeIds[(int) $session->user_id] = true;
            if (! isset($this->contexts[(int) $session->user_id])) {
                $this->openContext($session);
            }
        }

        // Derribar contextos cuyas sesiones ya no están activas.
        foreach (array_keys($this->contexts) as $userId) {
            if (! isset($activeIds[$userId])) {
                $this->closeContext($userId);
            }
        }
    }

    private function openContext(MeanReversionSession $session): void
    {
        $userId = (int) $session->user_id;
        $params = array_merge($this->strategyDefaults, (array) ($session->params ?? []));

        $initialUsdt = (float) ($session->initial_usdt ?: 10000.0);
        $walletInit = ! empty($session->wallet_snapshot)
            ? (array) $session->wallet_snapshot
            : [$this->exchange => [$this->quote => $initialUsdt]];

        $wallets = new WalletManager($walletInit);

        $positions = new PositionBook();
        if (! empty($session->position_snapshot)) {
            $positions->restore((array) $session->position_snapshot);
        }

        $metrics = new MetricsAggregator();

        $windows = new PriceWindowStore(
            windowMs: (int) ($params['window_seconds'] ?? 3600) * 1000,
            minIntervalMs: (int) ($params['sample_interval_ms'] ?? 1000),
        );

        $simulator = new MeanReversionExecutionSimulator(
            wallets: $wallets,
            positions: $positions,
            quoteAsset: $this->quote,
            feeRate: (float) ($params['fee_rate'] ?? 0.001),
            execDriftPct: (float) ($params['exec_drift_pct'] ?? 0.0),
        );

        $evaluator = new SignalEvaluator(
            entryZ: (float) ($params['entry_z'] ?? 1.5),
            exitZ: (float) ($params['exit_z'] ?? 1.0),
            minVolatilityPct: (float) ($params['min_volatility_pct'] ?? 0.3),
            takeProfitPct: (float) ($params['take_profit_pct'] ?? 1.5),
            stopLossPct: (float) ($params['stop_loss_pct'] ?? 3.0),
            minSamples: (int) ($params['min_samples'] ?? 60),
            minCoverageMs: (int) ($params['min_coverage_ms'] ?? 600000),
        );

        $engine = new MeanReversionEngine(
            windows: $windows,
            evaluator: $evaluator,
            positions: $positions,
            wallets: $wallets,
            simulator: $simulator,
            recorder: $this->buildRecorder($userId),
            metrics: $metrics,
            exchange: $this->exchange,
            quoteAsset: $this->quote,
            sliceUsdt: (float) ($params['slice_usdt'] ?? 200.0),
            maxPositionUsdt: (float) ($params['max_position_usdt'] ?? 1000.0),
            maxTotalUsdt: (float) ($params['max_total_usdt'] ?? 8000.0),
            maxOpenPositions: (int) ($params['max_open_positions'] ?? 10),
            perSymbolCooldownMs: (int) ($params['per_symbol_cooldown_ms'] ?? 15000),
            minRoundtripMargin: (float) ($params['min_roundtrip_margin'] ?? 0.001),
            feeRate: (float) ($params['fee_rate'] ?? 0.001),
            logger: $this->logger,
            diagnostics: $this->diagnostics,
        );

        $this->contexts[$userId] = [
            'session_id' => (int) $session->id,
            'engine' => $engine,
            'wallets' => $wallets,
            'positions' => $positions,
            'metrics' => $metrics,
        ];

        $this->logger?->info('[meanrev][context] sesión iniciada', [
            'user_id' => $userId,
            'initial_usdt' => $initialUsdt,
            'rehydrated' => ! empty($session->wallet_snapshot),
        ]);
    }

    private function closeContext(int $userId): void
    {
        $this->persistContext($userId);
        unset($this->contexts[$userId]);
        $this->logger?->info('[meanrev][context] sesión detenida', ['user_id' => $userId]);
    }

    /**
     * Recorder por usuario: log + persistencia DB (user_id) + broadcast al canal
     * privado del usuario.
     */
    private function buildRecorder(int $userId): SignalRecorderInterface
    {
        $recorders = [
            new LoggerSignalRecorder($this->logger, $this->diagnostics ? ['execute', 'reject'] : ['execute']),
        ];

        if ((bool) ($this->dashboard['persist_trades'] ?? true)) {
            $recorders[] = new DatabaseSignalRecorder($userId, $this->logger);
        }

        $recorders[] = new BroadcastSignalRecorder(
            events: app(Dispatcher::class),
            cache: app(Cache::class),
            userId: $userId,
            channelName: MeanReversionCacheKeys::channel($userId),
            maxBroadcastsPerSecond: (int) ($this->dashboard['max_broadcasts_per_second'] ?? 5),
            snapshotTtlSeconds: (int) ($this->dashboard['snapshot_ttl_seconds'] ?? 30),
            recentLimit: (int) ($this->dashboard['recent_signals'] ?? 40),
        );

        return new CompositeSignalRecorder(...$recorders);
    }

    /**
     * Unión de símbolos con inventario de todas las sesiones activas, para que
     * el SubscriptionManager nunca cierre un book que algún usuario necesita.
     *
     * @return array<string, bool>
     */
    private function unionHeldSymbols(): array
    {
        $held = [];
        foreach ($this->contexts as $ctx) {
            $assets = $ctx['wallets']->snapshot()[strtolower($this->exchange)] ?? [];
            foreach ($assets as $asset => $amount) {
                $asset = strtoupper((string) $asset);
                if ($asset === $this->quote) {
                    continue;
                }
                if ((float) $amount > 1e-8) {
                    $held[$asset.'/'.$this->quote] = true;
                }
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
            foreach (array_keys($this->contexts) as $userId) {
                $this->emitContext($userId);
                $this->persistContext($userId);
            }
        });
    }

    /** Cachea + broadcast del snapshot de métricas de un usuario. */
    private function emitContext(int $userId): void
    {
        $ctx = $this->contexts[$userId] ?? null;
        if ($ctx === null) {
            return;
        }

        $payload = array_merge($ctx['metrics']->toArray(), [
            'user_id' => $userId,
            'exchange' => $this->exchange,
            'quote' => $this->quote,
            'open_positions' => $ctx['positions']->openCount(),
            'deployed_usdt' => round($ctx['positions']->totalCostBasis(), 4),
            'positions' => $ctx['positions']->snapshot(),
            'wallet' => $ctx['wallets']->snapshot(),
            'usdt_balance' => round($ctx['wallets']->available($this->exchange, $this->quote), 4),
            'server_time_ms' => (int) (microtime(true) * 1000),
            'updated_at' => now()->toIso8601String(),
        ]);

        $this->logger?->info('[meanrev][heartbeat]', ['user_id' => $userId] + $ctx['metrics']->toArray());

        try {
            app(Cache::class)->put(
                MeanReversionCacheKeys::metrics($userId),
                $payload,
                max(5, (int) ($this->dashboard['snapshot_ttl_seconds'] ?? 30)),
            );
            app(Dispatcher::class)->dispatch(
                new MeanReversionMetrics($payload, MeanReversionCacheKeys::channel($userId)),
            );
        } catch (Throwable $e) {
            $this->logger?->warning('[meanrev][dashboard] no se pudo publicar métricas', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Persiste el snapshot (billetera + posiciones + P&L) en la sesión. */
    private function persistContext(int $userId): void
    {
        $ctx = $this->contexts[$userId] ?? null;
        if ($ctx === null) {
            return;
        }

        try {
            MeanReversionSession::where('user_id', $userId)->update([
                'wallet_snapshot' => $ctx['wallets']->snapshot(),
                'position_snapshot' => $ctx['positions']->snapshot(),
                'realized_pnl' => round($ctx['metrics']->realizedPnl(), 8),
            ]);
        } catch (Throwable $e) {
            $this->logger?->warning('[meanrev][persist] no se pudo guardar snapshot', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function persistAll(): void
    {
        foreach (array_keys($this->contexts) as $userId) {
            $this->persistContext($userId);
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
