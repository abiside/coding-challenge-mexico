<?php

declare(strict_types=1);

namespace App\Arbitrage\Contracts;

/**
 * Abstracción de una operación evaluada con métricas de profit/liquidez/latencia.
 *
 * Permite que el RiskManager y los guards trabajen indistintamente sobre
 * oportunidades de 2 patas (`EvaluatedOpportunity`) y ciclos triangulares
 * multi-pata (`EvaluatedCycle`). Toda la decisión de riesgo se apoya
 * exclusivamente en los métodos de esta interfaz, lo que mantiene los guards
 * idénticos para ambos tipos.
 */
interface ProfitableTrade
{
    /**
     * Etiqueta del activo o ciclo (símbolo "BTC/USDT" para opps, "USDT->BTC->ETH->USDT"
     * para ciclos), usada en logging/metricas/breaker.
     */
    public function label(): string;

    /**
     * Activo base relevante para la operación. Para opps es el `base` del
     * símbolo; para ciclos es el `start_asset` (USDT, USD, ...).
     */
    public function baseAsset(): string;

    /**
     * Profit neto en unidades de la moneda quote de referencia (o del activo
     * de partida en ciclos): es el campo que evalúa MinProfitGuard.
     */
    public function netProfit(): float;

    /**
     * Margen neto como fracción de la inversión (notional / start_amount).
     */
    public function netMargin(): float;

    /**
     * Volumen efectivamente ejecutable expresado en una unidad significativa
     * para el guard de volumen mínimo: base asset (opps) o start asset (ciclos).
     */
    public function executableVolume(): float;

    /**
     * Antigüedad combinada (suma) de los books usados en ms.
     */
    public function combinedAgeMs(?int $nowMs = null): int;

    /**
     * Edades individuales por book usado en ms, para guard de frescura.
     *
     * @return array<int, int>
     */
    public function bookAgesMs(?int $nowMs = null): array;

    /**
     * Clave estable para el circuit breaker: identifica a la "ruta" de la
     * operación (par de exchanges + símbolo en opps, secuencia completa en
     * ciclos). Dos operaciones con la misma clave comparten breaker.
     */
    public function circuitBreakerKey(): string;
}
