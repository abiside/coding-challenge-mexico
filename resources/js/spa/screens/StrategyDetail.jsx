/* NIFTY — dashboard de una instancia de estrategia. Para trading: tabs General /
   Señales / Posiciones / Configuración / Agente. Para cross-exchange: monta las
   pantallas del arbitraje propias de su contexto (Resumen, Oportunidades, Engine,
   Configuración) como tabs. Mercado, Wallets y Rendimiento son vistas globales y
   viven en el menú "Global". */
import { useEffect, useState } from 'react';
import { useNifty } from '../data/store';
import { I } from '../nifty/icons';
import { InfoTip } from '../nifty/InfoTip';
import { NumField } from '../nifty/widgets';
import { fmt, signedMoney, timeFromMs, relativeTime } from '../nifty/format';

import DashboardScreen from './Dashboard';
import OppsScreen from './Opportunities';
import EngineScreen from './Engine';
import ConfigScreen from './Config';

/* ---------- Cross-exchange (arbitraje) ----------
   Mercado, Wallets, Rendimiento y Autopilot viven como ítems de primer nivel en
   el menú (vistas transversales del arbitraje). Aquí quedan solo las tabs que
   solo cobran sentido dentro del contexto de la estrategia: el resumen, el feed
   de oportunidades, la salud del engine y sus reglas de operación. */

const CROSS_TABS = [
    ['dash', 'Resumen', 'dash'],
    ['opp', 'Oportunidades', 'opp'],
    ['engine', 'Engine', 'engine'],
    ['cfg', 'Configuración', 'cfg'],
];

function CrossExchangeDetail({ onOpen, onNav }) {
    const [tab, setTab] = useState('dash');

    let view;
    switch (tab) {
        case 'opp': view = <OppsScreen onOpen={onOpen} />; break;
        case 'engine': view = <EngineScreen />; break;
        case 'cfg': view = <ConfigScreen />; break;
        default: view = <DashboardScreen onOpen={onOpen} onNav={onNav} />;
    }

    // Nota: las pantallas montadas (Dashboard/Opps/Engine/Config) traen su propio
    // wrapper `.content`. Para que las tabs no queden más anchas que el cuerpo,
    // van en su propio `.content` hermano (mismo padding horizontal) en lugar de
    // anidar un `.content` dentro de otro.
    return (
        <>
            <div className="content" style={{ paddingBottom: 0 }}>
                <div className="panel" style={{ padding: 0 }}>
                    <div className="filters" style={{ padding: '10px 16px', gap: 6, flexWrap: 'wrap' }}>
                        {CROSS_TABS.map(([key, label, icon]) => {
                            const Icon = I[icon];
                            return (
                                <span key={key} className={'chip' + (tab === key ? ' on' : '')} onClick={() => setTab(key)} style={{ display: 'inline-flex', alignItems: 'center', gap: 5 }}>
                                    <Icon style={{ width: 13, height: 13 }} />{label}
                                </span>
                            );
                        })}
                    </div>
                </div>
            </div>

            {view}
        </>
    );
}

/* ---------- Trading (long/short simulado) ---------- */

const TRADING_TABS = [
    ['general', 'General'],
    ['signals', 'Señales'],
    ['positions', 'Posiciones'],
    ['cfg', 'Configuración'],
    ['ai', 'Agente'],
];

const SIDE_BADGE = { long: { cls: 'exec', label: 'LONG' }, short: { cls: 'reject', label: 'SHORT' } };

function Kpi({ label, value, cls, detail, info }) {
    return (
        <div className="panel mtile">
            <div className="ml">{label}{info && <InfoTip g={info} />}</div>
            <div className={'mv' + (cls ? ' ' + cls : '')}>{value}</div>
            {detail != null && <div className="ml" style={{ marginTop: 4 }}>{detail}</div>}
        </div>
    );
}

function TradingGeneral({ metrics }) {
    const positions = metrics?.positions || [];
    const equity = metrics?.equity_value ?? 0;
    const usdt = metrics?.usdt_balance ?? 0;
    const deployedValue = metrics?.deployed_value ?? 0;
    const unrealized = Number(metrics?.unrealized_pnl) || 0;
    const realized = metrics?.realized_pnl ?? 0;

    return (
        <>
            <div className="grid-3" style={{ gridTemplateColumns: 'repeat(3,1fr)' }}>
                <Kpi label="Equity (USDT)" info="equity_meanrev" value={'$' + fmt(equity)} detail={`caja $${fmt(usdt)} · invertido $${fmt(deployedValue)}`} />
                <Kpi label="P&L no realizado" info="pnl_no_realizado" value={signedMoney(unrealized)} cls={unrealized >= 0 ? 'pos' : 'neg'} />
                <Kpi label="P&L realizado" info="pnl_realizado" value={signedMoney(realized)} cls={realized >= 0 ? 'pos' : 'neg'} />
                <Kpi label="Win rate" value={metrics?.win_rate != null ? (metrics.win_rate * 100).toFixed(1) + '%' : '—'} detail={`${metrics?.wins ?? 0}W / ${metrics?.losses ?? 0}L`} />
                <Kpi label="Posiciones abiertas" info="posiciones_abiertas" value={metrics?.open_positions ?? positions.length} />
                <Kpi label="Señales (det/apr/rej)" info="funnel_candidatos" value={`${metrics?.signals_detected ?? 0}/${metrics?.signals_approved ?? 0}/${metrics?.signals_rejected ?? 0}`} />
            </div>
            <PositionsTable positions={positions} updatedAt={metrics?.updated_at} />
        </>
    );
}

function PositionsTable({ positions, updatedAt }) {
    return (
        <div className="panel">
            <div className="panel-h">
                <I.vol style={{ width: 16, height: 16, color: 'var(--accent)' }} />
                <h2>Posiciones abiertas</h2>
                <div className="right muted" style={{ fontSize: 12 }}>{updatedAt ? 'actualizado ' + relativeTime(Date.parse(updatedAt)) : '—'}</div>
            </div>
            <div style={{ overflowX: 'auto' }}>
                <table className="tbl">
                    <thead>
                        <tr><th>Símbolo</th><th>Lado</th><th>Entrada</th><th>Actual</th><th>Notional</th><th>TP / SL</th><Th info="pnl_no_realizado">P&L no real.</Th><th>Abierta</th></tr>
                    </thead>
                    <tbody>
                        {(positions || []).length === 0 ? (
                            <tr><td colSpan="8" className="empty-note">Sin posiciones abiertas. La caja está 100% en USDT.</td></tr>
                        ) : positions.map((p, i) => {
                            const up = Number(p.unrealized_pnl) || 0;
                            const badge = SIDE_BADGE[p.side] || SIDE_BADGE.long;
                            return (
                                <tr key={p.symbol + i}>
                                    <td style={{ fontWeight: 600 }}>{p.symbol}</td>
                                    <td><span className={'badge ' + badge.cls}><span className="d" />{badge.label}</span></td>
                                    <td className="mono">${fmt(p.entry_price, 6)}</td>
                                    <td className="mono">{p.last_price != null ? '$' + fmt(p.last_price, 6) : '—'}</td>
                                    <td className="mono">${fmt(p.notional)}</td>
                                    <td className="mono" style={{ color: 'var(--tx-lo)', fontSize: 11 }}>${fmt(p.take_profit, 6)} / ${fmt(p.stop_loss, 6)}</td>
                                    <td className={'mono ' + (up >= 0 ? 'pos' : 'neg')}>{signedMoney(up)}{p.unrealized_pct != null && <span style={{ color: 'var(--tx-lo)', fontSize: 11 }}> ({p.unrealized_pct >= 0 ? '+' : ''}{Number(p.unrealized_pct).toFixed(2)}%)</span>}</td>
                                    <td className="mono" style={{ color: 'var(--tx-lo)' }}>{relativeTime(p.opened_at_ms)}</td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

function Th({ info, children }) {
    return <th>{children}{info && <InfoTip g={info} />}</th>;
}

const KIND_BADGE = {
    open: { cls: 'exec', label: 'Apertura' },
    close: { cls: 'eval', label: 'Cierre' },
    signal: { cls: 'reject', label: 'Rechazada' },
};

function SignalsTab({ signals }) {
    return (
        <div className="panel">
            <div className="panel-h"><I.trade style={{ width: 16, height: 16, color: 'var(--fuchsia)' }} /><h2>Señales en vivo</h2></div>
            <div style={{ overflowX: 'auto' }}>
                <table className="tbl">
                    <thead>
                        <tr><th>Hora</th><th>Símbolo</th><th>Lado</th><Th info="confidence">Confianza</Th><th>Precio</th><th>Detalle</th><th>Evento</th></tr>
                    </thead>
                    <tbody>
                        {(signals || []).length === 0 ? (
                            <tr><td colSpan="7" className="empty-note">Sin señales todavía. Cuando una estrategia dispare aparecerá aquí.</td></tr>
                        ) : signals.map((s, i) => {
                            const sig = s.signal || {};
                            const pos = s.position || {};
                            const side = sig.side || pos.side;
                            const badge = KIND_BADGE[s.kind] || KIND_BADGE.signal;
                            const sb = SIDE_BADGE[side];
                            const ms = sig.created_at_ms || (s.published_at ? Date.parse(s.published_at) : null);
                            let detail = '—';
                            if (s.kind === 'close') detail = `${s.close_reason} · P&L ${signedMoney(Number(pos.net_pnl) || 0)}`;
                            else if (s.kind === 'open') detail = `notional $${fmt(pos.notional)}`;
                            else if (s.kind === 'signal') detail = s.reject_reason || (sig.reasons || []).join(' · ');
                            return (
                                <tr key={s.published_at || i} className={s._flashAt ? 'flash' : ''}>
                                    <td className="mono" style={{ color: 'var(--tx-lo)' }}>{timeFromMs(ms)}</td>
                                    <td style={{ fontWeight: 600 }}>{sig.symbol || pos.symbol || '—'}</td>
                                    <td>{sb ? <span className={'badge ' + sb.cls}><span className="d" />{sb.label}</span> : '—'}</td>
                                    <td className="mono">{sig.confidence_score != null ? (sig.confidence_score * 100).toFixed(0) + '%' : '—'}</td>
                                    <td className="mono">${fmt(sig.entry_price || pos.entry_price, 6)}</td>
                                    <td style={{ color: 'var(--tx-mid)', fontSize: 12 }}>{detail}</td>
                                    <td><span className={'badge ' + badge.cls}><span className="d" />{badge.label}</span></td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

const CLOSE_LABEL = {
    take_profit: 'Take-profit', stop_loss: 'Stop-loss', timeout: 'Timeout', liquidation: 'Liquidación',
};

function PositionsHistory({ id }) {
    const { actions } = useNifty();
    const [data, setData] = useState({ open: [], closed: [], summary: null });

    useEffect(() => {
        let alive = true;
        const load = () => actions.loadStrategyPositions(id).then((res) => { if (alive && res) setData(res); }).catch(() => {});
        load();
        const t = setInterval(load, 6000);
        return () => { alive = false; clearInterval(t); };
    }, [id, actions]);

    const closed = data.closed || [];

    return (
        <div className="panel">
            <div className="panel-h">
                <I.trade style={{ width: 16, height: 16, color: 'var(--turq)' }} /><h2>Historial de posiciones</h2>
                <div className="right muted" style={{ fontSize: 12 }}>{data.summary ? `${data.summary.closed_count} cerradas · P&L ${signedMoney(data.summary.realized_pnl)}` : '—'}</div>
            </div>
            <div style={{ overflowX: 'auto' }}>
                <table className="tbl">
                    <thead>
                        <tr><th>Cierre</th><th>Símbolo</th><th>Lado</th><th>Entrada</th><th>Salida</th><th>Notional</th><th>Fees</th><Th info="pnl_realizado">P&L neto</Th><th>Razón</th></tr>
                    </thead>
                    <tbody>
                        {closed.length === 0 ? (
                            <tr><td colSpan="9" className="empty-note">Sin posiciones cerradas todavía.</td></tr>
                        ) : closed.map((p) => {
                            const pnl = Number(p.net_pnl) || 0;
                            const badge = SIDE_BADGE[p.side] || SIDE_BADGE.long;
                            return (
                                <tr key={p.id}>
                                    <td className="mono" style={{ color: 'var(--tx-lo)' }}>{timeFromMs(p.closed_at_ms)}</td>
                                    <td style={{ fontWeight: 600 }}>{p.symbol}</td>
                                    <td><span className={'badge ' + badge.cls}><span className="d" />{badge.label}</span></td>
                                    <td className="mono">${fmt(p.entry_price, 6)}</td>
                                    <td className="mono">{p.exit_price != null ? '$' + fmt(p.exit_price, 6) : '—'}</td>
                                    <td className="mono">${fmt(p.notional)}</td>
                                    <td className="mono neg">−${fmt((Number(p.fees) || 0) + (Number(p.funding_fee) || 0), 4)}</td>
                                    <td className={'mono ' + (pnl >= 0 ? 'pos' : 'neg')} style={{ fontWeight: 600 }}>{signedMoney(pnl)}</td>
                                    <td style={{ color: 'var(--tx-mid)' }}>{CLOSE_LABEL[p.close_reason] || p.close_reason || p.status}</td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

const CONFIG_FIELDS = [
    ['slice_usdt', 'Tamaño por posición', 'USDT', 10, 'slice_usdt'],
    ['take_profit_pct', 'Take-profit', '%', 0.1, 'take_profit_pct'],
    ['stop_loss_pct', 'Stop-loss', '%', 0.1, 'stop_loss_pct'],
    ['max_holding_seconds', 'Tiempo máx.', 's', 30, null],
    ['max_open_positions', 'Máx. posiciones', '', 1, 'max_open_positions'],
    ['leverage', 'Apalancamiento', 'x', 0.5, 'leverage'],
    ['min_confidence', 'Confianza mínima', 'frac', 0.05, 'min_confidence'],
    ['max_spread_pct', 'Spread máx', '%', 0.05, 'max_spread_pct'],
];

function TradingConfig({ strategy }) {
    const { actions, busy } = useNifty();
    const [cfg, setCfg] = useState(strategy.config || {});
    const [name, setName] = useState(strategy.name);
    const [saved, setSaved] = useState(false);

    const setField = (key, v) => setCfg((c) => ({ ...c, [key]: Number.isNaN(v) ? '' : v }));

    const save = async () => {
        await actions.strategyConfig(strategy.id, { name, config: cfg });
        setSaved(true);
        setTimeout(() => setSaved(false), 2000);
    };

    return (
        <div className="panel panel-pad">
            <div className="sec-title"><h3>Configuración de la estrategia</h3><span className="ln" /></div>
            <p className="cfg-desc">Los cambios se aplican al reiniciar la instancia (si está activa, se reinicia su engine en vivo).</p>

            <label className="numfield" style={{ maxWidth: 360, marginTop: 14 }}>
                <span className="nf-label">Nombre</span>
                <span className="nf-input"><input value={name} onChange={(e) => setName(e.target.value)} /></span>
            </label>

            <div className="cfg-grid cols-3" style={{ marginTop: 18 }}>
                {CONFIG_FIELDS.map(([key, label, unit, step, info]) => (
                    <NumField key={key} label={label} info={info} unit={unit} step={step}
                        value={cfg[key] ?? ''} onChange={(v) => setField(key, v)} />
                ))}
            </div>

            <div style={{ marginTop: 18, display: 'flex', gap: 8, alignItems: 'center' }}>
                <button className="btn primary" onClick={save} disabled={busy}>Guardar configuración</button>
                {saved && <span style={{ color: 'var(--profit)', fontSize: 13 }}>Guardado ✓</span>}
            </div>
        </div>
    );
}

function TradingAi({ strategy }) {
    const { aiRecs } = useNifty();
    const recs = (aiRecs?.data || []).filter((r) => r.strategy_id === strategy.id || r.strategy_id == null);
    return (
        <div className="panel panel-pad">
            <div style={{ fontWeight: 600, color: 'var(--tx-hi)', marginBottom: 4 }}>Agente<InfoTip g="agente_ia" /></div>
            <div className="cfg-desc">Recomendaciones del agente para esta estrategia (modo asesor). No ejecuta operaciones por sí solo; tú decides aplicarlas.</div>
            {recs.length === 0 ? (
                <div className="empty-note">Sin recomendaciones para esta estrategia todavía.</div>
            ) : (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 6, marginTop: 8 }}>
                    {recs.slice(0, 10).map((r) => (
                        <div key={r.id} style={{ display: 'flex', gap: 8, padding: '6px 0', borderTop: '1px solid var(--line-2)' }}>
                            <span className="badge eval"><span className="d" />{r.type.replace(/_/g, ' ')}</span>
                            <div style={{ flex: 1, fontSize: 12, color: 'var(--tx-mid)' }}>{r.summary}</div>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

export function TradingDetail({ strategy, signals }) {
    const { strategyLive } = useNifty();
    const [tab, setTab] = useState('general');
    const metrics = strategyLive?.[strategy.id] || strategy.metrics || null;
    const active = strategy.active;
    const running = !!metrics;

    return (
        <div className="content">
            {active && !running && (
                <div className="panel panel-pad" style={{ borderLeft: '3px solid var(--warn)' }}>
                    <div style={{ fontWeight: 600, color: 'var(--tx-hi)' }}>Calentando el engine…</div>
                    <div className="cfg-desc">El worker <span className="mono">strategies:run</span> levantó la instancia y necesita cobertura de serie antes de operar.</div>
                </div>
            )}

            <div className="panel" style={{ padding: 0 }}>
                <div className="filters" style={{ padding: '10px 16px', gap: 6 }}>
                    {TRADING_TABS.map(([key, label]) => (
                        <span key={key} className={'chip' + (tab === key ? ' on' : '')} onClick={() => setTab(key)}>{label}</span>
                    ))}
                </div>
            </div>

            {tab === 'general' && <TradingGeneral metrics={metrics} />}
            {tab === 'signals' && <SignalsTab signals={signals} />}
            {tab === 'positions' && <PositionsHistory id={strategy.id} />}
            {tab === 'cfg' && <TradingConfig strategy={strategy} />}
            {tab === 'ai' && <TradingAi strategy={strategy} />}
        </div>
    );
}

export default function StrategyDetail({ id, onOpen, onNav }) {
    const { strategies, strategySignals } = useNifty();
    const strategy = (strategies?.data || []).find((s) => s.id === id);

    if (!strategy) {
        return <div className="content"><div className="panel panel-pad"><div className="empty-note">Estrategia no encontrada. Vuelve al resumen.</div></div></div>;
    }

    if (strategy.type === 'cross_exchange') {
        return <CrossExchangeDetail onOpen={onOpen} onNav={onNav} />;
    }

    const signals = strategySignals?.[id] || [];
    return <TradingDetail strategy={strategy} signals={signals} />;
}
