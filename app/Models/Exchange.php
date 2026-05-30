<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Exchange extends Model
{
    protected $fillable = [
        'name',
        'label',
        'taker_fee',
        'maker_fee',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'taker_fee' => 'float',
            'maker_fee' => 'float',
            'enabled' => 'bool',
        ];
    }
}
