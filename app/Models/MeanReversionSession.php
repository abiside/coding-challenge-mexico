<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sesión de la estrategia de reversión a la media por usuario. El worker global
 * `meanrev:run` reconcilia estas sesiones en caliente: por cada sesión activa
 * levanta un engine aislado (billetera/posiciones propias) y lo derriba al
 * detenerse. Cada usuario prueba el modo de forma independiente.
 *
 * @property int $id
 * @property int $user_id
 * @property string $status
 * @property float $initial_usdt
 * @property array|null $params
 * @property array|null $wallet_snapshot
 * @property array|null $position_snapshot
 * @property float $realized_pnl
 */
class MeanReversionSession extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_STOPPED = 'stopped';

    protected $fillable = [
        'user_id',
        'status',
        'initial_usdt',
        'params',
        'wallet_snapshot',
        'position_snapshot',
        'realized_pnl',
        'started_at',
        'stopped_at',
    ];

    protected $casts = [
        'initial_usdt' => 'float',
        'realized_pnl' => 'float',
        'params' => 'array',
        'wallet_snapshot' => 'array',
        'position_snapshot' => 'array',
        'started_at' => 'datetime',
        'stopped_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
