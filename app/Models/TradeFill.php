<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradeFill extends Model
{
    protected $fillable = [
        'trade_id',
        'side',
        'exchange',
        'symbol',
        'base_volume',
        'price',
        'notional',
        'fee',
    ];

    protected function casts(): array
    {
        return [
            'base_volume' => 'float',
            'price' => 'float',
            'notional' => 'float',
            'fee' => 'float',
        ];
    }

    /**
     * @return BelongsTo<Trade, $this>
     */
    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }
}
