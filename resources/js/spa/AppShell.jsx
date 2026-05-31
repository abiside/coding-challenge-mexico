/* NIFTY — app shell: sidebar + header + router por hash.

   Information architecture por ALCANCE, para que navegar sea intuitivo:
   • "Estrategias": el hub (Resumen consolidado) + una entrada por instancia.
     Cada instancia es una mini-app con su propio dashboard en tabs. Todo lo
     específico del arbitraje (Oportunidades, Engine, Configuración) vive dentro
     de su estrategia cross-exchange.
   • "Global": herramientas transversales a TODAS las estrategias — Mercado
     (monitor de order books compartido), Wallets y Rendimiento (consolidados),
     Trades (ledger global) y el Agente (asesor + autónomo).

   El header es CONTEXTUAL: solo muestra los controles del engine de arbitraje
   (Simulación, exchanges/latencia, Iniciar/Pausar) cuando estás en su contexto
   (estrategia cross-exchange o Agente). En el resto enseña un estado global. */
import { useEffect, useState } from 'react';
import { I } from './nifty/icons';
import { BrandLogo } from './nifty/BrandLogo';
import { formatClock } from './nifty/prefs';
import { useNifty } from './data/store';
import { OppDrawer } from './nifty/OppDrawer';
import { HelpProvider } from './nifty/HelpPanel';
import StrategiesScreen from './screens/Strategies';
import StrategyDetail from './screens/StrategyDetail';
import TransactionsScreen from './screens/Transactions';
import MarketScreen from './screens/Market';
import WalletsScreen from './screens/Wallets';
import PerfScreen from './screens/Performance';
import AgentScreen from './screens/Agent';
import SettingsScreen from './screens/Settings';

// Herramientas transversales a todas las estrategias.
const NAV_GLOBAL = [
    ['Mercado', 'market', 'market'],
    ['Wallets', 'wallet', 'wallet'],
    ['Rendimiento', 'perf', 'perf'],
    ['Trades', 'trade', 'trade'],
    ['Agente', 'agent', 'autopilot'],
];

const META = {
    strategies: { title: 'Estrategias', crumb: 'Hub unificado · trading + arbitraje cross-exchange' },
    market: { title: 'Mercado', crumb: 'Order book consolidado · monitor compartido' },
    wallet: { title: 'Wallets', crumb: 'Capital consolidado de todas tus estrategias' },
    perf: { title: 'Rendimiento', crumb: 'P&L y métricas de todas tus estrategias' },
    trade: { title: 'Trades', crumb: 'Transacciones consolidadas de todas las estrategias' },
    agent: { title: 'Agente', crumb: 'Asesor IA + modo autónomo sobre tus estrategias' },
    settings: { title: 'Configuración', crumb: 'Cuenta y preferencias generales' },
};

function resolveMeta(active, strategies) {
    if (active === 'autopilot') return META.agent;
    if (META[active]) return META[active];
    if (active.startsWith('strat:')) {
        const id = Number(active.slice(6));
        const s = (strategies?.data || []).find((x) => x.id === id);
        if (s) {
            return {
                title: s.name,
                crumb: s.type === 'cross_exchange'
                    ? 'Arbitraje cross-exchange · 2 patas + ciclos triangulares'
                    : 'Trading · ' + (s.algorithm ? s.algorithm.replace(/_/g, ' ') : 'long/short simulado'),
            };
        }
        return { title: 'Estrategia', crumb: '' };
    }
    return META.strategies;
}

// Contexto del engine de arbitraje: estrategia cross-exchange o el Agente.
function isEngineContext(active, strategies) {
    if (active === 'agent' || active === 'autopilot') return true;
    if (active.startsWith('strat:')) {
        const id = Number(active.slice(6));
        const s = (strategies?.data || []).find((x) => x.id === id);
        return !!s && s.type === 'cross_exchange';
    }
    return false;
}

function Sidebar({ active, onNav }) {
    const { simulation, engine, strategies, user } = useNifty();
    const conns = engine.connections || [];
    const online = conns.filter((c) => c.conn === 'ok').length;
    const instances = strategies?.data || [];
    const activeCount = instances.filter((s) => s.active).length;

    return (
        <aside className="side">
            <BrandLogo tagline="Strategies Hub" />

            <div className="nav-label">Estrategias</div>
            <button className={'nav-item' + (active === 'strategies' ? ' active' : '')} onClick={() => onNav('strategies')}>
                <I.dash />Resumen
            </button>
            {instances.map((s) => {
                const key = 'strat:' + s.id;
                const Icon = s.type === 'cross_exchange' ? I.opp : I.vol;
                return (
                    <button key={key} className={'nav-item' + (key === active ? ' active' : '')} onClick={() => onNav(key)} title={s.name}>
                        <Icon /><span style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{s.name}</span>
                        {s.active && <span className="conn ok" style={{ marginLeft: 'auto' }}><span className="d" /></span>}
                    </button>
                );
            })}

            <div className="nav-label">Global</div>
            {NAV_GLOBAL.map(([name, key, icon]) => {
                const Icon = I[icon];
                return (
                    <button key={key} className={'nav-item' + (key === active ? ' active' : '')} onClick={() => onNav(key)}>
                        <Icon />{name}
                    </button>
                );
            })}

            <div className="nav-spacer" />
            <div className="side-card">
                <div className="row"><span>Estrategias</span><span className="v">{activeCount} / {instances.length} activas</span></div>
                <div className="row"><span>Engine arbitraje</span><span className={'conn ' + (simulation.active ? 'ok' : 'recon')}><span className="d" />{simulation.active ? 'ONLINE' : 'IDLE'}</span></div>
                <div className="row"><span>Conexiones</span><span className="v">{online} / {conns.length || '—'}</span></div>
            </div>

            <button className={'nav-acct' + (active === 'settings' ? ' active' : '')} onClick={() => onNav('settings')} title="Configuración · cuenta y preferencias">
                <span className="avatar">{(user?.name || user?.email || '?').slice(0, 1).toUpperCase()}</span>
                <span className="acct-meta">
                    <span className="acct-name">{user?.name || 'Tu cuenta'}</span>
                    <span className="acct-email">{user?.email || 'Configuración'}</span>
                </span>
                <I.cfg className="acct-gear" />
            </button>
        </aside>
    );
}

/* Indicador + toggle del modo simulación (deriva sintética de precios) del
   engine de arbitraje. Refleja `simulation_enabled` y lo enciende/apaga sin
   reiniciar el worker. */
function SimToggle() {
    const { settings, actions } = useNifty();
    const [working, setWorking] = useState(false);
    if (!settings) return null;

    const on = settings.simulation_enabled === true;
    const toggle = async () => {
        setWorking(true);
        try {
            await actions.saveSettings({
                simulation_enabled: !on,
                simulation_max_drift_pct: settings.simulation_max_drift_pct || 0.5,
            });
            await actions.loadSettings();
        } catch { /* el error se refleja vía store */ } finally {
            setWorking(false);
        }
    };

    return (
        <button type="button" className={'pill pill-btn ' + (on ? 'live' : 'sim-off')} onClick={toggle} disabled={working}
            title={on
                ? 'Simulación de oportunidades ACTIVA · clic para apagar'
                : 'Simulación de oportunidades apagada · clic para encender'}>
            <span className="dot" />{working ? 'Aplicando…' : 'Simulación ' + (on ? 'ON' : 'OFF')}
        </button>
    );
}

function uptimeFrom(startedAt) {
    if (!startedAt) return '—';
    const start = Date.parse(startedAt);
    if (Number.isNaN(start)) return '—';
    const sec = Math.max(0, Math.floor((Date.now() - start) / 1000));
    const h = Math.floor(sec / 3600);
    const m = Math.floor((sec % 3600) / 60);
    return h > 0 ? `${h}h ${m}m` : `${m}m ${sec % 60}s`;
}

function Header({ meta, engineCtx, activeStrategy, user, onLogout }) {
    const { simulation, engine, busy, strategyLive, market, strategies, actions } = useNifty();
    const conns = engine.connections || [];
    const online = conns.filter((c) => c.conn === 'ok').length;
    const oks = conns.filter((c) => c.conn === 'ok' && c.age_ms != null);
    const avgLat = oks.length ? Math.round(oks.reduce((s, c) => s + c.age_ms, 0) / oks.length) : null;
    const paused = !simulation.active;
    const instances = strategies?.data || [];
    const activeCount = instances.filter((s) => s.active).length;

    // Contexto de una estrategia de trading: sus controles (iniciar/detener/
    // reiniciar) viven aquí en el header, igual que los del engine de arbitraje.
    const trading = activeStrategy && activeStrategy.type === 'trading' ? activeStrategy : null;
    const tradingMetrics = trading ? (strategyLive?.[trading.id] || trading.metrics) : null;
    const cb = tradingMetrics?.circuit_breaker;

    const [now, setNow] = useState(() => new Date());
    useEffect(() => {
        const t = setInterval(() => setNow(new Date()), 1000);
        return () => clearInterval(t);
    }, []);

    let pill;
    if (engineCtx) pill = <span className={'pill ' + (paused ? 'demo' : 'live')}><span className="dot" />{paused ? 'Engine pausado' : 'Engine activo'}</span>;
    else if (trading) pill = <span className={'pill ' + (trading.active ? 'live' : 'demo')}><span className="dot" />{trading.active ? 'Estrategia activa' : 'Estrategia detenida'}</span>;
    else pill = <span className="pill"><span className="dot" />{activeCount} / {instances.length} estrategias activas</span>;

    return (
        <header className="hdr">
            <div>
                <h1>{meta.title}</h1>
                <div className="crumb">{meta.crumb}</div>
            </div>

            {pill}
            {trading && cb && <span className="pill demo"><span className="dot" />CB: {cb}</span>}

            {engineCtx && <SimToggle />}

            <div className="hdr-stats">
                {engineCtx && (
                    <>
                        <div className="hstat"><span className="k">Exchanges</span><span className="v good">{online} / {conns.length || market.rows?.length || 0}</span></div>
                        <div className="hdr-divider" />
                        <div className="hstat"><span className="k">Latencia</span><span className="v">{avgLat != null ? avgLat + ' ms' : '—'}</span></div>
                        <div className="hdr-divider" />
                        <div className="hstat"><span className="k">Uptime</span><span className="v">{uptimeFrom(engine.metrics?.started_at)}</span></div>
                        <div className="hdr-divider" />
                    </>
                )}
                <div className="hstat"><span className="k">Hora</span><span className="v">{formatClock(now, true)}</span></div>
                {engineCtx && (
                    <button className={'btn' + (paused ? ' primary' : '')} onClick={actions.startStop} disabled={busy}>
                        {paused ? <I.bolt style={{ width: 14, height: 14 }} /> : <I.pause />}
                        {busy ? '…' : paused ? 'Iniciar' : 'Pausar'}
                    </button>
                )}
                {trading && (
                    <>
                        <button className="btn" disabled={busy} onClick={() => actions.strategyAction(trading.id, 'reset')} title="Reinicia billetera, posiciones y P&L">
                            <I.reset style={{ width: 14, height: 14 }} />Reiniciar
                        </button>
                        <button className={'btn ' + (trading.active ? 'danger' : 'primary')} disabled={busy} onClick={() => actions.strategyAction(trading.id, trading.active ? 'stop' : 'start')}>
                            {busy ? '…' : trading.active ? <I.pause /> : <I.bolt style={{ width: 14, height: 14 }} />}
                            {busy ? '' : trading.active ? 'Detener' : 'Iniciar'}
                        </button>
                    </>
                )}
                <button className="btn" onClick={onLogout} title={user?.email}><I.logout />Salir</button>
            </div>
        </header>
    );
}

export default function AppShell({ user, onLogout }) {
    const { strategies } = useNifty();
    const [active, setActive] = useState(() => {
        const h = (location.hash || '').replace('#', '');
        return h || 'strategies';
    });
    const [open, setOpen] = useState(null);

    useEffect(() => {
        location.hash = active;
        const main = document.querySelector('.main');
        if (main) main.scrollTop = 0;
    }, [active]);

    const onOpen = (o) => setOpen(o);
    const meta = resolveMeta(active, strategies);
    const engineCtx = isEngineContext(active, strategies);
    const activeStrategy = active.startsWith('strat:')
        ? (strategies?.data || []).find((s) => s.id === Number(active.slice(6)))
        : null;

    let view;
    if (active === 'strategies') view = <StrategiesScreen onNav={setActive} />;
    else if (active === 'market') view = <MarketScreen />;
    else if (active === 'wallet') view = <WalletsScreen />;
    else if (active === 'perf') view = <PerfScreen />;
    else if (active === 'agent' || active === 'autopilot') view = <AgentScreen />;
    else if (active === 'settings') view = <SettingsScreen />;
    else if (active === 'trade') view = <TransactionsScreen />;
    else if (active.startsWith('strat:')) view = <StrategyDetail id={Number(active.slice(6))} onOpen={onOpen} />;
    else view = <StrategiesScreen onNav={setActive} />;

    return (
        <HelpProvider>
            <div className="app">
                <Sidebar active={active} onNav={setActive} />
                <div className="main">
                    <Header meta={meta} engineCtx={engineCtx} activeStrategy={activeStrategy} user={user} onLogout={onLogout} />
                    {view}
                </div>
                <OppDrawer o={open} onClose={() => setOpen(null)} />
            </div>
        </HelpProvider>
    );
}
