<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::view('/app', 'app');

Route::view('/dashboard', 'dashboard');

// SPA multi-usuario (login, onboarding, monitoreo). El routing es client-side.
Route::view('/console', 'console');
Route::view('/console/{any?}', 'console')->where('any', '.*');
