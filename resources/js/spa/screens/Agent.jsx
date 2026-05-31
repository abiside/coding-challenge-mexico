/* NIFTY — Agente: "un agente por encima de tus estrategias". Unifica en una sola
   superficie las dos caras del mismo concepto:
     • Asesor (AI Supervisor): observa TODAS las estrategias y recomienda (resumen
       de mercado, focos, alertas, ajustes de parámetros). No ejecuta solo.
     • Autónomo (Autopilot): la capacidad EJECUTORA del agente. Hoy opera el
       arbitraje cross-exchange con champion-challenger (promueve campeones). Para
       trading, auto-aplicar sugerencias llegará como opt-in por estrategia.
   Las sugerencias del asesor se pueden aplicar con un clic (acción del usuario). */
import { useState } from 'react';
import { useNifty } from '../data/store';
import { I } from '../nifty/icons';
import { InfoTip } from '../nifty/InfoTip';
import AutopilotScreen from './Autopilot';

const SEVERITY_CLS = { info: 'eval', warning: 'expired', critical: 'reject' };

function Chips({ items, tone }) {
    if (!items || items.length === 0) return <span className="muted" style={{ fontSize: 12 }}>—</span>;
    return (
        <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
            {items.map((x) => (
                <span key={x} className="sym-chip" style={tone === 'avoid' ? { borderColor: 'var(--loss)', color: 'var(--loss)' } : undefined}>
                    {String(x).replace(/_/g, ' ')}
                </span>
            ))}
        </div>
    );
}

function AdvisorView() {
    const { aiRecs, actions } = useNifty();
    const [busyId, setBusyId] = useState(null);
    const summary = aiRecs?.latest_summary;
    const payload = summary?.payload || {};
    const recs = aiRecs?.data || [];
    const alerts = recs.filter((r) => r.type === 'alert');
    const suggestions = recs.filter((r) => r.type === 'parameter_suggestion' && r.status === 'active');

    const apply = async (rec) => {
        const p = rec.payload || {};
        if (!p.strategy_id || !p.param) return;
        setBusyId(rec.id);
        try {
            await actions.strategyConfig(p.strategy_id, { config: { [p.param]: p.suggested } });
            await actions.updateAiRecommendation(rec.id, 'applied');
        } catch { /* el error se refleja vía store */ } finally {
            setBusyId(null);
        }
    };

    return (
        <>
            <div className="panel">
                <div className="panel-h">
                    <I.autopilot style={{ width: 16, height: 16, color: 'var(--fuchsia)' }} />
                    <h2>Resumen del agente<InfoTip g="ai_supervisor" /></h2>
                    <div className="right muted" style={{ fontSize: 12 }}>modo asesor · no ejecuta solo</div>
                </div>
                <div className="panel-pad">
                    {!summary ? (
                        <div className="empty-note">El agente aún no ha generado un análisis. Corre <span className="mono">strategies:supervise</span> o espera al cron.</div>
                    ) : (
                        <>
                            <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 8, flexWrap: 'wrap' }}>
                                <span className="badge eval"><span className="d" />Régimen: {payload.market_regime || 'desconocido'}</span>
                                <span className="muted" style={{ fontSize: 11.5 }}>fuente: {summary.source === 'llm' ? 'LLM' : 'determinista'}</span>
                            </div>
                            <div style={{ color: 'var(--tx-mid)', fontSize: 13, lineHeight: 1.6 }}>{summary.summary}</div>
                            <div className="cfg-grid" style={{ marginTop: 14 }}>
                                <div>
                                    <div className="nf-label" style={{ marginBottom: 6, color: 'var(--profit)' }}>Foco recomendado</div>
                                    <Chips items={payload.recommended_focus} />
                                </div>
                                <div>
                                    <div className="nf-label" style={{ marginBottom: 6, color: 'var(--loss)' }}>Evitar</div>
                                    <Chips items={payload.avoid} tone="avoid" />
                                </div>
                            </div>
                        </>
                    )}
                </div>
            </div>

            <div className="panel">
                <div className="panel-h">
                    <I.bolt style={{ width: 16, height: 16, color: 'var(--warn)' }} />
                    <h2>Alertas</h2>
                    <div className="right muted" style={{ fontSize: 12 }}>{alerts.length}</div>
                </div>
                <div className="panel-pad">
                    {alerts.length === 0 ? (
                        <div className="empty-note">Sin alertas activas.</div>
                    ) : (
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
                            {alerts.slice(0, 12).map((r) => (
                                <div key={r.id} style={{ display: 'flex', alignItems: 'flex-start', gap: 8, padding: '8px 0', borderTop: '1px solid var(--line-2)' }}>
                                    <span className={'badge ' + (SEVERITY_CLS[r.severity] || 'eval')}><span className="d" />{r.severity}</span>
                                    <div style={{ flex: 1, fontSize: 12.5, color: 'var(--tx-mid)' }}>{r.summary}</div>
                                    {r.status === 'active' && (
                                        <button className="btn" style={{ padding: '2px 8px', fontSize: 11 }} onClick={() => actions.updateAiRecommendation(r.id, 'dismissed')}>Descartar</button>
                                    )}
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </div>

            <div className="panel">
                <div className="panel-h">
                    <I.cfg style={{ width: 16, height: 16, color: 'var(--turq)' }} />
                    <h2>Ajustes sugeridos<InfoTip g="ai_supervisor" /></h2>
                    <div className="right muted" style={{ fontSize: 12 }}>aplican con un clic</div>
                </div>
                <div style={{ overflowX: 'auto' }}>
                    <table className="tbl">
                        <thead>
                            <tr><th>Estrategia</th><th>Parámetro</th><th>Actual</th><th>Sugerido</th><th>Razón</th><th></th></tr>
                        </thead>
                        <tbody>
                            {suggestions.length === 0 ? (
                                <tr><td colSpan="6" className="empty-note">Sin ajustes sugeridos por ahora.</td></tr>
                            ) : suggestions.map((r) => {
                                const p = r.payload || {};
                                return (
                                    <tr key={r.id}>
                                        <td style={{ fontWeight: 600 }}>{p.strategy || '—'}</td>
                                        <td className="mono">{(p.param || '').replace(/_/g, ' ')}</td>
                                        <td className="mono" style={{ color: 'var(--tx-lo)' }}>{p.current ?? '—'}</td>
                                        <td className="mono" style={{ color: 'var(--turq)' }}>{p.suggested ?? '—'}</td>
                                        <td style={{ color: 'var(--tx-mid)', fontSize: 12 }}>{p.reason || '—'}</td>
                                        <td style={{ whiteSpace: 'nowrap' }}>
                                            <button className="btn primary" style={{ padding: '3px 10px', fontSize: 11 }} disabled={busyId === r.id || !p.strategy_id} onClick={() => apply(r)}>
                                                {busyId === r.id ? '…' : 'Aplicar'}
                                            </button>
                                            <button className="btn" style={{ padding: '3px 10px', fontSize: 11, marginLeft: 6 }} onClick={() => actions.updateAiRecommendation(r.id, 'dismissed')}>Descartar</button>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}

const TABS = [
    ['advisor', 'Asesor', 'autopilot'],
    ['autonomous', 'Autónomo (Autopilot)', 'bolt'],
];

export default function AgentScreen() {
    const [tab, setTab] = useState('advisor');

    return (
        <div className="content">
            <div className="panel panel-pad" style={{ display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
                <I.autopilot style={{ width: 18, height: 18, color: 'var(--fuchsia)' }} />
                <div style={{ flex: 1, minWidth: 240 }}>
                    <div style={{ fontWeight: 600, color: 'var(--tx-hi)' }}>Agente sobre tus estrategias<InfoTip g="agente_ia" /></div>
                    <div className="cfg-desc">
                        Un agente que vigila todas tus estrategias. En <strong>modo asesor</strong> recomienda focos, alertas y
                        ajustes (tú decides). En <strong>modo autónomo</strong> opera por ti: hoy corre el champion-challenger del
                        arbitraje; para trading, auto-aplicar sugerencias llegará como opción opt-in por estrategia.
                    </div>
                </div>
            </div>

            <div className="panel" style={{ padding: 0 }}>
                <div className="filters" style={{ padding: '10px 16px', gap: 6 }}>
                    {TABS.map(([key, label, icon]) => {
                        const Icon = I[icon];
                        return (
                            <span key={key} className={'chip' + (tab === key ? ' on' : '')} onClick={() => setTab(key)} style={{ display: 'inline-flex', alignItems: 'center', gap: 5 }}>
                                <Icon style={{ width: 13, height: 13 }} />{label}
                            </span>
                        );
                    })}
                </div>
            </div>

            {tab === 'advisor' ? <AdvisorView /> : <AutopilotScreen />}
        </div>
    );
}
