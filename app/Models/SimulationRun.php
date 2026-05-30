<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SimulationRun extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_STOPPED = 'stopped';

    protected $fillable = [
        'user_id',
        'status',
        'config_snapshot',
        'started_at',
        'stopped_at',
    ];

    protected function casts(): array
    {
        return [
            'config_snapshot' => 'array',
            'started_at' => 'datetime',
            'stopped_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
