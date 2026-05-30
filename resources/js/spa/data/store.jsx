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
    const [liveFeed, setLiveFeed] = useState([]);
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
            const [simRes, walletsRes, tradesRes, oppsRes, promoRes] = await Promise.all([
                api('/arbitrage/simulation'),
                api('/arbitrage/wallets'),
                api('/arbitrage/trades?limit=200'),
                api('/arbitrage/opportunities?limit=120'),
                api('/arbitrage/strategies/promotions'),
            ]);
            setSimulation(simRes);
            setWallets(walletsRes.data || []);
            setTrades(tradesRes.data || []);
            setOpportunities(oppsRes.data || []);
            setPromotions(promoRes.data || []);
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
        try {
            const echo = getEcho(token);
            channel = echo.private(`arbitrage.user.${user.id}`);
            channel.listen('.arbitrage.opportunity.processed', (payload) => {
                const opp = normalizeOpportunity({ ...payload, _flashAt: Date.now() });
                setLiveFeed((prev) => [opp, ...prev].slice(0, 30));
            });
            // Métricas del engine (embudo de descartes) emitidas en cada heartbeat.
            channel.listen('.arbitrage.engine.metrics', (payload) => {
                setEngineLive({ ...payload, _receivedAt: Date.now() });
            });
            channelRef.current = channel;
        } catch (err) {
            setError(`Realtime: ${err.message}`);
        }
        return () => {
            if (channel) {
                try { channel.stopListening('.arbitrage.opportunity.processed'); } catch { /* noop */ }
                try { channel.stopListening('.arbitrage.engine.metrics'); } catch { /* noop */ }
            }
        };
    }, [user]);

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

    const saveSettings = useCallback(async (patch) => {
        const res = await api('/arbitrage/settings', { method: 'PUT', body: patch });
        setSettings(res.data);
        return res.data;
    }, []);

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
        promotions, liveFeed, error, busy, btcPrice,
        actions: { startStop, saveSettings, addWallet, removeWallet, refreshFast, refreshSlow, loadSettings, setError },
    };

    return <NiftyContext.Provider value={value}>{children}</NiftyContext.Provider>;
}
