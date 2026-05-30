/* NIFTY — Autopilot: champion-challenger con promoción (datos reales). */
import { useEffect, useState } from 'react';
import { api } from '../client';
import { I } from '../nifty/icons';
import { Toggle, MultiLineChart, lineColor } from '../nifty/widgets';
import { signedMoney } from '../nifty/format';

function badgeClass(status) {
    if (status === 'champion') return 'exec';
    if (status === 'challenger') return 'eval';
    return 'expired';
}
function badgeLabel(status) {
    if (status === 'champion') return 'CHAMPION';
    if (status === 'challenger') return 'CHALLENGER';
    return 'ARCHIVADA';
}

function StrategyCard({ strategy, championScore, onPromote, busy }) {
    const isChallenger = strategy.status === 'challenger';
    const edge = championScore != null ? Number(strategy.score) - Number(championScore) : null;
    const params = strategy.params || {};
    const cells = [
        ['Min profit', Number(params.min_net_profit ?? 0).toFixed(2)],
        ['Min margin', (Number(params.min_net_margin ?? 0) * 10000).toFixed(2) + ' bps'],
        ['Vol [min,max]', Number(params.min_base_volume ?? 0).toFixed(4) + ' – ' + Number(params.max_base_volume ?? 0).toFixed(2)],
        ['Freshness', Number(params.freshness_ms ?? 0).toFixed(0) + ' ms'],
        ['Latency', Number(params.latency_max_ms ?? 0).toFixed(0) + ' ms'],
        ['Ejecuciones', strategy.executions_total ?? 0],
        ['P&L acum.', (Number(strategy.realized_pnl_total ?? 0) >= 0 ? '+' : '') + Number(strategy.realized_pnl_total ?? 0).toFixed(2)],
    ];

    return (
        <div className="panel panel-pad" style={{ marginBottom: 12 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 12 }}>
                <div>
                    <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                        <span className={'badge ' + badgeClass(strategy.status)}><span className="d" />{badgeLabel(strategy.status)}</span>
                        <strong style={{ fontSize: 15 }}>{strategy.name}</strong>
                        <span className="muted" style={{ fontSize: 12 }}>gen {strategy.generation}</span>
                    </div>
                    {strategy.rationale && <p className="muted" style={{ marginTop: 8, fontSize: 13, lineHeight: 1.5 }}>{strategy.rationale}</p>}
                </div>
                <div style={{ textAlign: 'right' }}>
                    <div className="ml" style={{ marginBottom: 0 }}>Score</div>
                    <div className={'mono ' + (Number(strategy.score) >= 0 ? 'pos' : 'neg')} style={{ fontSize: 20, fontWeight: 700 }}>{Number(strategy.score).toFixed(2)}</div>
                    {edge != null && isChallenger && (
                        <div className={'mono ' + (edge >= 0 ? 'pos' : 'neg')} style={{ fontSize: 12 }}>{edge >= 0 ? '+' : ''}{edge.toFixed(2)} vs champ</div>
                    )}
                </div>
            </div>
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 18, marginTop: 14, paddingTop: 12, borderTop: '1px solid var(--line)' }}>
                {cells.map(([k, v]) => (
                    <div key={k} style={{ minWidth: 110 }}>
                        <div className="ml" style={{ marginBottom: 2 }}>{k}</div>
                        <div className="mono" style={{ fontSize: 13, fontWeight: 600 }}>{v}</div>
                    </div>
                ))}
            </div>
            {isChallenger && onPromote && (
                <div style={{ marginTop: 14, display: 'flex', justifyContent: 'flex-end' }}>
                    <button className="btn primary" onClick={() => onPromote(strategy.id)} disabled={busy}>Promover a champion</button>
                </div>
            )}
        </div>
    );
}

export default function AutopilotScreen() {
    const [data, setData] = useState({ data: [], events: [] });
    const [settings, setSettings] = useState(null);
    const [series, setSeries] = useState({ axis: [], series: [] });
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    const refresh = async () => {
        try {
            const [strategiesRes, settingsRes, seriesRes] = await Promise.all([
                api('/arbitrage/strategies'),
                api('/arbitrage/settings'),
                api('/arbitrage/strategies/series'),
            ]);
            setData(strategiesRes);
            setSettings(settingsRes.data);
            setSeries(seriesRes);
        } catch (err) {
            setError(err.message);
        }
    };

    useEffect(() => {
        refresh();
        const t = setInterval(refresh, 8000);
        return () => clearInterval(t);
    }, []);

    const patchAutopilot = async (patch) => {
        if (!settings) return;
        setBusy(true); setError(null);
        try {
            await api('/arbitrage/autopilot', { method: 'POST', body: { enabled: !!settings.autopilot_enabled, ...patch } });
            await refresh();
        } catch (err) { setError(err.message); } finally { setBusy(false); }
    };

    const toggleAutopilot = () => patchAutopilot({ enabled: !settings?.autopilot_enabled });
    const updateMaxChallengers = (value) => patchAutopilot({ max_challengers: value });
    const toggleAutoPromote = () => patchAutopilot({ auto_promote: !settings?.autopilot_auto_promote });
    const updateInterval = (value) => patchAutopilot({ interval_minutes: value });

    const promote = async (id) => {
        if (!confirm('¿Promover este challenger a champion? Cambiará tus settings activos.')) return;
        setBusy(true); setError(null);
        try {
            await api(`/arbitrage/strategies/${id}/promote`, { method: 'POST' });
            await refresh();
        } catch (err) { setError(err.message); } finally { setBusy(false); }
    };

    const champion = (data.data || []).find((s) => s.status === 'champion');
    const challengers = (data.data || []).filter((s) => s.status === 'challenger');
    const archived = (data.data || []).filter((s) => s.status === 'archived').slice(0, 5);

    const chartSeries = series.series || [];
    const hasSeries = chartSeries.some((s) => (s.points || []).length > 1);

    return (
        <div className="content">
            {error && <div className="alert err"><span className="ad" />{error}</div>}

            <div className="panel">
                <div className="panel-h">
                    <I.autopilot style={{ width: 16, height: 16, color: 'var(--fuchsia)' }} />
                    <h2>Configuración del autopilot</h2>
                    <div className="right">
                        <span className={'pill ' + (settings?.autopilot_enabled ? 'live' : 'demo')}><span className="dot" />{settings?.autopilot_enabled ? 'Activo' : 'Apagado'}</span>
                        <Toggle on={!!settings?.autopilot_enabled} onChange={toggleAutopilot} />
                    </div>
                </div>
                <div className="panel-pad">
                    <div className="grid-3">
                        <div><div className="ml">Objetivo</div><div className="mv" style={{ fontSize: 18 }}>{settings?.optimization_objective || 'net_pnl'}</div></div>
                        <div>
                            <div className="ml">Max challengers</div>
                            <select className="input" style={{ width: 'auto', marginTop: 4 }} value={settings?.autopilot_max_challengers ?? 2} onChange={(e) => updateMaxChallengers(Number(e.target.value))} disabled={busy || !settings}>
                                {[0, 1, 2, 3, 4, 5].map((n) => <option key={n} value={n}>{n}</option>)}
                            </select>
                        </div>
                        <div>
                            <div className="ml">Periodo de promoción</div>
                            <select className="input" style={{ width: 'auto', marginTop: 4 }} value={settings?.autopilot_interval_minutes ?? 10} onChange={(e) => updateInterval(Number(e.target.value))} disabled={busy || !settings}>
                                {[1, 2, 5, 10, 15, 30, 60, 120, 240].map((n) => <option key={n} value={n}>{n} min</option>)}
                            </select>
                        </div>
                    </div>
                    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12, marginTop: 16, paddingTop: 14, borderTop: '1px solid var(--line)' }}>
                        <div>
                            <div style={{ fontSize: 14, fontWeight: 600 }}>Lanzar nuevo champion automáticamente</div>
                            <div className="muted" style={{ fontSize: 12, marginTop: 2 }}>
                                {settings?.autopilot_auto_promote
                                    ? `El ganador se promueve solo, como máximo cada ${settings?.autopilot_interval_minutes ?? 10} min.`
                                    : 'El juez solo recomienda; tú decides cuándo promover con el botón manual.'}
                            </div>
                        </div>
                        <Toggle on={!!settings?.autopilot_auto_promote} onChange={toggleAutoPromote} />
                    </div>
                    <p className="muted" style={{ fontSize: 12, marginTop: 14, lineHeight: 1.5 }}>
                        El optimizador crea challengers shadow que operan en paralelo sobre la misma data de mercado sin tocar tu wallet real. El juez (LLM) evalúa champion vs challengers cada ciclo; la promoción automática respeta el periodo configurado.
                    </p>
                </div>
            </div>

            <div className="panel hud">
                <div className="panel-h">
                    <I.perf style={{ width: 16, height: 16, color: 'var(--turq)' }} />
                    <div><h2>Optimizado vs. base · P&L acumulado por estrategia</h2></div>
                    <div className="right">
                        <span className={'pill ' + (hasSeries ? 'live' : 'demo')} style={{ fontSize: '10px', padding: '4px 9px' }}><span className="dot" />{hasSeries ? 'comparando' : 'sin datos'}</span>
                    </div>
                </div>
                {hasSeries && (
                    <div className="chart-legend" style={{ flexWrap: 'wrap', gap: 14 }}>
                        {chartSeries.map((s, idx) => (
                            <span className="lg" key={s.id} style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
                                <span className="swatch" style={{ background: lineColor(s, idx), width: 14, height: 3, borderRadius: 2, display: 'inline-block' }} />
                                {s.status === 'champion' ? 'Champion (base)' : `Challenger g${s.generation}`}
                                <span className="num" style={{ color: (s.final || 0) >= 0 ? 'var(--profit)' : 'var(--loss)', marginLeft: 4 }}>{signedMoney(s.final || 0)}</span>
                            </span>
                        ))}
                    </div>
                )}
                <div className="chart-wrap"><MultiLineChart axis={series.axis} series={chartSeries} markers={series.markers} /></div>
                <div className="num" style={{ fontSize: '10px', color: 'var(--tx-faint)', padding: '0 16px 12px', textAlign: 'center' }}>
                    P&L realizado acumulado por ventana de evaluación · línea sólida = champion (config aplicada), punteadas = challengers shadow · línea amarilla = promoción de un nuevo champion
                </div>
            </div>

            <div className="sec-title"><h3>Champion</h3><span className="ln" /></div>
            {champion ? <StrategyCard strategy={champion} championScore={null} /> : <div className="empty-note">Aún no hay champion. Inicia la simulación.</div>}

            <div className="sec-title"><h3>Challengers ({challengers.length})</h3><span className="ln" /></div>
            {challengers.length === 0 ? (
                <div className="empty-note">{settings?.autopilot_enabled ? 'Esperando al optimizador… (se generan en el próximo ciclo)' : 'Enciende el autopilot para que el agente proponga challengers.'}</div>
            ) : challengers.map((s) => (
                <StrategyCard key={s.id} strategy={s} championScore={champion?.score} onPromote={promote} busy={busy} />
            ))}

            <div className="col-2">
                <div className="panel">
                    <div className="panel-h"><I.opp style={{ width: 16, height: 16, color: 'var(--turq)' }} /><h2>Log del agente</h2></div>
                    <div className="console">
                        {(data.events || []).length === 0 ? (
                            <div className="empty-note" style={{ padding: '14px 16px' }}>Sin actividad del autopilot todavía.</div>
                        ) : (data.events || []).map((ev) => (
                            <div key={ev.id} className={'logline ' + (ev.type?.includes('promotion') ? 'info' : (ev.level || 'info'))}>
                                <span className="lt">{ev.created_at ? new Date(ev.created_at).toTimeString().slice(0, 8) : '—'}</span>
                                <span className={'lvl ' + (ev.level || 'info')}>{ev.level || 'info'}</span>
                                <span className="lmsg"><b>{ev.type}</b> · strategy #{ev.strategy_id ?? '—'}</span>
                            </div>
                        ))}
                    </div>
                </div>
                <div className="panel panel-pad">
                    <div className="sec-title"><h3>Archivadas</h3><span className="ln" /></div>
                    {archived.length === 0 ? (
                        <div className="empty-note">Aún no se ha archivado ninguna.</div>
                    ) : archived.map((s) => (
                        <div key={s.id} style={{ padding: '8px 0', borderBottom: '1px solid var(--line-2)', display: 'flex', justifyContent: 'space-between', fontSize: 13 }}>
                            <span>{s.name}</span>
                            <span className="muted mono">score {Number(s.score).toFixed(2)}</span>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}
