<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Purga el historial de series de tiempo del arbitraje, conservando solo las
 * últimas N horas (8 por defecto). En modo simulación/demo el engine produce un
 * flujo continuo de oportunidades, trades, evaluaciones y eventos que crece sin
 * límite; este comando mantiene la base acotada.
 *
 * NO toca tablas de configuración ni estado (arbitrage_strategies,
 * arbitrage_settings, simulation_runs, wallet_balances, exchanges, users): solo
 * las tablas de observación/eventos listadas en TABLES.
 *
 * El borrado es por lotes (chunk) usando los IDs, para ser portable entre MySQL
 * y SQLite (que no soporta DELETE ... LIMIT) y no bloquear con transacciones
 * gigantes. `trade_fills` se borra en cascada al eliminar `trades` (FK
 * cascadeOnDelete), pero también se purga por su propio `created_at` como red de
 * seguridad ante huérfanos.
 *
 * Ejemplos:
 *   php artisan arbitrage:prune
 *   php artisan arbitrage:prune --hours=8
 *   php artisan arbitrage:prune --dry-run
 */
class PruneArbitrageHistory extends Command
{
    protected $signature = 'arbitrage:prune
        {--hours= : Horas a conservar (default: config arbitrage.retention.hours)}
        {--chunk= : Tamaño de lote por borrado (default: config arbitrage.retention.chunk)}
        {--dry-run : Solo reporta cuántos registros se borrarían, sin borrar}';

    protected $description = 'Purga el historial de arbitraje anterior a las últimas N horas (8 por defecto).';

    /**
     * Tablas de series de tiempo a purgar, todas con columna `created_at`.
     * El orden borra primero hijos/dependientes para minimizar trabajo de
     * cascada. `trade_fills` cae en cascada con `trades`.
     *
     * @var array<int, string>
     */
    private const TABLES = [
        'trade_fills',
        'trades',
        'opportunities',
        'triangular_opportunities',
        'strategy_evaluations',
        'bot_events',
    ];

    public function handle(): int
    {
        $hours = (int) ($this->option('hours') ?? config('arbitrage.retention.hours', 8));
        if ($hours < 1) {
            $this->error('--hours debe ser >= 1.');

            return self::FAILURE;
        }

        $chunk = max(1, (int) ($this->option('chunk') ?? config('arbitrage.retention.chunk', 5000)));
        $dryRun = (bool) $this->option('dry-run');

        $cutoff = Carbon::now()->subHours($hours);

        $this->info(sprintf(
            '%sPurgando registros con created_at < %s (conservando últimas %dh)...',
            $dryRun ? '[dry-run] ' : '',
            $cutoff->toDateTimeString(),
            $hours,
        ));

        $grandTotal = 0;
        $rows = [];

        foreach (self::TABLES as $table) {
            $deleted = $dryRun
                ? (int) DB::table($table)->where('created_at', '<', $cutoff)->count()
                : $this->pruneTable($table, $cutoff, $chunk);

            $grandTotal += $deleted;
            $rows[] = [$table, number_format($deleted)];
        }

        $this->table(['Tabla', $dryRun ? 'A borrar' : 'Borrados'], $rows);
        $this->info(sprintf(
            '%s%s registros en total.',
            $dryRun ? '[dry-run] ' : 'Purga completa: ',
            number_format($grandTotal),
        ));

        return self::SUCCESS;
    }

    /**
     * Borra en lotes por ID para portabilidad y bloqueos cortos.
     */
    private function pruneTable(string $table, Carbon $cutoff, int $chunk): int
    {
        $total = 0;

        do {
            $ids = DB::table($table)
                ->where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->limit($chunk)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $total += DB::table($table)->whereIn('id', $ids)->delete();
        } while ($ids->count() === $chunk);

        return $total;
    }
}
