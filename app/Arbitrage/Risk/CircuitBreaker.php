<?php

declare(strict_types=1);

namespace App\Arbitrage\Risk;

/**
 * Circuit breaker por par de exchanges (symbol+buy+sell).
 *
 * Tras N fallos/rechazos consecutivos abre el circuito durante un cooldown,
 * evitando reevaluar un par problemático en cada tick. Un éxito lo resetea.
 */
final class CircuitBreaker
{
    /**
     * @var array<string, int>  key => fallos consecutivos
     */
    private array $failures = [];

    /**
     * @var array<string, int>  key => epoch ms en que se puede volver a intentar
     */
    private array $openUntil = [];

    public function __construct(
        private readonly bool $enabled,
        private readonly int $failureThreshold,
        private readonly int $cooldownMs,
    ) {
    }

    public function isOpen(string $key, ?int $nowMs = null): bool
    {
        if (! $this->enabled) {
            return false;
        }

        $nowMs ??= (int) (microtime(true) * 1000);
        $until = $this->openUntil[$key] ?? 0;

        if ($until === 0) {
            return false;
        }

        if ($nowMs >= $until) {
            // Cooldown cumplido: medio-cerrado, permitir reintento.
            unset($this->openUntil[$key]);
            $this->failures[$key] = 0;

            return false;
        }

        return true;
    }

    public function recordSuccess(string $key): void
    {
        $this->failures[$key] = 0;
        unset($this->openUntil[$key]);
    }

    public function recordFailure(string $key, ?int $nowMs = null): void
    {
        if (! $this->enabled) {
            return;
        }

        $nowMs ??= (int) (microtime(true) * 1000);
        $this->failures[$key] = ($this->failures[$key] ?? 0) + 1;

        if ($this->failures[$key] >= $this->failureThreshold) {
            $this->openUntil[$key] = $nowMs + $this->cooldownMs;
        }
    }

    /**
     * Clave estable para una oportunidad cross-exchange de 2 patas. Se conserva
     * `keyFor` como alias retro-compatible con tests/llamadas existentes.
     */
    public static function keyForOpportunity(string $symbol, string $buyExchange, string $sellExchange): string
    {
        return sprintf('%s|%s->%s', $symbol, $buyExchange, $sellExchange);
    }

    public static function keyFor(string $symbol, string $buyExchange, string $sellExchange): string
    {
        return self::keyForOpportunity($symbol, $buyExchange, $sellExchange);
    }

    /**
     * Clave estable para un ciclo triangular: secuencia completa de pasos.
     */
    public static function keyForCycle(string $cycleKey): string
    {
        return 'cycle|'.$cycleKey;
    }
}
