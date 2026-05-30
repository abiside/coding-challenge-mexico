<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Arbitrage\MarketData\RedisMarketSubscriber;
use App\Arbitrage\MarketData\SnapshotHydrator;
use App\Arbitrage\Realtime\ConsoleMonitorPublisher;
use App\Arbitrage\Support\ConsoleMonitorRenderer;
use App\Arbitrage\Support\EngineFactory;
use App\Arbitrage\Support\EngineRuntime;
use Illuminate\Console\Command;
use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;

/**
 * Monitor de consola (TUI) del motor de evaluación en tiempo real.
 *
 * Levanta un engine efímero con wallets y configuración arbitrarias (pasadas
 * por flags), consume los order books de Redis y redibuja en consola métricas,
 * wallets, decisiones y trades. No persiste ni publica a Reverb: es solo para
 * observar el comportamiento del motor mientras se construye la UI web.
 */
class MonitorArbitrage extends Command
{
    protected $signature = 'arbitrage:monitor
        {--symbols= : Símbolos a evaluar (BTC/USDT,ETH/USDT). Default: config}
        {--usdt=100000 : Saldo USDT por exchange}
        {--btc=2 : Saldo BTC por exchange}
        {--fee= : Override de fee (fracción, p. ej. 0.001) uniforme para todos los exchanges}
        {--min-profit= : Override de profit neto mínimo (quote)}
        {--min-margin= : Override de margen neto mínimo (fracción)}
        {--refresh=250 : Intervalo de redibujado en ms}
        {--duration= : Detener tras N segundos}';

    protected $description = 'Monitor de consola en tiempo real del engine de arbitraje (wallets/config arbitrarias).';

    public function handle(LoggerInterface $logger, EngineFactory $factory): int
    {
        $config = $this->buildConfig();
        if ($config['symbols'] === []) {
            $this->error('No hay símbolos configurados.');

            return self::FAILURE;
        }

        $publisher = new ConsoleMonitorPublisher;
        $runtime = $factory->make(
            config: $config,
            withPersistence: false,
            withDashboard: false,
            userId: null,
            dashboardOverride: $publisher,
        );

        $renderer = new ConsoleMonitorRenderer(
            configSummary: [
                'symbols' => $config['symbols'],
                'fee' => (float) ($config['fees']['default'] ?? 0),
                'min_net_profit' => (float) ($config['thresholds']['min_net_profit'] ?? 0),
                'refresh_ms' => (int) $this->option('refresh'),
            ],
            startedAt: microtime(true),
        );

        $loop = Loop::get();
        $subscriber = new RedisMarketSubscriber($loop, $this->redisUri(), $logger);
        $subscriber->subscribe($this->buildPatterns($config), function (string $channel, array $payload) use ($runtime): void {
            $this->onMessage($runtime, $payload);
        });

        $this->draw($renderer, $runtime, $publisher);
        $loop->addPeriodicTimer(max(0.05, (int) $this->option('refresh') / 1000), function () use ($renderer, $runtime, $publisher): void {
            $this->draw($renderer, $runtime, $publisher);
        });

        $this->registerSignalHandlers();
        $this->scheduleDuration();

        $loop->run();

        echo "\033[0m\n"; // reset de color al salir

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildConfig(): array
    {
        $config = (array) config('arbitrage');

        $symbols = (string) ($this->option('symbols') ?? '');
        if ($symbols !== '') {
            $config['symbols'] = array_values(array_filter(array_map('trim', explode(',', $symbols))));
        }

        if ($this->option('fee') !== null) {
            $config['fees'] = ['default' => (float) $this->option('fee')];
        }
        if ($this->option('min-profit') !== null) {
            $config['thresholds']['min_net_profit'] = (float) $this->option('min-profit');
        }
        if ($this->option('min-margin') !== null) {
            $config['thresholds']['min_net_margin'] = (float) $this->option('min-margin');
        }

        $config['initial_balances'] = $this->buildBalances();

        return $config;
    }

    /**
     * Saldos arbitrarios uniformes para todos los exchanges configurados.
     *
     * @return array<string, array<string, float>>
     */
    private function buildBalances(): array
    {
        $usdt = (float) $this->option('usdt');
        $btc = (float) $this->option('btc');
        $exchanges = array_values((array) config('marketdata.exchanges', []));

        $balances = [];
        foreach ($exchanges as $exchange) {
            $balances[$exchange] = ['USDT' => $usdt, 'BTC' => $btc];
        }

        return $balances;
    }

    private function draw(ConsoleMonitorRenderer $renderer, EngineRuntime $runtime, ConsoleMonitorPublisher $publisher): void
    {
        echo $renderer->render($runtime->wallets->snapshot(), $runtime->metrics->toArray(), $publisher);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function onMessage(EngineRuntime $runtime, array $payload): void
    {
        $snapshot = SnapshotHydrator::tryFromPayload($payload);
        if ($snapshot === null) {
            return;
        }

        $runtime->metrics->recordSnapshot();

        foreach ($runtime->engine->onSnapshot($snapshot) as $outcome) {
            $runtime->metrics->recordCandidate();
            $runtime->metrics->recordDecision($outcome->decision->decision);

            if ($outcome->simulation !== null && ! $outcome->simulation->duplicate) {
                $runtime->metrics->recordExecution(
                    $outcome->simulation->realizedPnl,
                    (float) $outcome->simulation->buyFill->baseVolume,
                    (float) $outcome->opportunity->profitability->netMargin(),
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, string>
     */
    private function buildPatterns(array $config): array
    {
        $channelPrefix = (string) (($config['input']['channel_prefix']) ?? 'market');
        $redisPrefix = (string) config('database.redis.options.prefix', '');
        $patterns = [];

        foreach ($config['symbols'] as $symbol) {
            $safe = strtolower(str_replace('/', '-', $symbol));
            $patterns[] = sprintf('%s%s:orderbook:*:%s', $redisPrefix, $channelPrefix, $safe);
        }

        return $patterns;
    }

    private function registerSignalHandlers(): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }

        $handler = function (): void {
            echo "\033[0m\n";
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

        Loop::get()->addTimer($duration, static function (): void {
            Loop::stop();
        });
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
