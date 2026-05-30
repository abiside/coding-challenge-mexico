<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Arbitrage;

use App\Http\Controllers\Controller;
use App\Models\ArbitrageSetting;
use App\Models\BotEvent;
use App\Models\Opportunity;
use App\Models\SimulationRun;
use App\Models\Trade;
use App\Support\ArbitrageCacheKeys;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Salud del engine para la pantalla "Engine": estado de las conexiones por
 * exchange (derivado de la frescura de los snapshots en Redis), métricas reales
 * disponibles (simulación activa, trades, oportunidades, circuit breaker) y los
 * últimos eventos del bot como log. No inventa métricas: lo que no se conoce se
 * devuelve como null y el frontend lo muestra como "—".
 */
class EngineController extends Controller
{
    public function __invoke(Request $request, RedisFactory $redis): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $symbol = $this->resolveSymbol($request);
        $safeSymbol = strtolower(str_replace('/', '-', $symbol));
        $exchanges = array_values(array_unique((array) config('marketdata.exchanges', [])));
        $prefix = (string) config('arbitrage.input.channel_prefix', 'market');
        $connectionName = (string) config('arbitrage.input.redis_connection', 'default');
        $freshnessMs = (int) config('arbitrage.freshness_ms', 2000);
        $nowMs = (int) (microtime(true) * 1000);

        try {
            $connection = $redis->connection($connectionName);
        } catch (Throwable) {
            $connection = null;
        }

        $connections = [];
        foreach ($exchanges as $exchange) {
            $ticker = $connection ? $this->readLatest($connection, "{$prefix}:ticker:{$exchange}:{$safeSymbol}:latest") : null;
            $book = $connection ? $this->readLatest($connection, "{$prefix}:orderbook:{$exchange}:{$safeSymbol}:latest") : null;

            $timestampMs = (int) ($book['timestamp_ms'] ?? $ticker['timestamp_ms'] ?? 0);
            $ageMs = $timestampMs > 0 ? max(0, $nowMs - $timestampMs) : null;
            $hasData = $ticker !== null || $book !== null;

            $conn = 'recon';
            if ($hasData && $ageMs !== null) {
                $conn = $ageMs <= $freshnessMs ? 'ok' : ($ageMs <= $freshnessMs * 6 ? 'stale' : 'recon');
            }

            $connections[] = [
                'exchange' => $exchange,
                'conn' => $conn,
                'age_ms' => $ageMs,
                'type' => 'WebSocket',
                'has_data' => $hasData,
            ];
        }

        $setting = ArbitrageSetting::where('user_id', $userId)->first();
        $run = SimulationRun::where('user_id', $userId)
            ->where('status', SimulationRun::STATUS_ACTIVE)
            ->latest('id')
            ->first();

        $sinceHour = now()->subHour();
        $metrics = [
            'active' => $run !== null,
            'mode' => 'Demo',
            'started_at' => $run?->started_at,
            'circuit_breaker_enabled' => (bool) ($setting?->circuit_breaker_enabled ?? false),
            'trades_total' => Trade::where('user_id', $userId)->count(),
            'opportunities_total' => Opportunity::where('user_id', $userId)->count(),
            'opportunities_last_hour' => Opportunity::where('user_id', $userId)->where('created_at', '>=', $sinceHour)->count(),
            'executed_last_hour' => Opportunity::where('user_id', $userId)->where('decision', 'execute')->where('created_at', '>=', $sinceHour)->count(),
            'realized_pnl' => round((float) Trade::where('user_id', $userId)->sum('realized_pnl'), 8),
        ];

        $logs = BotEvent::where('user_id', $userId)
            ->latest('id')
            ->limit(20)
            ->get(['id', 'type', 'level', 'symbol', 'payload', 'created_at'])
            ->map(static function (BotEvent $event): array {
                return [
                    'id' => $event->id,
                    'level' => self::normalizeLevel((string) ($event->level ?? 'info')),
                    'symbol' => $event->symbol,
                    'message' => self::messageFromType((string) $event->type, (array) $event->payload),
                    'type' => $event->type,
                    'created_at' => $event->created_at,
                ];
            })
            ->values();

        // Último snapshot de métricas en vivo del engine (embudo de descartes +
        // decisiones), escrito por el heartbeat de arbitrage:run. El frontend lo
        // usa como estado inicial y luego recibe updates por websocket. Si el
        // engine no está corriendo, queda null y la UI muestra "—".
        $live = cache()->get(ArbitrageCacheKeys::engineMetrics($userId));

        return response()->json([
            'connections' => $connections,
            'metrics' => $metrics,
            'live' => is_array($live) ? $live : null,
            'logs' => $logs,
            'server_time_ms' => $nowMs,
        ]);
    }

    private static function normalizeLevel(string $level): string
    {
        $level = strtolower($level);

        return in_array($level, ['info', 'warn', 'error'], true)
            ? $level
            : ($level === 'warning' ? 'warn' : 'info');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function messageFromType(string $type, array $payload): string
    {
        $extra = isset($payload['challenger_id']) ? ' #'.$payload['challenger_id'] : '';

        return $type.$extra;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readLatest(mixed $connection, string $key): ?array
    {
        try {
            $raw = $connection->get($key);
        } catch (Throwable) {
            return null;
        }

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function resolveSymbol(Request $request): string
    {
        $setting = $request->user()->arbitrageSetting;
        if ($setting !== null && ! empty($setting->symbols)) {
            return (string) $setting->symbols[0];
        }

        $symbols = array_values((array) config('arbitrage.symbols', ['BTC/USDT']));

        return (string) ($symbols[0] ?? 'BTC/USDT');
    }
}
