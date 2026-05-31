/* NIFTY — app shell: sidebar + header + router por hash. La navegación gira en
   torno a la sección "Estrategias" (resumen consolidado + una entrada por
   instancia), pero Mercado, Wallets, Rendimiento y Autopilot se exponen como
   ítems de primer nivel (vistas transversales del arbitraje), junto con Trades
   global. Oportunidades, Engine y Configuración viven dentro de la estrategia
   cross-exchange porque solo tienen sentido en ese contexto. */
import { useEffect, useState } from 'react';
import { I } from './nifty/icons';
import { BrandLogo } from './nifty/BrandLogo';
import { useNifty } from './data/store';
import { OppDrawer } from './nifty/OppDrawer';
import { HelpProvider } from './nifty/HelpPanel';
import { ResetProcessModal } from './nifty/ResetModal';
import StrategiesScreen from './screens/Strategies';
import StrategyDetail from './screens/StrategyDetail';
import TransactionsScreen from './screens/Transactions';
import MarketScreen from './screens/Market';
import WalletsScreen from './screens/Wallets';
import PerfScreen from './screens/Performance';
import AgentScreen from './screens/Agent';

// Vistas transversales del arbitraje promovidas a primer nivel.
const NAV_MONITOR = [
    ['Mercado', 'market', 'market'],
    ['Wallets', 'wallet', 'wallet'],
    ['Rendimiento', 'perf', 'perf'],
    ['Trades', 'trade', 'trade'],
];
// El "Agente" unifica asesor (AI Supervisor) + autónomo (Autopilot).
const NAV_SYSTEM = [
    ['Agente', 'agent', 'autopilot'],
];

const META = {
    strategies: { title: 'Estrategias', crumb: 'Hub unificado · trading + arbitraje cross-exchange' },
    market: { title: 'Mercado', crumb: 'Order book consolidado · multi-exchange' },
    wallet: { title: 'Wallets', crumb: 'Balances y distribución de capital' },
    perf: { title: 'Rendimiento', crumb: 'Análisis de P&L y métricas de calidad' },
    trade: { title: 'Trades', crumb: 'Transacciones consolidadas de todas las estrategias' },
    agent: { title: 'Agente', crumb: 'Asesor IA + modo autónomo sobre tus estrategias' },
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

function Sidebar({ active, onNav }) {
    const { simulation, engine, strategies } = useNifty();
    const conns = engine.connections || [];
    const online = conns.filter((c) => c.conn === 'ok').length;
    const instances = strategies?.data || [];

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

            <div className="nav-label">Monitoreo</div>
            {NAV_MONITOR.map(([name, key, icon]) => {
                const Icon = I[icon];
                return (
                    <button key={key} className={'nav-item' + (key === active ? ' active' : '')} onClick={() => onNav(key)}>
                        <Icon />{name}
                    </button>
                );
            })}

            <div className="nav-label">Sistema</div>
            {NAV_SYSTEM.map(([name, key, icon]) => {
                const Icon = I[icon];
                return (
                    <button key={key} className={'nav-item' + (key === active ? ' active' : '')} onClick={() => onNav(key)}>
                        <Icon />{name}
                    </button>
                );
            })}

            <div className="nav-spacer" />
            <div className="side-card">
                <div className="row"><span>Engine</span><span className={'conn ' + (simulation.active ? 'ok' : 'recon')}><span className="d" />{simulation.active ? 'ONLINE' : 'IDLE'}</span></div>
                <div className="row"><span>Conexiones</span><span className="v">{online} / {conns.length || '—'}</span></div>
                <div className="row"><span>Instancias</span><span className="v">{instances.length}</span></div>
            </div>
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

function Header({ meta, user, onLogout }) {
    const { simulation, engine, busy, market, actions } = useNifty();
    const conns = engine.connections || [];
    const online = conns.filter((c) => c.conn === 'ok').length;
    const oks = conns.filter((c) => c.conn === 'ok' && c.age_ms != null);
    const avgLat = oks.length ? Math.round(oks.reduce((s, c) => s + c.age_ms, 0) / oks.length) : null;
    const paused = !simulation.active;
    const [now, setNow] = useState(() => new Date());
    const [resetOpen, setResetOpen] = useState(false);
    useEffect(() => {
        const t = setInterval(() => setNow(new Date()), 1000);
        return () => clearInterval(t);
    }, []);

    return (
        <header className="hdr">
            <div>
                <h1>{meta.title}</h1>
                <div className="crumb">{meta.crumb}</div>
            </div>
            <span className={'pill ' + (paused ? 'demo' : 'live')}><span className="dot" />{paused ? 'Engine pausado' : 'Engine activo'}</span>
            <SimToggle />
            <button type="button" className="pill demo pill-btn" onClick={() => setResetOpen(true)}
                title="Reiniciar el proceso · borra toda la data y challengers">
                <span className="dot" />Modo Demo<I.reset className="pill-reset" />
            </button>
            <ResetProcessModal open={resetOpen} onClose={() => setResetOpen(false)} />
            <div className="hdr-stats">
                <div className="hstat"><span className="k">Exchanges</span><span className="v good">{online} / {conns.length || market.rows?.length || 0}</span></div>
                <div className="hdr-divider" />
                <div className="hstat"><span className="k">Latencia</span><span className="v">{avgLat != null ? avgLat + ' ms' : '—'}</span></div>
                <div className="hdr-divider" />
                <div className="hstat"><span className="k">Uptime</span><span className="v">{uptimeFrom(engine.metrics?.started_at)}</span></div>
                <div className="hdr-divider" />
                <div className="hstat"><span className="k">Hora</span><span className="v">{now.toTimeString().slice(0, 8)}</span></div>
                <button className={'btn' + (paused ? ' primary' : '')} onClick={actions.startStop} disabled={busy}>
                    {paused ? <I.bolt style={{ width: 14, height: 14 }} /> : <I.pause />}
                    {busy ? '…' : paused ? 'Iniciar' : 'Pausar'}
                </button>
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

    let view;
    if (active === 'strategies') view = <StrategiesScreen onNav={setActive} />;
    else if (active === 'market') view = <MarketScreen />;
    else if (active === 'wallet') view = <WalletsScreen />;
    else if (active === 'perf') view = <PerfScreen />;
    else if (active === 'agent' || active === 'autopilot') view = <AgentScreen />;
    else if (active === 'trade') view = <TransactionsScreen />;
    else if (active.startsWith('strat:')) view = <StrategyDetail id={Number(active.slice(6))} onOpen={onOpen} />;
    else view = <StrategiesScreen onNav={setActive} />;

    return (
        <HelpProvider>
            <div className="app">
                <Sidebar active={active} onNav={setActive} />
                <div className="main">
                    <Header meta={meta} user={user} onLogout={onLogout} />
                    {view}
                </div>
                <OppDrawer o={open} onClose={() => setOpen(null)} />
            </div>
        </HelpProvider>
    );
}
