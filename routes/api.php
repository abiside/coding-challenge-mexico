<?php

use App\Http\Controllers\Api\V1\Arbitrage\ArbitrageSnapshotController;
use App\Http\Controllers\Api\V1\Arbitrage\EngineController;
use App\Http\Controllers\Api\V1\Arbitrage\MarketController;
use App\Http\Controllers\Api\V1\Arbitrage\OpportunityController;
use App\Http\Controllers\Api\V1\Arbitrage\SettingsController;
use App\Http\Controllers\Api\V1\Arbitrage\SimulationController;
use App\Http\Controllers\Api\V1\Arbitrage\StrategyController;
use App\Http\Controllers\Api\V1\Arbitrage\TradeController;
use App\Http\Controllers\Api\V1\Arbitrage\WalletController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MessageController;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

// Endpoint de autorización de canales privados (Reverb) bajo token Sanctum.
Broadcast::routes(['middleware' => ['auth:sanctum']]);

Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthController::class);

    Route::prefix('auth')->group(function (): void {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::get('/me', [AuthController::class, 'me']);
            Route::post('/logout', [AuthController::class, 'logout']);
        });
    });

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/messages', MessageController::class);

        Route::prefix('arbitrage')->group(function (): void {
            Route::get('/settings', [SettingsController::class, 'show']);
            Route::put('/settings', [SettingsController::class, 'update']);

            Route::get('/wallets', [WalletController::class, 'index']);
            Route::post('/wallets', [WalletController::class, 'store']);
            Route::delete('/wallets/{id}', [WalletController::class, 'destroy']);

            Route::get('/simulation', [SimulationController::class, 'status']);
            Route::post('/simulation/start', [SimulationController::class, 'start']);
            Route::post('/simulation/stop', [SimulationController::class, 'stop']);

            Route::get('/strategies', [StrategyController::class, 'index']);
            Route::get('/strategies/series', [StrategyController::class, 'series']);
            Route::get('/strategies/promotions', [StrategyController::class, 'promotions']);
            Route::get('/strategies/{id}/evaluations', [StrategyController::class, 'evaluations']);
            Route::post('/strategies/{id}/promote', [StrategyController::class, 'promote']);
            Route::post('/autopilot', [StrategyController::class, 'autopilot']);

            Route::get('/market', MarketController::class);
            Route::get('/engine', EngineController::class);

            Route::get('/', [ArbitrageSnapshotController::class, 'index']);
            Route::get('/opportunities', OpportunityController::class);
            Route::get('/trades', TradeController::class);
            Route::get('/{symbol}', [ArbitrageSnapshotController::class, 'show']);
        });
    });
});
