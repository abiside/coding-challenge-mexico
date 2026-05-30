<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TriangularOpportunity extends Model
{
    protected $fillable = [
        'user_id',
        'strategy_id',
        'label',
        'start_asset',
        'start_exchange',
        'cycle_length',
        'gross_spread_bps',
        'net_rate_product',
        'start_amount',
        'end_amount',
        'gross_profit',
        'net_profit',
        'net_margin',
        'total_costs',
        'total_fees',
        'latency_penalty',
        'fixed_cost',
        'realized_pnl',
        'execution_delta',
        'partial_fill',
        'decision',
        'reasons',
        'legs',
        'exchanges',
        'idempotency_key',
        'detected_at_ms',
        'executed_at_ms',
        'evaluation_latency_us',
    ];

    protected function casts(): array
    {
        return [
            'cycle_length' => 'int',
            'gross_spread_bps' => 'float',
            'net_rate_product' => 'float',
            'start_amount' => 'float',
            'end_amount' => 'float',
            'gross_profit' => 'float',
            'net_profit' => 'float',
            'net_margin' => 'float',
            'total_costs' => 'float',
            'total_fees' => 'float',
            'latency_penalty' => 'float',
            'fixed_cost' => 'float',
            'realized_pnl' => 'float',
            'execution_delta' => 'float',
            'partial_fill' => 'bool',
            'reasons' => 'array',
            'legs' => 'array',
            'exchanges' => 'array',
            'detected_at_ms' => 'int',
            'executed_at_ms' => 'int',
            'evaluation_latency_us' => 'int',
        ];
    }
}
