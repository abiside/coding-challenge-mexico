const TOKEN_KEY = 'arbitrage_token';

export function getToken() {
    return localStorage.getItem(TOKEN_KEY);
}

export function setToken(token) {
    if (token) {
        localStorage.setItem(TOKEN_KEY, token);
    } else {
        localStorage.removeItem(TOKEN_KEY);
    }
}

/**
 * Wrapper mínimo de fetch que adjunta el token Bearer y normaliza errores de
 * validación de Laravel (422) en un mensaje legible.
 */
export async function api(path, { method = 'GET', body = null } = {}) {
    const headers = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
    };

    const token = getToken();
    if (token) {
        headers.Authorization = `Bearer ${token}`;
    }

    const response = await fetch(`/api/v1${path}`, {
        method,
        headers,
        body: body ? JSON.stringify(body) : null,
    });

    if (response.status === 204) {
        return null;
    }

    let data = null;
    try {
        data = await response.json();
    } catch {
        data = null;
    }

    if (!response.ok) {
        const message =
            data?.message ||
            (data?.errors ? Object.values(data.errors).flat().join(' ') : null) ||
            `Error ${response.status}`;
        const error = new Error(message);
        error.status = response.status;
        error.data = data;
        throw error;
    }

    return data;
}
