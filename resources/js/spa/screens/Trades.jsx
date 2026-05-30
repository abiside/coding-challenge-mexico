/* NIFTY — Trades: historial de operaciones simuladas (datos reales). */
import { useState } from 'react';
import { useNifty } from '../data/store';
import { I } from '../nifty/icons';
import { exLabel, fmt, fmtCompact, timeFromMs } from '../nifty/format';

const SCLASS = { executed: 'exec', ok: 'exec', partial: 'eval', failed: 'reject', cancelled: 'expired', cancel: 'expired' };
const SLABEL = { executed: 'Simulada', ok: 'Simulada', partial: 'Parcial', failed: 'Fallida', cancelled: 'Cancelada', cancel: 'Cancelada' };

function fillOf(t, side) {
    return (t.fills || []).find((f) => f.side === side) || null;
}

function shape(t) {
    const buy = fillOf(t, 'buy');
    const sell = fillOf(t, 'sell');
    const buyNotional = buy?.notional ?? 0;
    const net = Number(t.realized_pnl) || 0;
    return {
        id: t.id,
        time: timeFromMs(t.executed_at_ms || (t.created_at ? Date.parse(t.created_at) : Date.now())),
        buy: exLabel(t.buy_exchange),
        sell: exLabel(t.sell_exchange),
        btc: Number(t.base_volume) || 0,
        buyPrice: buy?.price ?? null,
        sellPrice: sell?.price ?? null,
        fees: (buy?.fee ?? 0) + (sell?.fee ?? 0),
        hasFees: buy != null || sell != null,
        net,
        pct: buyNotional > 0 ? (net / buyNotional) * 100 : 0,
        buyNotional,
        status: t.status || 'executed',
    };
}

export default function TradesScreen() {
    const { trades } = useNifty();
    const [res, setRes] = useState('all');
    const [stat, setStat] = useState('all');

    const shaped = (trades || []).map(shape);
    const statuses = ['all', ...Array.from(new Set(shaped.map((t) => t.status)))];

    let rows = shaped;
    if (res === 'pos') rows = rows.filter((t) => t.net > 0);
    if (res === 'neg') rows = rows.filter((t) => t.net < 0);
    if (stat !== 'all') rows = rows.filter((t) => t.status === stat);

    const wins = shaped.filter((t) => t.net > 0).length;
    const totalNet = shaped.reduce((s, t) => s + t.net, 0);
    const totalVol = shaped.reduce((s, t) => s + t.buyNotional, 0);
    const totalFees = shaped.reduce((s, t) => s + t.fees, 0);

    return (
        <div className="content">
            <div className="grid-3" style={{ gridTemplateColumns: 'repeat(4,1fr)' }}>
                <div className="panel mtile hud"><div className="ml">P&L de trades</div><div className={'mv ' + (totalNet >= 0 ? 'pos' : 'neg')}>{totalNet >= 0 ? '+' : '−'}${fmt(Math.abs(totalNet))}</div><div className="mvsub">{shaped.length} operaciones</div></div>
                <div className="panel mtile"><div className="ml">Win rate</div><div className="mv">{shaped.length ? ((wins / shaped.length) * 100).toFixed(1) : '0.0'}%</div><div className="mvsub">{wins} positivas</div></div>
                <div className="panel mtile"><div className="ml">Volumen operado</div><div className="mv">{fmtCompact(totalVol)}</div><div className="mvsub">simulado</div></div>
                <div className="panel mtile"><div className="ml">Fees totales</div><div className="mv neg">−${fmt(totalFees)}</div><div className="mvsub">comisiones</div></div>
            </div>

            <div className="panel">
                <div className="panel-h">
                    <I.trade style={{ width: 16, height: 16, color: 'var(--turq)' }} />
                    <h2>Operaciones simuladas</h2>
                    <div className="right filters">
                        <span className="chip-label">Resultado</span>
                        {[['all', 'Todos'], ['pos', 'Positivos'], ['neg', 'Negativos']].map(([v, l]) => (
                            <span key={v} className={'chip' + (res === v ? ' on' : '')} onClick={() => setRes(v)}>{l}</span>
                        ))}
                    </div>
                </div>
                {statuses.length > 2 && (
                    <div className="filters" style={{ padding: '12px 20px', borderBottom: '1px solid var(--line-2)' }}>
                        <span className="chip-label">Estado</span>
                        {statuses.map((v) => (
                            <span key={v} className={'chip' + (stat === v ? ' on' : '')} onClick={() => setStat(v)}>{v === 'all' ? 'Todos' : (SLABEL[v] || v)}</span>
                        ))}
                    </div>
                )}
                <div style={{ overflowX: 'auto' }}>
                    <table className="tbl">
                        <thead>
                            <tr>
                                <th>Hora</th><th>Comprar</th><th>Vender</th><th>BTC</th><th>P. compra</th><th>P. venta</th>
                                <th>Fees</th><th>Profit neto</th><th>Profit %</th><th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.length === 0 ? (
                                <tr><td colSpan="10" className="empty-note">Aún no hay operaciones simuladas.</td></tr>
                            ) : rows.map((t) => (
                                <tr key={t.id}>
                                    <td className="mono" style={{ color: 'var(--tx-lo)' }}>{t.time}</td>
                                    <td style={{ fontWeight: 600 }}>{t.buy}</td>
                                    <td style={{ fontWeight: 600 }}>{t.sell}</td>
                                    <td className="mono" style={{ color: 'var(--tx-mid)' }}>{t.btc.toFixed(4)}</td>
                                    <td className="mono" style={{ color: 'var(--tx-mid)' }}>{t.buyPrice != null ? '$' + fmt(t.buyPrice) : '—'}</td>
                                    <td className="mono" style={{ color: 'var(--tx-mid)' }}>{t.sellPrice != null ? '$' + fmt(t.sellPrice) : '—'}</td>
                                    <td className="mono neg">{t.hasFees ? '−$' + fmt(t.fees) : '—'}</td>
                                    <td className={'mono ' + (t.net > 0 ? 'pos' : t.net < 0 ? 'neg' : '')} style={{ fontWeight: 600 }}>{t.net === 0 ? '—' : (t.net > 0 ? '+' : '−') + '$' + Math.abs(t.net).toFixed(2)}</td>
                                    <td className={'mono ' + (t.net > 0 ? 'pos' : t.net < 0 ? 'neg' : '')}>{t.net === 0 ? '—' : (t.pct >= 0 ? '+' : '') + t.pct.toFixed(2) + '%'}</td>
                                    <td><span className={'badge ' + (SCLASS[t.status] || 'exec')}><span className="d" />{SLABEL[t.status] || t.status}</span></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
}
