<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'strategy_id',
        'type',
        'level',
        'symbol',
        'payload',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
