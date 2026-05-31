<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Convierte cada sesión de reversión a la media existente en una instancia de
 * estrategia de trading (algorithm=mean_reversion_long), para que el nuevo
 * worker `strategies:run` la reconozca sin perder la billetera/posiciones del
 * usuario. No borra las tablas legacy de meanrev.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mean_reversion_sessions') || ! Schema::hasTable('strategies')) {
            return;
        }

        $sessions = DB::table('mean_reversion_sessions')->get();

        foreach ($sessions as $session) {
            $exists = DB::table('strategies')
                ->where('user_id', $session->user_id)
                ->where('type', 'trading')
                ->where('algorithm', 'mean_reversion_long')
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('strategies')->insert([
                'user_id' => $session->user_id,
                'name' => 'Reversión a la media (long)',
                'type' => 'trading',
                'algorithm' => 'mean_reversion_long',
                'status' => $session->status === 'active' ? 'active' : 'stopped',
                'enabled' => true,
                'initial_usdt' => $session->initial_usdt,
                'config' => $session->params,
                'wallet_snapshot' => $session->wallet_snapshot,
                'position_snapshot' => $session->position_snapshot,
                'realized_pnl' => $session->realized_pnl,
                'started_at' => $session->started_at,
                'stopped_at' => $session->stopped_at,
                'created_at' => $session->created_at ?? now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Sin rollback de datos: las sesiones legacy permanecen intactas.
    }
};
