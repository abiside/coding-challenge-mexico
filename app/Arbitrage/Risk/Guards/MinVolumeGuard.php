<?php

declare(strict_types=1);

namespace App\Arbitrage\Risk\Guards;

use App\Arbitrage\Engine\DTO\EvaluatedOpportunity;
use App\Arbitrage\Risk\RiskDecision;

/**
 * Ignora oportunidades cuyo volumen ejecutable es demasiado pequeño para ser
 * accionable (ruido de liquidez o balance casi agotado).
 */
final class MinVolumeGuard implements Guard
{
    public function __construct(private readonly float $minBaseVolume)
    {
    }

    public function evaluate(EvaluatedOpportunity $opportunity, int $nowMs): ?RiskDecision
    {
        $volume = $opportunity->liquidity->executableBaseVolume;

        if ($volume < $this->minBaseVolume) {
            return RiskDecision::ignore(sprintf(
                'insufficient_volume: executable=%.8f min=%.8f',
                $volume,
                $this->minBaseVolume,
            ));
        }

        return null;
    }
}
