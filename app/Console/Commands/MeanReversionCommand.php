<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Arbitrage\Execution\WalletManager;
use App\Arbitrage\MeanReversion\Discovery\BinanceStreamHub;
use App\Arbitrage\MeanReversion\Discovery\SubscriptionManager;
use App\Arbitrage\MeanReversion\Discovery\VolatilityRanker;
use App\Arbitrage\MeanReversion\Engine\MeanReversionEngine;
use App\Arbitrage\MeanReversion\Engine\SignalEvaluator;
use App\Arbitrage\MeanReversion\Execution\MeanReversionExecutionSimulator;
use App\Arbitrage\MeanReversion\Execution\PositionBook;
use App\Arbitrage\MeanReversion\Persistence\LoggerSignalRecorder;
use App\Arbitrage\MeanReversion\Stats\PriceWindowStore;
use App\Arbitrage\Realtime\MetricsAggregator;
use App\Infrastructure\MarketData\Supervisor\BackoffStrategy;
use App\Infrastructure\MarketData\WebSocket\PawlWebSocketClient;
use App\Models\WalletBalance;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;

/**
 * Worker independiente de la estrategia de reversión a la media.
 *
 * NO comparte proceso ni feed con `arbitrage:run`. Abre una sola conexión a
 * Binance, descubre las monedas más volátiles de la última hora vía
 * !ticker@arr y administra dinámicamente qué streams de profundidad mantener
 * abiertos (top-N volátiles ∪ monedas con inventario). Sobre esos books corre
 * el motor de reversión a la media con su billetera simulada aislada.
 */
class MeanReversionCommand extends Command
{
    protected $signature = 'meanrev:run
        {--duration= : Detener el worker tras N segundos (smoke tests)}';

    protected $description = 'Worker de estrategia de reversión a la media (Binance spot, USDT) con sockets dinámicos.';

    private ?WalletManager $wallets = null;

    private ?PositionBook $positions = null;

    private ?MetricsAggregator $metrics = null;

    private ?int $persistUserId = null;

    public function handle(): int
    {
        $config = (array) config('meanreversion');

        if (! (bool) ($config['enabled'] ?? false)) {
            $this->warn('meanrev:run está deshabilitado (MEANREV_ENABLED=false).');

            return self::SUCCESS;
        }

        // Log dedicado con rotación horaria, aislado de laravel.log.
        $logger = Log::channel((string) ($config['log_channel'] ?? 'meanrev'));

        $exchange = (string) ($config['exchange'] ?? 'binance');
        $quote = strtoupper((string) ($config['quote'] ?? 'USDT'));
        $diagnostics = (bool) ($config['diagnostics'] ?? false);
        $discovery = (array) ($config['discovery'] ?? []);
        $strategy = (array) ($config['strategy'] ?? []);
        $persistence = (array) ($config['persistence'] ?? []);

        $loop = Loop::get();
        $client = new PawlWebSocketClient($loop);
        $backoff = new BackoffStrategy();

        $hub = new BinanceStreamHub(
            loop: $loop,
            client: $client,
            endpoint: (string) ($config['endpoint'] ?? 'wss://stream.binance.com:9443/stream'),
            backoff: $backoff,
            logger: $logger,
            orderBookDepth: (int) ($discovery['orderbook_depth'] ?? 20),
            orderBookSpeed: (string) ($discovery['orderbook_speed'] ?? '100ms'),
            exchange: $exchange,
        );

        $this->wallets = new WalletManager([
            $exchange => (array) ($config['initial_balances'] ?? ['USDT' => 10000.0]),
        ]);
        $this->positions = new PositionBook();
        $this->metrics = new MetricsAggregator();

        $windowStore = new PriceWindowStore(
            windowMs: (int) ($strategy['window_seconds'] ?? 3600) * 1000,
            minIntervalMs: (int) ($strategy['sample_interval_ms'] ?? 1000),
        );

        $simulator = new MeanReversionExecutionSimulator(
            wallets: $this->wallets,
            positions: $this->positions,
            quoteAsset: $quote,
            feeRate: (float) ($strategy['fee_rate'] ?? 0.001),
            execDriftPct: (float) ($strategy['exec_drift_pct'] ?? 0.0),
        );

        $evaluator = new SignalEvaluator(
            entryZ: (float) ($strategy['entry_z'] ?? 1.5),
            exitZ: (float) ($strategy['exit_z'] ?? 1.0),
            minVolatilityPct: (float) ($strategy['min_volatility_pct'] ?? 0.3),
            takeProfitPct: (float) ($strategy['take_profit_pct'] ?? 1.5),
            stopLossPct: (float) ($strategy['stop_loss_pct'] ?? 3.0),
            minSamples: (int) ($strategy['min_samples'] ?? 60),
            minCoverageMs: (int) ($strategy['min_coverage_ms'] ?? 600000),
        );

        $engine = new MeanReversionEngine(
            windows: $windowStore,
            evaluator: $evaluator,
            positions: $this->positions,
            wallets: $this->wallets,
            simulator: $simulator,
            recorder: new LoggerSignalRecorder($logger, $diagnostics ? ['execute', 'reject'] : ['execute']),
            metrics: $this->metrics,
            exchange: $exchange,
            quoteAsset: $quote,
            sliceUsdt: (float) ($strategy['slice_usdt'] ?? 200.0),
            maxPositionUsdt: (float) ($strategy['max_position_usdt'] ?? 1000.0),
            maxTotalUsdt: (float) ($strategy['max_total_usdt'] ?? 8000.0),
            maxOpenPositions: (int) ($strategy['max_open_positions'] ?? 10),
            perSymbolCooldownMs: (int) ($strategy['per_symbol_cooldown_ms'] ?? 15000),
            minRoundtripMargin: (float) ($strategy['min_roundtrip_margin'] ?? 0.001),
            feeRate: (float) ($strategy['fee_rate'] ?? 0.001),
            logger: $logger,
            diagnostics: $diagnostics,
        );

        $ranker = new VolatilityRanker(
            windowMs: (int) ($discovery['window_seconds'] ?? 3600) * 1000,
            minVolatilityPct: (float) ($discovery['min_volatility_pct'] ?? 0.3),
            minSamples: (int) ($discovery['min_samples'] ?? 20),
            quote: $quote,
            excludeLeveraged: (bool) ($discovery['exclude_leveraged'] ?? true),
        );

        $subscriptions = new SubscriptionManager(
            ranker: $ranker,
            wallets: $this->wallets,
            hub: $hub,
            logger: $logger,
            exchange: $exchange,
            quoteAsset: $quote,
            topN: (int) ($discovery['top_n'] ?? 15),
            maxSubscriptions: (int) ($discovery['max_subscriptions'] ?? 40),
            minSubscriptionMs: (int) ($discovery['min_subscription_ms'] ?? 30000),
            diagnostics: $diagnostics,
        );

        $hub->onAllTickers(function (array $tickers, int $nowMs) use ($ranker): void {
            $ranker->ingest($tickers, $nowMs);
        });
        $hub->onDepth(function ($snapshot) use ($engine): void {
            $engine->onOrderBook($snapshot);
        });

        $hub->start();

        $refreshMs = max(500, (int) ($discovery['refresh_ms'] ?? 4000));
        $loop->addPeriodicTimer($refreshMs / 1000, function () use ($subscriptions): void {
            $subscriptions->reconcile((int) (microtime(true) * 1000));
        });

        $this->scheduleHeartbeat($config, $logger);
        $this->schedulePersistence($persistence);
        $this->registerSignalHandlers();
        $this->scheduleDuration();

        $this->info('meanrev:run iniciado.');
        $this->info(sprintf(
            'Exchange=%s quote=%s top_n=%d max_subs=%d',
            $exchange,
            $quote,
            (int) ($discovery['top_n'] ?? 15),
            (int) ($discovery['max_subscriptions'] ?? 40),
        ));

        $loop->run();

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function scheduleHeartbeat(array $config, LoggerInterface $logger): void
    {
        $interval = (int) ($config['heartbeat_interval_seconds'] ?? 15);
        if ($interval <= 0) {
            return;
        }

        Loop::get()->addPeriodicTimer($interval, function () use ($logger): void {
            if ($this->metrics === null || $this->positions === null || $this->wallets === null) {
                return;
            }

            $logger->info('[meanrev][heartbeat]', array_merge(
                $this->metrics->toArray(),
                [
                    'open_positions' => $this->positions->openCount(),
                    'deployed_usdt' => round($this->positions->totalCostBasis(), 4),
                    'wallet' => $this->wallets->snapshot(),
                ],
            ));
        });
    }

    /**
     * @param  array<string, mixed>  $persistence
     */
    private function schedulePersistence(array $persistence): void
    {
        if (! (bool) ($persistence['persist_wallet'] ?? false)) {
            return;
        }

        $this->persistUserId = isset($persistence['user_id']) ? (int) $persistence['user_id'] : null;
        $intervalMs = max(1000, (int) ($persistence['flush_interval_ms'] ?? 5000));

        Loop::get()->addPeriodicTimer($intervalMs / 1000, function (): void {
            $this->persistWalletSnapshot();
        });
    }

    private function persistWalletSnapshot(): void
    {
        if ($this->wallets === null) {
            return;
        }

        try {
            $now = now();
            foreach ($this->wallets->snapshot() as $exchange => $assets) {
                foreach ($assets as $asset => $available) {
                    WalletBalance::updateOrCreate(
                        ['user_id' => $this->persistUserId, 'exchange' => $exchange, 'asset' => $asset],
                        [
                            'available' => $available,
                            'version' => $this->wallets->version($exchange, $asset),
                            'updated_at' => $now,
                        ],
                    );
                }
            }
        } catch (\Throwable $e) {
            $this->warn('No se pudo persistir snapshot de wallets: '.$e->getMessage());
        }
    }

    private function registerSignalHandlers(): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }

        $handler = function (int $signal): void {
            $this->warn(sprintf('Señal %d recibida, cerrando worker...', $signal));
            $this->persistWalletSnapshot();
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
            $this->persistWalletSnapshot();
            Loop::stop();
        });
    }
}
