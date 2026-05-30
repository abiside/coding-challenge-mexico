<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WalletBalance extends Model
{
    protected $fillable = [
        'user_id',
        'exchange',
        'asset',
        'available',
        'locked',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'available' => 'float',
            'locked' => 'float',
            'version' => 'int',
        ];
    }
}
