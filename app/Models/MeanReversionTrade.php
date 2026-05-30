<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Operación ejecutada por la estrategia de reversión a la media (simulada).
 * Sirve de histórico para el panel del dashboard.
 *
 * @property int $id
 * @property string $exchange
 * @property string $symbol
 * @property string $side
 * @property string $reason
 * @property string $idempotency_key
 * @property int $executed_at_ms
 */
class MeanReversionTrade extends Model
{
    protected $fillable = [
        'user_id',
        'exchange',
        'symbol',
        'side',
        'reason',
        'price',
        'base_quantity',
        'quote_amount',
        'fee',
        'realized_pnl',
        'z_score',
        'idempotency_key',
        'executed_at_ms',
    ];

    protected $casts = [
        'price' => 'float',
        'base_quantity' => 'float',
        'quote_amount' => 'float',
        'fee' => 'float',
        'realized_pnl' => 'float',
        'z_score' => 'float',
        'executed_at_ms' => 'integer',
    ];
}
