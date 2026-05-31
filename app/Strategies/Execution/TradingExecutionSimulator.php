<?php

declare(strict_types=1);

namespace App\Strategies\Execution;

use App\Strategies\DTO\Side;
use App\Strategies\DTO\StrategySignal;

/**
 * Único escritor de la billetera del módulo. Abre y cierra posiciones simuladas
 * (long y short) aplicando fees de apertura/cierre, funding opcional y
 * actualizando el saldo libre de USDT. Modela el short con USDT como colateral:
 * al abrir bloquea el margen, al cerrar libera margen ± P&L.
 *
 *   margen = notional / leverage
 *   P&L bruto (long)  = (exit - entry)/entry * notional
 *   P&L bruto (short) = (entry - exit)/entry * notional
 *   P&L neto = bruto - fee_apertura - fee_cierre - funding
 */
final class TradingExecutionSimulator
{
    /** @var array<string, bool> */
    private array $executed = [];

    public function __construct(
        private readonly StrategyWallet $wallet,
        private readonly TradingPositionBook $positions,
        private readonly float $feeRate = 0.001,
        private readonly float $fundingFeePct = 0.0,
    ) {
    }

    public function reset(): void
    {
        $this->executed = [];
    }

    /**
     * Abre una posición a partir de una señal aprobada. Devuelve el registro de
     * la posición abierta (o null si ya existía esa clave de idempotencia o no
     * hay fondos suficientes para el margen).
     *
     * @return array<string, mixed>|null
     */
    public function open(StrategySignal $signal, float $notional, float $leverage, string $idempotencyKey, ?int $signalId): ?array
    {
        if (isset($this->executed[$idempotencyKey]) || $notional <= 0.0 || $signal->entryPrice <= 0.0) {
            return null;
        }

        $leverage = max(1.0, $leverage);
        $margin = $notional / $leverage;
        $feeOpen = $notional * $this->feeRate;

        if ($this->wallet->available() < ($margin + $feeOpen)) {
            return null;
        }

        $nowMs = (int) (microtime(true) * 1000);
        $size = $notional / $signal->entryPrice;

        $this->wallet->debit($margin + $feeOpen);

        $position = [
            'key' => $idempotencyKey,
            'symbol' => $signal->symbol,
            'side' => $signal->side->value,
            'entry_price' => $signal->entryPrice,
            'size' => $size,
            'notional' => $notional,
            'margin' => $margin,
            'leverage' => $leverage,
            'take_profit' => $signal->takeProfit,
            'stop_loss' => $signal->stopLoss,
            'fee_open' => $feeOpen,
            'opened_at_ms' => $nowMs,
            'max_holding_seconds' => $signal->maxHoldingSeconds,
            'signal_id' => $signalId,
            'open_reason' => $signal->reasons[0] ?? $signal->algorithm,
        ];

        $this->positions->open($position);
        $this->executed[$idempotencyKey] = true;

        return $position;
    }

    /**
     * Cierra una posición al precio dado y libera margen ± P&L. Devuelve el
     * resultado con desglose de costos y P&L neto, o null si no existe.
     *
     * @return array<string, mixed>|null
     */
    public function close(string $key, float $exitPrice, string $closeReason, string $status): ?array
    {
        $pos = $this->positions->close($key);
        if ($pos === null) {
            return null;
        }

        $notional = (float) $pos['notional'];
        $move = $pos['side'] === Side::Long->value
            ? ($exitPrice - $pos['entry_price'])
            : ($pos['entry_price'] - $exitPrice);
        $gross = $pos['entry_price'] > 0.0 ? ($move / $pos['entry_price']) * $notional : 0.0;

        $feeClose = $notional * $this->feeRate;
        $funding = $notional * ($this->fundingFeePct / 100.0);
        $feeOpen = (float) $pos['fee_open'];
        $net = $gross - $feeOpen - $feeClose - $funding;

        $this->wallet->credit($pos['margin'] + $gross - $feeClose - $funding);

        $nowMs = (int) (microtime(true) * 1000);

        return [
            'key' => $key,
            'symbol' => $pos['symbol'],
            'side' => $pos['side'],
            'entry_price' => $pos['entry_price'],
            'exit_price' => $exitPrice,
            'size' => $pos['size'],
            'notional' => $notional,
            'leverage' => $pos['leverage'],
            'take_profit' => $pos['take_profit'],
            'stop_loss' => $pos['stop_loss'],
            'gross_pnl' => round($gross, 8),
            'fees' => round($feeOpen + $feeClose, 8),
            'funding_fee' => round($funding, 8),
            'net_pnl' => round($net, 8),
            'status' => $status,
            'open_reason' => $pos['open_reason'],
            'close_reason' => $closeReason,
            'signal_id' => $pos['signal_id'],
            'opened_at_ms' => $pos['opened_at_ms'],
            'closed_at_ms' => $nowMs,
        ];
    }
}
