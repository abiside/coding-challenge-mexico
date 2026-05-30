<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Variante de configuración del engine para un usuario. Coexisten 1 champion
 * (la que está aplicada en ArbitrageSetting y persiste wallet real) y N
 * challengers shadow (corren en paralelo sobre la misma data de mercado).
 */
class ArbitrageStrategy extends Model
{
    public const STATUS_CHAMPION = 'champion';

    public const STATUS_CHALLENGER = 'challenger';

    public const STATUS_ARCHIVED = 'archived';

    public const ORIGIN_BASELINE = 'baseline';

    public const ORIGIN_MANUAL = 'manual';

    public const ORIGIN_AGENT = 'agent';

    protected $fillable = [
        'user_id',
        'name',
        'status',
        'origin',
        'parent_id',
        'generation',
        'config',
        'config_hash',
        'score',
        'rationale',
        'promoted_at',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'score' => 'float',
            'parent_id' => 'int',
            'generation' => 'int',
            'promoted_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<StrategyEvaluation, $this>
     */
    public function evaluations(): HasMany
    {
        return $this->hasMany(StrategyEvaluation::class, 'strategy_id');
    }

    public function isChampion(): bool
    {
        return $this->status === self::STATUS_CHAMPION;
    }

    public function isActive(): bool
    {
        return $this->status !== self::STATUS_ARCHIVED;
    }

    /**
     * Hash determinista de una config para deduplicar challengers idénticos y
     * detectar hot-reload sin diff profundo.
     *
     * @param  array<string, mixed>  $config
     */
    public static function hashConfig(array $config): string
    {
        $normalized = self::normalize($config);

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  mixed  $value
     * @return mixed
     */
    private static function normalize($value)
    {
        if (is_array($value)) {
            $sorted = [];
            $keys = array_keys($value);
            sort($keys);
            foreach ($keys as $key) {
                $sorted[$key] = self::normalize($value[$key]);
            }

            return $sorted;
        }

        return $value;
    }
}
