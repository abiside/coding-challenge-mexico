<?php

declare(strict_types=1);

namespace App\Arbitrage\MeanReversion\Persistence;

use App\Arbitrage\MeanReversion\Contracts\SignalRecorderInterface;
use App\Arbitrage\MeanReversion\DTO\ProcessedSignal;
use App\Arbitrage\Risk\Decision;
use App\Models\MeanReversionTrade;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Persiste en DB cada ejecución (no duplicada) para el histórico del panel.
 * Solo guarda movimientos reales (decisión = execute); rechazos/ignores no se
 * persisten. El volumen es bajo (cooldowns + warmup), así que escribe directo
 * sin buffer. Cualquier fallo de DB se loguea pero NO interrumpe el loop.
 */
final class DatabaseSignalRecorder implements SignalRecorderInterface
{
    public function __construct(
        private readonly int $userId,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function record(ProcessedSignal $processed): void
    {
        if ($processed->decision->decision !== Decision::Execute) {
            return;
        }

        $sim = $processed->simulation;
        if ($sim === null || $sim->duplicate) {
            return;
        }

        try {
            // La clave de idempotencia del candidato es de mercado (igual para
            // todos los usuarios), así que la scope-amos por usuario para no
            // colisionar en la unique global.
            MeanReversionTrade::updateOrCreate(
                ['idempotency_key' => 'u'.$this->userId.'|'.$sim->idempotencyKey],
                [
                    'user_id' => $this->userId,
                    'exchange' => $sim->exchange,
                    'symbol' => $sim->symbol,
                    'side' => $sim->side->value,
                    'reason' => $processed->candidate->reason,
                    'price' => $sim->price,
                    'base_quantity' => $sim->baseQuantity,
                    'quote_amount' => $sim->quoteAmount,
                    'fee' => $sim->fee,
                    'realized_pnl' => $sim->realizedPnl,
                    'z_score' => $processed->candidate->zScore,
                    'executed_at_ms' => $sim->executedAtMs,
                ],
            );
        } catch (Throwable $e) {
            $this->logger?->warning('[meanrev][db] no se pudo persistir trade', [
                'error' => $e->getMessage(),
                'symbol' => $sim->symbol,
            ]);
        }
    }
}
