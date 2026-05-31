<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Strategies;

use App\Http\Controllers\Controller;
use App\Models\SimulatedPosition;
use App\Models\Strategy;
use App\Models\Trade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Vista consolidada de TODAS las transacciones del usuario (arbitraje +
 * trading), etiquetadas por estrategia, con datos de monitoreo (side, entrada/
 * salida, fees, P&L neto, duración, razón de cierre). Permite filtrar por tipo
 * o por instancia de estrategia.
 */
class TransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $limit = max(1, min(500, (int) $request->query('limit', 150)));
        $type = (string) $request->query('type', 'all'); // all | trading | cross_exchange
        $strategyId = $request->query('strategy_id');

        $strategies = Strategy::where('user_id', $userId)->get()->keyBy('id');
        $crossName = optional($strategies->firstWhere('type', Strategy::TYPE_CROSS_EXCHANGE))->name ?? 'Arbitraje cross-exchange';

        $rows = [];

        // Trading: posiciones simuladas cerradas (+ abiertas como en curso).
        if ($type === 'all' || $type === 'trading') {
            $q = SimulatedPosition::where('user_id', $userId);
            if ($strategyId !== null) {
                $q->where('strategy_id', (int) $strategyId);
            }
            foreach ($q->latest('id')->limit($limit)->get() as $p) {
                $strategy = $strategies->get($p->strategy_id);
                $rows[] = [
                    'source' => 'trading',
                    'strategy_id' => (int) $p->strategy_id,
                    'strategy_name' => $strategy->name ?? 'Trading',
                    'strategy_type' => 'trading',
                    'algorithm' => $p->algorithm,
                    'symbol' => $p->symbol,
                    'side' => $p->side,
                    'entry_price' => (float) $p->entry_price,
                    'exit_price' => $p->exit_price !== null ? (float) $p->exit_price : null,
                    'size' => (float) $p->size,
                    'notional' => (float) $p->notional,
                    'fees' => (float) $p->fees + (float) $p->funding_fee,
                    'net_pnl' => (float) $p->net_pnl,
                    'status' => $p->status,
                    'reason' => $p->close_reason ?? $p->open_reason,
                    'opened_at_ms' => (int) $p->opened_at_ms,
                    'closed_at_ms' => $p->closed_at_ms !== null ? (int) $p->closed_at_ms : null,
                    'ts_ms' => (int) ($p->closed_at_ms ?? $p->opened_at_ms),
                ];
            }
        }

        // Cross-exchange: trades del arbitraje.
        if (($type === 'all' || $type === 'cross_exchange') && $strategyId === null) {
            foreach (Trade::where('user_id', $userId)->latest('id')->limit($limit)->get() as $t) {
                $rows[] = [
                    'source' => 'arbitrage',
                    'strategy_id' => null,
                    'strategy_name' => $crossName,
                    'strategy_type' => 'cross_exchange',
                    'algorithm' => 'arbitrage',
                    'symbol' => $t->symbol,
                    'side' => 'arbitrage',
                    'entry_price' => null,
                    'exit_price' => null,
                    'size' => (float) $t->base_volume,
                    'notional' => null,
                    'fees' => null,
                    'net_pnl' => (float) $t->realized_pnl,
                    'status' => $t->status,
                    'reason' => $t->buy_exchange.' → '.$t->sell_exchange,
                    'opened_at_ms' => (int) $t->executed_at_ms,
                    'closed_at_ms' => (int) $t->executed_at_ms,
                    'ts_ms' => (int) $t->executed_at_ms,
                ];
            }
        }

        usort($rows, static fn ($a, $b) => $b['ts_ms'] <=> $a['ts_ms']);
        $rows = array_slice($rows, 0, $limit);

        $realized = array_sum(array_map(static fn ($r) => $r['net_pnl'] ?? 0.0, $rows));

        return response()->json([
            'data' => $rows,
            'summary' => [
                'count' => count($rows),
                'realized_pnl' => round((float) $realized, 4),
            ],
        ]);
    }
}
