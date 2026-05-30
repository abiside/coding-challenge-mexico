<?php

declare(strict_types=1);

namespace App\Arbitrage\Risk;

/**
 * Resultado tipado de la evaluación de riesgo: qué hacer, por qué, y con qué
 * volumen final aprobado.
 */
final class RiskDecision
{
    /**
     * @param  array<int, string>  $reasons
     */
    private function __construct(
        public readonly Decision $decision,
        public readonly array $reasons,
        public readonly float $finalVolume,
    ) {
    }

    public static function execute(float $finalVolume): self
    {
        return new self(Decision::Execute, [], $finalVolume);
    }

    /**
     * @param  array<int, string>|string  $reasons
     */
    public static function reject(array|string $reasons): self
    {
        return new self(Decision::Reject, is_array($reasons) ? $reasons : [$reasons], 0.0);
    }

    /**
     * @param  array<int, string>|string  $reasons
     */
    public static function ignore(array|string $reasons): self
    {
        return new self(Decision::Ignore, is_array($reasons) ? $reasons : [$reasons], 0.0);
    }

    public function shouldExecute(): bool
    {
        return $this->decision === Decision::Execute;
    }
}
