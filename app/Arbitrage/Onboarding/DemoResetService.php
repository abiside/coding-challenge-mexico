<?php

declare(strict_types=1);

namespace App\Arbitrage\Onboarding;

use App\Models\ArbitrageStrategy;
use App\Models\BotEvent;
use App\Models\Opportunity;
use App\Models\StrategyEvaluation;
use App\Models\Trade;
use App\Models\TradeFill;
use App\Models\TriangularOpportunity;
use Illuminate\Support\Facades\DB;

/**
 * Reinicia "todo a empezar" para un usuario: borra TODA su data de transacciones
 * (oportunidades de 2 patas y triangulares, trades + fills) y de aprendizaje
 * (champion + challengers + sus evaluaciones, eventos del bot) y restaura las
 * wallets a su saldo inicial de demo.
 *
 * Conserva la configuración (ArbitrageSetting) y la sesión de simulación: el
 * engine recrea perezosamente un champion baseline fresco vía StrategyResolver,
 * por lo que el proceso continúa pero como si arrancara de cero.
 *
 * Las FKs de strategy_id en opportunities/trades/bot_events son nullOnDelete y
 * strategy_evaluations es cascadeOnDelete; aun así borramos cada tabla por
 * user_id de forma explícita para no dejar nada del usuario.
 */
final class DemoResetService
{
    public function __construct(private readonly DemoProvisioner $provisioner) {}

    /**
     * @return array<string, int> registros borrados por tabla
     */
    public function reset(int $userId, bool $resetWallets = true): array
    {
        $counts = DB::transaction(function () use ($userId): array {
            $tradeIds = Trade::where('user_id', $userId)->pluck('id');

            $c = [];
            $c['trade_fills'] = $tradeIds->isEmpty()
                ? 0
                : TradeFill::whereIn('trade_id', $tradeIds)->delete();
            $c['trades'] = Trade::where('user_id', $userId)->delete();
            $c['opportunities'] = Opportunity::where('user_id', $userId)->delete();
            $c['triangular_opportunities'] = TriangularOpportunity::where('user_id', $userId)->delete();
            $c['strategy_evaluations'] = StrategyEvaluation::where('user_id', $userId)->delete();
            $c['bot_events'] = BotEvent::where('user_id', $userId)->delete();
            $c['strategies'] = ArbitrageStrategy::where('user_id', $userId)->delete();

            return $c;
        });

        if ($resetWallets) {
            $this->provisioner->resetWallets($userId);
        }

        return $counts;
    }
}
