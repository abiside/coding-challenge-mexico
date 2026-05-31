<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Símbolos a evaluar para arbitraje
    |--------------------------------------------------------------------------
    |
    | El engine solo compara books de exchanges para estos símbolos
    | normalizados. Cada símbolo se evalúa de forma independiente.
    |
    */

    'symbols' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ARBITRAGE_SYMBOLS', 'BTC/USDT'))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Frescura del order book
    |--------------------------------------------------------------------------
    |
    | Un book deja de ser válido para evaluación si su último update supera
    | este umbral en milisegundos. Evita operar sobre datos stale.
    |
    */

    'freshness_ms' => (int) env('ARBITRAGE_FRESHNESS_MS', 2000),

    /*
    |--------------------------------------------------------------------------
    | Diagnóstico del pipeline de evaluación
    |--------------------------------------------------------------------------
    |
    | Cuando está activo, el scanner y el engine emiten logs `debug` por cada
    | comparativa de books explicando POR QUÉ se descartó (book stale, sin
    | liquidez, spread no cruzado, sin volumen ejecutable, rechazo de wallet o
    | de risk manager). Útil cuando "no se dispara ninguna evaluación" para ver
    | en qué etapa se cae cada cruce. Mantener apagado en producción: en el hot
    | path genera mucho volumen de logs.
    |
    */

    'diagnostics' => [
        'enabled' => (bool) env('ARBITRAGE_DIAGNOSTICS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Modo simulación (jitter de precios sintético)
    |--------------------------------------------------------------------------
    |
    | Solo para pruebas/demo: cuando está activo, cada order book entrante se
    | desplaza aleatoriamente hasta ±`max_drift_pct`% antes de evaluarse. Como
    | la deriva es independiente por exchange, aparecen spreads cross-exchange
    | lo bastante amplios para que la utilidad supere los costos de transacción
    | y se vean escenarios de ganancia. Normalmente se controla por usuario
    | desde el panel del Engine (ArbitrageSetting); esto es el fallback global.
    |
    */

    'simulation' => [
        'enabled' => (bool) env('ARBITRAGE_SIMULATION', false),
        // Jitter del order book ANTES de evaluar (genera spreads rentables).
        'max_drift_pct' => (float) env('ARBITRAGE_SIMULATION_MAX_DRIFT_PCT', 0),
        // Slippage AL EJECUTAR: deriva del precio de fill (compra/venta) respecto
        // al precio evaluado. Modela el movimiento de precio entre la decisión y
        // el trade; puede mejorar o empeorar (incluso volver negativo) el P&L.
        'max_exec_drift_pct' => (float) env('ARBITRAGE_SIMULATION_MAX_EXEC_DRIFT_PCT', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Entrada de mercado (Redis Pub/Sub)
    |--------------------------------------------------------------------------
    |
    | El engine se suscribe a estos patrones publicados por `market:feed`.
    | El prefijo debe coincidir con marketdata.publisher.channel_prefix.
    |
    */

    'input' => [
        'redis_connection' => env('ARBITRAGE_REDIS_CONNECTION', 'default'),
        'channel_prefix' => env('ARBITRAGE_CHANNEL_PREFIX', 'market'),
        'subscribe_orderbook' => (bool) env('ARBITRAGE_SUBSCRIBE_ORDERBOOK', true),
        'subscribe_ticker' => (bool) env('ARBITRAGE_SUBSCRIBE_TICKER', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Trading fees por exchange (taker, fracción decimal)
    |--------------------------------------------------------------------------
    |
    | 0.001 = 0.1%. Se usa "default" cuando un exchange no está listado.
    |
    */

    'fees' => [
        'default' => (float) env('ARBITRAGE_FEE_DEFAULT', 0.001),
        'binance' => (float) env('ARBITRAGE_FEE_BINANCE', 0.001),
        'kraken' => (float) env('ARBITRAGE_FEE_KRAKEN', 0.0016),
        'coinbase' => (float) env('ARBITRAGE_FEE_COINBASE', 0.005),
        'bybit' => (float) env('ARBITRAGE_FEE_BYBIT', 0.001),
        'okx' => (float) env('ARBITRAGE_FEE_OKX', 0.001),
        'bitget' => (float) env('ARBITRAGE_FEE_BITGET', 0.001),
    ],

    /*
    |--------------------------------------------------------------------------
    | Umbrales de rentabilidad y volumen
    |--------------------------------------------------------------------------
    */

    'thresholds' => [
        // Profit neto mínimo (en moneda quote, p. ej. USDT) para considerar ejecutar.
        'min_net_profit' => (float) env('ARBITRAGE_MIN_NET_PROFIT', 1.0),
        // Profit neto mínimo como fracción del notional de compra (0.0005 = 0.05%).
        'min_net_margin' => (float) env('ARBITRAGE_MIN_NET_MARGIN', 0.0005),
        // Volumen mínimo ejecutable (en base asset, p. ej. BTC).
        'min_base_volume' => (float) env('ARBITRAGE_MIN_BASE_VOLUME', 0.0001),
        // Volumen máximo por operación (en base asset).
        'max_base_volume' => (float) env('ARBITRAGE_MAX_BASE_VOLUME', 1.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Penalización por latencia
    |--------------------------------------------------------------------------
    |
    | Costo simulado aplicado al profit por cada ms de antigüedad combinada
    | de los books usados, y latencia máxima tolerada antes de rechazar.
    |
    */

    'latency' => [
        'penalty_per_ms' => (float) env('ARBITRAGE_LATENCY_PENALTY_PER_MS', 0.0),
        'max_ms' => (int) env('ARBITRAGE_LATENCY_MAX_MS', 1500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Costo fijo por operación (en moneda quote)
    |--------------------------------------------------------------------------
    */

    'fixed_cost' => (float) env('ARBITRAGE_FIXED_COST', 0.0),

    /*
    |--------------------------------------------------------------------------
    | Circuit breaker
    |--------------------------------------------------------------------------
    |
    | Tras N rechazos/errores consecutivos en un par de exchanges, se abre
    | el breaker durante cooldown_ms para evitar tormentas de evaluación.
    |
    */

    'circuit_breaker' => [
        'enabled' => (bool) env('ARBITRAGE_CB_ENABLED', true),
        'failure_threshold' => (int) env('ARBITRAGE_CB_FAILURE_THRESHOLD', 10),
        'cooldown_ms' => (int) env('ARBITRAGE_CB_COOLDOWN_MS', 5000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Balances iniciales simulados (single-writer)
    |--------------------------------------------------------------------------
    |
    | Se siembran al iniciar el engine si la wallet no existe todavía.
    | Estructura: exchange => [ asset => cantidad ].
    |
    */

    'initial_balances' => [
        // Saldo de cada activo por exchange. Incluye ETH para habilitar
        // arbitraje triangular intra-exchange (USDT->BTC->ETH->USDT).
        // Las cotizaciones por defecto son en USDT en todos los exchanges
        // (consistente con `config/marketdata.php`).
        'binance' => ['USDT' => 100000.0, 'BTC' => 2.0, 'ETH' => 30.0],
        'kraken' => ['USDT' => 100000.0, 'BTC' => 2.0, 'ETH' => 30.0],
        'coinbase' => ['USDT' => 100000.0, 'BTC' => 2.0, 'ETH' => 30.0],
        'bybit' => ['USDT' => 100000.0, 'BTC' => 2.0, 'ETH' => 30.0],
        'okx' => ['USDT' => 100000.0, 'BTC' => 2.0, 'ETH' => 30.0],
        'bitget' => ['USDT' => 100000.0, 'BTC' => 2.0, 'ETH' => 30.0],
    ],

    /*
    |--------------------------------------------------------------------------
    | Persistencia desacoplada
    |--------------------------------------------------------------------------
    |
    | El engine acumula eventos en un buffer y los vacía por tamaño o tiempo
    | para no escribir en DB dentro del camino crítico.
    |
    */

    'persistence' => [
        'enabled' => (bool) env('ARBITRAGE_PERSISTENCE_ENABLED', true),
        'flush_size' => (int) env('ARBITRAGE_PERSIST_FLUSH_SIZE', 50),
        'flush_interval_ms' => (int) env('ARBITRAGE_PERSIST_FLUSH_INTERVAL_MS', 1000),
        // Solo persistir oportunidades cuya decisión esté en esta lista.
        'record_decisions' => ['execute', 'reject'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Publicación al dashboard (Reverb)
    |--------------------------------------------------------------------------
    */

    'dashboard' => [
        'enabled' => (bool) env('ARBITRAGE_DASHBOARD_ENABLED', true),
        'channel' => env('ARBITRAGE_DASHBOARD_CHANNEL', 'arbitrage-dashboard'),
        // Máximo de broadcasts por segundo por símbolo (throttle).
        'max_broadcasts_per_second' => (int) env('ARBITRAGE_DASHBOARD_MAX_BPS', 5),
        // Cache key para snapshot REST inicial.
        'snapshot_cache_prefix' => env('ARBITRAGE_SNAPSHOT_PREFIX', 'arbitrage:snapshot'),
        'snapshot_ttl_seconds' => (int) env('ARBITRAGE_SNAPSHOT_TTL', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Heartbeat / métricas del engine
    |--------------------------------------------------------------------------
    */

    'heartbeat_interval_seconds' => (int) env('ARBITRAGE_HEARTBEAT_INTERVAL', 15),

    /*
    |--------------------------------------------------------------------------
    | Ventana de evaluación de estrategias (autopilot)
    |--------------------------------------------------------------------------
    |
    | Cada N segundos el runner drena las métricas acumuladas por cada engine
    | (champion + challengers) y las persiste en `strategy_evaluations`. Esa
    | tabla es el log de aprendizaje que alimenta al optimizador y el eje X de la
    | gráfica "Optimizado vs base" del autopilot: un punto por ventana. Un
    | intervalo más corto = más puntos = curva más constante (a costa de más
    | filas y de mostrar una ventana temporal más corta en la gráfica).
    |
    */

    'evaluation_interval_seconds' => (int) env('ARBITRAGE_EVAL_INTERVAL', 15),

    /*
    |--------------------------------------------------------------------------
    | Retención de historial (purga de series de tiempo)
    |--------------------------------------------------------------------------
    |
    | El engine en modo simulación/demo genera un volumen alto y continuo de
    | registros (oportunidades, trades, evaluaciones, eventos). Para mantener la
    | base acotada, `arbitrage:prune` borra todo lo anterior a `hours` horas en
    | las tablas de series de tiempo. NO toca configuración ni estado
    | (estrategias, settings, wallets, runs, exchanges, usuarios).
    |
    | El borrado es por lotes (`chunk`) para no bloquear con transacciones
    | gigantes. Se agenda en routes/console.php.
    |
    */

    'retention' => [
        'enabled' => (bool) env('ARBITRAGE_RETENTION_ENABLED', true),
        'hours' => (int) env('ARBITRAGE_RETENTION_HOURS', 8),
        'chunk' => (int) env('ARBITRAGE_RETENTION_CHUNK', 5000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Arbitraje triangular (ciclos multi-pata)
    |--------------------------------------------------------------------------
    |
    | Cuando está activo, el engine evalúa ciclos cerrados de conversiones
    | (USDT->BTC->ETH->USDT intra-exchange o cross-exchange) además de las
    | oportunidades de 2 patas. Reutiliza order book store, wallets, fees y
    | risk manager. Ver: app/Arbitrage/Triangular.
    |
    | - max_cycle_length: cantidad máxima de saltos por ciclo (3 = triangular).
    | - cross_exchange: incluye aristas de "equivalencia de inventario" entre
    |   exchanges (modelo del 2-patas: mantenemos saldo en ambos wallets).
    | - transfer_cost: costo por arista de equivalencia (fracción), default 0.
    | - start_assets: whitelist de activos de partida del ciclo.
    | - thresholds: umbrales en unidades del activo de partida.
    |
    */

    'triangular' => [
        'enabled' => (bool) env('ARBITRAGE_TRIANGULAR_ENABLED', false),
        'max_cycle_length' => (int) env('ARBITRAGE_TRIANGULAR_MAX_LENGTH', 3),
        'cross_exchange' => (bool) env('ARBITRAGE_TRIANGULAR_CROSS_EXCHANGE', true),
        'transfer_cost' => (float) env('ARBITRAGE_TRIANGULAR_TRANSFER_COST', 0.0),
        'start_assets' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('ARBITRAGE_TRIANGULAR_START_ASSETS', 'USDT,USD'))
        ))),
        'thresholds' => [
            // Profit neto mínimo en activo de partida (USDT, USD...).
            'min_net_profit' => (float) env('ARBITRAGE_TRIANGULAR_MIN_NET_PROFIT', 1.0),
            // Margen neto mínimo como fracción del capital invertido.
            'min_net_margin' => (float) env('ARBITRAGE_TRIANGULAR_MIN_NET_MARGIN', 0.0005),
            // Cantidad mínima del activo de partida para ejecutar.
            'min_start_amount' => (float) env('ARBITRAGE_TRIANGULAR_MIN_START', 10.0),
            // Cantidad máxima del activo de partida por ciclo.
            'max_start_amount' => (float) env('ARBITRAGE_TRIANGULAR_MAX_START', 10000.0),
        ],
    ],
];
