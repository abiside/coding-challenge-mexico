import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

let echoInstance = null;

/**
 * Crea (una sola vez) el cliente Echo apuntando a Reverb, autenticando los
 * canales privados con el token Sanctum vía /api/broadcasting/auth.
 */
export function getEcho(token) {
    if (echoInstance) {
        return echoInstance;
    }

    echoInstance = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 80),
        wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https',
        enabledTransports: ['ws', 'wss'],
        authEndpoint: '/api/broadcasting/auth',
        auth: {
            headers: {
                Authorization: `Bearer ${token}`,
                Accept: 'application/json',
            },
        },
    });

    return echoInstance;
}

export function resetEcho() {
    if (echoInstance) {
        try {
            echoInstance.disconnect();
        } catch {
            // noop
        }
        echoInstance = null;
    }
}
