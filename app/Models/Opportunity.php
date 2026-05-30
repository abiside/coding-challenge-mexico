<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Opportunity extends Model
{
    protected $fillable = [
        'user_id',
        'strategy_id',
        'symbol',
        'buy_exchange',
        'sell_exchange',
        'buy_ask',
        'sell_bid',
        'gross_spread_bps',
        'base_volume',
        'weighted_buy_price',
        'weighted_sell_price',
        'gross_profit',
        'net_profit',
        'realized_pnl',
        'execution_delta',
        'net_margin',
        'total_costs',
        'buy_fee',
        'sell_fee',
        'slippage_cost',
        'latency_penalty',
        'fixed_cost',
        'partial_fill',
        'decision',
        'reasons',
        'detected_at_ms',
    ];

    protected function casts(): array
    {
        return [
            'buy_ask' => 'float',
            'sell_bid' => 'float',
            'gross_spread_bps' => 'float',
            'base_volume' => 'float',
            'weighted_buy_price' => 'float',
            'weighted_sell_price' => 'float',
            'gross_profit' => 'float',
            'net_profit' => 'float',
            'realized_pnl' => 'float',
            'execution_delta' => 'float',
            'net_margin' => 'float',
            'total_costs' => 'float',
            'buy_fee' => 'float',
            'sell_fee' => 'float',
            'slippage_cost' => 'float',
            'latency_penalty' => 'float',
            'fixed_cost' => 'float',
            'partial_fill' => 'bool',
            'reasons' => 'array',
            'detected_at_ms' => 'int',
        ];
    }

    /**
     * @return HasMany<Trade, $this>
     */
    public function trades(): HasMany
    {
        return $this->hasMany(Trade::class);
    }
}
