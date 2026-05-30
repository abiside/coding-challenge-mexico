/* NIFTY — Oportunidades: monitor en vivo + histórico con filtros.
   Conmuta entre arbitraje de 2 patas (cross-exchange) y triangular (ciclos). */
import { useState } from 'react';
import { useNifty } from '../data/store';
import { I } from '../nifty/icons';
import { normalizeOpportunity, fmt, signedMoney } from '../nifty/format';
import { Segmented, CyclesPanel } from '../nifty/widgets';

const FILTERS = [
    { v: 'all', l: 'Todas' }, { v: 'eval', l: 'En evaluación' },
    { v: 'exec', l: 'Ejecutadas' }, { v: 'reject', l: 'Rechazadas' }, { v: 'expired', l: 'Ignoradas' },
];

export default function OppsScreen({ onOpen }) {
    const { liveFeed, opportunities, engine, cycleFeed, cycles, cyclesSummary } = useNifty();
    const [filter, setFilter] = useState('all');
    const [mode, setMode] = useState('pairs');

    const seen = new Set(liveFeed.map((o) => o.id));
    const history = (opportunities || []).map(normalizeOpportunity).filter((o) => !seen.has(o.id));
    const all = [...liveFeed, ...history].slice(0, 80);

    const counts = { exec: 0, reject: 0, eval: 0, expired: 0 };
    all.forEach((o) => { counts[o.status] = (counts[o.status] || 0) + 1; });
    const shown = all.filter((o) => filter === 'all' || o.status === filter);
    const detectedHour = engine.metrics?.opportunities_last_hour ?? all.length;

    const labelFor = (s) => ({ eval: 'En evaluación', exec: 'Ejecutada', reject: 'Rechazada', expired: 'Ignorada' }[s]);

    return (
        <div className="content">
            <div className="panel panel-pad" style={{ display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
                <I.opp style={{ width: 16, height: 16, color: 'var(--fuchsia)' }} />
                <div style={{ flex: 1, minWidth: 200 }}>
                    <div style={{ fontWeight: 600, color: 'var(--tx-hi)' }}>Monitor de arbitraje</div>
                    <div className="cfg-desc" style={{ margin: 0 }}>Conmuta entre oportunidades de 2 patas (cross-exchange) y ciclos triangulares (intra/cross-exchange).</div>
                </div>
                <Segmented value={mode} onChange={setMode} options={[{ value: 'pairs', label: '2 patas' }, { value: 'triangular', label: 'Triangular' }]} />
            </div>

            {mode === 'triangular' ? (
                <>
                    <div className="grid-3" style={{ gridTemplateColumns: 'repeat(4,1fr)' }}>
                        <div className="panel mtile"><div className="ml">Ciclos totales</div><div className="mv">{cyclesSummary?.cycles_total ?? 0}</div></div>
                        <div className="panel mtile"><div className="ml">Ejecutados / hora</div><div className="mv pos">{cyclesSummary?.executed_last_hour ?? 0}</div></div>
                        <div className="panel mtile"><div className="ml">P&L realizado (1h)</div><div className={'mv ' + ((cyclesSummary?.realized_pnl_last_hour ?? 0) >= 0 ? 'pos' : 'neg')}>{signedMoney(cyclesSummary?.realized_pnl_last_hour ?? 0)}</div></div>
                        <div className="panel mtile"><div className="ml">P&L realizado (total)</div><div className={'mv ' + ((cyclesSummary?.realized_pnl ?? 0) >= 0 ? 'pos' : 'neg')}>{signedMoney(cyclesSummary?.realized_pnl ?? 0)}</div></div>
                    </div>
                    <CyclesPanel cycleFeed={cycleFeed} cycles={cycles} summary={cyclesSummary} limit={80} />
                    <div className="muted" style={{ fontSize: 12, paddingLeft: 4 }}>Los ciclos triangulares mueven la billetera dentro de un mismo exchange (USDT→BTC→ETH→USDT) y no generan filas en el flujo de 2 patas.</div>
                </>
            ) : (
            <>
            <div className="grid-3" style={{ gridTemplateColumns: 'repeat(4,1fr)' }}>
                <div className="panel mtile"><div className="ml">Detectadas / hora</div><div className="mv">{detectedHour}</div></div>
                <div className="panel mtile"><div className="ml">En evaluación</div><div className="mv" style={{ color: 'var(--warn)' }}>{counts.eval}</div></div>
                <div className="panel mtile"><div className="ml">Ejecutadas</div><div className="mv pos">{counts.exec}</div></div>
                <div className="panel mtile"><div className="ml">Rechazadas / ign.</div><div className="mv neg">{counts.reject + counts.expired}</div></div>
            </div>

            <div className="panel">
                <div className="panel-h">
                    <I.opp style={{ width: 16, height: 16, color: 'var(--fuchsia)' }} />
                    <h2>Monitor de oportunidades</h2>
                    <div className="right filters">
                        {FILTERS.map((f) => (
                            <span key={f.v} className={'chip' + (filter === f.v ? ' on' : '')} onClick={() => setFilter(f.v)}>{f.l}</span>
                        ))}
                    </div>
                </div>
                <div style={{ overflowX: 'auto' }}>
                    <table className="tbl">
                        <thead>
                            <tr>
                                <th>Hora</th><th>Comprar</th><th>Vender</th><th>P. compra</th><th>P. venta</th>
                                <th>Spread bruto</th><th>Spread neto</th><th>Volumen</th><th>Profit</th><th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            {shown.length === 0 ? (
                                <tr><td colSpan="10" className="empty-note">Sin oportunidades todavía. Inicia la simulación y el feed de mercado.</td></tr>
                            ) : shown.map((o) => {
                                const pv = o.profit === 0 ? '—' : (o.profit > 0 ? '+' : '−') + '$' + Math.abs(o.profit).toFixed(2);
                                const pcls = o.profit > 0 ? 'pos' : o.profit < 0 ? 'neg' : '';
                                return (
                                    <tr key={o.id} onClick={() => onOpen(o)} style={{ cursor: 'pointer' }}>
                                        <td className="mono" style={{ color: 'var(--tx-lo)' }}>{o.time}</td>
                                        <td style={{ fontWeight: 600 }}>{o.buy}</td>
                                        <td style={{ fontWeight: 600 }}>{o.sell}</td>
                                        <td className="mono" style={{ color: 'var(--tx-mid)' }}>${fmt(o.buyPrice)}</td>
                                        <td className="mono" style={{ color: 'var(--tx-mid)' }}>${fmt(o.sellPrice)}</td>
                                        <td className="mono" style={{ color: 'var(--tx-hi)' }}>+{o.grossPct.toFixed(2)}%</td>
                                        <td className={'mono ' + (o.netPct >= 0 ? 'pos' : 'neg')}>{o.netPct >= 0 ? '+' : ''}{o.netPct.toFixed(2)}%</td>
                                        <td className="mono" style={{ color: 'var(--tx-mid)' }}>{o.vol} BTC</td>
                                        <td className={'mono ' + pcls}>{pv}</td>
                                        <td><span className={'badge ' + o.status}><span className="d" />{labelFor(o.status)}</span></td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            </div>
            <div className="muted" style={{ fontSize: 12, paddingLeft: 4 }}>Clic en cualquier fila para ver el desglose de cálculo, liquidez usada y decisión del risk manager.</div>
            </>
            )}
        </div>
    );
}
