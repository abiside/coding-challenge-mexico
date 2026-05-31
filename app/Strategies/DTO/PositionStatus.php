<?php

declare(strict_types=1);

namespace App\Strategies\DTO;

/**
 * Estados del ciclo de vida de una posición simulada (doc sección 8.4).
 */
enum PositionStatus: string
{
    case Pending = 'pending';
    case Open = 'open';
    case Closed = 'closed';
    case StoppedOut = 'stopped_out';
    case TakeProfitHit = 'take_profit_hit';
    case Expired = 'expired';
    case Rejected = 'rejected';
    case LiquidatedSimulated = 'liquidated_simulated';
}
