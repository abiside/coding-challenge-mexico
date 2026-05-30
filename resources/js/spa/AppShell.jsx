/* NIFTY — app shell: sidebar + header + router por hash (portado del diseño). */
import { useEffect, useState } from 'react';
import { I } from './nifty/icons';
import { useNifty } from './data/store';
import { OppDrawer } from './nifty/OppDrawer';
import DashboardScreen from './screens/Dashboard';
import MarketScreen from './screens/Market';
import OppsScreen from './screens/Opportunities';
import TradesScreen from './screens/Trades';
import WalletsScreen from './screens/Wallets';
import PerfScreen from './screens/Performance';
import EngineScreen from './screens/Engine';
import ConfigScreen from './screens/Config';
import AutopilotScreen from './screens/Autopilot';

const NAV_MONITOR = [
    ['Dashboard', 'dash', 'dash'],
    ['Mercado', 'market', 'market'],
    ['Oportunidades', 'opp', 'opp'],
    ['Trades', 'trade', 'trade'],
    ['Wallets', 'wallet', 'wallet'],
    ['Rendimiento', 'perf', 'perf'],
];
const NAV_SYSTEM = [
    ['Engine', 'engine', 'engine'],
    ['Autopilot', 'autopilot', 'autopilot'],
    ['Configuración', 'cfg', 'cfg'],
];

const META = {
    dash: { title: 'Dashboard', crumb: 'BTC/USDT · arbitraje multi-exchange' },
    market: { title: 'Mercado', crumb: 'Order book consolidado · multi-exchange' },
    opp: { title: 'Oportunidades', crumb: 'Monitor de arbitraje en tiempo real' },
    trade: { title: 'Trades', crumb: 'Historial de operaciones simuladas' },
    wallet: { title: 'Wallets', crumb: 'Balances y distribución de capital' },
    perf: { title: 'Rendimiento', crumb: 'Análisis de P&L y métricas de calidad' },
    engine: { title: 'Engine', crumb: 'Salud del motor y conexiones' },
    autopilot: { title: 'Autopilot', crumb: 'Champion-challenger y optimización' },
    cfg: { title: 'Configuración', crumb: 'Reglas de operación y gestión de riesgo' },
};

function Sidebar({ active, onNav }) {
    const { simulation, engine } = useNifty();
    const conns = engine.connections || [];
    const online = conns.filter((c) => c.conn === 'ok').length;
    return (
        <aside className="side">
            <div className="brand">
                <div className="brand-mark"><I.bolt style={{ width: 16, height: 16, color: '#0a0710' }} /></div>
                <div>
                    <div className="brand-name">Nifty</div>
                    <div className="brand-sub">Arbitrage Engine</div>
                </div>
            </div>
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
                <div className="row"><span>Circuit breaker</span><span className="v" style={{ color: 'var(--profit)' }}>{engine.metrics?.circuit_breaker_enabled ? 'ON' : 'OFF'}</span></div>
            </div>
        </aside>
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

function Header({ active, user, onLogout }) {
    const { simulation, engine, busy, market, actions } = useNifty();
    const m = META[active];
    const conns = engine.connections || [];
    const online = conns.filter((c) => c.conn === 'ok').length;
    const oks = conns.filter((c) => c.conn === 'ok' && c.age_ms != null);
    const avgLat = oks.length ? Math.round(oks.reduce((s, c) => s + c.age_ms, 0) / oks.length) : null;
    const paused = !simulation.active;
    const [now, setNow] = useState(() => new Date());
    useEffect(() => {
        const t = setInterval(() => setNow(new Date()), 1000);
        return () => clearInterval(t);
    }, []);

    return (
        <header className="hdr">
            <div>
                <h1>{m.title}</h1>
                <div className="crumb">{m.crumb}</div>
            </div>
            <span className={'pill ' + (paused ? 'demo' : 'live')}><span className="dot" />{paused ? 'Engine pausado' : 'Engine activo'}</span>
            <span className="pill demo"><span className="dot" />Modo Demo</span>
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

const SIM_INVITE_KEY = 'nifty_sim_invite_dismissed';

function SimulatorInvite() {
    const { settings, simulation, actions } = useNifty();
    const [dismissed, setDismissed] = useState(() => localStorage.getItem(SIM_INVITE_KEY) === '1');
    const [working, setWorking] = useState(false);

    if (!settings || settings.simulation_enabled !== false || dismissed) return null;

    const dismiss = () => { localStorage.setItem(SIM_INVITE_KEY, '1'); setDismissed(true); };

    const enable = async () => {
        setWorking(true);
        try {
            await actions.saveSettings({ simulation_enabled: true, simulation_max_drift_pct: settings.simulation_max_drift_pct || 0.5 });
            if (!simulation.active) await actions.startStop();
            await actions.loadSettings();
            await actions.refreshSlow();
        } catch { /* el error se refleja vía store */ } finally { setWorking(false); }
    };

    return (
        <div className="panel panel-pad" style={{ margin: '16px 30px 0', display: 'flex', alignItems: 'center', gap: 14, flexWrap: 'wrap', justifyContent: 'space-between', borderLeft: '3px solid var(--accent)' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 12, minWidth: 280, flex: 1 }}>
                <div className="brand-mark" style={{ flex: 'none' }}><I.bolt style={{ width: 16, height: 16, color: '#0a0710' }} /></div>
                <div>
                    <div style={{ fontWeight: 600, color: 'var(--tx-hi)' }}>Enciende el simulador de oportunidades</div>
                    <div className="cfg-desc">En mercado real los spreads casi nunca cubren los fees. El simulador inyecta una deriva sintética de precios para crear escenarios rentables (2 patas y ciclos triangulares) y ver el motor operar.</div>
                </div>
            </div>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                <button className="btn" onClick={dismiss}>Más tarde</button>
                <button className="btn primary" onClick={enable} disabled={working}>
                    <I.bolt style={{ width: 14, height: 14 }} />{working ? 'Encendiendo…' : 'Encender simulador'}
                </button>
            </div>
        </div>
    );
}

export default function AppShell({ user, onLogout }) {
    const [active, setActive] = useState(() => {
        const h = (location.hash || '').replace('#', '');
        return META[h] ? h : 'dash';
    });
    const [open, setOpen] = useState(null);

    useEffect(() => {
        location.hash = active;
        const main = document.querySelector('.main');
        if (main) main.scrollTop = 0;
    }, [active]);

    const onOpen = (o) => setOpen(o);

    let view;
    switch (active) {
        case 'market': view = <MarketScreen />; break;
        case 'opp': view = <OppsScreen onOpen={onOpen} />; break;
        case 'trade': view = <TradesScreen />; break;
        case 'wallet': view = <WalletsScreen />; break;
        case 'perf': view = <PerfScreen />; break;
        case 'engine': view = <EngineScreen />; break;
        case 'autopilot': view = <AutopilotScreen />; break;
        case 'cfg': view = <ConfigScreen />; break;
        default: view = <DashboardScreen onOpen={onOpen} />;
    }

    return (
        <div className="app">
            <Sidebar active={active} onNav={setActive} />
            <div className="main">
                <Header active={active} user={user} onLogout={onLogout} />
                <SimulatorInvite />
                {view}
            </div>
            <OppDrawer o={open} onClose={() => setOpen(null)} />
        </div>
    );
}
