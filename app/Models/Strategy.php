<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Instancia de estrategia creada por un usuario. Puede ser de tipo
 * `cross_exchange` (envuelve el arbitraje existente) o `trading` (módulo de
 * estrategias long/short simuladas). El worker `strategies:run` reconcilia las
 * instancias trading activas: levanta un engine aislado por cada una.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $type
 * @property string|null $algorithm
 * @property string $status
 * @property bool $enabled
 * @property float $initial_usdt
 * @property array|null $config
 * @property array|null $wallet_snapshot
 * @property array|null $position_snapshot
 * @property float $realized_pnl
 */
class Strategy extends Model
{
    public const TYPE_TRADING = 'trading';

    public const TYPE_CROSS_EXCHANGE = 'cross_exchange';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_STOPPED = 'stopped';

    protected $fillable = [
        'user_id',
        'name',
        'type',
        'algorithm',
        'status',
        'enabled',
        'initial_usdt',
        'config',
        'wallet_snapshot',
        'position_snapshot',
        'realized_pnl',
        'started_at',
        'stopped_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'initial_usdt' => 'float',
        'realized_pnl' => 'float',
        'config' => 'array',
        'wallet_snapshot' => 'array',
        'position_snapshot' => 'array',
        'started_at' => 'datetime',
        'stopped_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function signals(): HasMany
    {
        return $this->hasMany(StrategySignal::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(SimulatedPosition::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isTrading(): bool
    {
        return $this->type === self::TYPE_TRADING;
    }
}
