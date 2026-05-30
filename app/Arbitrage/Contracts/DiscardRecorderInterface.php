<?php

declare(strict_types=1);

namespace App\Arbitrage\Contracts;

/**
 * Sink ligero para contabilizar por qué se descarta cada comparativa de books
 * a lo largo del pipeline (scanner y engine). Permite alimentar el embudo de
 * razones del heartbeat/dashboard sin acoplar el dominio a la implementación
 * concreta de métricas, y sin depender de logs línea por línea.
 */
interface DiscardRecorderInterface
{
    /**
     * Incrementa el contador para una razón normalizada de descarte
     * (p. ej. "not_crossed", "not_executable", "low_net_profit").
     */
    public function recordDiscard(string $reason): void;
}
