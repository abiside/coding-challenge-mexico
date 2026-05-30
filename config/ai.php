<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Capa AI del autopilot
    |--------------------------------------------------------------------------
    |
    | El StrategyAdvisor usa un LLM para validar/explicar los candidatos que
    | propone el optimizador estadístico. Es opcional: si no hay API key,
    | el autopilot degrada a optimizador puro (el clamp y el gating ya están
    | implementados en código y son obligatorios sin importar el LLM).
    |
    */

    'autopilot' => [
        'enabled' => (bool) env('AI_AUTOPILOT_ENABLED', false),
        // openai | anthropic | none.
        'provider' => env('AI_PROVIDER', 'openai'),
        'model' => env('AI_MODEL', 'gpt-4o-mini'),
        'api_key' => env('AI_API_KEY'),
        'base_url' => env('AI_BASE_URL', 'https://api.openai.com/v1'),
        // Timeout duro de la llamada al LLM en segundos.
        'timeout_seconds' => (int) env('AI_TIMEOUT_SECONDS', 20),
        // Si el LLM falla o tarda demasiado, ¿seguimos con la propuesta del
        // optimizador puro? true = sí (recomendado), false = abortar el ciclo.
        'fallback_to_optimizer' => (bool) env('AI_FALLBACK_TO_OPTIMIZER', true),
        // Guarda de suficiencia de datos: el juez solo puede promover a un
        // challenger con al menos este número de ventanas de evaluación, para no
        // promover sobre ruido de una sola muestra.
        'min_judge_windows' => (int) env('AI_MIN_JUDGE_WINDOWS', 2),
    ],

];
