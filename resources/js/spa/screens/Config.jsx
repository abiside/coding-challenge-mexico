/* NIFTY — Configuración: reglas de operación, fees, riesgo y wallets (datos reales). */
import { useMemo, useState } from 'react';
import { useNifty } from '../data/store';
import { I } from '../nifty/icons';
import { NumField, Toggle } from '../nifty/widgets';
import { exLabel, exColor, deriveMarketRows } from '../nifty/format';

export default function ConfigScreen() {
    const { settings, options, market, wallets, actions } = useNifty();
    const [draft, setDraft] = useState(null);
    const [feeDraft, setFeeDraft] = useState(null);
    const [walletForm, setWalletForm] = useState({ exchange: '', asset: 'USDT', available: '' });
    const [msg, setMsg] = useState(null);
    const [saving, setSaving] = useState(false);

    const cfg = draft || settings;
    const connByEx = useMemo(() => {
        const map = {};
        deriveMarketRows(market).forEach((r) => { map[r.rawEx] = r.conn; });
        return map;
    }, [market]);

    if (!cfg) {
        return <div className="content"><div className="empty-note">Cargando configuración…</div></div>;
    }

    const fees = feeDraft || (cfg.fees || {});
    const set = (k, v) => setDraft({ ...cfg, [k]: v });
    const setFee = (ex, pct) => setFeeDraft({ ...fees, [ex]: pct === '' || Number.isNaN(pct) ? undefined : pct / 100 });

    const toggleSymbol = (symbol) => {
        const current = cfg.symbols || [];
        const next = current.includes(symbol) ? current.filter((s) => s !== symbol) : [...current, symbol];
        set('symbols', next);
    };

    const save = async () => {
        setSaving(true);
        setMsg(null);
        try {
            const payload = {
                symbols: cfg.symbols,
                min_net_profit: Number(cfg.min_net_profit),
                min_net_margin: Number(cfg.min_net_margin),
                min_base_volume: Number(cfg.min_base_volume),
                max_base_volume: Number(cfg.max_base_volume),
                freshness_ms: Number(cfg.freshness_ms),
                latency_max_ms: Number(cfg.latency_max_ms),
                circuit_breaker_enabled: !!cfg.circuit_breaker_enabled,
            };
            const cleanFees = {};
            Object.entries(fees).forEach(([ex, v]) => { if (v != null && !Number.isNaN(v)) cleanFees[ex] = v; });
            if (Object.keys(cleanFees).length) payload.fees = cleanFees;
            await actions.saveSettings(payload);
            setDraft(null);
            setFeeDraft(null);
            setMsg({ ok: true, t: 'Configuración guardada.' });
        } catch (err) {
            setMsg({ ok: false, t: err.message });
        } finally {
            setSaving(false);
        }
    };

    const discard = () => { setDraft(null); setFeeDraft(null); setMsg(null); };

    const addWallet = async (e) => {
        e.preventDefault();
        setMsg(null);
        try {
            await actions.addWallet({
                exchange: walletForm.exchange || options.exchanges[0],
                asset: walletForm.asset,
                available: Number(walletForm.available),
            });
            setWalletForm({ ...walletForm, available: '' });
        } catch (err) {
            setMsg({ ok: false, t: err.message });
        }
    };

    const dirty = draft != null || feeDraft != null;

    return (
        <div className="content">
            <div className="panel">
                <div className="panel-h"><I.cfg style={{ width: 16, height: 16, color: 'var(--turq)' }} /><h2>Exchanges y fees</h2><span className="sub">{(options.exchanges || []).length} exchanges</span></div>
                {(options.exchanges || []).map((ex) => {
                    const conn = connByEx[ex] || 'recon';
                    const pct = fees[ex] != null ? (fees[ex] * 100) : '';
                    return (
                        <div className="ex-cfg" key={ex}>
                            <span className="exc-name"><span className="ex-dot" style={{ background: exColor(ex), opacity: conn === 'ok' ? 1 : 0.4 }} />{exLabel(ex)}</span>
                            <div className="exc-meta">
                                <div className="exc-kv"><span className="k">Par</span><span className="v">{(cfg.symbols || ['BTC/USDT'])[0]}</span></div>
                                <div className="exc-kv"><span className="k">Feed</span><span className="v" style={{ color: conn === 'ok' ? 'var(--profit)' : 'var(--warn)' }}>{conn === 'ok' ? 'fresco' : conn === 'stale' ? 'atrasado' : 'sin datos'}</span></div>
                                <div style={{ width: 150 }}>
                                    <NumField label="Fee taker (%)" value={pct} unit="%" step={0.01} onChange={(v) => setFee(ex, v)} />
                                </div>
                            </div>
                        </div>
                    );
                })}
                <div className="muted" style={{ fontSize: 11.5, padding: '12px 16px' }}>Deja el fee vacío para usar el valor por defecto del backend por exchange.</div>
            </div>

            <div className="col-2b">
                <div className="panel panel-pad">
                    <div className="sec-title"><h3>Reglas de operación</h3><span className="ln" /></div>
                    <div className="cfg-grid" style={{ marginTop: 8 }}>
                        <NumField label="Volumen mínimo" value={cfg.min_base_volume} unit="BTC" step={0.0001} onChange={(v) => set('min_base_volume', v)} />
                        <NumField label="Volumen máximo" value={cfg.max_base_volume} unit="BTC" step={0.01} onChange={(v) => set('max_base_volume', v)} />
                        <NumField label="Profit neto mínimo" value={cfg.min_net_profit} unit="USDT" step={0.01} onChange={(v) => set('min_net_profit', v)} />
                        <NumField label="Margen neto mínimo" value={cfg.min_net_margin} unit="frac" step={0.0001} onChange={(v) => set('min_net_margin', v)} />
                        <NumField label="Edad máx. order book" value={cfg.freshness_ms} unit="ms" step={100} onChange={(v) => set('freshness_ms', v)} />
                        <NumField label="Latencia máxima" value={cfg.latency_max_ms} unit="ms" step={100} onChange={(v) => set('latency_max_ms', v)} />
                    </div>
                    <div style={{ marginTop: 16 }}>
                        <div className="nf-label" style={{ marginBottom: 8 }}>Símbolos a evaluar</div>
                        <div className="sym-grid">
                            {(options.symbols || []).map((s) => (
                                <span key={s} className={'sym-chip' + ((cfg.symbols || []).includes(s) ? ' on' : '')} onClick={() => toggleSymbol(s)}>{s}</span>
                            ))}
                        </div>
                    </div>
                </div>

                <div className="panel panel-pad">
                    <div className="sec-title"><h3>Risk manager</h3><span className="ln" /></div>
                    <div className="cfg-row">
                        <div className="cfg-info"><div className="cfg-name">Circuit breaker</div><div className="cfg-desc">Pausar el motor ante rachas de rechazos/errores</div></div>
                        <Toggle on={!!cfg.circuit_breaker_enabled} onChange={(v) => set('circuit_breaker_enabled', v)} />
                    </div>
                    <div className="cfg-row">
                        <div className="cfg-info"><div className="cfg-name">Autopilot</div><div className="cfg-desc">Optimización champion-challenger (en la pestaña Autopilot)</div></div>
                        <span className={'wstat ' + (settings.autopilot_enabled ? 'ok' : 'warn')}>● {settings.autopilot_enabled ? 'Activo' : 'Apagado'}</span>
                    </div>
                    <div className="cfg-row" style={{ borderBottom: 'none' }}>
                        <div className="cfg-info"><div className="cfg-name">Objetivo de optimización</div><div className="cfg-desc">Función objetivo del optimizador</div></div>
                        <span className="mono" style={{ color: 'var(--tx-hi)' }}>{settings.optimization_objective || 'net_pnl'}</span>
                    </div>
                </div>
            </div>

            <div className="panel panel-pad">
                <div className="sec-title"><h3>Wallets simuladas</h3><span className="ln" /><span className="tag">{wallets.length} saldos</span></div>
                <form onSubmit={addWallet} style={{ display: 'flex', gap: 10, alignItems: 'flex-end', flexWrap: 'wrap', marginTop: 10 }}>
                    <div style={{ minWidth: 150 }}>
                        <div className="nf-label" style={{ marginBottom: 6 }}>Exchange</div>
                        <select className="input" value={walletForm.exchange || (options.exchanges || [])[0] || ''} onChange={(e) => setWalletForm({ ...walletForm, exchange: e.target.value })}>
                            {(options.exchanges || []).map((ex) => <option key={ex} value={ex}>{exLabel(ex)}</option>)}
                        </select>
                    </div>
                    <div style={{ width: 110 }}>
                        <div className="nf-label" style={{ marginBottom: 6 }}>Asset</div>
                        <select className="input" value={walletForm.asset} onChange={(e) => setWalletForm({ ...walletForm, asset: e.target.value })}>
                            {(options.assets || ['USDT', 'BTC']).map((a) => <option key={a} value={a}>{a}</option>)}
                        </select>
                    </div>
                    <div style={{ width: 150 }}>
                        <NumField label="Monto" value={walletForm.available} step={0.0001} onChange={(v) => setWalletForm({ ...walletForm, available: Number.isNaN(v) ? '' : v })} />
                    </div>
                    <button className="btn primary" type="submit">Fondear</button>
                </form>
                <div style={{ marginTop: 16, overflowX: 'auto' }}>
                    <table className="tbl">
                        <thead><tr><th>Exchange</th><th>Asset</th><th>Disponible</th><th></th></tr></thead>
                        <tbody>
                            {wallets.length === 0 ? (
                                <tr><td colSpan="4" className="empty-note">Sin saldos todavía.</td></tr>
                            ) : wallets.map((w) => (
                                <tr key={w.id}>
                                    <td><span className="ex-name"><span className="ex-dot" style={{ background: exColor(w.exchange) }} />{exLabel(w.exchange)}</span></td>
                                    <td className="mono">{w.asset}</td>
                                    <td className="mono" style={{ color: 'var(--tx-hi)' }}>{Number(w.available).toLocaleString()}</td>
                                    <td><a style={{ color: 'var(--loss)', fontSize: 12, cursor: 'pointer' }} onClick={() => actions.removeWallet(w.id)}>Quitar</a></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            {msg && <div className={'alert ' + (msg.ok ? 'ok' : 'err')}><span className="ad" />{msg.t}</div>}

            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10 }}>
                <button className="btn" onClick={discard} disabled={!dirty || saving}>Descartar cambios</button>
                <button className="btn primary" onClick={save} disabled={!dirty || saving}>{saving ? 'Guardando…' : 'Guardar configuración'}</button>
            </div>
        </div>
    );
}
