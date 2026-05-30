<?php

declare(strict_types=1);

namespace Tests\Unit\MarketData\Supervisor;

use App\Domain\MarketData\Contracts\MarketMessagePublisher;
use App\Domain\MarketData\DTO\MarketTick;
use App\Domain\MarketData\DTO\OrderBookSnapshot;
use App\Domain\MarketData\DTO\StreamSubscription;
use App\Domain\MarketData\Enums\StreamType;
use App\Infrastructure\MarketData\Exchanges\Binance\BinanceConnector;
use App\Infrastructure\MarketData\Supervisor\BackoffStrategy;
use App\Infrastructure\MarketData\Supervisor\ConnectorSupervisor;
use App\Infrastructure\MarketData\WebSocket\WebSocketClient;
use App\Infrastructure\MarketData\WebSocket\WebSocketConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use function React\Promise\reject;
use function React\Promise\resolve;

final class FakeWebSocketConnection implements WebSocketConnection
{
    /** @var array<int, string> */
    public array $sent = [];

    /** @var callable|null */
    public $messageListener = null;

    /** @var callable|null */
    public $closeListener = null;

    /** @var callable|null */
    public $errorListener = null;

    public bool $closed = false;

    public function send(string $payload): void
    {
        $this->sent[] = $payload;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function onMessage(callable $listener): void
    {
        $this->messageListener = $listener;
    }

    public function onClose(callable $listener): void
    {
        $this->closeListener = $listener;
    }

    public function onError(callable $listener): void
    {
        $this->errorListener = $listener;
    }

    public function emitMessage(string $message): void
    {
        if ($this->messageListener !== null) {
            ($this->messageListener)($message);
        }
    }

    public function triggerClose(?int $code = 1006, ?string $reason = 'test-close'): void
    {
        if ($this->closeListener !== null) {
            ($this->closeListener)($code, $reason);
        }
    }
}

final class FakeWebSocketClient implements WebSocketClient
{
    /** @var array<int, FakeWebSocketConnection> */
    public array $connections = [];

    public int $failuresBeforeSuccess = 0;

    public int $attempts = 0;

    public function connect(string $url): PromiseInterface
    {
        $this->attempts++;
        if ($this->failuresBeforeSuccess > 0) {
            $this->failuresBeforeSuccess--;

            return reject(new \RuntimeException('forced failure'));
        }

        $conn = new FakeWebSocketConnection();
        $this->connections[] = $conn;

        return resolve($conn);
    }

    public function lastConnection(): ?FakeWebSocketConnection
    {
        return $this->connections === [] ? null : $this->connections[array_key_last($this->connections)];
    }
}

final class CollectingPublisher implements MarketMessagePublisher
{
    /** @var array<int, MarketTick> */
    public array $ticks = [];

    /** @var array<int, OrderBookSnapshot> */
    public array $books = [];

    public function publishTick(MarketTick $tick): void
    {
        $this->ticks[] = $tick;
    }

    public function publishOrderBook(OrderBookSnapshot $snapshot): void
    {
        $this->books[] = $snapshot;
    }
}

class ConnectorSupervisorTest extends TestCase
{
    public function test_subscribes_and_publishes_received_message(): void
    {
        $loop = Loop::get();
        $client = new FakeWebSocketClient();
        $publisher = new CollectingPublisher();

        $supervisor = new ConnectorSupervisor(
            loop: $loop,
            client: $client,
            publisher: $publisher,
            logger: new NullLogger(),
            backoff: new BackoffStrategy(baseMs: 1, capMs: 5),
        );

        $supervisor->register(new BinanceConnector(), [
            new StreamSubscription(StreamType::Ticker, 'BTC/USDT'),
        ]);
        $supervisor->startAll();

        $loop->addTimer(0.05, function () use ($client, $loop): void {
            $conn = $client->lastConnection();
            $this->assertNotNull($conn);
            $this->assertNotEmpty($conn->sent);

            $payload = (string) file_get_contents(__DIR__.'/../../../Fixtures/MarketData/binance_ticker.json');
            $conn->emitMessage($payload);
            $conn->emitMessage($payload);

            $loop->addTimer(0.05, function () use ($loop): void {
                $loop->stop();
            });
        });

        $loop->addTimer(2.0, fn () => $loop->stop());
        $loop->run();

        $supervisor->stopAll();

        $this->assertCount(2, $publisher->ticks);
        $this->assertSame('BTC/USDT', $publisher->ticks[0]->symbol);

        $statuses = $supervisor->statuses();
        $this->assertArrayHasKey('binance', $statuses);
        $this->assertGreaterThanOrEqual(1, $statuses['binance']['metrics']['ticker']['messages_total']);
        $this->assertNotNull($statuses['binance']['metrics']['ticker']['inter_arrival_ms']['p50']);
    }

    public function test_reconnects_after_initial_failures(): void
    {
        $loop = Loop::get();
        $client = new FakeWebSocketClient();
        $client->failuresBeforeSuccess = 2;
        $publisher = new CollectingPublisher();

        $supervisor = new ConnectorSupervisor(
            loop: $loop,
            client: $client,
            publisher: $publisher,
            logger: new NullLogger(),
            backoff: new BackoffStrategy(baseMs: 1, capMs: 5),
        );

        $supervisor->register(new BinanceConnector(), [
            new StreamSubscription(StreamType::Ticker, 'BTC/USDT'),
        ]);
        $supervisor->startAll();

        $loop->addTimer(0.5, function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();

        $supervisor->stopAll();

        $this->assertGreaterThanOrEqual(3, $client->attempts);
        $this->assertNotEmpty($client->connections);
    }

    public function test_reconnects_after_close_and_resubscribes(): void
    {
        $loop = Loop::get();
        $client = new FakeWebSocketClient();
        $publisher = new CollectingPublisher();

        $supervisor = new ConnectorSupervisor(
            loop: $loop,
            client: $client,
            publisher: $publisher,
            logger: new NullLogger(),
            backoff: new BackoffStrategy(baseMs: 1, capMs: 5),
        );

        $supervisor->register(new BinanceConnector(), [
            new StreamSubscription(StreamType::Ticker, 'BTC/USDT'),
        ]);
        $supervisor->startAll();

        $loop->addTimer(0.05, function () use ($client): void {
            $conn = $client->lastConnection();
            $this->assertNotNull($conn);
            $conn->triggerClose(1006, 'forced');
        });

        $loop->addTimer(0.4, function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();

        $supervisor->stopAll();

        $this->assertGreaterThanOrEqual(2, count($client->connections));
        $this->assertNotEmpty($client->connections[0]->sent);
        $this->assertNotEmpty($client->connections[1]->sent);
    }
}
