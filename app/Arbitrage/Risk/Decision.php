<?php

declare(strict_types=1);

namespace App\Arbitrage\Risk;

enum Decision: string
{
    /** Ejecutar la simulación. */
    case Execute = 'execute';

    /** Oportunidad evaluada pero descartada por una regla de riesgo. */
    case Reject = 'reject';

    /** Ruido / no accionable; ni siquiera se registra como rechazo relevante. */
    case Ignore = 'ignore';
}
