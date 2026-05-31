<?php

declare(strict_types=1);

namespace App\Strategies\Risk;

/**
 * Estado de cartera que el Risk Manager necesita para decidir: cuántas
 * posiciones abiertas hay, racha de pérdidas, P&L del día y capital disponible.
 */
final class PortfolioState
{
    public function __construct(
        public readonly int $openPositions,
        public readonly int $lossStreak,
        public readonly float $dailyPnl,
        public readonly float $freeUsdt,
        public readonly float $deployedUsdt,
        public readonly bool $hasPositionForSymbol,
    ) {
    }
}
