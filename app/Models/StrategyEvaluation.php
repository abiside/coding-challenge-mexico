<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Métricas agregadas por ventana para una estrategia. Es el "log de
 * estrategias" que retroalimenta al optimizador y al LLM.
 */
class StrategyEvaluation extends Model
{
    protected $fillable = [
        'strategy_id',
        'user_id',
        'window_start_ms',
        'window_end_ms',
        'snapshots',
        'candidates',
        'executions',
        'rejects',
        'ignores',
        'realized_pnl',
        'executed_volume',
        'avg_margin',
        'score',
    ];

    protected function casts(): array
    {
        return [
            'window_start_ms' => 'int',
            'window_end_ms' => 'int',
            'snapshots' => 'int',
            'candidates' => 'int',
            'executions' => 'int',
            'rejects' => 'int',
            'ignores' => 'int',
            'realized_pnl' => 'float',
            'executed_volume' => 'float',
            'avg_margin' => 'float',
            'score' => 'float',
        ];
    }

    /**
     * @return BelongsTo<ArbitrageStrategy, $this>
     */
    public function strategy(): BelongsTo
    {
        return $this->belongsTo(ArbitrageStrategy::class, 'strategy_id');
    }
}
