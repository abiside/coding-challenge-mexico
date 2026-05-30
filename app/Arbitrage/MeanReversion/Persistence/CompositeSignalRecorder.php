<?php

declare(strict_types=1);

namespace App\Arbitrage\MeanReversion\Persistence;

use App\Arbitrage\MeanReversion\Contracts\SignalRecorderInterface;
use App\Arbitrage\MeanReversion\DTO\ProcessedSignal;

/**
 * Fan-out: reenvía cada señal procesada a varios recorders (log, DB, broadcast).
 * Un fallo en uno no debe afectar a los demás.
 */
final class CompositeSignalRecorder implements SignalRecorderInterface
{
    /** @var array<int, SignalRecorderInterface> */
    private array $recorders;

    public function __construct(SignalRecorderInterface ...$recorders)
    {
        $this->recorders = $recorders;
    }

    public function record(ProcessedSignal $processed): void
    {
        foreach ($this->recorders as $recorder) {
            $recorder->record($processed);
        }
    }
}
