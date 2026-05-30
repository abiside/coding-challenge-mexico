<?php

declare(strict_types=1);

namespace App\Arbitrage\MeanReversion\Persistence;

use App\Arbitrage\MeanReversion\Contracts\SignalRecorderInterface;
use App\Arbitrage\MeanReversion\DTO\ProcessedSignal;

final class NullSignalRecorder implements SignalRecorderInterface
{
    public function record(ProcessedSignal $processed): void
    {
    }
}
