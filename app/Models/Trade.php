<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Trade extends Model
{
    protected $fillable = [
        'user_id',
        'strategy_id',
        'opportunity_id',
        'symbol',
        'buy_exchange',
        'sell_exchange',
        'base_volume',
        'realized_pnl',
        'status',
        'idempotency_key',
        'executed_at_ms',
    ];

    protected function casts(): array
    {
        return [
            'base_volume' => 'float',
            'realized_pnl' => 'float',
            'executed_at_ms' => 'int',
        ];
    }

    /**
     * @return BelongsTo<Opportunity, $this>
     */
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    /**
     * @return HasMany<TradeFill, $this>
     */
    public function fills(): HasMany
    {
        return $this->hasMany(TradeFill::class);
    }
}
