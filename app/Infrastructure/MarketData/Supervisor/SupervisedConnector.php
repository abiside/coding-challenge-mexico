<?php

declare(strict_types=1);

namespace App\Infrastructure\MarketData\Supervisor;

use App\Domain\MarketData\Contracts\ExchangeConnector;
use App\Domain\MarketData\Contracts\MarketMessagePublisher;
use App\Domain\MarketData\DTO\MarketTick;
use App\Domain\MarketData\DTO\OrderBookSnapshot;
use App\Domain\MarketData\DTO\StreamSubscription;
use App\Infrastructure\MarketData\WebSocket\WebSocketClient;
use App\Infrastructure\MarketData\WebSocket\WebSocketConnection;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use Throwable;

/**
 * Mantiene una sola conexión WebSocket viva para un ExchangeConnector.
 * Si la conexión falla o cierra, reintenta con backoff y reenvía
 * los frames de suscripción.
 */
final class SupervisedConnector
{
    private bool $stopped = false;

    private int $failedAttempts = 0;

    private ?WebSocketConnection $activeConnection = null;

    private int $lastMessageAt = 0;

    private int $connectedSince = 0;

    private StreamHealthMetrics $tickerMetrics;

    private StreamHealthMetrics $orderBookMetrics;

    /**
     * @param  array<int, StreamSubscription>  $subscriptions
     */
    public function __construct(
        private readonly ExchangeConnector $connector,
        private readonly array $subscriptions,
        private readonly WebSocketClient $client,
        private readonly MarketMessagePublisher $publisher,
        private readonly BackoffStrategy $backoff,
        private readonly LoopInterface $loop,
        private readonly LoggerInterface $logger,
    ) {
        $this->tickerMetrics = new StreamHealthMetrics();
        $this->orderBookMetrics = new StreamHealthMetrics();
    }

    public function start(): void
    {
        $this->stopped = false;
        $this->scheduleConnect(0);
    }

    public function stop(): void
    {
        $this->stopped = true;

        if ($this->activeConnection !== null) {
            try {
                $this->activeConnection->close();
            } catch (Throwable $e) {
                $this->logger->warning('[market-feed] error cerrando conexion', [
                    'exchange' => $this->connector->name(),
                    'error' => $e->getMessage(),
                ]);
            }
            $this->activeConnection = null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        return [
            'exchange' => $this->connector->name(),
            'connected' => $this->activeConnection !== null,
            'connected_since' => $this->connectedSince > 0 ? date('c', $this->connectedSince) : null,
            'last_message_at' => $this->lastMessageAt > 0 ? date('c', $this->lastMessageAt) : null,
            'failed_attempts' => $this->failedAttempts,
            'subscriptions' => array_map(static fn (StreamSubscription $s): string => $s->key(), $this->subscriptions),
            'metrics' => [
                'ticker' => $this->tickerMetrics->summary(),
                'orderbook' => $this->orderBookMetrics->summary(),
            ],
        ];
    }

    private function scheduleConnect(int $attempt): void
    {
        if ($this->stopped) {
            return;
        }

        $delayMs = $attempt === 0 ? 0 : $this->backoff->delayMs($attempt);

        if ($delayMs > 0) {
            $this->logger->info('[market-feed] reintentando conexion', [
                'exchange' => $this->connector->name(),
                'attempt' => $attempt,
                'delay_ms' => $delayMs,
            ]);
        }

        $this->loop->addTimer($delayMs / 1000, function (): void {
            $this->doConnect();
        });
    }

    private function doConnect(): void
    {
        if ($this->stopped) {
            return;
        }

        $exchange = $this->connector->name();
        $endpoint = $this->connector->endpoint();

        $this->logger->info('[market-feed] conectando', [
            'exchange' => $exchange,
            'endpoint' => $endpoint,
        ]);

        $this->client->connect($endpoint)->then(
            function (WebSocketConnection $conn) use ($exchange): void {
                if ($this->stopped) {
                    $conn->close();

                    return;
                }

                $this->activeConnection = $conn;
                $this->failedAttempts = 0;
                $this->connectedSince = time();
                $this->lastMessageAt = time();

                $this->logger->info('[market-feed] conectado', [
                    'exchange' => $exchange,
                ]);

                $conn->onMessage(function (string $raw) use ($exchange): void {
                    $this->lastMessageAt = time();
                    $this->handleMessage($raw, $exchange);
                });

                $conn->onError(function (Throwable $error) use ($exchange): void {
                    $this->logger->warning('[market-feed] error de conexion', [
                        'exchange' => $exchange,
                        'error' => $error->getMessage(),
                    ]);
                });

                $conn->onClose(function (?int $code, ?string $reason) use ($exchange): void {
                    $this->logger->warning('[market-feed] conexion cerrada', [
                        'exchange' => $exchange,
                        'code' => $code,
                        'reason' => $reason,
                    ]);
                    $this->activeConnection = null;
                    $this->connectedSince = 0;
                    $this->failedAttempts++;
                    $this->scheduleConnect($this->failedAttempts);
                });

                foreach ($this->connector->buildSubscribeFrames($this->subscriptions) as $frame) {
                    $conn->send($frame);
                }
            },
            function (Throwable $reason) use ($exchange): void {
                $this->failedAttempts++;
                $this->logger->error('[market-feed] fallo al conectar', [
                    'exchange' => $exchange,
                    'attempt' => $this->failedAttempts,
                    'error' => $reason->getMessage(),
                ]);
                $this->scheduleConnect($this->failedAttempts);
            }
        );
    }

    private function handleMessage(string $raw, string $exchange): void
    {
        try {
            foreach ($this->connector->parser()->parse($raw) as $dto) {
                if ($dto instanceof MarketTick) {
                    $this->publisher->publishTick($dto);
                    $this->tickerMetrics->observe(
                        sourceTimestampMs: $dto->timestampMs,
                        ingestTimestampMs: (int) round(microtime(true) * 1000)
                    );

                    continue;
                }

                if ($dto instanceof OrderBookSnapshot) {
                    $this->publisher->publishOrderBook($dto);
                    $this->orderBookMetrics->observe(
                        sourceTimestampMs: $dto->timestampMs,
                        ingestTimestampMs: (int) round(microtime(true) * 1000)
                    );
                }
            }
        } catch (Throwable $e) {
            $this->logger->warning('[market-feed] error parseando mensaje', [
                'exchange' => $exchange,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
