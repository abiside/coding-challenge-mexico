<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Arbitrage\MarketData\RedisMarketSubscriber;
use App\Arbitrage\MarketData\SnapshotHydrator;
use App\Arbitrage\Optimization\StrategyResolver;
use App\Arbitrage\Realtime\MetricsAggregator;
use App\Arbitrage\Support\EngineFactory;
use App\Arbitrage\Support\EngineRuntime;
use App\Events\ArbitrageEngineMetrics;
use App\Models\ArbitrageSetting;
use App\Models\ArbitrageStrategy;
use App\Models\SimulationRun;
use App\Models\StrategyEvaluation;
use App\Models\WalletBalance;
use App\Support\ArbitrageCacheKeys;
use Illuminate\Console\Command;
use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;

/**
 * Engine de arbitraje como proceso persistente y event-driven, multi-usuario
 * y multi-estrategia.
 *
 * Por cada usuario con simulación activa se levanta:
 *  - 1 engine champion (settings aplicados, escribe wallet_balances reales)
 *  - 0..N engines challenger (sandbox shadow, mismo feed de mercado, no
 *    contaminan wallets reales) cuyo P&L se evalúa contra el champion.
 *
 * El runner reconcilia periódicamente las estrategias vivas, dispara flush
 * de buffers de persistencia y vuelca snapshots de métricas (ventanas) a
 * strategy_evaluations para alimentar al optimizador del autopilot.
 */
class RunArbitrageBot extends Command
{
    protected $signature = 'arbitrage:run
        {--user= : Ejecutar solo para el usuario indicado (id)}
        {--global : Ejecutar un engine global sin scoping por usuario}
        {--symbols= : Override de símbolos (BTC/USDT,ETH/USDT) en modo global}
        {--no-persistence : No escribir en base de datos}
        {--no-dashboard : No publicar eventos al dashboard}
        {--duration= : Detener el engine tras N segundos (smoke tests)}';

    protected $description = 'Engine de arbitraje event-driven multi-usuario y multi-estrategia: champion + challengers shadow.';

    /**
     * @var array<string, array{runtime: EngineRuntime, symbols: array<int, string>, strategy_id: ?int}>
     */
    private array $contexts = [];

    private bool $reconcile = false;

    private ?EngineFactory $factory = null;

    private ?StrategyResolver $resolver = null;

    public function handle(LoggerInterface $logger, EngineFactory $factory, StrategyResolver $resolver): int
    {
        $this->factory = $factory;
        $this->resolver = $resolver;
        $config = (array) config('arbitrage');

        $this->contexts = $this->buildContexts($config, $factory);

        if ($this->contexts === [] && ! $this->reconcile) {
            $this->error('No hay simulaciones activas. Inicia una sesión (POST /api/v1/arbitrage/simulation) o usa --global.');

            return self::FAILURE;
        }

        foreach ($this->contexts as $context) {
            $this->persistWalletSnapshot($context['runtime']);
        }

        $loop = Loop::get();
        $subscriber = new RedisMarketSubscriber($loop, $this->redisUri(), $logger);

        // En modo multi-usuario nos suscribimos a todos los símbolos posibles
        // para que las simulaciones que se inicien en caliente reciban datos.
        $symbols = $this->reconcile
            ? array_values(array_filter((array) ($config['symbols'] ?? [])))
            : $this->allSymbols();

        $patterns = $this->buildPatterns($config, $symbols);
        $subscriber->subscribe($patterns, function (string $channel, array $payload): void {
            $this->onMessage($payload);
        });

        $this->info('arbitrage:run iniciado. Engines activos: '.count($this->contexts));
        $this->info('Símbolos: '.implode(', ', $symbols));
        $this->info('Patrones Redis: '.implode(', ', $patterns));

        $this->scheduleFlush($config);
        $this->scheduleHeartbeat($config, $logger);
        $this->scheduleReconcile($config);
        $this->scheduleEvaluationDrain($config);
        $this->registerSignalHandlers();
        $this->scheduleDuration();

        $loop->run();

        $this->flushAll();

        return self::SUCCESS;
    }

    /**
     * Determina qué engines levantar al arranque.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, array{runtime: EngineRuntime, symbols: array<int, string>, strategy_id: ?int}>
     */
    private function buildContexts(array $config, EngineFactory $factory): array
    {
        if ((bool) $this->option('global')) {
            return ['global' => $this->buildGlobalContext($config, $factory)];
        }

        $userId = $this->option('user');
        if ($userId !== null && $userId !== '') {
            $uid = (int) $userId;
            $setting = ArbitrageSetting::where('user_id', $uid)->first();

            return $this->buildUserStrategyContexts($uid, $setting, $config, $factory);
        }

        // Sin --user ni --global: modo multi-usuario con reconciliación en caliente.
        $this->reconcile = true;
        $contexts = [];
        $runs = SimulationRun::where('status', SimulationRun::STATUS_ACTIVE)
            ->with('user.arbitrageSetting')
            ->get();

        foreach ($runs as $run) {
            $setting = $run->user?->arbitrageSetting;
            $contexts = array_merge(
                $contexts,
                $this->buildUserStrategyContexts((int) $run->user_id, $setting, $config, $factory),
            );
        }

        return $contexts;
    }

    /**
     * Sincroniza los engines vivos con las simulaciones activas + estrategias
     * vivas en DB: levanta engines nuevos (champion promovido, challengers
     * añadidos por el optimizador), reconstruye los que cambiaron de config
     * (hash distinto) y descarta los archivados o de sesiones detenidas.
     *
     * @param  array<string, mixed>  $config
     */
    private function reconcileContexts(array $config): void
    {
        if (! $this->reconcile || $this->factory === null || $this->resolver === null) {
            return;
        }

        $activeUserIds = SimulationRun::where('status', SimulationRun::STATUS_ACTIVE)
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->all();

        // Construye el set de contextos esperados (uid:sid -> ArbitrageStrategy).
        $expected = [];
        foreach ($activeUserIds as $userId) {
            $setting = ArbitrageSetting::where('user_id', $userId)->first();
            if ($setting === null) {
                continue;
            }

            $strategies = $this->resolver->resolveForUser($userId, $setting, $config);
            foreach ($strategies as $strategy) {
                $expected[$this->contextKey($userId, $strategy->id)] = [
                    'user_id' => $userId,
                    'strategy' => $strategy,
                    'setting' => $setting,
                ];
            }
        }

        // Bajar engines que ya no están en el set esperado, o cuyo hash de
        // config cambió (settings/promoción): los reconstruimos.
        foreach (array_keys($this->contexts) as $key) {
            $shouldRebuild = false;
            if (! isset($expected[$key])) {
                $shouldRebuild = false;
                $this->tearDown($key, 'simulación o estrategia inactiva');

                continue;
            }

            /** @var ArbitrageStrategy $strategy */
            $strategy = $expected[$key]['strategy'];
            if ($this->contexts[$key]['runtime']->configHash !== $strategy->config_hash) {
                $shouldRebuild = true;
            }

            if ($shouldRebuild) {
                $this->tearDown($key, 'cambio de configuración (hot-reload)');
            }
        }

        // Levantar los engines que faltan.
        foreach ($expected as $key => $payload) {
            if (isset($this->contexts[$key])) {
                continue;
            }

            $userId = (int) $payload['user_id'];
            /** @var ArbitrageStrategy $strategy */
            $strategy = $payload['strategy'];
            /** @var ArbitrageSetting $setting */
            $setting = $payload['setting'];

            $this->contexts[$key] = $this->buildStrategyContext($userId, $strategy, $setting, $config, $this->factory);
            if ($strategy->isChampion()) {
                $this->persistWalletSnapshot($this->contexts[$key]['runtime']);
            }
            $this->info(sprintf(
                'Engine %s iniciado: user=%d strategy=%d (%s)',
                $key,
                $userId,
                (int) $strategy->id,
                $strategy->status,
            ));
        }
    }

    private function tearDown(string $key, string $reason): void
    {
        if (! isset($this->contexts[$key])) {
            return;
        }

        $runtime = $this->contexts[$key]['runtime'];
        // Vacía el buffer antes de descartar y persiste wallet si era champion.
        $runtime->buffer?->flush();
        if ($runtime->persistWallet) {
            $this->persistWalletSnapshot($runtime);
        }
        // Drena las métricas pendientes a strategy_evaluations.
        $this->persistEvaluation($runtime);

        unset($this->contexts[$key]);
        $this->info("Engine {$key} detenido ({$reason}).");
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function scheduleReconcile(array $config): void
    {
        if (! $this->reconcile) {
            return;
        }

        Loop::get()->addPeriodicTimer(3.0, function () use ($config): void {
            try {
                $this->reconcileContexts($config);
            } catch (\Throwable $e) {
                $this->warn('Reconcile falló: '.$e->getMessage());
            }
        });
    }

    /**
     * Construye contextos por estrategia para un usuario (champion + N challengers).
     *
     * @param  array<string, mixed>  $config
     * @return array<string, array{runtime: EngineRuntime, symbols: array<int, string>, strategy_id: ?int}>
     */
    private function buildUserStrategyContexts(int $userId, ?ArbitrageSetting $setting, array $config, EngineFactory $factory): array
    {
        if ($setting === null || $this->resolver === null) {
            return [];
        }

        $contexts = [];
        foreach ($this->resolver->resolveForUser($userId, $setting, $config) as $strategy) {
            $contexts[$this->contextKey($userId, $strategy->id)] = $this->buildStrategyContext(
                $userId,
                $strategy,
                $setting,
                $config,
                $factory,
            );
        }

        return $contexts;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{runtime: EngineRuntime, symbols: array<int, string>, strategy_id: ?int}
     */
    private function buildStrategyContext(int $userId, ArbitrageStrategy $strategy, ArbitrageSetting $setting, array $config, EngineFactory $factory): array
    {
        $variantConfig = (array) $strategy->config;
        $variantConfig['initial_balances'] = $this->resolveInitialBalances($variantConfig, $userId, $strategy);

        $runtime = $factory->make(
            config: $variantConfig,
            withPersistence: ! (bool) $this->option('no-persistence'),
            withDashboard: ! (bool) $this->option('no-dashboard'),
            userId: $userId,
            strategyId: (int) $strategy->id,
            persistWallet: $strategy->isChampion(),
            configHash: $strategy->config_hash,
        );

        return [
            'runtime' => $runtime,
            'symbols' => array_values(array_filter((array) ($variantConfig['symbols'] ?? []))),
            'strategy_id' => (int) $strategy->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{runtime: EngineRuntime, symbols: array<int, string>, strategy_id: ?int}
     */
    private function buildGlobalContext(array $config, EngineFactory $factory): array
    {
        $option = (string) ($this->option('symbols') ?? '');
        if ($option !== '') {
            $config['symbols'] = array_values(array_filter(array_map('trim', explode(',', $option))));
        }

        $config['initial_balances'] = $this->resolveInitialBalances($config, null, null);

        $runtime = $factory->make(
            config: $config,
            withPersistence: ! (bool) $this->option('no-persistence'),
            withDashboard: ! (bool) $this->option('no-dashboard'),
            userId: null,
        );

        return [
            'runtime' => $runtime,
            'symbols' => array_values(array_filter((array) ($config['symbols'] ?? []))),
            'strategy_id' => null,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function allSymbols(): array
    {
        $symbols = [];
        foreach ($this->contexts as $context) {
            foreach ($context['symbols'] as $symbol) {
                $symbols[$symbol] = true;
            }
        }

        return array_keys($symbols);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function onMessage(array $payload): void
    {
        $snapshot = SnapshotHydrator::tryFromPayload($payload);
        if ($snapshot === null) {
            return;
        }

        foreach ($this->contexts as $context) {
            $runtime = $context['runtime'];

            // Cada engine solo procesa los símbolos que su estrategia configuró.
            if ($context['symbols'] !== [] && ! in_array($snapshot->symbol, $context['symbols'], true)) {
                continue;
            }

            $runtime->metrics->recordSnapshot();

            // Modo simulación: cada engine ve su propia versión del book con
            // jitter de precios, generando spreads cruzados rentables a demanda.
            $effectiveSnapshot = $runtime->perturbator?->apply($snapshot) ?? $snapshot;

            $processed = $runtime->engine->onSnapshot($effectiveSnapshot);
            foreach ($processed as $outcome) {
                $runtime->metrics->recordCandidate();
                $runtime->metrics->recordDecision($outcome->decision->decision);

                if ($outcome->simulation !== null && ! $outcome->simulation->duplicate) {
                    $runtime->metrics->recordExecution(
                        $outcome->simulation->realizedPnl,
                        (float) $outcome->simulation->buyFill->baseVolume,
                        (float) $outcome->opportunity->profitability->netMargin(),
                    );
                    if ($runtime->persistWallet) {
                        $this->persistWalletSnapshot($runtime);
                    }
                }
            }
        }
    }

    /**
     * Reanuda balances desde DB para el champion. Para challengers shadow
     * usamos los balances del champion como punto de partida y luego mutan
     * solo en memoria, sin tocar `wallet_balances`.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, array<string, float>>
     */
    private function resolveInitialBalances(array $config, ?int $userId, ?ArbitrageStrategy $strategy): array
    {
        $rows = WalletBalance::query()
            ->where('user_id', $userId)
            ->get(['exchange', 'asset', 'available']);

        if ($rows->isEmpty()) {
            return (array) ($config['initial_balances'] ?? []);
        }

        $balances = [];
        foreach ($rows as $row) {
            $balances[$row->exchange][$row->asset] = (float) $row->available;
        }

        return $balances;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<int, string>  $symbols
     * @return array<int, string>
     */
    private function buildPatterns(array $config, array $symbols): array
    {
        $channelPrefix = (string) (($config['input']['channel_prefix']) ?? 'market');
        $redisPrefix = (string) config('database.redis.options.prefix', '');
        $patterns = [];

        foreach ($symbols as $symbol) {
            $safe = strtolower(str_replace('/', '-', $symbol));
            if ((bool) (($config['input']['subscribe_orderbook']) ?? true)) {
                $patterns[] = sprintf('%s%s:orderbook:*:%s', $redisPrefix, $channelPrefix, $safe);
            }
        }

        return $patterns;
    }

    private function persistWalletSnapshot(EngineRuntime $runtime): void
    {
        if ((bool) $this->option('no-persistence')) {
            return;
        }
        if (! $runtime->persistWallet) {
            // Los challengers no escriben wallets reales.
            return;
        }

        try {
            $now = now();
            foreach ($runtime->wallets->snapshot() as $exchange => $assets) {
                foreach ($assets as $asset => $available) {
                    WalletBalance::updateOrCreate(
                        ['user_id' => $runtime->userId, 'exchange' => $exchange, 'asset' => $asset],
                        [
                            'available' => $available,
                            'version' => $runtime->wallets->version($exchange, $asset),
                            'updated_at' => $now,
                        ],
                    );
                }
            }
        } catch (\Throwable $e) {
            $this->warn('No se pudo persistir snapshot de wallets: '.$e->getMessage());
        }
    }

    /**
     * Drena las métricas acumuladas en cada runtime a strategy_evaluations.
     * Esto es lo que retroalimenta al optimizador (la base de aprendizaje).
     */
    private function persistEvaluation(EngineRuntime $runtime): void
    {
        if ((bool) $this->option('no-persistence')) {
            return;
        }
        if ($runtime->userId === null || $runtime->strategyId === null) {
            return;
        }

        $window = $runtime->metrics->drain();
        // Evitar persistir ventanas vacías: ahorra ruido en la tabla.
        if (($window['snapshots'] ?? 0) === 0 && ($window['candidates'] ?? 0) === 0) {
            return;
        }

        $score = $this->scoreFromWindow($window);

        try {
            StrategyEvaluation::create([
                'strategy_id' => $runtime->strategyId,
                'user_id' => $runtime->userId,
                'window_start_ms' => $window['window_start_ms'],
                'window_end_ms' => $window['window_end_ms'],
                'snapshots' => $window['snapshots'],
                'candidates' => $window['candidates'],
                'executions' => $window['executions'],
                'rejects' => $window['rejects'],
                'ignores' => $window['ignores'],
                'realized_pnl' => $window['realized_pnl'],
                'executed_volume' => $window['executed_volume'],
                'avg_margin' => $window['avg_margin'],
                'score' => $score,
            ]);

            // Actualiza score cacheado de la estrategia (media móvil simple).
            $strategy = ArbitrageStrategy::find($runtime->strategyId);
            if ($strategy !== null) {
                $prev = (float) $strategy->score;
                $strategy->score = round(($prev * 0.7) + ($score * 0.3), 8);
                $strategy->save();
            }
        } catch (\Throwable $e) {
            $this->warn('No se pudo persistir strategy_evaluation: '.$e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $window
     */
    private function scoreFromWindow(array $window): float
    {
        // P&L neto de la ventana es nuestro score primario (objetivo confirmado).
        // Los objetivos alternativos (volumen, ajustado a riesgo) se aplican
        // a nivel de optimizador, no aquí, para mantener el log uniforme.
        return (float) ($window['realized_pnl'] ?? 0.0);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function scheduleFlush(array $config): void
    {
        $intervalMs = (int) (($config['persistence']['flush_interval_ms']) ?? 1000);
        $loop = Loop::get();
        $loop->addPeriodicTimer(max(0.1, $intervalMs / 1000), function (): void {
            foreach ($this->contexts as $context) {
                $context['runtime']->buffer?->flush();
            }
        });
    }

    /**
     * Persiste un snapshot de evaluación por estrategia cada N segundos.
     * Esa ventana es la unidad de comparación entre champion y challengers.
     *
     * @param  array<string, mixed>  $config
     */
    private function scheduleEvaluationDrain(array $config): void
    {
        $intervalSeconds = (int) ($config['evaluation_interval_seconds'] ?? 60);
        if ($intervalSeconds <= 0) {
            return;
        }

        Loop::get()->addPeriodicTimer($intervalSeconds, function (): void {
            foreach ($this->contexts as $context) {
                try {
                    $this->persistEvaluation($context['runtime']);
                } catch (\Throwable $e) {
                    $this->warn('Evaluation drain falló: '.$e->getMessage());
                }
            }
        });
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
            foreach ($this->contexts as $context) {
                $runtime = $context['runtime'];
                $metrics = $this->heartbeatPayload($runtime->metrics);

                $logger->info('[arbitrage][heartbeat]', array_merge(
                    [
                        'user_id' => $runtime->userId,
                        'strategy_id' => $runtime->strategyId,
                    ],
                    $metrics,
                ));

                $this->broadcastEngineMetrics($runtime, $metrics);
            }
        });
    }

    /**
     * Publica el embudo de descartes y las métricas operativas del champion al
     * canal privado del usuario (websocket) y las cachea para el estado inicial
     * por REST. Los challengers shadow no se publican para no confundir la UI.
     *
     * @param  array<string, mixed>  $metrics
     */
    private function broadcastEngineMetrics(EngineRuntime $runtime, array $metrics): void
    {
        // Solo el engine real del usuario (champion) alimenta el dashboard.
        if ($runtime->userId === null || ! $runtime->persistWallet) {
            return;
        }
        if ((bool) $this->option('no-dashboard')) {
            return;
        }

        $payload = array_merge($metrics, [
            'user_id' => $runtime->userId,
            'strategy_id' => $runtime->strategyId,
            'server_time_ms' => (int) (microtime(true) * 1000),
        ]);

        try {
            cache()->put(
                ArbitrageCacheKeys::engineMetrics($runtime->userId),
                $payload,
                (int) (config('arbitrage.dashboard.snapshot_ttl_seconds', 30)),
            );

            ArbitrageEngineMetrics::dispatch(
                $payload,
                ArbitrageCacheKeys::dashboardChannel($runtime->userId),
                true,
            );
        } catch (\Throwable $e) {
            $this->warn('No se pudo publicar métricas del engine: '.$e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function heartbeatPayload(MetricsAggregator $metrics): array
    {
        return $metrics->toArray();
    }

    private function registerSignalHandlers(): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }

        $handler = function (int $signal): void {
            $this->warn(sprintf('Señal %d recibida, cerrando engine...', $signal));
            $this->flushAll();
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
            $this->warn('Duration alcanzada, deteniendo engine...');
            $this->flushAll();
            Loop::stop();
        });
    }

    private function flushAll(): void
    {
        foreach ($this->contexts as $context) {
            $context['runtime']->buffer?->flush();
            $this->persistWalletSnapshot($context['runtime']);
            $this->persistEvaluation($context['runtime']);
        }
    }

    private function contextKey(int $userId, ?int $strategyId): string
    {
        return sprintf('u%d:s%s', $userId, $strategyId ?? 'global');
    }

    private function redisUri(): string
    {
        $config = (array) config('database.redis.default', []);
        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (int) ($config['port'] ?? 6379);
        $password = $config['password'] ?? null;

        $auth = ($password !== null && $password !== '' && $password !== 'null')
            ? ':'.rawurlencode((string) $password).'@'
            : '';

        return sprintf('redis://%s%s:%d', $auth, $host, $port);
    }
}
