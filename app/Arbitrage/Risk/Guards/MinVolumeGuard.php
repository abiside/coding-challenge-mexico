<?php

declare(strict_types=1);

namespace App\Arbitrage\Risk\Guards;

use App\Arbitrage\Contracts\ProfitableTrade;
use App\Arbitrage\Risk\RiskDecision;

/**
 * Ignora operaciones cuyo volumen ejecutable es demasiado pequeño para ser
 * accionables (ruido de liquidez o balance casi agotado).
 *
 * Para oportunidades de 2 patas, el volumen está en activo base (p. ej. BTC);
 * para ciclos triangulares, el volumen está expresado en el activo de partida
 * (start asset). El umbral configurado se interpreta en la unidad del trade
 * evaluado.
 */
final class MinVolumeGuard implements Guard
{
    public function __construct(private readonly float $minBaseVolume)
    {
    }

    public function evaluate(ProfitableTrade $opportunity, int $nowMs): ?RiskDecision
    {
        $volume = $opportunity->executableVolume();

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
