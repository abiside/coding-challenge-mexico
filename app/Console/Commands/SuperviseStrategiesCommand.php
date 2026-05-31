<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Strategy;
use App\Models\User;
use App\Strategies\Supervisor\StrategySupervisor;
use Illuminate\Console\Command;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * AI Supervisor del módulo de Estrategias. Corre periódicamente (ver
 * routes/console.php), FUERA del loop ReactPHP. Por cada usuario con al menos
 * una estrategia de trading, agrega un resumen (régimen, top señales,
 * performance, salud) y persiste recomendaciones auditables en
 * `ai_recommendations`. NUNCA ejecuta operaciones ni toca balances.
 */
class SuperviseStrategiesCommand extends Command
{
    protected $signature = 'strategies:supervise
        {--user= : Solo este usuario (id)}';

    protected $description = 'AI Supervisor: resume el mercado y genera recomendaciones (no ejecuta nada) por usuario.';

    public function handle(StrategySupervisor $supervisor, LoggerInterface $logger): int
    {
        if (! (bool) config('strategies.enabled', false)) {
            $this->info('Módulo de estrategias deshabilitado; nada que supervisar.');

            return self::SUCCESS;
        }

        $userIds = $this->resolveUserIds();
        if ($userIds === []) {
            $this->info('No hay usuarios con estrategias de trading.');

            return self::SUCCESS;
        }

        foreach ($userIds as $userId) {
            $user = User::find($userId);
            if ($user === null) {
                continue;
            }

            try {
                $result = $supervisor->supervise($user);
                if (($result['skipped'] ?? false) === true) {
                    $this->line(sprintf('Usuario %d: omitido (%s).', $userId, $result['reason'] ?? '—'));

                    continue;
                }
                $this->info(sprintf(
                    'Usuario %d: %d recomendación(es) [%s] · %d alertas · %d sugerencias.',
                    $userId,
                    (int) ($result['created'] ?? 0),
                    (string) ($result['source'] ?? '—'),
                    (int) ($result['alerts'] ?? 0),
                    (int) ($result['suggestions'] ?? 0),
                ));
            } catch (Throwable $e) {
                $logger->error('[strategies][supervisor] falló para un usuario', [
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
                $this->error(sprintf('Usuario %d: error: %s', $userId, $e->getMessage()));
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, int>
     */
    private function resolveUserIds(): array
    {
        $only = $this->option('user');
        if ($only !== null && $only !== '') {
            return [(int) $only];
        }

        return Strategy::query()
            ->where('type', Strategy::TYPE_TRADING)
            ->distinct()
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
