<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Recomendación del AI Supervisor. Es una capa de análisis/explicación: NUNCA
 * ejecuta operaciones; solo resume el mercado, prioriza estrategias y sugiere
 * ajustes de parámetros de forma auditable.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $strategy_id
 * @property string $type
 * @property string|null $summary
 * @property array|null $payload
 * @property string $severity
 * @property string $status
 * @property string $source
 */
class AiRecommendation extends Model
{
    protected $fillable = [
        'user_id',
        'strategy_id',
        'type',
        'summary',
        'payload',
        'severity',
        'status',
        'source',
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}
