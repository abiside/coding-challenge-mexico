/* NIFTY — Dashboard (portado del diseño, cableado a datos reales). */
import { useState, useEffect } from 'react';
import { api } from '../client';
import { useNifty } from '../data/store';
import { I } from '../nifty/icons';
import { Kpi, BigChart, OppRow, Lat, Segmented, CyclesPanel } from '../nifty/widgets';
import { deriveKpis, deriveChartSeries, equityToChartSeries, deriveWinRateSpark, windowTotal, normalizeOpportunity, deriveMarketRows, fmt, signedMoney } from '../nifty/format';

export default function DashboardScreen({ onOpen }) {
    const { trades, opportunities, market, liveFeed, engine, promotions, cycleFeed, cycles, cyclesSummary } = useNifty();
    const [tf, setTf] = useState('m15');

    // Curva de equity ESTABLE desde el servidor: evita que la historia se
    // reescriba al deslizarse el feed acotado de 200 trades. Se refresca al
    // cambiar el timeframe y en intervalo.
    const [equity, setEquity] = useState(null);
    useEffect(() => {
        let alive = true;
        const load = () => api(`/arbitrage/trades/equity?tf=${tf}`).then((r) => { if (alive) setEquity(r); }).catch(() => {});
        load();
        const id = setInterval(load, 6000);
        return () => { alive = false; clearInterval(id); };
    }, [tf]);

    const k = deriveKpis(trades);
    const chartSeries = equity ? equityToChartSeries(equity, promotions) : deriveChartSeries(trades, tf, promotions);
    const chart = chartSeries.values;
    const winSpark = deriveWinRateSpark(trades);
    const tfTotal = equity ? equity.window_total : windowTotal(trades, tf);
    const pnlValue = equity ? signedMoney(equity.total) : k.pnl.value;

    // Feed: eventos en vivo + relleno con histórico normalizado.
    const seen = new Set(liveFeed.map((o) => o.id));
    const history = (opportunities || []).map(normalizeOpportunity).filter((o) => !seen.has(o.id));
    const feed = [...liveFeed, ...history].slice(0, 8);
    const detectedHour = engine.metrics?.opportunities_last_hour ?? feed.length;

    const rows = deriveMarketRows(market);
    const connected = rows.filter((r) => r.conn === 'ok').length;

    return (
        <div className="content">
            <div className="kpis">
                <Kpi hero label="P&L acumulado" icon={I.pnl} value={pnlValue}
                    detail={k.pnl.detail} spark={chart} sparkColor="#2ff0cf" />
                <Kpi label="Ganancia neta del día" icon={I.day} value={k.day.value}
                    detail={k.day.detail} sparkColor="#2fe3b6" />
                <Kpi label="Win rate" icon={I.target} value={k.winRate.value}
                    detail={k.winRate.detail} spark={winSpark} sparkColor="#b964e0" />
                <Kpi label="Volumen simulado" icon={I.vol} value={k.volume.value}
                    detail={k.volume.detail} sparkColor="#ff39a8" />
            </div>

            <div className="grid-2">
                <div className="panel hud">
                    <div className="panel-h">
                        <I.perf style={{ width: 16, height: 16, color: 'var(--turq)' }} />
                        <div><h2>Rendimiento · P&L acumulado</h2></div>
                        <div className="right">
                            <Segmented value={tf} onChange={setTf} options={[{ value: 'm15', label: '15 min' }, { value: 'h1', label: '1 h' }, { value: 'h4', label: '4 h' }]} />
                        </div>
                    </div>
                    <div className="chart-legend">
                        <span className="lg"><span className="swatch" style={{ background: 'linear-gradient(90deg,#ff39a8,#2ff0cf)' }} />P&L neto simulado</span>
                        <span className="lg"><span className="swatch" style={{ background: 'repeating-linear-gradient(90deg,#f5b73d 0 4px,transparent 4px 7px)' }} />Nuevo champion</span>
                        <span className="lg" style={{ marginLeft: 'auto', color: tfTotal >= 0 ? 'var(--profit)' : 'var(--loss)' }}>
                            <span className="num">{signedMoney(tfTotal)}</span>
                        </span>
                    </div>
                    <div className="chart-wrap"><BigChart data={chart} times={chartSeries.times} steps={chartSeries.steps} domain={chartSeries.domain} markers={promotions} /></div>
                </div>

                <div className="panel">
                    <div className="panel-h">
                        <I.opp style={{ width: 16, height: 16, color: 'var(--fuchsia)' }} />
                        <h2>Oportunidades 2 patas (en vivo)</h2>
                        <div className="right">
                            <span className="pill live" style={{ fontSize: '10px', padding: '4px 9px' }}><span className="dot" />{detectedHour}/h</span>
                        </div>
                    </div>
                    <div className="opp-list">
                        {feed.length === 0 ? (
                            <div className="empty-note" style={{ padding: '20px' }}>Inicia la simulación para ver el flujo de oportunidades en tiempo real.</div>
                        ) : (
                            feed.map((o) => <OppRow key={o.id} o={o} onClick={() => onOpen(o)} />)
                        )}
                    </div>
                </div>
            </div>

            <CyclesPanel cycleFeed={cycleFeed} cycles={cycles} summary={cyclesSummary} limit={8} compact />


            <div className="panel">
                <div className="panel-h">
                    <I.market style={{ width: 16, height: 16, color: 'var(--turq)' }} />
                    <h2>Mercado en tiempo real</h2>
                    <span className="sub">{market.symbol || 'BTC/USDT'}</span>
                    <div className="right">
                        <span className={'conn ' + (connected ? 'ok' : 'recon')} style={{ fontSize: '10.5px' }}><span className="d" />{connected} conectados</span>
                    </div>
                </div>
                <div style={{ overflowX: 'auto' }}>
                    <table className="tbl">
                        <thead>
                            <tr>
                                <th>Exchange</th><th>Best Bid</th><th>Qty</th><th>Best Ask</th><th>Qty</th>
                                <th>Spread</th><th>Latencia</th><th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.length === 0 ? (
                                <tr><td colSpan="8" className="empty-note">Sin datos de mercado. Levanta <span className="mono">market:feed</span> para alimentar el order book.</td></tr>
                            ) : rows.map((r) => (
                                <tr key={r.ex}>
                                    <td><span className="ex-name"><span className="ex-dot" style={{ background: r.color }} />{r.ex}</span></td>
                                    <td className={'mono bid' + (r.bestBid ? ' best' : '')}>{r.bid != null ? '$' + fmt(r.bid) : '—'}</td>
                                    <td className="mono" style={{ color: 'var(--tx-mid)' }}>{r.bidQ != null ? r.bidQ.toFixed(2) : '—'}</td>
                                    <td className={'mono ask' + (r.bestAsk ? ' best' : '')}>{r.ask != null ? '$' + fmt(r.ask) : '—'}</td>
                                    <td className="mono" style={{ color: 'var(--tx-mid)' }}>{r.askQ != null ? r.askQ.toFixed(2) : '—'}</td>
                                    <td className="mono" style={{ color: 'var(--tx-hi)' }}>{r.spread != null ? '$' + r.spread.toFixed(2) : '—'}</td>
                                    <td><Lat ms={r.lat} /></td>
                                    <td>
                                        <span className={'conn ' + r.conn}>
                                            <span className="d" />
                                            {r.conn === 'ok' ? 'Datos frescos' : r.conn === 'stale' ? 'Atrasado' : 'Sin datos'}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
}
