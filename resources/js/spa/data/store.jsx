/* NIFTY — capa de datos central. Un provider que reúne el estado real del
   engine (REST + Reverb) y lo expone a todas las pantallas vía contexto. */
import { createContext, useCallback, useContext, useEffect, useRef, useState } from 'react';
import { api, getToken } from '../client';
import { getEcho } from '../realtime';
import { normalizeOpportunity } from '../nifty/format';

const NiftyContext = createContext(null);

export function useNifty() {
    const ctx = useContext(NiftyContext);
    if (!ctx) throw new Error('useNifty fuera de NiftyProvider');
    return ctx;
}

const FAST_MS = 4000;   // mercado + engine
const SLOW_MS = 6000;   // simulación + wallets + trades + oportunidades

// Cadencia de revelado del feed en vivo. En calma mostramos un item cada
// REVEAL_BASE_MS (sensación de secuencia constante). Si el backend empuja una
// ráfaga y se acumula backlog, acortamos el intervalo de forma proporcional
// (hasta REVEAL_MIN_MS) y revelamos en lotes pequeños para alcanzar el ritmo
// sin que se sienta un volcado de golpe.
const REVEAL_BASE_MS = 750;
const REVEAL_MIN_MS = 60;
const REVEAL_DRAIN_MS = 2500; // objetivo aproximado para vaciar el backlog actual

/* Cola de reproducción: encola lo que llega por websocket y lo revela de a poco.
   Devuelve [shown, enqueue]; `shown` mantiene el mismo shape que antes
   (más nuevos primero, recortado a `cap`). */
function usePacedFeed(cap = 30) {
    const [shown, setShown] = useState([]);
    const queueRef = useRef([]); // FIFO: el más viejo pendiente primero
    const timerRef = useRef(null);

    useEffect(() => () => {
        if (timerRef.current) clearTimeout(timerRef.current);
        timerRef.current = null;
    }, []);

    const pump = useCallback(() => {
        if (timerRef.current != null) return; // ya está drenando
        const tick = () => {
            const q = queueRef.current;
            const pending = q.length;
            if (pending === 0) { timerRef.current = null; return; }
            // A más backlog, lote un poco mayor + intervalo más corto.
            const batch = Math.max(1, Math.ceil(pending / 30));
            const drained = q.splice(0, batch);
            const stamped = drained.map((it) => ({ ...it, _flashAt: Date.now() }));
            // `drained` viene en orden de llegada (viejo→nuevo); al anteponer
            // invertimos para que el más nuevo del lote quede arriba.
            setShown((prev) => [...stamped.reverse(), ...prev].slice(0, cap));
            const wait = Math.min(REVEAL_BASE_MS, Math.max(REVEAL_MIN_MS, Math.round(REVEAL_DRAIN_MS / pending)));
            timerRef.current = setTimeout(tick, wait);
        };
        timerRef.current = setTimeout(tick, REVEAL_BASE_MS);
    }, [cap]);

    const enqueue = useCallback((item) => {
        queueRef.current.push(item);
        pump();
    }, [pump]);

    return [shown, enqueue];
}

export function NiftyProvider({ user, children }) {
    const [simulation, setSimulation] = useState({ active: false, stats: { trades: 0, realized_pnl: 0 } });
    const [wallets, setWallets] = useState([]);
    const [trades, setTrades] = useState([]);
    const [opportunities, setOpportunities] = useState([]);
    const [market, setMarket] = useState({ rows: [], symbol: 'BTC/USDT' });
    const [engine, setEngine] = useState({ connections: [], metrics: {}, logs: [] });
    // Momentos de promoción champion-challenger, para marcar en la gráfica.
    const [promotions, setPromotions] = useState([]);
    // Métricas en vivo del engine (embudo de descartes + decisiones) recibidas
    // por websocket; tiene prioridad sobre el snapshot cacheado del REST.
    const [engineLive, setEngineLive] = useState(null);
    const [settings, setSettings] = useState(null);
    const [options, setOptions] = useState({ exchanges: [], symbols: [], assets: [] });
    // Feeds en vivo pasados por una cola de reproducción: aunque el backend
    // empuje varios de golpe, se muestran de a uno para dar sensación de flujo.
    const [liveFeed, enqueueOpp] = usePacedFeed(30);
    // Feed en vivo de ciclos triangulares: ciclos detectados, evaluados y
    // ejecutados por el `CycleEngine`. Independiente del feed de opps 2-patas.
    const [cycleFeed, enqueueCycle] = usePacedFeed(30);
    // Histórico REST de ciclos triangulares + resumen (para no depender solo del
    // feed en vivo y mostrar estado al cargar la página).
    const [cycles, setCycles] = useState([]);
    const [cyclesSummary, setCyclesSummary] = useState(null);
    // Estrategia de reversión a la media (worker meanrev:run, billetera global).
    const [meanRev, setMeanRev] = useState({ enabled: false, running: false, metrics: null, recent_signals: [] });
    const [meanRevTrades, setMeanRevTrades] = useState({ data: [], summary: null });
    const [meanRevLive, setMeanRevLive] = useState(null);
    const [meanRevFeed, enqueueMeanRev] = usePacedFeed(30);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);
    const channelRef = useRef(null);

    const refreshFast = useCallback(async () => {
        try {
            const [marketRes, engineRes] = await Promise.all([
                api('/arbitrage/market'),
                api('/arbitrage/engine'),
            ]);
            setMarket(marketRes);
            setEngine(engineRes);
        } catch (err) {
            setError(err.message);
        }
    }, []);

    const refreshSlow = useCallback(async () => {
        try {
            const [simRes, walletsRes, tradesRes, oppsRes, cyclesRes, promoRes, mrRes, mrTradesRes] = await Promise.all([
                api('/arbitrage/simulation'),
                api('/arbitrage/wallets'),
                api('/arbitrage/trades?limit=200'),
                api('/arbitrage/opportunities?limit=120'),
                api('/arbitrage/cycles?limit=80'),
                api('/arbitrage/strategies/promotions'),
                api('/meanrev/overview'),
                api('/meanrev/trades?limit=100'),
            ]);
            setSimulation(simRes);
            setWallets(walletsRes.data || []);
            setTrades(tradesRes.data || []);
            setOpportunities(oppsRes.data || []);
            setCycles(cyclesRes.data || []);
            setCyclesSummary(cyclesRes.summary || null);
            setPromotions(promoRes.data || []);
            setMeanRev(mrRes);
            setMeanRevTrades({ data: mrTradesRes.data || [], summary: mrTradesRes.summary || null });
        } catch (err) {
            setError(err.message);
        }
    }, []);

    const loadSettings = useCallback(async () => {
        try {
            const res = await api('/arbitrage/settings');
            setSettings(res.data);
            setOptions(res.options);
        } catch (err) {
            setError(err.message);
        }
    }, []);

    useEffect(() => {
        loadSettings();
        refreshFast();
        refreshSlow();
        const fast = setInterval(refreshFast, FAST_MS);
        const slow = setInterval(refreshSlow, SLOW_MS);
        return () => { clearInterval(fast); clearInterval(slow); };
    }, [loadSettings, refreshFast, refreshSlow]);

    // Feed en vivo por canal privado del usuario.
    useEffect(() => {
        const token = getToken();
        if (!token || !user) return undefined;
        let channel;
        let meanRevChannel;
        let echoRef;
        try {
            const echo = getEcho(token);
            echoRef = echo;
            channel = echo.private(`arbitrage.user.${user.id}`);
            channel.listen('.arbitrage.opportunity.processed', (payload) => {
                // _flashAt se sella al revelar (en la cola), no al llegar.
                enqueueOpp(normalizeOpportunity({ ...payload }));
            });
            // Ciclos triangulares: payload con cycle{label, legs[], net_profit, ...}
            // se mantiene crudo (sin normalizar a la estructura de opps 2-patas)
            // porque su estructura es multi-pata y se renderiza aparte.
            channel.listen('.arbitrage.cycle.processed', (payload) => {
                enqueueCycle({ ...payload });
            });
            // Métricas del engine (embudo de descartes) emitidas en cada heartbeat.
            channel.listen('.arbitrage.engine.metrics', (payload) => {
                setEngineLive({ ...payload, _receivedAt: Date.now() });
            });
            channelRef.current = channel;

            // Canal PRIVADO de la sesión de reversión a la media del usuario.
            meanRevChannel = echo.private(`meanrev.user.${user.id}`);
            meanRevChannel.listen('.meanrev.signal.processed', (payload) => {
                enqueueMeanRev({ ...payload, _flashAt: Date.now() });
            });
            meanRevChannel.listen('.meanrev.engine.metrics', (payload) => {
                setMeanRevLive({ ...payload, _receivedAt: Date.now() });
            });
        } catch (err) {
            setError(`Realtime: ${err.message}`);
        }
        return () => {
            if (channel) {
                try { channel.stopListening('.arbitrage.opportunity.processed'); } catch { /* noop */ }
                try { channel.stopListening('.arbitrage.cycle.processed'); } catch { /* noop */ }
                try { channel.stopListening('.arbitrage.engine.metrics'); } catch { /* noop */ }
            }
            if (meanRevChannel) {
                try { meanRevChannel.stopListening('.meanrev.signal.processed'); } catch { /* noop */ }
                try { meanRevChannel.stopListening('.meanrev.engine.metrics'); } catch { /* noop */ }
                try { echoRef?.leaveChannel(`private-meanrev.user.${user.id}`); } catch { /* noop */ }
            }
        };
    }, [user, enqueueOpp, enqueueCycle, enqueueMeanRev]);

    const startStop = useCallback(async () => {
        setBusy(true);
        setError(null);
        try {
            await api(`/arbitrage/simulation/${simulation.active ? 'stop' : 'start'}`, { method: 'POST' });
            await refreshSlow();
        } catch (err) {
            setError(err.message);
        } finally {
            setBusy(false);
        }
    }, [simulation.active, refreshSlow]);

    const meanRevStartStop = useCallback(async () => {
        setBusy(true);
        setError(null);
        try {
            await api(`/meanrev/${meanRev.active ? 'stop' : 'start'}`, { method: 'POST' });
            if (meanRev.active) setMeanRevLive(null);
            await refreshSlow();
        } catch (err) {
            setError(err.message);
        } finally {
            setBusy(false);
        }
    }, [meanRev.active, refreshSlow]);

    const saveSettings = useCallback(async (patch) => {
        const res = await api('/arbitrage/settings', { method: 'PUT', body: patch });
        setSettings(res.data);
        return res.data;
    }, []);

    const resetProcess = useCallback(async () => {
        const res = await api('/arbitrage/onboarding/reset', { method: 'POST' });
        await Promise.all([loadSettings(), refreshFast(), refreshSlow()]);
        return res;
    }, [loadSettings, refreshFast, refreshSlow]);

    const addWallet = useCallback(async (body) => {
        await api('/arbitrage/wallets', { method: 'POST', body });
        await refreshSlow();
    }, [refreshSlow]);

    const removeWallet = useCallback(async (id) => {
        await api(`/arbitrage/wallets/${id}`, { method: 'DELETE' });
        await refreshSlow();
    }, [refreshSlow]);

    // Precio BTC de referencia: mejor ask de BTC/USDT entre exchanges frescos.
    const btcPrice = (() => {
        const asks = (market.rows || []).filter((r) => r.ask != null).map((r) => r.ask);
        const bids = (market.rows || []).filter((r) => r.bid != null).map((r) => r.bid);
        if (asks.length && bids.length) return (Math.min(...asks) + Math.max(...bids)) / 2;
        if (asks.length) return Math.min(...asks);
        return 0;
    })();

    const value = {
        user,
        simulation, wallets, trades, opportunities, market, engine, engineLive, settings, options,
        promotions, liveFeed, cycleFeed, cycles, cyclesSummary, error, busy, btcPrice,
        meanRev, meanRevTrades, meanRevLive, meanRevFeed,
        actions: { startStop, meanRevStartStop, saveSettings, addWallet, removeWallet, resetProcess, refreshFast, refreshSlow, loadSettings, setError },
    };

    return <NiftyContext.Provider value={value}>{children}</NiftyContext.Provider>;
}
