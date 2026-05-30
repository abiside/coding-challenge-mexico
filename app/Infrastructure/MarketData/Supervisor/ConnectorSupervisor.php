<?php

declare(strict_types=1);

namespace App\Infrastructure\MarketData\Supervisor;

use App\Domain\MarketData\Contracts\ExchangeConnector;
use App\Domain\MarketData\Contracts\MarketMessagePublisher;
use App\Domain\MarketData\DTO\StreamSubscription;
use App\Infrastructure\MarketData\WebSocket\WebSocketClient;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;

/**
 * Orquesta múltiples SupervisedConnector dentro del mismo event loop.
 */
final class ConnectorSupervisor
{
    /**
     * @var array<string, SupervisedConnector>
     */
    private array $supervised = [];

    public function __construct(
        private readonly LoopInterface $loop,
        private readonly WebSocketClient $client,
        private readonly MarketMessagePublisher $publisher,
        private readonly LoggerInterface $logger,
        private readonly BackoffStrategy $backoff = new BackoffStrategy(),
    ) {
    }

    /**
     * @param  array<int, StreamSubscription>  $subscriptions
     */
    public function register(ExchangeConnector $connector, array $subscriptions): void
    {
        $this->supervised[$connector->name()] = new SupervisedConnector(
            connector: $connector,
            subscriptions: $subscriptions,
            client: $this->client,
            publisher: $this->publisher,
            backoff: $this->backoff,
            loop: $this->loop,
            logger: $this->logger,
        );
    }

    public function startAll(): void
    {
        foreach ($this->supervised as $supervised) {
            $supervised->start();
        }
    }

    public function stopAll(): void
    {
        foreach ($this->supervised as $supervised) {
            $supervised->stop();
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function statuses(): array
    {
        $statuses = [];
        foreach ($this->supervised as $name => $supervised) {
            $statuses[$name] = $supervised->status();
        }

        return $statuses;
    }
}
