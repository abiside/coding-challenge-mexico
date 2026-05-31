<?php

declare(strict_types=1);

namespace App\Strategies\Risk;

/**
 * Veredicto del Risk Manager para una señal. Si no se aprueba, lleva el motivo
 * principal (para el feed/auditoría) y las banderas acumuladas.
 */
final class RiskAssessment
{
    /**
     * @param  array<int, string>  $flags
     */
    private function __construct(
        public readonly bool $approved,
        public readonly ?string $reason,
        public readonly array $flags,
    ) {
    }

    public static function approve(array $flags = []): self
    {
        return new self(true, null, $flags);
    }

    public static function reject(string $reason, array $flags = []): self
    {
        return new self(false, $reason, $flags);
    }
}
