<?php

declare(strict_types=1);

namespace App\Strategies\DTO;

/**
 * Lado de una posición de trading. A diferencia del módulo de arbitraje (que
 * compra/vende spot), aquí una posición es direccional: long apuesta a que el
 * precio sube; short (simulado, USDT como colateral) a que baja.
 */
enum Side: string
{
    case Long = 'long';
    case Short = 'short';

    public function isLong(): bool
    {
        return $this === self::Long;
    }

    public function isShort(): bool
    {
        return $this === self::Short;
    }
}
