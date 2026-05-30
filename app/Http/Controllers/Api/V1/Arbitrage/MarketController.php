<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Arbitrage;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Estado de mercado por exchange para el dashboard (pantalla "Mercado" y la
 * tabla del dashboard). Lee los snapshots `:latest` que deja `market:feed` en
 * Redis (ticker + orderbook) y los normaliza a filas por exchange. No calcula
 * arbitraje: solo expone el último estado observado de cada conector.
 */
class MarketController extends Controller
{
    public function __invoke(Request $request, RedisFactory $redis): JsonResponse
    {
        $symbol = $this->resolveSymbol($request);
        $safeSymbol = $this->safeSymbol($symbol);
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

        $rows = [];
        foreach ($exchanges as $exchange) {
            $ticker = $connection
                ? $this->readLatest($connection, "{$prefix}:ticker:{$exchange}:{$safeSymbol}:latest")
                : null;
            $book = $connection
                ? $this->readLatest($connection, "{$prefix}:orderbook:{$exchange}:{$safeSymbol}:latest")
                : null;

            $rows[] = $this->buildRow($exchange, $symbol, $ticker, $book, $nowMs, $freshnessMs);
        }

        // Marca mejor bid (más alto) y mejor ask (más bajo) entre exchanges con datos.
        $best = $this->markBest($rows);

        return response()->json([
            'symbol' => $symbol,
            'rows' => $rows,
            'best_bid_exchange' => $best['bid'],
            'best_ask_exchange' => $best['ask'],
            'server_time_ms' => $nowMs,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $ticker
     * @param  array<string, mixed>|null  $book
     * @return array<string, mixed>
     */
    private function buildRow(
        string $exchange,
        string $symbol,
        ?array $ticker,
        ?array $book,
        int $nowMs,
        int $freshnessMs,
    ): array {
        $topBid = $this->topLevel($book['bids'] ?? null);
        $topAsk = $this->topLevel($book['asks'] ?? null);

        $bid = $topBid['price'] ?? $this->floatOrNull($ticker['bid'] ?? null);
        $ask = $topAsk['price'] ?? $this->floatOrNull($ticker['ask'] ?? null);
        $bidQty = $topBid['size'] ?? null;
        $askQty = $topAsk['size'] ?? null;

        $timestampMs = (int) ($book['timestamp_ms'] ?? $ticker['timestamp_ms'] ?? 0);
        $ageMs = $timestampMs > 0 ? max(0, $nowMs - $timestampMs) : null;

        $hasData = $bid !== null || $ask !== null;
        $conn = 'recon';
        if ($hasData && $ageMs !== null) {
            $conn = $ageMs <= $freshnessMs ? 'ok' : ($ageMs <= $freshnessMs * 6 ? 'stale' : 'recon');
        }

        return [
            'exchange' => $exchange,
            'symbol' => $symbol,
            'bid' => $bid,
            'ask' => $ask,
            'bid_qty' => $bidQty,
            'ask_qty' => $askQty,
            'spread' => $bid !== null && $ask !== null ? round($ask - $bid, 2) : null,
            'price' => $this->floatOrNull($ticker['price'] ?? null),
            'volume_24h' => $this->floatOrNull($ticker['volume_24h'] ?? null),
            'timestamp_ms' => $timestampMs ?: null,
            'age_ms' => $ageMs,
            'conn' => $conn,
            'has_data' => $hasData,
        ];
    }

    /**
     * @param  array<int, mixed>|null  $levels
     * @return array{price: float, size: float}|null
     */
    private function topLevel(?array $levels): ?array
    {
        if (empty($levels) || ! isset($levels[0])) {
            return null;
        }

        $level = $levels[0];
        if (! is_array($level) || ! isset($level[0])) {
            return null;
        }

        return [
            'price' => (float) $level[0],
            'size' => (float) ($level[1] ?? 0),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{bid: string|null, ask: string|null}
     */
    private function markBest(array &$rows): array
    {
        $bestBid = null;
        $bestAsk = null;
        $bestBidEx = null;
        $bestAskEx = null;

        foreach ($rows as $row) {
            if ($row['conn'] === 'recon' || ! $row['has_data']) {
                continue;
            }
            if ($row['bid'] !== null && ($bestBid === null || $row['bid'] > $bestBid)) {
                $bestBid = $row['bid'];
                $bestBidEx = $row['exchange'];
            }
            if ($row['ask'] !== null && ($bestAsk === null || $row['ask'] < $bestAsk)) {
                $bestAsk = $row['ask'];
                $bestAskEx = $row['exchange'];
            }
        }

        foreach ($rows as &$row) {
            $row['best_bid'] = $row['exchange'] === $bestBidEx;
            $row['best_ask'] = $row['exchange'] === $bestAskEx;
        }
        unset($row);

        return ['bid' => $bestBidEx, 'ask' => $bestAskEx];
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

    private function floatOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    private function resolveSymbol(Request $request): string
    {
        $requested = $request->query('symbol');
        if (is_string($requested) && $requested !== '') {
            return strtoupper(str_replace('-', '/', $requested));
        }

        $setting = $request->user()->arbitrageSetting;
        if ($setting !== null && ! empty($setting->symbols)) {
            return (string) $setting->symbols[0];
        }

        $symbols = array_values((array) config('arbitrage.symbols', ['BTC/USDT']));

        return (string) ($symbols[0] ?? 'BTC/USDT');
    }

    private function safeSymbol(string $symbol): string
    {
        return strtolower(str_replace('/', '-', $symbol));
    }
}
