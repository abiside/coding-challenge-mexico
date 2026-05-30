<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * @return HasOne<ArbitrageSetting, $this>
     */
    public function arbitrageSetting(): HasOne
    {
        return $this->hasOne(ArbitrageSetting::class);
    }

    /**
     * @return HasMany<WalletBalance, $this>
     */
    public function walletBalances(): HasMany
    {
        return $this->hasMany(WalletBalance::class);
    }

    /**
     * @return HasMany<SimulationRun, $this>
     */
    public function simulationRuns(): HasMany
    {
        return $this->hasMany(SimulationRun::class);
    }

    /**
     * @return HasMany<ArbitrageStrategy, $this>
     */
    public function arbitrageStrategies(): HasMany
    {
        return $this->hasMany(ArbitrageStrategy::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
