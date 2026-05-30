<?php

declare(strict_types=1);

namespace App\Infrastructure\MarketData\Supervisor;

final class BackoffStrategy
{
    public function __construct(
        private readonly int $baseMs = 1000,
        private readonly int $capMs = 30000,
    ) {
    }

    /**
     * Exponential backoff con jitter "decorrelated" estilo AWS.
     *
     * @param  int  $attempt  Número de intento empezando en 1.
     */
    public function delayMs(int $attempt): int
    {
        $attempt = max($attempt, 1);
        $exp = min($this->capMs, $this->baseMs * (2 ** ($attempt - 1)));

        return random_int($this->baseMs, max($this->baseMs, (int) $exp));
    }
}
