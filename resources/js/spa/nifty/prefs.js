/* NIFTY — preferencias generales de la cuenta (cliente, persistidas en
   localStorage). Hoy cubren formato/zona horaria; el módulo de formato lee el
   valor vigente vía getPrefs(), y la store mantiene una copia reactiva. */

const KEY = 'nifty.prefs';
const DEFAULTS = { timeFormat: '24h', timezone: 'local' };

export const TIMEZONES = [
    ['local', 'Local del navegador'],
    ['UTC', 'UTC'],
    ['America/Mexico_City', 'Ciudad de México'],
    ['America/New_York', 'Nueva York'],
    ['America/Argentina/Buenos_Aires', 'Buenos Aires'],
    ['Europe/Madrid', 'Madrid'],
    ['Asia/Singapore', 'Singapur'],
];

let current = load();

function load() {
    try {
        const raw = JSON.parse(localStorage.getItem(KEY) || '{}');
        return { ...DEFAULTS, ...(raw && typeof raw === 'object' ? raw : {}) };
    } catch {
        return { ...DEFAULTS };
    }
}

export function getPrefs() {
    return current;
}

export function setPrefs(patch) {
    current = { ...current, ...patch };
    try { localStorage.setItem(KEY, JSON.stringify(current)); } catch { /* almacenamiento no disponible */ }
    return current;
}

/** Opciones de Intl.DateTimeFormat derivadas de la preferencia vigente. */
export function clockOptions(withSeconds = true) {
    const o = { hour: '2-digit', minute: '2-digit', hour12: current.timeFormat === '12h' };
    if (withSeconds) o.second = '2-digit';
    if (current.timezone && current.timezone !== 'local') o.timeZone = current.timezone;
    return o;
}

/** Formatea un Date como reloj según el formato/zona elegidos. */
export function formatClock(date, withSeconds = true) {
    try {
        return new Intl.DateTimeFormat('es-MX', clockOptions(withSeconds)).format(date);
    } catch {
        return date.toTimeString().slice(0, withSeconds ? 8 : 5);
    }
}
