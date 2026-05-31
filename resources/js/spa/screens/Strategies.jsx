/* NIFTY — Estrategias: dashboard consolidado del hub. Muestra el rendimiento
   unificado (P&L realizado/no realizado + equity), una tarjeta por instancia
   (trading + arbitraje cross-exchange) y el panel del AI Supervisor. Desde aquí
   se crean nuevas estrategias y se entra a su dashboard. */
import { useState } from 'react';
import { useNifty } from '../data/store';
import { I } from '../nifty/icons';
import { InfoTip } from '../nifty/InfoTip';
import { StrategyWizard } from '../nifty/StrategyWizard';
import { fmt, signedMoney, relativeTime } from '../nifty/format';

const TYPE_LABEL = { trading: 'Trading', cross_exchange: 'Cross-exchange' };

function liveMetrics(item, strategyLive) {
    return strategyLive?.[item.id] || item.metrics || null;
}

function StrategyCard({ item, live, onOpen, actions, busy }) {
    const isTrading = item.type === 'trading';
    const realized = live?.realized_pnl ?? item.realized_pnl ?? 0;
    const unrealized = Number(live?.unrealized_pnl) || 0;
    const equity = live?.equity_value ?? null;
    const winRate = live?.win_rate != null ? (live.win_rate * 100).toFixed(1) + '%' : '—';
    const openPos = live?.open_positions ?? 0;
    const active = item.active;
    const cb = live?.circuit_breaker;

    return (
        <div className="panel panel-pad" style={{ display: 'flex', flexDirection: 'column', gap: 8, borderLeft: '3px solid ' + (active ? 'var(--accent)' : 'var(--line-2)') }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                {isTrading ? <I.vol style={{ width: 16, height: 16, color: 'var(--accent)' }} /> : <I.opp style={{ width: 16, height: 16, color: 'var(--turq)' }} />}
                <button className="link-btn" style={{ fontWeight: 600, color: 'var(--tx-hi)', fontSize: 14, background: 'none', border: 0, cursor: 'pointer', textAlign: 'left' }} onClick={() => onOpen(item)}>
                    {item.name}
                </button>
                <span className={'badge ' + (active ? 'exec' : 'expired')} style={{ marginLeft: 'auto' }}><span className="d" />{active ? 'ACTIVA' : 'DETENIDA'}</span>
            </div>
            <div className="cfg-desc" style={{ margin: 0 }}>{TYPE_LABEL[item.type] || item.type}{item.algorithm ? ' · ' + item.algorithm.replace(/_/g, ' ') : ''}</div>

            {cb && <div className="alert err" style={{ margin: 0, padding: '4px 8px', fontSize: 11 }}><span className="ad" />Circuit breaker: {cb}</div>}

            {isTrading ? (
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2,1fr)', gap: 6 }}>
                    <div><div className="ml" style={{ fontSize: 11 }}>P&L realizado</div><div className={'mono ' + (realized >= 0 ? 'pos' : 'neg')} style={{ fontWeight: 600 }}>{signedMoney(realized)}</div></div>
                    <div><div className="ml" style={{ fontSize: 11 }}>P&L no real.</div><div className={'mono ' + (unrealized >= 0 ? 'pos' : 'neg')}>{signedMoney(unrealized)}</div></div>
                    <div><div className="ml" style={{ fontSize: 11 }}>Equity</div><div className="mono">{equity != null ? '$' + fmt(equity) : '—'}</div></div>
                    <div><div className="ml" style={{ fontSize: 11 }}>Win rate · abiertas</div><div className="mono">{winRate} · {openPos}</div></div>
                </div>
            ) : (
                <div className="cfg-desc" style={{ margin: 0 }}>
                    Detección de arbitraje multi-exchange (2 patas + ciclos triangulares). Abre el dashboard para ver
                    oportunidades, mercado, engine, wallets y rendimiento.
                </div>
            )}

            <div style={{ display: 'flex', gap: 6, marginTop: 'auto', flexWrap: 'wrap' }}>
                <button className="btn" onClick={() => onOpen(item)}>Abrir dashboard</button>
                {isTrading && (
                    <>
                        <button className={'btn ' + (active ? 'danger' : 'primary')} disabled={busy} onClick={() => actions.strategyAction(item.id, active ? 'stop' : 'start')}>
                            {active ? 'Detener' : 'Iniciar'}
                        </button>
                        <button className="btn" disabled={busy} onClick={() => actions.strategyAction(item.id, 'reset')} title="Reinicia billetera, posiciones y P&L">
                            <I.reset style={{ width: 13, height: 13 }} />
                        </button>
                    </>
                )}
            </div>
        </div>
    );
}

function PnlBars({ byStrategy }) {
    const rows = (byStrategy || []).filter((s) => s.type === 'trading' || s.realized_pnl !== 0);
    if (!rows.length) return <div className="empty-note">Aún no hay rendimiento registrado.</div>;
    const max = Math.max(1, ...rows.map((s) => Math.abs(s.realized_pnl || 0)));
    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
            {rows.map((s) => {
                const v = s.realized_pnl || 0;
                const w = (Math.abs(v) / max) * 100;
                return (
                    <div key={s.id} style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                        <span style={{ width: 160, fontSize: 12, color: 'var(--tx-mid)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{s.name}</span>
                        <div style={{ flex: 1, height: 10, background: 'var(--bg-1)', borderRadius: 5, position: 'relative' }}>
                            <div style={{ position: 'absolute', left: 0, top: 0, height: '100%', width: w + '%', background: v >= 0 ? 'var(--profit)' : 'var(--loss)', borderRadius: 5 }} />
                        </div>
                        <span className={'mono ' + (v >= 0 ? 'pos' : 'neg')} style={{ width: 90, textAlign: 'right' }}>{signedMoney(v)}</span>
                    </div>
                );
            })}
        </div>
    );
}

const SEVERITY_CLS = { info: 'eval', warning: 'expired', critical: 'reject' };

function AiSupervisorPanel({ onNav }) {
    const { aiRecs, actions } = useNifty();
    const summary = aiRecs?.latest_summary;
    const recs = aiRecs?.data || [];

    return (
        <div className="panel">
            <div className="panel-h">
                <I.autopilot style={{ width: 16, height: 16, color: 'var(--fuchsia)' }} />
                <h2>Agente<InfoTip g="agente_ia" /></h2>
                <div className="right" style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                    <span className="muted" style={{ fontSize: 12 }}>asesor + autónomo</span>
                    {onNav && <button className="btn" style={{ padding: '3px 10px', fontSize: 11 }} onClick={() => onNav('agent')}>Abrir agente</button>}
                </div>
            </div>
            <div className="panel-pad">
                {!summary && recs.length === 0 ? (
                    <div className="empty-note">El AI Supervisor aún no ha generado recomendaciones. Corre <span className="mono">strategies:supervise</span> o espera al cron.</div>
                ) : (
                    <>
                        {summary && (
                            <div style={{ marginBottom: 12 }}>
                                <div style={{ fontWeight: 600, color: 'var(--tx-hi)', marginBottom: 4 }}>Resumen de mercado</div>
                                <div className="cfg-desc" style={{ margin: 0 }}>{summary.summary}</div>
                                {summary.payload?.recommended_focus?.length > 0 && (
                                    <div style={{ marginTop: 6, fontSize: 12, color: 'var(--tx-mid)' }}>Foco sugerido: {summary.payload.recommended_focus.join(', ')}</div>
                                )}
                                {summary.payload?.avoid?.length > 0 && (
                                    <div style={{ fontSize: 12, color: 'var(--tx-lo)' }}>Evitar: {summary.payload.avoid.join(', ')}</div>
                                )}
                            </div>
                        )}
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
                            {recs.filter((r) => r.type !== 'market_summary').slice(0, 6).map((r) => (
                                <div key={r.id} style={{ display: 'flex', alignItems: 'flex-start', gap: 8, padding: '6px 0', borderTop: '1px solid var(--line-2)' }}>
                                    <span className={'badge ' + (SEVERITY_CLS[r.severity] || 'eval')}><span className="d" />{r.severity}</span>
                                    <div style={{ flex: 1, fontSize: 12, color: 'var(--tx-mid)' }}>{r.summary}</div>
                                    {r.status === 'active' && (
                                        <button className="btn" style={{ padding: '2px 8px', fontSize: 11 }} onClick={() => actions.updateAiRecommendation(r.id, 'dismissed')}>Descartar</button>
                                    )}
                                </div>
                            ))}
                        </div>
                    </>
                )}
            </div>
        </div>
    );
}

export default function StrategiesScreen({ onNav }) {
    const { strategies, strategyLive, busy, actions } = useNifty();
    const [wizard, setWizard] = useState(false);

    const data = strategies?.data || [];
    const cons = strategies?.consolidated || {};
    const enabled = !!strategies?.enabled;

    const openDetail = (item) => onNav('strat:' + item.id);

    return (
        <div className="content">
            <div className="panel panel-pad" style={{ display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
                <I.dash style={{ width: 18, height: 18, color: 'var(--accent)' }} />
                <div style={{ flex: 1, minWidth: 220 }}>
                    <div style={{ fontWeight: 600, color: 'var(--tx-hi)' }}>Estrategias<InfoTip g="estrategias_hub" /></div>
                    <div className="cfg-desc" style={{ margin: 0 }}>
                        Hub unificado: crea y monitorea estrategias de trading (long/short simulado) y de arbitraje
                        cross-exchange, con su rendimiento consolidado.
                    </div>
                </div>
                {!enabled && <span className="badge expired"><span className="d" />Módulo deshabilitado</span>}
                <button className="btn primary" onClick={() => setWizard(true)} disabled={!enabled}>
                    <I.bolt style={{ width: 14, height: 14 }} />Nueva estrategia
                </button>
            </div>
            <StrategyWizard open={wizard} onClose={() => setWizard(false)} />

            <div className="grid-3" style={{ gridTemplateColumns: 'repeat(4,1fr)' }}>
                <div className="panel mtile"><div className="ml">P&L realizado total<InfoTip g="pnl_realizado" /></div><div className={'mv ' + ((cons.total_realized_pnl || 0) >= 0 ? 'pos' : 'neg')}>{signedMoney(cons.total_realized_pnl || 0)}</div></div>
                <div className="panel mtile"><div className="ml">P&L no realizado<InfoTip g="pnl_no_realizado" /></div><div className={'mv ' + ((cons.total_unrealized_pnl || 0) >= 0 ? 'pos' : 'neg')}>{signedMoney(cons.total_unrealized_pnl || 0)}</div></div>
                <div className="panel mtile"><div className="ml">Equity de trading</div><div className="mv">${fmt(cons.total_equity || 0)}</div></div>
                <div className="panel mtile"><div className="ml">Posiciones abiertas</div><div className="mv">{cons.open_positions || 0}</div></div>
            </div>

            <div className="panel">
                <div className="panel-h">
                    <I.perf style={{ width: 16, height: 16, color: 'var(--accent)' }} />
                    <h2>Rendimiento por estrategia</h2>
                </div>
                <div className="panel-pad"><PnlBars byStrategy={cons.by_strategy} /></div>
            </div>

            <div className="panel">
                <div className="panel-h">
                    <I.dash style={{ width: 16, height: 16, color: 'var(--accent)' }} />
                    <h2>Mis estrategias</h2>
                    <div className="right muted" style={{ fontSize: 12 }}>{data.length} instancia(s)</div>
                </div>
                <div className="panel-pad">
                    {data.length === 0 ? (
                        <div className="empty-note">Sin estrategias todavía. Crea una con “Nueva estrategia”.</div>
                    ) : (
                        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(280px, 1fr))', gap: 12 }}>
                            {data.map((item) => (
                                <StrategyCard key={item.id} item={item} live={liveMetrics(item, strategyLive)} onOpen={openDetail} actions={actions} busy={busy} />
                            ))}
                        </div>
                    )}
                </div>
            </div>

            <AiSupervisorPanel onNav={onNav} />
        </div>
    );
}
