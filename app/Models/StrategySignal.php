<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Señal detectada por una estrategia de trading antes (o después) de pasar por
 * el Risk Manager. Sirve de feed y auditoría en el dashboard.
 *
 * @property int $id
 * @property int $strategy_id
 * @property string $symbol
 * @property string $side
 * @property float $confidence_score
 * @property string $status
 */
class StrategySignal extends Model
{
    protected $fillable = [
        'strategy_id',
        'user_id',
        'algorithm',
        'symbol',
        'side',
        'confidence_score',
        'entry_price',
        'suggested_size',
        'take_profit',
        'stop_loss',
        'max_holding_time',
        'status',
        'reasons',
        'risk_flags',
        'detected_at_ms',
    ];

    protected $casts = [
        'confidence_score' => 'float',
        'entry_price' => 'float',
        'suggested_size' => 'float',
        'take_profit' => 'float',
        'stop_loss' => 'float',
        'max_holding_time' => 'integer',
        'reasons' => 'array',
        'risk_flags' => 'array',
        'detected_at_ms' => 'integer',
    ];

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(Strategy::class);
    }
}
