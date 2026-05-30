/* NIFTY — Reversión a la media: panel de la estrategia meanrev:run.
   Multi-tenant: cada usuario tiene su propia sesión/billetera aislada. El
   estado llega por canal PRIVADO de Reverb + REST. Muestra métricas en vivo,
   posiciones abiertas y el histórico de movimientos propios. */
import { useState } from 'react';
import { useNifty } from '../data/store';
import { I } from '../nifty/icons';
import { fmt, signedMoney, timeFromMs, relativeTime } from '../nifty/format';

const REASON_LABEL = {
    zscore_entry: 'Entrada z-score',
    zscore_exit: 'Salida z-score',
    mean_reversion: 'Reversión',
    take_profit: 'Take-profit',
    stop_loss: 'Stop-loss',
};

function reasonLabel(r) {
    if (!r) return '—';
    return REASON_LABEL[r] || String(r).replace(/_/g, ' ');
}

const DECISION_BADGE = {
    execute: { cls: 'exec', label: 'Ejecutada' },
    reject: { cls: 'reject', label: 'Rechazada' },
    ignore: { cls: 'expired', label: 'Ignorada' },
};

// Une el feed en vivo (websocket) con las señales recientes del snapshot REST,
// deduplicando por marca de tiempo de publicación.
function mergeSignals(live, recent) {
    const seen = new Set();
    const out = [];
    for (const it of [...(live || []), ...(recent || [])]) {
        const key = it.published_at || `${it?.signal?.symbol}-${it?.signal?.detected_at_ms}`;
        if (seen.has(key)) continue;
        seen.add(key);
        out.push(it);
    }
    return out.slice(0, 50);
}

function Kpi({ label, value, cls, detail }) {
    return (
        <div className="panel mtile">
            <div className="ml">{label}</div>
            <div className={'mv' + (cls ? ' ' + cls : '')}>{value}</div>
            {detail != null && <div className="ml" style={{ marginTop: 4 }}>{detail}</div>}
        </div>
    );
}

export default function MeanReversionScreen() {
    const { meanRev, meanRevLive, meanRevFeed, meanRevTrades, busy, actions } = useNifty();
    const [tab, setTab] = useState('signals');

    const enabled = !!meanRev?.enabled;
    const active = !!meanRev?.active;
    const metrics = active ? (meanRevLive || meanRev?.metrics || null) : null;
    const running = !!metrics;
    const positions = metrics?.positions || [];
    const usdt = metrics?.usdt_balance;
    const deployed = metrics?.deployed_usdt;
    const realized = metrics?.realized_pnl ?? meanRevTrades?.summary?.realized_pnl ?? 0;
    const executions = metrics?.executions ?? meanRevTrades?.summary?.trades_total ?? 0;
    const equity = (Number(usdt) || 0) + (Number(deployed) || 0);

    const signals = mergeSignals(meanRevFeed, meanRev?.recent_signals);
    const trades = meanRevTrades?.data || [];

    return (
        <div className="content">
            <div className="panel panel-pad" style={{ display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
                <I.vol style={{ width: 18, height: 18, color: 'var(--accent)' }} />
                <div style={{ flex: 1, minWidth: 220 }}>
                    <div style={{ fontWeight: 600, color: 'var(--tx-hi)' }}>Mi simulación de reversión a la media</div>
                    <div className="cfg-desc" style={{ margin: 0 }}>
                        Billetera simulada propia (USDT). Compra por debajo de la media de 1h y vende al revertir,
                        de forma aislada de otros usuarios y del arbitraje.
                    </div>
                </div>
                <span className={'badge ' + (active ? 'exec' : 'expired')}>
                    <span className="d" />{active ? 'ACTIVA' : 'DETENIDA'}
                </span>
                <button
                    className={'btn ' + (active ? 'danger' : 'primary')}
                    disabled={busy || !enabled}
                    onClick={() => actions.meanRevStartStop()}
                    title={!enabled ? 'Deshabilitado en el servidor (MEANREV_ENABLED=false)' : undefined}
                >
                    {busy ? '…' : active ? 'Detener mi simulación' : 'Iniciar mi simulación'}
                </button>
            </div>

            {active && !running && (
                <div className="panel panel-pad" style={{ borderLeft: '3px solid var(--warn)' }}>
                    <div style={{ fontWeight: 600, color: 'var(--tx-hi)' }}>Calentando tu engine…</div>
                    <div className="cfg-desc">
                        El worker <span className="mono">meanrev:run</span> levantó tu sesión y necesita ~10 min de
                        cobertura por símbolo antes de operar. En cuanto emita el primer heartbeat verás aquí tu estado en vivo.
                    </div>
                </div>
            )}

            {!active && (
                <div className="panel panel-pad" style={{ borderLeft: '3px solid var(--accent)' }}>
                    <div style={{ fontWeight: 600, color: 'var(--tx-hi)' }}>Tu simulación está detenida</div>
                    <div className="cfg-desc">
                        Pulsa <strong>Iniciar mi simulación</strong> para arrancar tu propia billetera de reversión a la media.
                        Tus posiciones y movimientos son privados y no afectan a otros usuarios.
                    </div>
                </div>
            )}

            <div className="grid-3" style={{ gridTemplateColumns: 'repeat(5,1fr)' }}>
                <Kpi label="Equity (USDT)" value={'$' + fmt(equity)} detail={`caja $${fmt(usdt || 0)} · desplegado $${fmt(deployed || 0)}`} />
                <Kpi label="P&L realizado" value={signedMoney(realized)} cls={realized >= 0 ? 'pos' : 'neg'} />
                <Kpi label="Posiciones abiertas" value={positions.length || (metrics?.open_positions ?? 0)} />
                <Kpi label="Ejecuciones" value={executions} detail={`ventas ${meanRevTrades?.summary?.sells_total ?? 0}`} />
                <Kpi label="Candidatas / snapshots" value={`${metrics?.candidates_detected ?? 0} / ${metrics?.snapshots_processed ?? 0}`} />
            </div>

            <div className="panel">
                <div className="panel-h">
                    <I.vol style={{ width: 16, height: 16, color: 'var(--accent)' }} />
                    <h2>Posiciones abiertas</h2>
                    <div className="right muted" style={{ fontSize: 12 }}>
                        {metrics?.updated_at ? 'actualizado ' + relativeTime(Date.parse(metrics.updated_at)) : '—'}
                    </div>
                </div>
                <div style={{ overflowX: 'auto' }}>
                    <table className="tbl">
                        <thead>
                            <tr><th>Activo</th><th>Cantidad</th><th>Costo prom.</th><th>Costo base (USDT)</th><th>Abierta</th></tr>
                        </thead>
                        <tbody>
                            {positions.length === 0 ? (
                                <tr><td colSpan="5" className="empty-note">Sin posiciones abiertas. La caja está 100% en USDT.</td></tr>
                            ) : positions.map((p) => (
                                <tr key={p.asset}>
                                    <td style={{ fontWeight: 600 }}>{p.asset}</td>
                                    <td className="mono">{fmt(p.quantity, 6)}</td>
                                    <td className="mono">${fmt(p.avg_cost, 6)}</td>
                                    <td className="mono">${fmt(p.cost_basis)}</td>
                                    <td className="mono" style={{ color: 'var(--tx-lo)' }}>{relativeTime(p.opened_at_ms)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            <div className="panel">
                <div className="panel-h">
                    <I.trade style={{ width: 16, height: 16, color: 'var(--fuchsia)' }} />
                    <h2>Movimientos</h2>
                    <div className="right filters">
                        <span className={'chip' + (tab === 'signals' ? ' on' : '')} onClick={() => setTab('signals')}>Señales en vivo</span>
                        <span className={'chip' + (tab === 'trades' ? ' on' : '')} onClick={() => setTab('trades')}>Histórico ejecutado</span>
                    </div>
                </div>

                {tab === 'signals' ? (
                    <div style={{ overflowX: 'auto' }}>
                        <table className="tbl">
                            <thead>
                                <tr><th>Hora</th><th>Símbolo</th><th>Lado</th><th>Motivo</th><th>Precio</th><th>Z-score</th><th>Vol %</th><th>P&L</th><th>Decisión</th></tr>
                            </thead>
                            <tbody>
                                {signals.length === 0 ? (
                                    <tr><td colSpan="9" className="empty-note">Sin señales todavía. En cuanto un símbolo se desvíe de su media de 1h aparecerá aquí.</td></tr>
                                ) : signals.map((s, i) => {
                                    const sig = s.signal || {};
                                    const sim = s.simulation;
                                    const badge = DECISION_BADGE[s.decision] || DECISION_BADGE.reject;
                                    const pnl = sim ? Number(sim.realized_pnl) : null;
                                    const ms = sig.detected_at_ms || (s.published_at ? Date.parse(s.published_at) : null);
                                    return (
                                        <tr key={s.published_at || i} className={s._flashAt ? 'flash' : ''}>
                                            <td className="mono" style={{ color: 'var(--tx-lo)' }}>{timeFromMs(ms)}</td>
                                            <td style={{ fontWeight: 600 }}>{sig.symbol || '—'}</td>
                                            <td><span className={'badge ' + (sig.side === 'buy' ? 'exec' : 'reject')}><span className="d" />{sig.side === 'buy' ? 'COMPRA' : 'VENTA'}</span></td>
                                            <td style={{ color: 'var(--tx-mid)' }}>{reasonLabel(sig.reason)}</td>
                                            <td className="mono">${fmt(sig.price, 6)}</td>
                                            <td className={'mono ' + ((sig.z_score || 0) >= 0 ? 'pos' : 'neg')}>{(sig.z_score ?? 0).toFixed(2)}</td>
                                            <td className="mono" style={{ color: 'var(--tx-mid)' }}>{fmt(sig.volatility_pct, 2)}%</td>
                                            <td className={'mono ' + (pnl == null ? '' : pnl >= 0 ? 'pos' : 'neg')}>{pnl == null ? '—' : signedMoney(pnl)}</td>
                                            <td><span className={'badge ' + badge.cls}><span className="d" />{badge.label}</span></td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <div style={{ overflowX: 'auto' }}>
                        <table className="tbl">
                            <thead>
                                <tr><th>Hora</th><th>Símbolo</th><th>Lado</th><th>Motivo</th><th>Precio</th><th>Cantidad</th><th>Monto (USDT)</th><th>Fee</th><th>P&L</th></tr>
                            </thead>
                            <tbody>
                                {trades.length === 0 ? (
                                    <tr><td colSpan="9" className="empty-note">Sin operaciones ejecutadas todavía.</td></tr>
                                ) : trades.map((t) => {
                                    const pnl = Number(t.realized_pnl) || 0;
                                    const ms = t.executed_at_ms || (t.created_at ? Date.parse(t.created_at) : null);
                                    return (
                                        <tr key={t.id}>
                                            <td className="mono" style={{ color: 'var(--tx-lo)' }}>{timeFromMs(ms)}</td>
                                            <td style={{ fontWeight: 600 }}>{t.symbol}</td>
                                            <td><span className={'badge ' + (t.side === 'buy' ? 'exec' : 'reject')}><span className="d" />{t.side === 'buy' ? 'COMPRA' : 'VENTA'}</span></td>
                                            <td style={{ color: 'var(--tx-mid)' }}>{reasonLabel(t.reason)}</td>
                                            <td className="mono">${fmt(t.price, 6)}</td>
                                            <td className="mono">{fmt(t.base_quantity, 6)}</td>
                                            <td className="mono">${fmt(t.quote_amount)}</td>
                                            <td className="mono" style={{ color: 'var(--tx-lo)' }}>${fmt(t.fee, 4)}</td>
                                            <td className={'mono ' + (t.side === 'sell' ? (pnl >= 0 ? 'pos' : 'neg') : '')}>{t.side === 'sell' ? signedMoney(pnl) : '—'}</td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </div>
    );
}
