<?php

declare(strict_types=1);

namespace App\Strategies\Engine;

use App\Strategies\DTO\MarketContext;
use App\Strategies\DTO\StrategySignal;

/**
 * Contrato común de toda estrategia de trading (doc sección 6). Una estrategia
 * es lógica PURA: dado el contexto de mercado de un símbolo, decide si hay una
 * señal de entrada (long o short) y con qué reglas de salida. No hace sizing ni
 * toca riesgo de cartera (eso es del Risk Manager) ni ejecuta (del simulador).
 */
interface TradingStrategy
{
    /** Nombre legible para el dashboard. */
    public function name(): string;

    /** Identificador estable del algoritmo (coincide con strategies.algorithm). */
    public function algorithm(): string;

    public function evaluate(MarketContext $context): ?StrategySignal;
}
