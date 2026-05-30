<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Exchanges habilitados por defecto
    |--------------------------------------------------------------------------
    |
    | Lista separada por comas en MARKET_FEED_EXCHANGES. El comando
    | `market:feed` usa este valor cuando no se le pasa --exchanges.
    |
    */

    'exchanges' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('MARKET_FEED_EXCHANGES', 'binance,kraken,coinbase,bybit,okx,bitget'))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Símbolos por exchange
    |--------------------------------------------------------------------------
    |
    | Permite definir un set distinto de símbolos por cada exchange. Si el
    | exchange no aparece aquí, se usa el set "default".
    |
    */

    'symbols' => [
        'default' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('MARKET_FEED_SYMBOLS', 'BTC/USDT,ETH/USDT'))
        ))),
        'kraken' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('MARKET_FEED_SYMBOLS_KRAKEN', 'BTC/USD,ETH/USD'))
        ))),
        'coinbase' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('MARKET_FEED_SYMBOLS_COINBASE', 'BTC/USD,ETH/USD'))
        ))),
        'bybit' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('MARKET_FEED_SYMBOLS_BYBIT', 'BTC/USDT,ETH/USDT'))
        ))),
        'okx' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('MARKET_FEED_SYMBOLS_OKX', 'BTC/USDT,ETH/USDT'))
        ))),
        'bitget' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('MARKET_FEED_SYMBOLS_BITGET', 'BTC/USDT,ETH/USDT'))
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Streams habilitados por defecto (ticker, orderbook)
    |--------------------------------------------------------------------------
    */

    'streams' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('MARKET_FEED_STREAMS', 'ticker,orderbook'))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Configuración de publicación en Redis
    |--------------------------------------------------------------------------
    */

    'publisher' => [
        'redis_connection' => env('MARKET_FEED_REDIS_CONNECTION', 'default'),
        'channel_prefix' => env('MARKET_FEED_CHANNEL_PREFIX', 'market'),
        'latest_state_ttl_seconds' => (int) env('MARKET_FEED_LATEST_TTL', 300),
        'log_messages' => (bool) env('MARKET_FEED_LOG_MESSAGES', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Backoff exponencial para reconexiones
    |--------------------------------------------------------------------------
    */

    'backoff' => [
        'base_ms' => (int) env('MARKET_FEED_BACKOFF_BASE_MS', 1000),
        'cap_ms' => (int) env('MARKET_FEED_BACKOFF_CAP_MS', 30000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Heartbeat / status
    |--------------------------------------------------------------------------
    |
    | Cada cuántos segundos imprimir el estado de los conectores en el log.
    |
    */

    'status_interval_seconds' => (int) env('MARKET_FEED_STATUS_INTERVAL', 30),
];
