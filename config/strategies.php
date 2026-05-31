<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Módulo unificado de Estrategias (worker strategies:run)
    |--------------------------------------------------------------------------
    |
    | Estrategias de trading de corto plazo (long spot + short SIMULADO con USDT
    | como colateral) sobre monedas volátiles de Binance. Es un simulador: nunca
    | ejecuta dinero real. Reutiliza la infraestructura de discovery/sockets del
    | módulo de reversión a la media (un solo socket compartido, fan-out a N
    | engines aislados por instancia de estrategia).
    |
    */

    'enabled' => (bool) env('STRATEGIES_ENABLED', true),

    'exchange' => env('STRATEGIES_EXCHANGE', 'binance'),

    'endpoint' => env('STRATEGIES_BINANCE_ENDPOINT', 'wss://stream.binance.com:9443/stream'),

    'quote' => env('STRATEGIES_QUOTE', 'USDT'),

    'diagnostics' => (bool) env('STRATEGIES_DIAGNOSTICS', false),

    'log_channel' => env('STRATEGIES_LOG_CHANNEL', 'meanrev'),

    'dashboard' => [
        'max_broadcasts_per_second' => (int) env('STRATEGIES_DASHBOARD_BPS', 5),
        'snapshot_ttl_seconds' => (int) env('STRATEGIES_DASHBOARD_TTL', 30),
        'recent_signals' => (int) env('STRATEGIES_DASHBOARD_RECENT', 40),
        'persist_positions' => (bool) env('STRATEGIES_PERSIST_POSITIONS', true),
        'persist_signals' => (bool) env('STRATEGIES_PERSIST_SIGNALS', true),
    ],

    'discovery' => [
        'top_n' => (int) env('STRATEGIES_TOP_N', 15),
        'window_seconds' => (int) env('STRATEGIES_DISCOVERY_WINDOW', 3600),
        'min_volatility_pct' => (float) env('STRATEGIES_DISCOVERY_MIN_VOL_PCT', 0.3),
        'min_samples' => (int) env('STRATEGIES_DISCOVERY_MIN_SAMPLES', 20),
        'refresh_ms' => (int) env('STRATEGIES_REFRESH_MS', 4000),
        'max_subscriptions' => (int) env('STRATEGIES_MAX_SUBSCRIPTIONS', 40),
        'min_subscription_ms' => (int) env('STRATEGIES_MIN_SUBSCRIPTION_MS', 30000),
        'orderbook_depth' => (int) env('STRATEGIES_DEPTH', 20),
        'orderbook_speed' => env('STRATEGIES_DEPTH_SPEED', '100ms'),
        'exclude_leveraged' => (bool) env('STRATEGIES_EXCLUDE_LEVERAGED', true),
        // Suscribir velas 1m para features de volumen (volume_spike, trades/min).
        'subscribe_klines' => (bool) env('STRATEGIES_SUBSCRIBE_KLINES', true),
        'kline_interval' => env('STRATEGIES_KLINE_INTERVAL', '1m'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Defaults de estrategia / riesgo (sobre-escribibles por instancia)
    |--------------------------------------------------------------------------
    */

    'defaults' => [
        // Serie de precios (Feature Engine).
        'window_seconds' => (int) env('STRATEGIES_PRICE_WINDOW', 3600),
        'min_samples' => (int) env('STRATEGIES_MIN_SAMPLES', 60),
        'min_coverage_ms' => (int) env('STRATEGIES_MIN_COVERAGE_MS', 600000),
        'sample_interval_ms' => (int) env('STRATEGIES_SAMPLE_INTERVAL_MS', 1000),

        // Cadencia de evaluación por símbolo (throttle de features pesadas).
        'evaluation_interval_ms' => (int) env('STRATEGIES_EVAL_INTERVAL_MS', 1000),

        // Sizing y exposición (USDT).
        'slice_usdt' => (float) env('STRATEGIES_SLICE_USDT', 200.0),
        'max_position_usdt' => (float) env('STRATEGIES_MAX_POSITION_USDT', 1000.0),
        'max_total_usdt' => (float) env('STRATEGIES_MAX_TOTAL_USDT', 8000.0),
        'max_open_positions' => (int) env('STRATEGIES_MAX_OPEN_POSITIONS', 10),
        'per_symbol_cooldown_ms' => (int) env('STRATEGIES_COOLDOWN_MS', 15000),

        // Salidas obligatorias.
        'take_profit_pct' => (float) env('STRATEGIES_TAKE_PROFIT_PCT', 1.5),
        'stop_loss_pct' => (float) env('STRATEGIES_STOP_LOSS_PCT', 2.0),
        'max_holding_seconds' => (int) env('STRATEGIES_MAX_HOLDING_SECONDS', 1800),

        // Short simulado.
        'leverage' => (float) env('STRATEGIES_LEVERAGE', 1.0),
        'funding_fee_pct' => (float) env('STRATEGIES_FUNDING_FEE_PCT', 0.0),
        'liquidation_buffer_pct' => (float) env('STRATEGIES_LIQUIDATION_BUFFER_PCT', 90.0),

        // Costos.
        'fee_rate' => (float) env('STRATEGIES_FEE_RATE', 0.001),

        // Risk Manager.
        'min_confidence' => (float) env('STRATEGIES_MIN_CONFIDENCE', 0.55),
        'max_spread_pct' => (float) env('STRATEGIES_MAX_SPREAD_PCT', 0.15),
        'min_liquidity_usdt' => (float) env('STRATEGIES_MIN_LIQUIDITY_USDT', 2000.0),
        'max_book_age_ms' => (int) env('STRATEGIES_MAX_BOOK_AGE_MS', 5000),
        'max_loss_streak' => (int) env('STRATEGIES_MAX_LOSS_STREAK', 5),
        'max_daily_drawdown_usdt' => (float) env('STRATEGIES_MAX_DAILY_DRAWDOWN', 1000.0),

        // Umbrales específicos de estrategias (z-score, returns, volumen).
        'entry_z' => (float) env('STRATEGIES_ENTRY_Z', 2.0),
        'exit_z' => (float) env('STRATEGIES_EXIT_Z', 0.5),
        'min_volatility_pct' => (float) env('STRATEGIES_STRAT_MIN_VOL_PCT', 0.3),
        'breakout_return_pct' => (float) env('STRATEGIES_BREAKOUT_RETURN_PCT', 1.5),
        'pump_return_pct' => (float) env('STRATEGIES_PUMP_RETURN_PCT', 4.0),
        'volume_spike_ratio' => (float) env('STRATEGIES_VOLUME_SPIKE_RATIO', 2.0),
        'imbalance_long' => (float) env('STRATEGIES_IMBALANCE_LONG', 0.65),
        'imbalance_short' => (float) env('STRATEGIES_IMBALANCE_SHORT', 0.35),
    ],

    'initial_balances' => [
        'USDT' => (float) env('STRATEGIES_INITIAL_USDT', 10000.0),
    ],

    'heartbeat_interval_seconds' => (int) env('STRATEGIES_HEARTBEAT_INTERVAL', 15),
];
