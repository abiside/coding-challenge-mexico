/* NIFTY — Dashboard (portado del diseño, cableado a datos reales). */
import { useState, useEffect } from 'react';
import { api } from '../client';
import { useNifty } from '../data/store';
import { I } from '../nifty/icons';
import { Kpi, BigChart, OppRow, Lat, Segmented, CyclesPanel, Th, Toggle, MultiLineChart, lineColor } from '../nifty/widgets';
import { InfoTip } from '../nifty/InfoTip';
import { deriveKpis, deriveChartSeries, equityToChartSeries, deriveWinRateSpark, windowTotal, normalizeOpportunity, deriveMarketRows, deriveEfficiency, fmt, fmtLatency, signedMoney } from '../nifty/format';

/* Gráfica compacta de champion-challenger (autopilot) que aprovecha el hueco
   bajo la gráfica de P&L. Muestra el P&L acumulado por estrategia (igual que en
   Agente → Autónomo), con encendido/apagado del autopilot y acceso a su config. */
function ChallengersChart({ onNav }) {
    const [settings, setSettings] = useState(null);
    const [series, setSeries] = useState({ axis: [], series: [], markers: [] });
    const [busy, setBusy] = useState(false);

    const refresh = async () => {
        try {
            const [st, sr] = await Promise.all([api('/arbitrage/settings'), api('/arbitrage/strategies/series')]);
            setSettings(st.data);
            setSeries(sr);
        } catch { /* se reintenta en el siguiente ciclo */ }
    };
    useEffect(() => {
        refresh();
        const t = setInterval(refresh, 8000);
        return () => clearInterval(t);
    }, []);

    const on = !!settings?.autopilot_enabled;
    const toggle = async () => {
        if (!settings) return;
        setBusy(true);
        try {
            await api('/arbitrage/autopilot', { method: 'POST', body: { enabled: !on } });
            await refresh();
        } catch { /* el toggle reflejará el estado real al refrescar */ } finally {
            setBusy(false);
        }
    };

    const chartSeries = series.series || [];
    const hasSeries = chartSeries.some((s) => (s.points || []).length > 1);

    return (
        <div className="panel chal-panel">
            <div className="panel-h">
                <I.autopilot style={{ width: 15, height: 15, color: 'var(--fuchsia)' }} />
                <h2>Challengers · autopilot<InfoTip g="challenger" /></h2>
                <div className="right" style={{ display: 'flex', alignItems: 'center', gap: 9 }}>
                    <span className={'chal-state ' + (on ? 'on' : 'off')}>{on ? 'Activo' : 'Apagado'}</span>
                    <Toggle on={on} onChange={toggle} disabled={busy || !settings} />
                    <button className="btn icon" title="Configurar en Agente → Autónomo" onClick={() => onNav && onNav('agent-auto')}><I.cfg style={{ width: 15, height: 15 }} /></button>
                </div>
            </div>

            {hasSeries ? (
                <>
                    <div className="chart-wrap chal-chart"><MultiLineChart axis={series.axis} series={chartSeries} markers={series.markers} h={150} /></div>
                    <div className="chal-pills">
                        {chartSeries.map((s, idx) => {
                            const v = Number(s.final || 0);
                            return (
                                <span className="chal-pill" key={s.id} title={s.status === 'champion' ? 'Champion (config aplicada)' : `Challenger generación ${s.generation}`}>
                                    <span className="sw" style={{ background: lineColor(s, idx) }} />
                                    {s.status === 'champion' ? 'Champion' : 'g' + s.generation}
                                    <span className={'num ' + (v >= 0 ? 'pos' : 'neg')}>{signedMoney(v)}</span>
                                </span>
                            );
                        })}
                    </div>
                </>
            ) : (
                <div className="empty-note" style={{ padding: '24px 16px', fontSize: 12.5 }}>
                    {on ? 'Esperando challengers del optimizador… (se generan en el próximo ciclo)' : 'Autopilot apagado · enciéndelo para que el agente proponga y compare challengers shadow.'}
                </div>
            )}
        </div>
    );
}

export default function DashboardScreen({ onOpen, onNav }) {
    const { trades, opportunities, market, liveFeed, engine, engineLive, promotions, cycleFeed, cycles, cyclesSummary } = useNifty();
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

    // Métricas de eficiencia del motor: latencia de evaluación por oportunidad,
    // throughput, conversión y frescura del feed.
    const live = engineLive || engine.live || null;
    const eff = deriveEfficiency(opportunities, engine.metrics, live, rows);
    const effTiles = [
        { l: 'Evaluación media / oportunidad', v: fmtLatency(eff.avgEvalUs), g: 'tiempo_evaluacion', sub: eff.sampleSize ? `muestra de ${eff.sampleSize} opp` : 'sin datos' },
        { l: 'Evaluación p95', v: fmtLatency(eff.p95EvalUs), g: 'eval_p95', sub: 'el 95% se evalúa por debajo' },
        { l: 'Evaluación máx', v: fmtLatency(eff.maxEvalUs), g: 'eval_max', sub: 'peor caso observado' },
        { l: 'Latencia de datos (feed)', v: eff.avgFeedMs != null ? Math.round(eff.avgFeedMs) + ' ms' : '—', g: 'latencia_feed', sub: eff.maxFeedMs != null ? 'máx ' + Math.round(eff.maxFeedMs) + ' ms' : 'frescura del order book' },
        { l: 'Conversión detección → ejecución', v: eff.conversion != null ? eff.conversion.toFixed(1) + '%' : '—', g: 'conversion_ejecucion', sub: eff.detectedHour != null ? `${eff.executedHour ?? 0} / ${eff.detectedHour} en 1h` : 'oportunidades que se ejecutan' },
        { l: 'Tasa de aprobación', v: eff.approvalRate != null ? eff.approvalRate.toFixed(1) + '%' : '—', g: 'tasa_aprobacion', sub: 'execute vs reject del risk manager' },
        { l: 'Eficiencia de detección', v: eff.detectEff != null ? eff.detectEff.toFixed(2) + '%' : '—', g: 'eficiencia_deteccion', sub: eff.snapshots != null ? fmt(eff.snapshots, 0) + ' snapshots' : 'candidatos / snapshots' },
        { l: 'Margen neto medio', v: eff.avgNetMarginPct != null ? eff.avgNetMarginPct.toFixed(3) + '%' : '—', g: 'margen_neto_medio', sub: 'calidad del edge evaluado' },
    ];

    return (
        <div className="content">
            <div className="kpis">
                <Kpi hero label="P&L acumulado" icon={I.pnl} value={pnlValue} info="pnl_acumulado"
                    detail={k.pnl.detail} spark={chart} sparkColor="#2ff0cf" />
                <Kpi label="Ganancia neta del día" icon={I.day} value={k.day.value} info="pnl_dia"
                    detail={k.day.detail} sparkColor="#2fe3b6" />
                <Kpi label="Win rate" icon={I.target} value={k.winRate.value} info="win_rate"
                    detail={k.winRate.detail} spark={winSpark} sparkColor="#b964e0" />
                <Kpi label="Volumen simulado" icon={I.vol} value={k.volume.value} info="volumen_simulado"
                    detail={k.volume.detail} sparkColor="#ff39a8" />
            </div>

            <div className="grid-2">
                <div className="grid-col">
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

                    <ChallengersChart onNav={onNav} />
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

            <div className="panel">
                <div className="panel-h">
                    <I.engine style={{ width: 16, height: 16, color: 'var(--turq)' }} />
                    <h2>Eficiencia del motor</h2>
                    <div className="right">
                        <span className={'pill ' + (live ? 'live' : '')} style={{ fontSize: '10px', padding: '4px 9px' }}>
                            <span className="dot" />{live ? 'en vivo' : eff.sampleSize ? 'histórico' : 'sin datos'}
                        </span>
                    </div>
                </div>
                <div className="panel-pad" style={{ paddingTop: 8 }}>
                    <div className="grid-3" style={{ gridTemplateColumns: 'repeat(4, 1fr)' }}>
                        {effTiles.map((t, i) => (
                            <div key={i} className="mtile" style={{ padding: '10px 0' }}>
                                <div className="ml">{t.l}{t.g && <InfoTip g={t.g} />}</div>
                                <div className="mv" style={{ fontSize: 20 }}>{t.v}</div>
                                {t.sub && <div className="mvsub">{t.sub}</div>}
                            </div>
                        ))}
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
                                <th>Exchange</th>
                                <Th info="best_bid">Best Bid</Th><Th info="qty">Qty</Th>
                                <Th info="best_ask">Best Ask</Th><Th info="qty">Qty</Th>
                                <Th info="spread">Spread</Th><Th info="latencia">Latencia</Th><Th info="estado_feed">Estado</Th>
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
