/* NIFTY — Trades consolidados: TODAS las transacciones del usuario (arbitraje +
   trading) en una sola tabla, etiquetadas por estrategia, con datos de
   monitoreo. Filtra por tipo de estrategia y por resultado. */
import { useEffect, useState } from 'react';
import { useNifty } from '../data/store';
import { I } from '../nifty/icons';
import { InfoTip } from '../nifty/InfoTip';
import { fmt, signedMoney, timeFromMs } from '../nifty/format';

const SIDE_BADGE = {
    long: { cls: 'exec', label: 'LONG' },
    short: { cls: 'reject', label: 'SHORT' },
    arbitrage: { cls: 'eval', label: 'ARB' },
};

const STATUS_LABEL = {
    open: 'Abierta', closed: 'Cerrada', take_profit_hit: 'Take-profit', stopped_out: 'Stop-loss',
    expired: 'Timeout', liquidated_simulated: 'Liquidada', executed: 'Ejecutada',
};

const REASON_LABEL = {
    take_profit: 'Take-profit', stop_loss: 'Stop-loss', timeout: 'Timeout', liquidation: 'Liquidación',
};

function duration(open, close) {
    if (!open || !close) return '—';
    const s = Math.max(0, Math.floor((close - open) / 1000));
    if (s < 60) return s + 's';
    if (s < 3600) return Math.floor(s / 60) + 'm ' + (s % 60) + 's';
    return Math.floor(s / 3600) + 'h ' + Math.floor((s % 3600) / 60) + 'm';
}

export default function TransactionsScreen() {
    const { transactions, actions } = useNifty();
    const [type, setType] = useState('all');
    const [res, setRes] = useState('all');

    useEffect(() => {
        actions.loadTransactions(type === 'all' ? {} : { type });
        const t = setInterval(() => actions.loadTransactions(type === 'all' ? {} : { type }), 6000);
        return () => clearInterval(t);
    }, [type, actions]);

    let rows = transactions?.data || [];
    if (res === 'pos') rows = rows.filter((r) => (r.net_pnl || 0) > 0);
    if (res === 'neg') rows = rows.filter((r) => (r.net_pnl || 0) < 0);

    const totalNet = (transactions?.data || []).reduce((s, r) => s + (Number(r.net_pnl) || 0), 0);
    const count = (transactions?.data || []).length;
    const wins = (transactions?.data || []).filter((r) => (r.net_pnl || 0) > 0).length;

    return (
        <div className="content">
            <div className="grid-3" style={{ gridTemplateColumns: 'repeat(3,1fr)' }}>
                <div className="panel mtile"><div className="ml">P&L consolidado<InfoTip g="pnl_realizado" /></div><div className={'mv ' + (totalNet >= 0 ? 'pos' : 'neg')}>{signedMoney(totalNet)}</div><div className="mvsub">{count} transacciones</div></div>
                <div className="panel mtile"><div className="ml">Win rate</div><div className="mv">{count ? ((wins / count) * 100).toFixed(1) : '0.0'}%</div><div className="mvsub">{wins} positivas</div></div>
                <div className="panel mtile"><div className="ml">Fuentes</div><div className="mv">Trading + Arbitraje</div><div className="mvsub">todas las estrategias</div></div>
            </div>

            <div className="panel">
                <div className="panel-h">
                    <I.trade style={{ width: 16, height: 16, color: 'var(--turq)' }} />
                    <h2>Transacciones consolidadas<InfoTip g="trades_consolidado" /></h2>
                    <div className="right filters">
                        <span className="chip-label">Tipo</span>
                        {[['all', 'Todas'], ['trading', 'Trading'], ['cross_exchange', 'Arbitraje']].map(([v, l]) => (
                            <span key={v} className={'chip' + (type === v ? ' on' : '')} onClick={() => setType(v)}>{l}</span>
                        ))}
                    </div>
                </div>
                <div className="filters" style={{ padding: '12px 20px', borderBottom: '1px solid var(--line-2)' }}>
                    <span className="chip-label">Resultado</span>
                    {[['all', 'Todos'], ['pos', 'Positivos'], ['neg', 'Negativos']].map(([v, l]) => (
                        <span key={v} className={'chip' + (res === v ? ' on' : '')} onClick={() => setRes(v)}>{l}</span>
                    ))}
                </div>
                <div style={{ overflowX: 'auto' }}>
                    <table className="tbl">
                        <thead>
                            <tr>
                                <th>Hora</th><Th info="estrategia_columna">Estrategia</Th><th>Símbolo</th><th>Lado</th>
                                <th>Entrada</th><th>Salida</th><th>Notional</th><th>Fees</th>
                                <Th info="pnl_realizado">P&L neto</Th><th>Duración</th><th>Razón</th><th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.length === 0 ? (
                                <tr><td colSpan="12" className="empty-note">Aún no hay transacciones.</td></tr>
                            ) : rows.map((r, i) => {
                                const pnl = Number(r.net_pnl) || 0;
                                const badge = SIDE_BADGE[r.side] || SIDE_BADGE.long;
                                return (
                                    <tr key={(r.source) + i}>
                                        <td className="mono" style={{ color: 'var(--tx-lo)' }}>{timeFromMs(r.ts_ms)}</td>
                                        <td>
                                            <span style={{ fontWeight: 600, color: 'var(--tx-hi)' }}>{r.strategy_name}</span>
                                            <span className="badge" style={{ marginLeft: 6, opacity: 0.8 }}>{r.strategy_type === 'cross_exchange' ? 'arb' : 'trade'}</span>
                                        </td>
                                        <td style={{ fontWeight: 600 }}>{r.symbol}</td>
                                        <td><span className={'badge ' + badge.cls}><span className="d" />{badge.label}</span></td>
                                        <td className="mono">{r.entry_price != null ? '$' + fmt(r.entry_price, 6) : '—'}</td>
                                        <td className="mono">{r.exit_price != null ? '$' + fmt(r.exit_price, 6) : '—'}</td>
                                        <td className="mono">{r.notional != null ? '$' + fmt(r.notional) : '—'}</td>
                                        <td className="mono neg">{r.fees != null ? '−$' + fmt(r.fees, 4) : '—'}</td>
                                        <td className={'mono ' + (pnl >= 0 ? 'pos' : 'neg')} style={{ fontWeight: 600 }}>{signedMoney(pnl)}</td>
                                        <td className="mono" style={{ color: 'var(--tx-lo)' }}>{duration(r.opened_at_ms, r.closed_at_ms)}</td>
                                        <td style={{ color: 'var(--tx-mid)', fontSize: 12 }}>{REASON_LABEL[r.reason] || r.reason || '—'}</td>
                                        <td><span className="badge eval"><span className="d" />{STATUS_LABEL[r.status] || r.status}</span></td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
}

function Th({ info, children }) {
    return <th>{children}{info && <InfoTip g={info} />}</th>;
}
