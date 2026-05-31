<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Posición simulada (long o short) abierta/cerrada por una estrategia de
 * trading. El short se modela con USDT como colateral y P&L = (entry - exit) *
 * size. Es el histórico de operaciones del módulo.
 *
 * @property int $id
 * @property int $strategy_id
 * @property string $symbol
 * @property string $side
 * @property float $entry_price
 * @property float|null $exit_price
 * @property float $size
 * @property float $net_pnl
 * @property string $status
 */
class SimulatedPosition extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_STOPPED_OUT = 'stopped_out';

    public const STATUS_TAKE_PROFIT_HIT = 'take_profit_hit';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_LIQUIDATED = 'liquidated_simulated';

    protected $fillable = [
        'strategy_id',
        'user_id',
        'strategy_signal_id',
        'algorithm',
        'symbol',
        'side',
        'entry_price',
        'exit_price',
        'size',
        'notional',
        'leverage',
        'take_profit',
        'stop_loss',
        'gross_pnl',
        'fees',
        'funding_fee',
        'slippage',
        'net_pnl',
        'status',
        'open_reason',
        'close_reason',
        'opened_at_ms',
        'closed_at_ms',
        'idempotency_key',
    ];

    protected $casts = [
        'entry_price' => 'float',
        'exit_price' => 'float',
        'size' => 'float',
        'notional' => 'float',
        'leverage' => 'float',
        'take_profit' => 'float',
        'stop_loss' => 'float',
        'gross_pnl' => 'float',
        'fees' => 'float',
        'funding_fee' => 'float',
        'slippage' => 'float',
        'net_pnl' => 'float',
        'opened_at_ms' => 'integer',
        'closed_at_ms' => 'integer',
    ];

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(Strategy::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }
}
