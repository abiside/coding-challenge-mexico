/* NIFTY — Rendimiento (global): P&L y métricas de TODAS las estrategias.
   Cabecera consolidada (trading + arbitraje) y, debajo, el detalle analítico
   del arbitraje cross-exchange (derivado de trades + oportunidades). */
import { useEffect, useState } from 'react';
import { useNifty } from '../data/store';
import { I } from '../nifty/icons';
import { BigChart, BarChart, Columns, Donut, Segmented } from '../nifty/widgets';
import { InfoTip } from '../nifty/InfoTip';
import { derivePerf, deriveChartSeries, fmt, signedMoney } from '../nifty/format';

function HBars({ rows }) {
    const maxAbs = Math.max(...rows.map((r) => Math.abs(r.pnl)), 0) || 1;
    if (rows.length === 0) return <div className="empty-note">Sin datos todavía.</div>;
    return (
        <div>
            {rows.map((r, i) => {
                const pct = (Math.abs(r.pnl) / maxAbs) * 100;
                const pos = r.pnl >= 0;
                return (
                    <div className="hbar-row" key={i}>
                        <span className="hb-label">{r.label}</span>
                        <span className="hbar-track"><span className="hbar-fill" style={{ width: pct + '%', background: pos ? 'linear-gradient(90deg, rgba(47,227,182,0.5), var(--profit))' : 'linear-gradient(90deg, rgba(255,71,111,0.5), var(--loss))' }} /></span>
                        <span className={'hb-val ' + (pos ? 'pos' : 'neg')}>{pos ? '+' : '−'}${fmt(Math.abs(r.pnl))}</span>
                    </div>
                );
            })}
        </div>
    );
}

function FunnelRow({ label, value, max, color }) {
    return (
        <div className="hbar-row" style={{ gridTemplateColumns: '110px 1fr 60px' }}>
            <span className="hb-label">{label}</span>
            <span className="hbar-track" style={{ height: 14 }}><span className="hbar-fill" style={{ width: (max ? (value / max * 100) : 0) + '%', background: color, opacity: 0.8 }} /></span>
            <span className="hb-val mono" style={{ color: 'var(--tx-hi)' }}>{value.toLocaleString()}</span>
        </div>
    );
}

export default function PerfScreen() {
    const { trades, opportunities, strategies, transactions, actions } = useNifty();
    const [tf, setTf] = useState('week');
    const p = derivePerf(trades, opportunities);
    const chartSeries = deriveChartSeries(trades, tf);

    // Ledger global para win rate consolidado (todas las estrategias).
    useEffect(() => {
        actions.loadTransactions();
        const t = setInterval(() => actions.loadTransactions(), 15000);
        return () => clearInterval(t);
    }, [actions]);

    // Consolidado por estrategia. El arbitraje no acumula equity de trading, así
    // que su P&L realizado se toma del análisis de trades (p.pnlAcc).
    const cons = strategies?.consolidated;
    const byStrat = cons?.by_strategy || [];
    const tradingStrats = byStrat.filter((s) => s.type === 'trading');
    const tradingRealized = tradingStrats.reduce((a, s) => a + (Number(s.realized_pnl) || 0), 0);
    const tradingUnreal = tradingStrats.reduce((a, s) => a + (Number(s.unrealized_pnl) || 0), 0);
    const globalRealized = p.pnlAcc + tradingRealized;

    const pnlBars = byStrat.map((s) => ({
        label: s.name,
        pnl: s.type === 'cross_exchange' ? p.pnlAcc : (Number(s.realized_pnl) || 0),
    }));

    const txs = transactions?.data || [];
    const txWins = txs.filter((t) => (Number(t.net_pnl) || 0) > 0).length;
    const globalWin = txs.length ? Math.round((txWins / txs.length) * 100) : 0;

    const qmetrics = [
        { l: 'Win rate', v: p.winRate + '%', g: 'win_rate' },
        { l: 'Profit factor', v: p.profitFactor.toFixed(2), g: 'profit_factor' },
        { l: 'Profit neto prom.', v: '+$' + p.profitAvg.toFixed(2), g: 'profit_promedio' },
        { l: 'Pérdida prom.', v: '−$' + Math.abs(p.lossAvg).toFixed(2), g: 'perdida_promedio' },
        { l: 'Spread bruto prom.', v: '+' + p.avgGross.toFixed(2) + '%', g: 'spread_bruto' },
        { l: 'Spread neto prom.', v: (p.avgNet >= 0 ? '+' : '') + p.avgNet.toFixed(2) + '%', g: 'spread_neto' },
        { l: '% rechazos', v: p.rejectPct + '%', g: 'pct_rechazos' },
        { l: 'Razón principal', v: p.mainReject, small: true, g: 'razon_principal' },
    ];

    return (
        <div className="content">
            <div className="sec-title"><span className="tag">Consolidado · todas las estrategias</span><span className="ln" /></div>
            <div className="grid-3" style={{ gridTemplateColumns: 'repeat(4,1fr)' }}>
                <div className="panel mtile hud" style={{ background: 'linear-gradient(150deg, rgba(47,240,207,0.07), transparent 70%)' }}><div className="ml">P&L realizado global<InfoTip g="pnl_acumulado" /></div><div className={'mv ' + (globalRealized >= 0 ? 'pos' : 'neg')}>{signedMoney(globalRealized)}</div><div className="mvsub">arbitraje + trading</div></div>
                <div className="panel mtile"><div className="ml">P&L no realizado (trading)<InfoTip g="pnl_no_realizado" /></div><div className={'mv ' + (tradingUnreal >= 0 ? 'pos' : 'neg')}>{signedMoney(tradingUnreal)}</div><div className="mvsub">posiciones abiertas: {cons?.open_positions ?? 0}</div></div>
                <div className="panel mtile"><div className="ml">Win rate global<InfoTip g="win_rate" /></div><div className="mv">{globalWin}%</div><div className="mvsub">{txs.length} operaciones cerradas</div></div>
                <div className="panel mtile"><div className="ml">Estrategias<InfoTip g="trades_consolidado" /></div><div className="mv">{byStrat.length}</div><div className="mvsub">{tradingStrats.length} trading + arbitraje</div></div>
            </div>

            {pnlBars.length > 1 && (
                <div className="panel panel-pad">
                    <div className="sec-title"><h3>P&L por estrategia</h3><span className="ln" /></div>
                    <HBars rows={pnlBars} />
                </div>
            )}

            <div className="sec-title"><span className="tag">Detalle · Arbitraje cross-exchange</span><span className="ln" /></div>
            <div className="grid-3" style={{ gridTemplateColumns: 'repeat(4,1fr)' }}>
                <div className="panel mtile hud" style={{ background: 'linear-gradient(150deg, rgba(47,240,207,0.07), transparent 70%)' }}><div className="ml">P&L acumulado<InfoTip g="pnl_acumulado" /></div><div className={'mv ' + (p.pnlAcc >= 0 ? 'pos' : 'neg')}>{p.pnlAcc >= 0 ? '+' : '−'}${fmt(Math.abs(p.pnlAcc))}</div></div>
                <div className="panel mtile"><div className="ml">P&L del día<InfoTip g="pnl_dia" /></div><div className={'mv ' + (p.pnlDay >= 0 ? 'pos' : 'neg')}>{p.pnlDay >= 0 ? '+' : '−'}${fmt(Math.abs(p.pnlDay))}</div></div>
                <div className="panel mtile"><div className="ml">Mejor operación</div><div className="mv pos">+${p.best.toFixed(2)}</div></div>
                <div className="panel mtile"><div className="ml">Peor operación</div><div className="mv neg">−${Math.abs(p.worst).toFixed(2)}</div></div>
            </div>

            <div className="panel hud">
                <div className="panel-h">
                    <I.perf style={{ width: 16, height: 16, color: 'var(--turq)' }} />
                    <h2>P&L acumulado en el tiempo</h2>
                    <div className="right"><Segmented value={tf} onChange={setTf} options={[{ value: 'h24', label: '1h' }, { value: 'day', label: 'Día' }, { value: 'week', label: 'Semana' }]} /></div>
                </div>
                <div className="chart-wrap"><BigChart data={chartSeries.values} times={chartSeries.times} steps={chartSeries.steps} domain={chartSeries.domain} /></div>
            </div>

            <div className="col-2">
                <div className="panel panel-pad">
                    <div className="sec-title"><h3>Profit por operación</h3><span className="ln" /><span className="tag">últimas {p.profitPerOp.length}</span></div>
                    {p.profitPerOp.length ? <BarChart data={p.profitPerOp} h={150} /> : <div className="empty-note">Sin operaciones todavía.</div>}
                    <div className="muted" style={{ fontSize: 11.5, marginTop: 10 }}>Barras turquesa = profit neto positivo · rosa = negativo</div>
                </div>
                <div className="panel panel-pad" style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 18 }}>
                    <div className="sec-title" style={{ width: '100%' }}><h3>Resultado de operaciones</h3><span className="ln" /></div>
                    <Donut label={p.winRate + '%'} sub="Win rate" segments={[{ value: p.winRate, color: '#2fe3b6' }, { value: 100 - p.winRate, color: '#ff476f' }]} size={150} thickness={18} />
                    <div className="dist-legend" style={{ justifyContent: 'center' }}>
                        <span className="dist-item"><span className="sw" style={{ background: '#2fe3b6' }} />Positivas <span className="dv">{Math.round(p.executed * p.winRate / 100)}</span></span>
                        <span className="dist-item"><span className="sw" style={{ background: '#ff476f' }} />Negativas <span className="dv">{Math.round(p.executed * (100 - p.winRate) / 100)}</span></span>
                    </div>
                </div>
            </div>

            <div className="col-2">
                <div className="panel panel-pad">
                    <div className="sec-title"><h3>Volumen operado por hora</h3><span className="ln" /><span className="tag">24h</span></div>
                    <Columns data={p.hourlyVolume} h={100} />
                </div>
                <div className="panel panel-pad">
                    <div className="sec-title"><h3>Detectadas vs ejecutadas</h3><span className="ln" /></div>
                    <div style={{ display: 'flex', flexDirection: 'column', gap: 12, marginTop: 8 }}>
                        <FunnelRow label="Detectadas" value={p.detected} max={p.detected} color="#9aa3b2" />
                        <FunnelRow label="Ejecutadas" value={p.executed} max={p.detected || p.executed} color="#2fe3b6" />
                        <FunnelRow label="Rechazadas" value={p.rejected} max={p.detected || 1} color="#ff476f" />
                        <FunnelRow label="Ignoradas" value={p.expired} max={p.detected || 1} color="#7a8395" />
                    </div>
                </div>
            </div>

            <div className="col-2b">
                <div className="panel panel-pad">
                    <div className="sec-title"><h3>P&L por exchange (compra)</h3><span className="ln" /></div>
                    <HBars rows={p.byExchange.map((x) => ({ label: x.ex, pnl: x.pnl }))} />
                </div>
                <div className="panel panel-pad">
                    <div className="sec-title"><h3>P&L por dirección de arbitraje</h3><span className="ln" /></div>
                    <HBars rows={p.byDirection.map((x) => ({ label: x.route, pnl: x.pnl }))} />
                </div>
            </div>

            <div className="sec-title"><span className="tag">Métricas de calidad</span><span className="ln" /></div>
            <div className="grid-3" style={{ gridTemplateColumns: 'repeat(4,1fr)' }}>
                {qmetrics.map((q, i) => (
                    <div className="panel mtile" key={i}>
                        <div className="ml">{q.l}{q.g && <InfoTip g={q.g} />}</div>
                        <div className="mv" style={{ fontSize: q.small ? 15 : 24 }}>{q.v}</div>
                    </div>
                ))}
            </div>
        </div>
    );
}
