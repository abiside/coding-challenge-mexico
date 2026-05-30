<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Canal privado del dashboard de arbitraje: solo el dueño puede escuchar.
Broadcast::channel('arbitrage.user.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});

// Canal privado del panel de reversión a la media (sesión aislada por usuario).
Broadcast::channel('meanrev.user.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});
