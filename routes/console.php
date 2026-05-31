<?php

use App\Services\Parallel\ReactParallelRunner;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Autopilot AI: corre el optimizador cada N minutos sobre usuarios con
// autopilot_enabled + simulación activa. La creación de challengers y la
// promoción del champion se aplican dentro del comando.
Schedule::command('arbitrage:optimize')
    ->cron((string) env('AUTOPILOT_CRON', '*/5 * * * *'))
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

// Retención: purga el historial de series de tiempo anterior a las últimas N
// horas (config arbitrage.retention.hours, 8 por defecto) para mantener la base
// acotada durante simulaciones/demo continuas.
if ((bool) config('arbitrage.retention.enabled', true)) {
    Schedule::command('arbitrage:prune')
        ->cron((string) env('ARBITRAGE_PRUNE_CRON', '0 * * * *'))
        ->withoutOverlapping()
        ->onOneServer()
        ->runInBackground();
}

// AI Supervisor del módulo de Estrategias: corre fuera del loop ReactPHP y, por
// cada usuario con estrategias de trading, resume el mercado/performance y
// persiste recomendaciones (solo opina, nunca ejecuta). Degrada a un resumen
// determinista si no hay API key del LLM.
Schedule::command('strategies:supervise')
    ->cron((string) config('ai.supervisor.cron', '*/15 * * * *'))
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('react:task-worker {taskId} {seconds}', function (int $taskId, int $seconds): void {
    $startedAt = microtime(true);
    sleep($seconds);
    $endedAt = microtime(true);

    $this->line(json_encode([
        'task_id' => $taskId,
        'ran_seconds' => $seconds,
        'started_at' => date('c', (int) $startedAt),
        'ended_at' => date('c', (int) $endedAt),
        'elapsed_ms' => (int) round(($endedAt - $startedAt) * 1000),
    ], JSON_THROW_ON_ERROR));
})->purpose('Worker interno para ejecucion paralela con ReactPHP');

Artisan::command('tasks:parallel-demo {--tasks=6} {--max=3}', function (): void {
    $tasksCount = max((int) $this->option('tasks'), 1);
    $maxConcurrency = max((int) $this->option('max'), 1);

    $tasksDurations = [];
    for ($taskId = 1; $taskId <= $tasksCount; $taskId++) {
        $tasksDurations[$taskId] = random_int(1, 4);
    }

    $this->components->info("Ejecutando {$tasksCount} tareas con concurrencia maxima {$maxConcurrency}...");
    $this->line('Duraciones esperadas (s): '.json_encode($tasksDurations, JSON_THROW_ON_ERROR));

    /** @var ReactParallelRunner $runner */
    $runner = app(ReactParallelRunner::class);
    $batchStartedAt = microtime(true);

    $results = $runner->run(
        tasksDurations: $tasksDurations,
        maxConcurrency: $maxConcurrency,
        onProgress: function (array $event): void {
            if (($event['type'] ?? null) === 'started') {
                $this->line(sprintf(
                    '[START] Task %d (%ds)',
                    $event['task_id'],
                    $event['seconds']
                ));

                return;
            }

            if (($event['type'] ?? null) === 'finished') {
                $this->line(sprintf(
                    '[DONE]  Task %d (exit=%s, reported=%ss)',
                    $event['task_id'],
                    (string) $event['exit_code'],
                    (string) ($event['reported_seconds'] ?? 'n/a')
                ));
            }
        }
    );

    $totalElapsedMs = (int) round((microtime(true) - $batchStartedAt) * 1000);

    $rows = array_map(static fn (array $result): array => [
        (string) $result['task_id'],
        (string) ($result['ran_seconds'] ?? $result['expected_seconds']),
        (string) ($result['elapsed_ms'] ?? 'n/a'),
        (string) $result['exit_code'],
    ], $results);

    $this->table(['task_id', 'ran_seconds', 'elapsed_ms', 'exit_code'], $rows);
    $this->newLine();
    $this->components->info("Tiempo total del lote: {$totalElapsedMs}ms");
    $this->comment('Tip: sube --tasks y --max para probar mayor paralelismo con ReactPHP.');
})->purpose('Demo de paralelismo de tareas con ReactPHP y procesos hijos');
