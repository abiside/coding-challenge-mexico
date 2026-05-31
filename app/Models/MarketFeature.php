<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Muestra de features de mercado por símbolo (auditoría / entrada para el AI
 * Supervisor). Se persiste muestreado, no por tick.
 */
class MarketFeature extends Model
{
    protected $fillable = [
        'symbol',
        'exchange',
        'mid_price',
        'return_1m',
        'return_5m',
        'volume_spike',
        'z_score',
        'spread_pct',
        'bid_depth',
        'ask_depth',
        'imbalance',
        'volatility',
    ];

    protected $casts = [
        'mid_price' => 'float',
        'return_1m' => 'float',
        'return_5m' => 'float',
        'volume_spike' => 'float',
        'z_score' => 'float',
        'spread_pct' => 'float',
        'bid_depth' => 'float',
        'ask_depth' => 'float',
        'imbalance' => 'float',
        'volatility' => 'float',
    ];
}
