/* NIFTY — Engine: salud del motor, conexiones por exchange y logs reales. */
import { useState } from 'react';
import { useNifty } from '../data/store';
import { I } from '../nifty/icons';
import { Lat, Toggle, NumField } from '../nifty/widgets';
import { ResetProcessModal } from '../nifty/ResetModal';
import { exLabel, exColor, signedMoney, relativeTime, discardLabel, fmt } from '../nifty/format';

/* Zona de peligro: reinicia el proceso (borra toda la data + challengers y
   restaura wallets). Pide confirmación porque es irreversible. */
function DangerZone() {
    const [open, setOpen] = useState(false);
    const [done, setDone] = useState(false);

    return (
        <div className="panel panel-pad" style={{ borderLeft: '3px solid var(--loss)' }}>
            <div className="sec-title"><h3>Zona de peligro</h3><span className="ln" /></div>
            <div className="cfg-row" style={{ borderBottom: 'none', marginTop: 4 }}>
                <div className="cfg-info">
                    <div className="cfg-name">Reiniciar el proceso actual</div>
                    <div className="cfg-desc">Borra toda la data de transacciones (oportunidades y trades) y reinicia los challengers y el champion del autopilot. Las wallets vuelven a su saldo inicial. Todo vuelve a empezar y no se puede deshacer.</div>
                </div>
                <button className="btn danger" onClick={() => { setDone(false); setOpen(true); }}>Reiniciar proceso</button>
            </div>
            {done && <div className="alert ok" style={{ marginTop: 12, marginBottom: 0 }}><span className="ad" />Proceso reiniciado: data de transacciones y challengers borrados, wallets restauradas.</div>}
            <ResetProcessModal open={open} onClose={(ok) => { setOpen(false); if (ok) setDone(true); }} />
        </div>
    );
}

/* Panel del modo simulación: inyecta jitter de precios para forzar spreads
   rentables. El toggle + el % máximo persisten en ArbitrageSetting y disparan
   hot-reload del engine en el siguiente ciclo de reconciliación. */
function SimulationPanel() {
    const { settings, actions } = useNifty();
    const [draft, setDraft] = useState(null);
    const [saving, setSaving] = useState(false);
    const [msg, setMsg] = useState(null);

    if (!settings) return null;

    const cfg = draft || settings;
    const enabled = !!cfg.simulation_enabled;
    const drift = cfg.simulation_max_drift_pct ?? 0;
    const execDrift = cfg.simulation_max_exec_drift_pct ?? 0;
    const set = (k, v) => setDraft({ ...cfg, [k]: v });
    const dirty = draft != null;

    const save = async () => {
        setSaving(true);
        setMsg(null);
        try {
            await actions.saveSettings({
                simulation_enabled: enabled,
                simulation_max_drift_pct: Number(drift) || 0,
                simulation_max_exec_drift_pct: Number(execDrift) || 0,
            });
            setDraft(null);
            setMsg({ ok: true, t: 'Modo simulación guardado. El engine lo aplica en unos segundos.' });
        } catch (err) {
            setMsg({ ok: false, t: err.message });
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="panel">
            <div className="panel-h">
                <I.engine style={{ width: 16, height: 16, color: 'var(--profit)' }} />
                <h2>Modo simulación</h2>
                <div className="right">
                    <span className={'pill ' + (enabled ? 'live' : '')} style={{ fontSize: '10px', padding: '4px 9px' }}>
                        <span className="dot" />{enabled ? 'activo' : 'apagado'}
                    </span>
                </div>
            </div>
            <div className="panel-pad" style={{ paddingTop: 8 }}>
                <div className="cfg-row">
                    <div className="cfg-info">
                        <div className="cfg-name">Inyección de precio sintético</div>
                        <div className="cfg-desc">Desplaza aleatoriamente cada order book para generar spreads cruzados rentables. Solo para pruebas/demo: no usar con dinero real.</div>
                    </div>
                    <Toggle on={enabled} onChange={(v) => set('simulation_enabled', v)} />
                </div>
                <div className="cfg-row" style={{ alignItems: 'flex-end' }}>
                    <div className="cfg-info">
                        <div className="cfg-name">Deriva del order book (pre-evaluación)</div>
                        <div className="cfg-desc">Porcentaje máximo (±) que se modifica el precio real del order book antes de evaluar. Mayor deriva = spreads más amplios y más oportunidades rentables.</div>
                    </div>
                    <div style={{ width: 170 }}>
                        <NumField label="Deriva máx." value={drift} unit="%" step={0.05} onChange={(v) => set('simulation_max_drift_pct', Number.isNaN(v) ? 0 : v)} />
                    </div>
                </div>
                <div className="cfg-row" style={{ borderBottom: 'none', alignItems: 'flex-end' }}>
                    <div className="cfg-info">
                        <div className="cfg-name">Slippage de ejecución (al hacer el trade)</div>
                        <div className="cfg-desc">Porcentaje máximo (±) que se desvía el precio de fill de compra/venta respecto al precio evaluado, simulando el movimiento entre la decisión y la ejecución. Puede mejorar o empeorar el P&L realizado.</div>
                    </div>
                    <div style={{ width: 170 }}>
                        <NumField label="Slippage máx." value={execDrift} unit="%" step={0.05} onChange={(v) => set('simulation_max_exec_drift_pct', Number.isNaN(v) ? 0 : v)} />
                    </div>
                </div>
                {msg && <div className={'alert ' + (msg.ok ? 'ok' : 'err')} style={{ marginTop: 12 }}><span className="ad" />{msg.t}</div>}
                <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 14 }}>
                    <button className="btn" onClick={() => { setDraft(null); setMsg(null); }} disabled={!dirty || saving}>Descartar</button>
                    <button className="btn primary" onClick={save} disabled={!dirty || saving}>{saving ? 'Guardando…' : 'Guardar'}</button>
                </div>
            </div>
        </div>
    );
}

const CONN = { ok: 'Conectado', stale: 'Stale', recon: 'Sin datos' };

function logTime(createdAt) {
    if (!createdAt) return '--:--:--';
    return new Date(createdAt).toTimeString().slice(0, 8);
}

/* Tabla de ciclos triangulares recibidos en vivo: muestra cada ciclo detectado
   con su ruta, profit neto evaluado y el resultado realizado (en simulación).
   Coexiste con el resto del Engine: los ciclos comparten store/wallets pero
   se renderizan aparte porque su estructura es multi-pata. */
function CyclesPanel({ cycleFeed }) {
    const rows = (cycleFeed || []).slice(0, 12);

    const STATUS_MAP = {
        execute: { cls: 'pos', t: 'Ejecutado' },
        reject: { cls: 'neg', t: 'Rechazado' },
        ignore: { cls: '', t: 'Ignorado' },
    };

    return (
        <div className="panel">
            <div className="panel-h">
                <I.opp style={{ width: 16, height: 16, color: 'var(--turq)' }} />
                <h2>Arbitraje triangular (ciclos)</h2>
                <div className="right">
                    <span className={'pill ' + (rows.length ? 'live' : '')} style={{ fontSize: '10px', padding: '4px 9px' }}>
                        <span className="dot" />{rows.length ? 'en vivo' : 'sin ciclos'}
                    </span>
                </div>
            </div>
            <div style={{ overflowX: 'auto' }}>
                <table className="tbl">
                    <thead>
                        <tr>
                            <th>Hora</th>
                            <th>Ruta</th>
                            <th>Spread bruto</th>
                            <th>Profit neto</th>
                            <th>Margen</th>
                            <th>Realizado</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.length === 0 ? (
                            <tr><td colSpan="7" className="empty-note">Sin ciclos triangulares todavía. Activa <span className="mono">ARBITRAGE_TRIANGULAR_ENABLED=true</span> y reinicia el engine.</td></tr>
                        ) : rows.map((row, idx) => {
                            const c = row.cycle || {};
                            const sim = row.simulation || null;
                            const status = STATUS_MAP[row.decision] || STATUS_MAP.reject;
                            const detectedMs = Number(c.detected_at_ms) || (row.published_at ? Date.parse(row.published_at) : Date.now());
                            const time = new Date(detectedMs).toTimeString().slice(0, 8);
                            const net = Number(c.net_profit) || 0;
                            const margin = (Number(c.net_margin) || 0) * 100;
                            const realized = sim ? Number(sim.realized_pnl) || 0 : null;
                            const grossBps = (Number(c.gross_spread_bps) || 0) / 100;
                            const startAsset = c.start_asset || '';
                            const fmtAmt = (n) => (n >= 0 ? '+' : '−') + fmt(Math.abs(n), startAsset === 'USDT' || startAsset === 'USD' ? 2 : 6);
                            return (
                                <tr key={idx}>
                                    <td className="mono" style={{ color: 'var(--tx-lo)' }}>{time}</td>
                                    <td className="mono" style={{ color: 'var(--tx-hi)' }}>{c.label || '—'}</td>
                                    <td className="mono" style={{ color: 'var(--tx-hi)' }}>{grossBps >= 0 ? '+' : ''}{grossBps.toFixed(3)}%</td>
                                    <td className={'mono ' + (net >= 0 ? 'pos' : 'neg')}>{fmtAmt(net)} {startAsset}</td>
                                    <td className={'mono ' + (margin >= 0 ? 'pos' : 'neg')}>{margin >= 0 ? '+' : ''}{margin.toFixed(3)}%</td>
                                    <td className={'mono ' + (realized != null ? (realized >= 0 ? 'pos' : 'neg') : '')} style={realized == null ? { color: 'var(--tx-lo)' } : {}}>
                                        {realized != null ? fmtAmt(realized) + ' ' + startAsset : '—'}
                                    </td>
                                    <td><span className={'badge ' + status.cls}><span className="d" />{status.t}</span></td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

export default function EngineScreen() {
    const { engine, engineLive, simulation, cycleFeed } = useNifty();
    const conns = engine.connections || [];
    const metrics = engine.metrics || {};
    const logs = engine.logs || [];
    const serverTime = engine.server_time_ms;
    const online = conns.filter((c) => c.conn === 'ok').length;

    // Embudo de descartes + tallies: prioriza el websocket sobre el snapshot REST.
    const live = engineLive || engine.live || null;
    const discards = live?.discards || {};
    const discardRows = Object.entries(discards)
        .map(([reason, count]) => ({ reason, count: Number(count) || 0 }))
        .sort((a, b) => b.count - a.count);
    const discardTotal = discardRows.reduce((s, r) => s + r.count, 0);
    const discardMax = discardRows.reduce((m, r) => Math.max(m, r.count), 0);
    const decisions = live?.decisions || {};
    const funnelTiles = [
        { l: 'Snapshots', v: live ? fmt(live.snapshots_processed ?? 0, 0) : '—', c: 'var(--turq)' },
        { l: 'Candidatos', v: live ? fmt(live.candidates_detected ?? 0, 0) : '—', c: 'var(--fuchsia)' },
        { l: 'Ejecutadas', v: live ? fmt(decisions.execute ?? 0, 0) : '—', c: 'var(--profit)' },
        { l: 'Rechazadas', v: live ? fmt(decisions.reject ?? 0, 0) : '—', c: 'var(--warn)' },
        { l: 'Descartes', v: live ? fmt(discardTotal, 0) : '—', c: 'var(--tx-mid)' },
    ];

    const metricTiles = [
        { l: 'Trades totales', v: metrics.trades_total ?? '—' },
        { l: 'Oportunidades totales', v: metrics.opportunities_total ?? '—' },
        { l: 'Detectadas / hora', v: metrics.opportunities_last_hour ?? '—' },
        { l: 'Ejecutadas / hora', v: metrics.executed_last_hour ?? '—' },
        { l: 'P&L realizado', v: metrics.realized_pnl != null ? signedMoney(metrics.realized_pnl) : '—' },
        { l: 'Modo actual', v: metrics.mode || 'Demo' },
    ];

    return (
        <div className="content">
            <div className="grid-3">
                <div className="panel mtile hud"><div className="ml">Estado del engine</div><div className={'mv ' + (simulation.active ? 'pos' : '')} style={simulation.active ? {} : { color: 'var(--tx-mid)' }}>● {simulation.active ? 'Activo' : 'Detenido'}</div><div className="mvsub">{metrics.mode || 'Demo'}</div></div>
                <div className="panel mtile"><div className="ml">Circuit breaker</div><div className={'mv ' + (metrics.circuit_breaker_enabled ? 'pos' : '')} style={metrics.circuit_breaker_enabled ? {} : { color: 'var(--tx-mid)' }}>{metrics.circuit_breaker_enabled ? 'ON' : 'OFF'}</div><div className="mvsub">protección de riesgo</div></div>
                <div className="panel mtile"><div className="ml">Exchanges conectados</div><div className="mv">{online} / {conns.length}</div><div className="mvsub">{conns.length - online} con incidencias</div></div>
            </div>

            <SimulationPanel />

            <DangerZone />

            <CyclesPanel cycleFeed={cycleFeed} />

            <div className="panel">
                <div className="panel-h"><I.engine style={{ width: 16, height: 16, color: 'var(--turq)' }} /><h2>Conexiones por exchange</h2></div>
                <div style={{ overflowX: 'auto' }}>
                    <table className="tbl">
                        <thead>
                            <tr><th>Exchange</th><th>Conexión</th><th>Tipo</th><th>Último mensaje</th><th>Latencia</th><th>Estado feed</th></tr>
                        </thead>
                        <tbody>
                            {conns.length === 0 ? (
                                <tr><td colSpan="6" className="empty-note">Sin conectores. Levanta <span className="mono">market:feed</span>.</td></tr>
                            ) : conns.map((c) => (
                                <tr key={c.exchange}>
                                    <td><span className="ex-name"><span className="ex-dot" style={{ background: exColor(c.exchange) }} />{exLabel(c.exchange)}</span></td>
                                    <td style={{ textAlign: 'right' }}><span className={'conn ' + c.conn}><span className="d" />{CONN[c.conn]}</span></td>
                                    <td className="mono" style={{ color: 'var(--tx-lo)' }}>{c.type}</td>
                                    <td className="mono" style={{ color: c.conn === 'ok' ? 'var(--tx-mid)' : 'var(--warn)' }}>{c.age_ms != null && serverTime ? relativeTime(serverTime - c.age_ms) : '—'}</td>
                                    <td><Lat ms={c.age_ms} /></td>
                                    <td className="mono" style={{ color: c.has_data ? 'var(--profit)' : 'var(--tx-lo)' }}>{c.has_data ? 'con datos' : 'sin datos'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            <div className="panel">
                <div className="panel-h">
                    <I.engine style={{ width: 16, height: 16, color: 'var(--fuchsia)' }} />
                    <h2>Embudo de descartes</h2>
                    <div className="right">
                        <span className={'pill ' + (live ? 'live' : '')} style={{ fontSize: '10px', padding: '4px 9px' }}>
                            <span className="dot" />{live ? 'en vivo' : 'sin datos'}
                        </span>
                    </div>
                </div>
                <div className="panel-pad" style={{ paddingTop: 4 }}>
                    <div className="grid-3" style={{ gridTemplateColumns: 'repeat(5, 1fr)', marginBottom: 14 }}>
                        {funnelTiles.map((t, i) => (
                            <div key={i} className="mtile" style={{ padding: '8px 0' }}>
                                <div className="ml">{t.l}</div>
                                <div className="mv" style={{ fontSize: 20, color: t.c }}>{t.v}</div>
                            </div>
                        ))}
                    </div>
                    {discardRows.length === 0 ? (
                        <div className="empty-note" style={{ padding: '14px 16px' }}>
                            {live
                                ? 'Sin descartes en la ventana actual.'
                                : 'Sin métricas todavía. Levanta el engine con la simulación activa.'}
                        </div>
                    ) : (
                        <div className="funnel">
                            {discardRows.map((r) => {
                                const pct = discardTotal > 0 ? (r.count / discardTotal) * 100 : 0;
                                const width = discardMax > 0 ? (r.count / discardMax) * 100 : 0;
                                return (
                                    <div key={r.reason} className="funnel-row" style={{ display: 'grid', gridTemplateColumns: '200px 1fr 96px', alignItems: 'center', gap: 12, padding: '5px 0' }}>
                                        <span style={{ color: 'var(--tx-mid)', fontSize: 13 }}>{discardLabel(r.reason)}</span>
                                        <span style={{ background: 'var(--line)', borderRadius: 5, height: 8, overflow: 'hidden' }}>
                                            <span style={{ display: 'block', height: '100%', width: width + '%', background: r.reason.startsWith('risk:') ? 'var(--warn)' : 'var(--turq)', borderRadius: 5 }} />
                                        </span>
                                        <span className="mono" style={{ textAlign: 'right', color: 'var(--tx-lo)', fontSize: 12 }}>
                                            {fmt(r.count, 0)} · {pct.toFixed(1)}%
                                        </span>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </div>
            </div>

            <div className="col-2">
                <div className="panel">
                    <div className="panel-h"><I.opp style={{ width: 16, height: 16, color: 'var(--fuchsia)' }} /><h2>Eventos recientes del bot</h2><div className="right"><span className="pill live" style={{ fontSize: '10px', padding: '4px 9px' }}><span className="dot" />stream</span></div></div>
                    <div className="console">
                        {logs.length === 0 ? (
                            <div className="empty-note" style={{ padding: '14px 16px' }}>Sin eventos registrados todavía.</div>
                        ) : logs.map((l) => (
                            <div key={l.id} className={'logline ' + l.level}>
                                <span className="lt">{logTime(l.created_at)}</span>
                                <span className={'lvl ' + l.level}>{l.level}</span>
                                <span className="lmsg">{l.symbol && <b>[{l.symbol}] </b>}{l.message}</span>
                            </div>
                        ))}
                    </div>
                </div>

                <div className="panel panel-pad">
                    <div className="sec-title"><h3>Métricas del engine</h3><span className="ln" /></div>
                    <div className="cfg-grid" style={{ marginTop: 6 }}>
                        {metricTiles.map((m, i) => (
                            <div key={i} className="mtile" style={{ padding: '10px 0' }}>
                                <div className="ml">{m.l}</div>
                                <div className="mv" style={{ fontSize: 20 }}>{m.v}</div>
                            </div>
                        ))}
                    </div>
                </div>
            </div>
        </div>
    );
}
