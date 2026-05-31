<?php

declare(strict_types=1);

namespace App\Arbitrage\MeanReversion\Discovery;

use App\Domain\MarketData\DTO\OrderBookLevel;
use App\Domain\MarketData\DTO\OrderBookSnapshot;
use App\Infrastructure\MarketData\Exchanges\Binance\BinanceSymbolMapper;
use App\Infrastructure\MarketData\Supervisor\BackoffStrategy;
use App\Infrastructure\MarketData\WebSocket\WebSocketClient;
use App\Infrastructure\MarketData\WebSocket\WebSocketConnection;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use Throwable;

/**
 * Conexión única al combined stream de Binance con suscripciones DINÁMICAS.
 *
 * Mantiene siempre activo `!miniTicker@arr` (discovery de todo el mercado) y
 * permite abrir/cerrar en caliente los streams de profundidad por símbolo
 * (`<sym>@depthN@speed`) enviando frames SUBSCRIBE/UNSUBSCRIBE sobre la misma
 * conexión. Reconecta con backoff y reenvía el set de streams vigente, de modo
 * que un corte no pierde las suscripciones.
 *
 * Enruta los mensajes a dos callbacks: `onAllTickers` (ranking de volatilidad)
 * y `onDepth` (motor de la estrategia).
 */
final class BinanceStreamHub
{
    /**
     * Stream de discovery de todo el mercado. Usamos !miniTicker@arr (no
     * !ticker@arr): el ticker completo genera un frame de cientos de KB que
     * Binance no entrega de forma fiable por esta conexión, mientras que el
     * miniTicker (~8KB) llega cada 1s y ya trae símbolo (`s`) y last price
     * (`c`), que es todo lo que necesita el ranking de volatilidad.
     */
    private const DISCOVERY_STREAM = '!miniTicker@arr';

    private ?WebSocketConnection $connection = null;

    private bool $stopped = false;

    private int $failedAttempts = 0;

    private int $msgId = 1;

    /** @var array<string, bool>  símbolo normalizado => suscrito a profundidad */
    private array $activeSymbols = [];

    /** @var (callable(OrderBookSnapshot): void)|null */
    private $onDepth = null;

    /** @var (callable(array<int, mixed>, int): void)|null */
    private $onAllTickers = null;

    /** @var (callable(array<string, mixed>): void)|null */
    private $onKline = null;

    public function __construct(
        private readonly LoopInterface $loop,
        private readonly WebSocketClient $client,
        private readonly string $endpoint,
        private readonly BackoffStrategy $backoff,
        private readonly LoggerInterface $logger,
        private readonly int $orderBookDepth = 20,
        private readonly string $orderBookSpeed = '100ms',
        private readonly string $exchange = 'binance',
        // Suscribir además velas por símbolo (features de volumen). Off por
        // defecto para no alterar el worker de reversión a la media.
        private readonly bool $subscribeKlines = false,
        private readonly string $klineInterval = '1m',
    ) {
    }

    /**
     * @param  callable(OrderBookSnapshot): void  $callback
     */
    public function onDepth(callable $callback): void
    {
        $this->onDepth = $callback;
    }

    /**
     * @param  callable(array<int, mixed>, int): void  $callback
     */
    public function onAllTickers(callable $callback): void
    {
        $this->onAllTickers = $callback;
    }

    /**
     * @param  callable(array<string, mixed>): void  $callback
     */
    public function onKline(callable $callback): void
    {
        $this->onKline = $callback;
    }

    public function start(): void
    {
        $this->stopped = false;
        $this->scheduleConnect(0);
    }

    public function stop(): void
    {
        $this->stopped = true;
        if ($this->connection !== null) {
            try {
                $this->connection->close();
            } catch (Throwable $e) {
                $this->logger->warning('[meanrev][hub] error cerrando conexión', ['error' => $e->getMessage()]);
            }
            $this->connection = null;
        }
    }

    /**
     * @param  array<int, string>  $symbols
     */
    public function subscribeSymbols(array $symbols): void
    {
        $toAdd = [];
        foreach ($symbols as $symbol) {
            if (! isset($this->activeSymbols[$symbol])) {
                $this->activeSymbols[$symbol] = true;
                $toAdd[] = $this->depthStream($symbol);
                if ($this->subscribeKlines) {
                    $toAdd[] = $this->klineStream($symbol);
                }
            }
        }

        if ($toAdd !== [] && $this->connection !== null) {
            $this->sendControl('SUBSCRIBE', $toAdd);
        }
    }

    /**
     * @param  array<int, string>  $symbols
     */
    public function unsubscribeSymbols(array $symbols): void
    {
        $toRemove = [];
        foreach ($symbols as $symbol) {
            if (isset($this->activeSymbols[$symbol])) {
                unset($this->activeSymbols[$symbol]);
                $toRemove[] = $this->depthStream($symbol);
                if ($this->subscribeKlines) {
                    $toRemove[] = $this->klineStream($symbol);
                }
            }
        }

        if ($toRemove !== [] && $this->connection !== null) {
            $this->sendControl('UNSUBSCRIBE', $toRemove);
        }
    }

    /**
     * @return array<int, string>
     */
    public function activeSymbols(): array
    {
        return array_keys($this->activeSymbols);
    }

    public function isConnected(): bool
    {
        return $this->connection !== null;
    }

    private function scheduleConnect(int $attempt): void
    {
        if ($this->stopped) {
            return;
        }

        $delayMs = $attempt === 0 ? 0 : $this->backoff->delayMs($attempt);
        $this->loop->addTimer($delayMs / 1000, function (): void {
            $this->doConnect();
        });
    }

    private function doConnect(): void
    {
        if ($this->stopped) {
            return;
        }

        $this->logger->info('[meanrev][hub] conectando', ['endpoint' => $this->endpoint]);

        $this->client->connect($this->endpoint)->then(
            function (WebSocketConnection $conn): void {
                if ($this->stopped) {
                    $conn->close();

                    return;
                }

                $this->connection = $conn;
                $this->failedAttempts = 0;
                $this->logger->info('[meanrev][hub] conectado', [
                    'active_streams' => count($this->activeSymbols),
                ]);

                $conn->onMessage(function (string $raw): void {
                    $this->handleMessage($raw);
                });

                $conn->onError(function (Throwable $error): void {
                    $this->logger->warning('[meanrev][hub] error de conexión', ['error' => $error->getMessage()]);
                });

                $conn->onClose(function (?int $code, ?string $reason): void {
                    $this->logger->warning('[meanrev][hub] conexión cerrada', ['code' => $code, 'reason' => $reason]);
                    $this->connection = null;
                    $this->failedAttempts++;
                    $this->scheduleConnect($this->failedAttempts);
                });

                // Discovery siempre activo + reenvío del set de profundidad vigente.
                $this->sendControl('SUBSCRIBE', [self::DISCOVERY_STREAM]);
                $streams = [];
                foreach (array_keys($this->activeSymbols) as $s) {
                    $streams[] = $this->depthStream($s);
                    if ($this->subscribeKlines) {
                        $streams[] = $this->klineStream($s);
                    }
                }
                if ($streams !== []) {
                    $this->sendControl('SUBSCRIBE', $streams);
                }
            },
            function (Throwable $reason): void {
                $this->failedAttempts++;
                $this->logger->error('[meanrev][hub] fallo al conectar', [
                    'attempt' => $this->failedAttempts,
                    'error' => $reason->getMessage(),
                ]);
                $this->scheduleConnect($this->failedAttempts);
            }
        );
    }

    /**
     * @param  array<int, string>  $streams
     */
    private function sendControl(string $method, array $streams): void
    {
        if ($this->connection === null || $streams === []) {
            return;
        }

        try {
            $this->connection->send(json_encode([
                'method' => $method,
                'params' => array_values($streams),
                'id' => $this->msgId++,
            ], JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            $this->logger->warning('[meanrev][hub] error enviando control', [
                'method' => $method,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function handleMessage(string $raw): void
    {
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return;
        }

        // Acks de SUBSCRIBE/UNSUBSCRIBE: {"result":null,"id":N}.
        $stream = $decoded['stream'] ?? null;
        $data = $decoded['data'] ?? null;
        if (! is_string($stream) || ! is_array($data)) {
            return;
        }

        $nowMs = (int) (microtime(true) * 1000);

        if ($stream === self::DISCOVERY_STREAM) {
            if ($this->onAllTickers !== null) {
                ($this->onAllTickers)($data, $nowMs);
            }

            return;
        }

        if (str_contains($stream, '@depth')) {
            $snapshot = $this->buildSnapshot($stream, $data, $nowMs);
            if ($snapshot !== null && $this->onDepth !== null) {
                ($this->onDepth)($snapshot);
            }

            return;
        }

        if (str_contains($stream, '@kline')) {
            if ($this->onKline !== null) {
                ($this->onKline)($data);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function buildSnapshot(string $stream, array $data, int $nowMs): ?OrderBookSnapshot
    {
        // El partial depth de Binance no incluye el símbolo en el payload; lo
        // tomamos del nombre del stream ("btcusdt@depth20@100ms").
        $at = strpos($stream, '@');
        $rawSymbol = $at === false ? $stream : substr($stream, 0, $at);
        if ($rawSymbol === '') {
            return null;
        }

        $bids = $this->levels($data['bids'] ?? []);
        $asks = $this->levels($data['asks'] ?? []);
        if ($bids === [] && $asks === []) {
            return null;
        }

        return new OrderBookSnapshot(
            exchange: $this->exchange,
            symbol: BinanceSymbolMapper::normalize(strtoupper($rawSymbol)),
            bids: $bids,
            asks: $asks,
            timestampMs: isset($data['E']) ? (int) $data['E'] : $nowMs,
            isSnapshot: true,
            sequence: isset($data['lastUpdateId']) ? (int) $data['lastUpdateId'] : null,
        );
    }

    /**
     * @param  mixed  $raw
     * @return array<int, OrderBookLevel>
     */
    private function levels($raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $levels = [];
        foreach ($raw as $entry) {
            if (! is_array($entry) || ! isset($entry[0], $entry[1])) {
                continue;
            }
            $levels[] = new OrderBookLevel((string) $entry[0], (string) $entry[1]);
        }

        return $levels;
    }

    private function depthStream(string $symbol): string
    {
        $raw = strtolower(str_replace('/', '', $symbol));

        return sprintf('%s@depth%d@%s', $raw, $this->orderBookDepth, $this->orderBookSpeed);
    }

    private function klineStream(string $symbol): string
    {
        $raw = strtolower(str_replace('/', '', $symbol));

        return sprintf('%s@kline_%s', $raw, $this->klineInterval);
    }
}
