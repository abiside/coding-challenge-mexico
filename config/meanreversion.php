<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Estrategia de reversión a la media (worker independiente meanrev:run)
    |--------------------------------------------------------------------------
    |
    | Estrategia SPOT con USDT como caja central: compra monedas volátiles que
    | caen por debajo de su media de 1h (esperando rebote) y vende a USDT las
    | que tiene por encima de su media (rotación de inventario). NO usa margen
    | ni short real. Corre en su propio proceso, con su billetera aislada y su
    | propia conexión WebSocket a Binance con suscripciones dinámicas.
    |
    */

    'enabled' => (bool) env('MEANREV_ENABLED', true),

    'exchange' => env('MEANREV_EXCHANGE', 'binance'),

    'endpoint' => env('MEANREV_BINANCE_ENDPOINT', 'wss://stream.binance.com:9443/stream'),

    // Solo se operan pares contra esta moneda quote.
    'quote' => env('MEANREV_QUOTE', 'USDT'),

    'diagnostics' => (bool) env('MEANREV_DIAGNOSTICS', false),

    // Canal de log dedicado (rotación horaria, ver config/logging.php).
    'log_channel' => env('MEANREV_LOG_CHANNEL', 'meanrev'),

    /*
    |--------------------------------------------------------------------------
    | Discovery + administración dinámica de sockets
    |--------------------------------------------------------------------------
    |
    | El stream !ticker@arr (un solo stream con todo el mercado) alimenta el
    | ranking de volatilidad de 1h. El SubscriptionManager mantiene abiertos los
    | streams de profundidad solo para el top-N volátil ∪ las monedas con saldo.
    |
    */

    'discovery' => [
        'top_n' => (int) env('MEANREV_TOP_N', 15),
        'window_seconds' => (int) env('MEANREV_DISCOVERY_WINDOW', 3600),
        'min_volatility_pct' => (float) env('MEANREV_DISCOVERY_MIN_VOL_PCT', 0.3),
        'min_samples' => (int) env('MEANREV_DISCOVERY_MIN_SAMPLES', 20),
        // Cada cuánto reconcilia el conjunto de streams (agrupa add/remove).
        'refresh_ms' => (int) env('MEANREV_REFRESH_MS', 4000),
        // Tope duro de streams de profundidad abiertos simultáneamente.
        'max_subscriptions' => (int) env('MEANREV_MAX_SUBSCRIPTIONS', 40),
        // Histéresis: una moneda recién suscrita no se cierra antes de esto.
        'min_subscription_ms' => (int) env('MEANREV_MIN_SUBSCRIPTION_MS', 30000),
        // Profundidad del partial book de Binance.
        'orderbook_depth' => (int) env('MEANREV_DEPTH', 20),
        'orderbook_speed' => env('MEANREV_DEPTH_SPEED', '100ms'),
        // Excluir tokens apalancados (UP/DOWN/BULL/BEAR).
        'exclude_leveraged' => (bool) env('MEANREV_EXCLUDE_LEVERAGED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Parámetros de la estrategia y riesgo
    |--------------------------------------------------------------------------
    */

    'strategy' => [
        // Ventana de la media de precio (mid del order book).
        'window_seconds' => (int) env('MEANREV_PRICE_WINDOW', 3600),
        // Warmup: muestras y cobertura mínima antes de operar un símbolo.
        'min_samples' => (int) env('MEANREV_MIN_SAMPLES', 60),
        'min_coverage_ms' => (int) env('MEANREV_MIN_COVERAGE_MS', 600000),
        // Intervalo mínimo entre muestras del mid almacenadas (downsampling).
        'sample_interval_ms' => (int) env('MEANREV_SAMPLE_INTERVAL_MS', 1000),
        // Umbrales de z-score: entrar comprando bajo, salir vendiendo alto.
        'entry_z' => (float) env('MEANREV_ENTRY_Z', 1.5),
        'exit_z' => (float) env('MEANREV_EXIT_Z', 1.0),
        // Solo opera monedas con suficiente movimiento (stddev/mean en %).
        'min_volatility_pct' => (float) env('MEANREV_STRAT_MIN_VOL_PCT', 0.3),
        // Salidas por objetivo de ganancia y corte de pérdida (en %).
        'take_profit_pct' => (float) env('MEANREV_TAKE_PROFIT_PCT', 1.5),
        'stop_loss_pct' => (float) env('MEANREV_STOP_LOSS_PCT', 3.0),
        // Tamaño por entrada y topes de exposición (en USDT).
        'slice_usdt' => (float) env('MEANREV_SLICE_USDT', 200.0),
        'max_position_usdt' => (float) env('MEANREV_MAX_POSITION_USDT', 1000.0),
        'max_total_usdt' => (float) env('MEANREV_MAX_TOTAL_USDT', 8000.0),
        'max_open_positions' => (int) env('MEANREV_MAX_OPEN_POSITIONS', 10),
        // Anti-churn: cooldown por símbolo entre operaciones.
        'per_symbol_cooldown_ms' => (int) env('MEANREV_COOLDOWN_MS', 15000),
        // Margen mínimo (fracción) que un round-trip debe rendir tras fees.
        'min_roundtrip_margin' => (float) env('MEANREV_MIN_ROUNDTRIP_MARGIN', 0.001),
        // Fee taker por operación (fracción). 0.001 = 0.1%.
        'fee_rate' => (float) env('MEANREV_FEE_RATE', 0.001),
        // Slippage de ejecución (% máx) del precio de fill. 0 = sin slippage.
        'exec_drift_pct' => (float) env('MEANREV_EXEC_DRIFT_PCT', 0.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Billetera simulada (aislada de la del arbitraje)
    |--------------------------------------------------------------------------
    */

    'initial_balances' => [
        'USDT' => (float) env('MEANREV_INITIAL_USDT', 10000.0),
    ],

    'persistence' => [
        'persist_wallet' => (bool) env('MEANREV_PERSIST_WALLET', false),
        'user_id' => env('MEANREV_USER_ID') !== null ? (int) env('MEANREV_USER_ID') : null,
        'flush_interval_ms' => (int) env('MEANREV_WALLET_FLUSH_MS', 5000),
    ],

    'heartbeat_interval_seconds' => (int) env('MEANREV_HEARTBEAT_INTERVAL', 15),
];
