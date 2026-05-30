<?php

declare(strict_types=1);

namespace App\Arbitrage\MeanReversion\Persistence;

use App\Arbitrage\MeanReversion\Contracts\SignalRecorderInterface;
use App\Arbitrage\MeanReversion\DTO\ProcessedSignal;
use App\Arbitrage\Risk\Decision;
use Psr\Log\LoggerInterface;

/**
 * Registra cada señal accionada en el log. Solo persiste las decisiones de la
 * whitelist (por defecto solo ejecuciones), para no inundar el log con ruido.
 */
final class LoggerSignalRecorder implements SignalRecorderInterface
{
    /**
     * @param  array<int, string>  $recordDecisions
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly array $recordDecisions = ['execute'],
    ) {
    }

    public function record(ProcessedSignal $processed): void
    {
        if (! in_array($processed->decision->decision->value, $this->recordDecisions, true)) {
            return;
        }

        $context = [
            'candidate' => $processed->candidate->toArray(),
            'decision' => $processed->decision->decision->value,
            'reasons' => $processed->decision->reasons,
            'simulation' => $processed->simulation?->toArray(),
        ];

        if ($processed->decision->decision === Decision::Execute) {
            $this->logger->info('[meanrev][trade]', $context);

            return;
        }

        $this->logger->debug('[meanrev][signal]', $context);
    }
}
