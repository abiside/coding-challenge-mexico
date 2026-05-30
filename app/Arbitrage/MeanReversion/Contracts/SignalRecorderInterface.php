<?php

declare(strict_types=1);

namespace App\Arbitrage\MeanReversion\Contracts;

use App\Arbitrage\MeanReversion\DTO\ProcessedSignal;

interface SignalRecorderInterface
{
    public function record(ProcessedSignal $processed): void;
}
