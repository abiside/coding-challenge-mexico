<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Arbitrage\Onboarding\DemoProvisioner;
use App\Models\ArbitrageSetting;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Refresca los datos de demo (símbolos triangulares + wallets con ETH) para
 * usuarios existentes. Los usuarios onboardeados antes de la introducción del
 * módulo triangular tienen settings con solo `BTC/USDT` y wallets sin ETH;
 * este comando los reprovisiona idempotentemente vía `DemoProvisioner`.
 *
 * Ejemplos:
 *   php artisan arbitrage:demo:refresh --user=4
 *   php artisan arbitrage:demo:refresh --all
 */
class RefreshArbitrageDemo extends Command
{
    protected $signature = 'arbitrage:demo:refresh
        {--user= : ID de usuario específico a reprovisionar}
        {--all : Reprovisionar TODOS los usuarios con ArbitrageSetting}';

    protected $description = 'Reprovisiona datos demo (símbolos triangulares + wallet ETH) para usuarios existentes.';

    public function handle(DemoProvisioner $provisioner): int
    {
        $userIds = $this->resolveUserIds();
        if ($userIds === []) {
            $this->error('Especifica --user=ID o --all para reprovisionar.');

            return self::FAILURE;
        }

        $count = 0;
        foreach ($userIds as $userId) {
            try {
                $result = $provisioner->provision($userId);
                $this->info(sprintf(
                    'Usuario %d reprovisionado: setting #%d, run #%d, símbolos=[%s]',
                    $userId,
                    (int) $result['setting']->id,
                    (int) $result['run']->id,
                    implode(', ', (array) $result['setting']->symbols),
                ));
                $count++;
            } catch (\Throwable $e) {
                $this->warn(sprintf('Usuario %d falló: %s', $userId, $e->getMessage()));
            }
        }

        $this->info(sprintf('Reprovisionados %d/%d usuarios.', $count, count($userIds)));

        return self::SUCCESS;
    }

    /**
     * @return array<int, int>
     */
    private function resolveUserIds(): array
    {
        $userOption = $this->option('user');
        if ($userOption !== null && $userOption !== '') {
            return [(int) $userOption];
        }

        if ((bool) $this->option('all')) {
            return ArbitrageSetting::query()
                ->pluck('user_id')
                ->map(static fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();
        }

        // Sin --user ni --all: por defecto, todos los usuarios reales (no
        // arroja error si no hay ninguno).
        return User::query()->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }
}
