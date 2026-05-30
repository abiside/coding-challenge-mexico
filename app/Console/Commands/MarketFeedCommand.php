<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\MarketData\Contracts\ExchangeConnector;
use App\Domain\MarketData\Contracts\MarketMessagePublisher;
use App\Domain\MarketData\DTO\StreamSubscription;
use App\Domain\MarketData\Enums\StreamType;
use App\Infrastructure\MarketData\Exchanges\Binance\BinanceConnector;
use App\Infrastructure\MarketData\Exchanges\Bitget\BitgetConnector;
use App\Infrastructure\MarketData\Exchanges\Bybit\BybitConnector;
use App\Infrastructure\MarketData\Exchanges\Coinbase\CoinbaseConnector;
use App\Infrastructure\MarketData\Exchanges\Kraken\KrakenConnector;
use App\Infrastructure\MarketData\Exchanges\Okx\OkxConnector;
use App\Infrastructure\MarketData\Publishers\CompositeMarketMessagePublisher;
use App\Infrastructure\MarketData\Publishers\LoggerMarketMessagePublisher;
use App\Infrastructure\MarketData\Publishers\RedisMarketMessagePublisher;
use App\Infrastructure\MarketData\Supervisor\BackoffStrategy;
use App\Infrastructure\MarketData\Supervisor\ConnectorSupervisor;
use App\Infrastructure\MarketData\WebSocket\PawlWebSocketClient;
use Illuminate\Console\Command;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;

class MarketFeedCommand extends Command
{
    protected $signature = 'market:feed
        {--exchanges= : Lista separada por comas (binance,kraken,coinbase,bybit,okx,bitget)}
        {--symbols= : Símbolos normalizados separados por comas (BTC/USDT,ETH/USDT)}
        {--streams= : Streams a habilitar (ticker,orderbook)}
        {--no-redis : No publicar a Redis (solo log)}
        {--quiet-logs : Silenciar el log por mensaje individual}
        {--duration= : Detener el listener tras N segundos (útil para smoke tests)}';

    protected $description = 'Mantiene conexiones WebSocket persistentes a los exchanges y publica los mensajes normalizados.';

    public function handle(LoggerInterface $logger, RedisFactory $redisFactory): int
    {
        $exchanges = $this->resolveCsvOption('exchanges', (array) config('marketdata.exchanges', []));
        $streams = $this->resolveCsvOption('streams', (array) config('marketdata.streams', []));
        $cliSymbols = $this->resolveCsvOption('symbols', []);

        if ($exchanges === []) {
            $this->error('No hay exchanges configurados.');

            return self::FAILURE;
        }

        $streamTypes = $this->mapStreams($streams);
        if ($streamTypes === []) {
            $this->error('No hay streams válidos. Usa ticker y/o orderbook.');

            return self::FAILURE;
        }

        $loop = Loop::get();
        $client = new PawlWebSocketClient($loop);

        $publisher = $this->buildPublisher($logger, $redisFactory);
        $backoff = new BackoffStrategy(
            baseMs: (int) config('marketdata.backoff.base_ms', 1000),
            capMs: (int) config('marketdata.backoff.cap_ms', 30000),
        );

        $supervisor = new ConnectorSupervisor(
            loop: $loop,
            client: $client,
            publisher: $publisher,
            logger: $logger,
            backoff: $backoff,
        );

        foreach ($exchanges as $exchangeName) {
            $connector = $this->buildConnector($exchangeName);
            if ($connector === null) {
                $this->warn("Exchange no soportado: {$exchangeName}. Se ignora.");

                continue;
            }

            $symbols = $this->symbolsFor($exchangeName, $cliSymbols);
            if ($symbols === []) {
                $this->warn("Sin símbolos configurados para {$exchangeName}. Se ignora.");

                continue;
            }

            $subscriptions = [];
            foreach ($symbols as $symbol) {
                foreach ($streamTypes as $type) {
                    $subscriptions[] = new StreamSubscription($type, $symbol);
                }
            }

            $supervisor->register($connector, $subscriptions);
            $this->info(sprintf(
                'Registrado %s -> [%s] x [%s]',
                $exchangeName,
                implode(', ', $symbols),
                implode(', ', array_map(static fn (StreamType $t): string => $t->value, $streamTypes))
            ));
        }

        $this->info('Iniciando supervisor (Ctrl+C para salir)...');
        $supervisor->startAll();

        $this->registerSignalHandlers($supervisor);
        $this->scheduleStatusReporter($supervisor, $logger);

        $duration = (int) ($this->option('duration') ?? 0);
        if ($duration > 0) {
            $loop->addTimer($duration, function () use ($supervisor): void {
                $this->warn('Duration alcanzada, deteniendo supervisor...');
                $supervisor->stopAll();
                Loop::stop();
            });
        }

        $loop->run();

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function resolveCsvOption(string $name, array $fallback): array
    {
        $value = (string) ($this->option($name) ?? '');
        if ($value === '') {
            return array_values(array_filter(array_map('trim', $fallback)));
        }

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    /**
     * @param  array<int, string>  $streams
     * @return array<int, StreamType>
     */
    private function mapStreams(array $streams): array
    {
        $types = [];
        foreach ($streams as $stream) {
            $type = StreamType::tryFrom(strtolower($stream));
            if ($type !== null) {
                $types[] = $type;
            }
        }

        return $types;
    }

    private function buildConnector(string $name): ?ExchangeConnector
    {
        return match (strtolower($name)) {
            'binance' => new BinanceConnector(),
            'kraken' => new KrakenConnector(),
            'coinbase' => new CoinbaseConnector(),
            'bybit' => new BybitConnector(),
            'okx' => new OkxConnector(),
            'bitget' => new BitgetConnector(),
            default => null,
        };
    }

    /**
     * @param  array<int, string>  $cliSymbols
     * @return array<int, string>
     */
    private function symbolsFor(string $exchange, array $cliSymbols): array
    {
        if ($cliSymbols !== []) {
            return $cliSymbols;
        }

        $perExchange = (array) config('marketdata.symbols.'.strtolower($exchange), []);
        if ($perExchange !== []) {
            return $perExchange;
        }

        return (array) config('marketdata.symbols.default', []);
    }

    private function buildPublisher(LoggerInterface $logger, RedisFactory $redisFactory): MarketMessagePublisher
    {
        $publishers = [];

        // Logueo por-mensaje: OPT-IN explícito. Antes estaba activo por defecto
        // (salvo --quiet-logs) y generaba GB de logs (un info por cada tick y
        // cada orderbook de cada exchange/símbolo). Ahora solo se activa con
        // MARKET_FEED_LOG_MESSAGES=true.
        if ((bool) config('marketdata.publisher.log_messages', false)) {
            $publishers[] = new LoggerMarketMessagePublisher($logger);
        }

        if (! $this->option('no-redis')) {
            $publishers[] = new RedisMarketMessagePublisher(
                redis: $redisFactory,
                logger: $logger,
                connection: (string) config('marketdata.publisher.redis_connection', 'default'),
                channelPrefix: (string) config('marketdata.publisher.channel_prefix', 'market'),
                latestStateTtlSeconds: (int) config('marketdata.publisher.latest_state_ttl_seconds', 300),
            );
        }

        if ($publishers === []) {
            $publishers[] = new LoggerMarketMessagePublisher($logger);
        }

        return new CompositeMarketMessagePublisher($publishers);
    }

    private function registerSignalHandlers(ConnectorSupervisor $supervisor): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }

        $handler = function (int $signal) use ($supervisor): void {
            $this->warn(sprintf('Recibida señal %d, cerrando conexiones...', $signal));
            $supervisor->stopAll();
            Loop::stop();
        };

        $loop = Loop::get();
        $loop->addSignal(SIGINT, $handler);
        $loop->addSignal(SIGTERM, $handler);
    }

    private function scheduleStatusReporter(ConnectorSupervisor $supervisor, LoggerInterface $logger): void
    {
        $interval = (int) config('marketdata.status_interval_seconds', 30);
        if ($interval <= 0) {
            return;
        }

        Loop::get()->addPeriodicTimer($interval, function () use ($supervisor, $logger): void {
            $logger->info('[market-feed][status]', $supervisor->statuses());
        });
    }
}
