<?php

declare(strict_types=1);

namespace App\Arbitrage\MarketData;

use Clue\React\Redis\Client;
use Clue\React\Redis\Factory;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use Throwable;

/**
 * Suscriptor event-driven a los canales Redis publicados por `market:feed`.
 *
 * Usa pub/sub asíncrono (clue/redis-react) sobre el mismo event loop del
 * engine: cada mensaje recibido dispara el callback de evaluación sin polling.
 *
 * Reconecta automáticamente con backoff si la conexión pub/sub se cae o no se
 * puede establecer: sin esto, una desconexión transitoria dejaba al engine
 * "vivo pero sordo" (timers corriendo, pero sin mensajes de mercado).
 */
final class RedisMarketSubscriber
{
    /** Backoff base y tope (ms) entre intentos de reconexión. */
    private const RECONNECT_BASE_MS = 1000;

    private const RECONNECT_MAX_MS = 15000;

    private int $reconnectAttempts = 0;

    public function __construct(
        private readonly LoopInterface $loop,
        private readonly string $uri,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Se suscribe a los patrones dados (p. ej. "market:orderbook:*") y entrega
     * el payload decodificado al callback. Mantiene viva la suscripción
     * reconectando ante caídas.
     *
     * @param  array<int, string>  $patterns
     * @param  callable(string, array<string, mixed>): void  $onMessage
     */
    public function subscribe(array $patterns, callable $onMessage): void
    {
        $factory = new Factory($this->loop);

        $factory->createClient($this->uri)->then(
            function (Client $client) use ($patterns, $onMessage): void {
                $this->reconnectAttempts = 0;

                $client->on('pmessage', function ($pattern, $channel, $payload) use ($onMessage): void {
                    try {
                        $decoded = json_decode((string) $payload, true, 512, JSON_THROW_ON_ERROR);
                        if (is_array($decoded)) {
                            $onMessage((string) $channel, $decoded);
                        }
                    } catch (Throwable $e) {
                        $this->logger->warning('[arbitrage][subscriber] payload inválido', [
                            'channel' => $channel,
                            'error' => $e->getMessage(),
                        ]);
                    }
                });

                $client->on('error', function (Throwable $e): void {
                    $this->logger->error('[arbitrage][subscriber] error de cliente redis', [
                        'error' => $e->getMessage(),
                    ]);
                });

                // Si la conexión pub/sub se cierra, reprogramamos la suscripción
                // completa. Sin esto el engine quedaba sordo permanentemente.
                $client->on('close', function () use ($patterns, $onMessage): void {
                    $this->logger->warning('[arbitrage][subscriber] conexión cerrada, reconectando…');
                    $this->scheduleReconnect($patterns, $onMessage);
                });

                foreach ($patterns as $pattern) {
                    $client->__call('psubscribe', [$pattern]);
                    $this->logger->info('[arbitrage][subscriber] suscrito', ['pattern' => $pattern]);
                }
            },
            function (Throwable $e) use ($patterns, $onMessage): void {
                $this->logger->error('[arbitrage][subscriber] no se pudo conectar a redis', [
                    'error' => $e->getMessage(),
                ]);
                $this->scheduleReconnect($patterns, $onMessage);
            },
        );
    }

    /**
     * Reprograma una reconexión con backoff exponencial acotado.
     *
     * @param  array<int, string>  $patterns
     * @param  callable(string, array<string, mixed>): void  $onMessage
     */
    private function scheduleReconnect(array $patterns, callable $onMessage): void
    {
        $this->reconnectAttempts++;
        $delayMs = min(
            self::RECONNECT_MAX_MS,
            self::RECONNECT_BASE_MS * (2 ** min($this->reconnectAttempts - 1, 4)),
        );

        $this->logger->info('[arbitrage][subscriber] reintento de conexión programado', [
            'attempt' => $this->reconnectAttempts,
            'delay_ms' => $delayMs,
        ]);

        $this->loop->addTimer($delayMs / 1000, function () use ($patterns, $onMessage): void {
            $this->subscribe($patterns, $onMessage);
        });
    }
}
