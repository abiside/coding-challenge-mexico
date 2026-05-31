/* NIFTY — Onboarding: configuración inicial (estrategia + wallets) con design system Nifty. */
import { useEffect, useState } from 'react';
import { api } from '../client';
import { I } from '../nifty/icons';
import { BrandLogo } from '../nifty/BrandLogo';
import { NumField } from '../nifty/widgets';
import { exLabel, exColor } from '../nifty/format';

export default function Onboarding({ onDone, onLogout }) {
    const [options, setOptions] = useState({ exchanges: [], symbols: [], assets: [] });
    const [settings, setSettings] = useState(null);
    const [wallets, setWallets] = useState([]);
    const [walletForm, setWalletForm] = useState({ exchange: '', asset: 'USDT', available: '' });
    const [error, setError] = useState(null);
    const [saving, setSaving] = useState(false);
    const [demoLoading, setDemoLoading] = useState(false);

    const load = async () => {
        const [settingsRes, walletsRes] = await Promise.all([api('/arbitrage/settings'), api('/arbitrage/wallets')]);
        setOptions(settingsRes.options);
        setSettings(settingsRes.data);
        setWallets(walletsRes.data);
        setWalletForm((f) => ({ ...f, exchange: settingsRes.options.exchanges[0] || '' }));
    };

    useEffect(() => { load().catch((e) => setError(e.message)); }, []);

    if (!settings) {
        return <div className="loading-screen">{error ? <div className="form-error">{error}</div> : 'Cargando…'}</div>;
    }

    const setField = (key, value) => setSettings({ ...settings, [key]: value });
    const toggleSymbol = (symbol) => {
        const current = settings.symbols || [];
        setField('symbols', current.includes(symbol) ? current.filter((s) => s !== symbol) : [...current, symbol]);
    };

    const addWallet = async (e) => {
        e.preventDefault();
        setError(null);
        try {
            await api('/arbitrage/wallets', { method: 'POST', body: { exchange: walletForm.exchange, asset: walletForm.asset, available: Number(walletForm.available) } });
            setWalletForm({ ...walletForm, available: '' });
            setWallets((await api('/arbitrage/wallets')).data);
        } catch (err) { setError(err.message); }
    };

    const removeWallet = async (id) => {
        await api(`/arbitrage/wallets/${id}`, { method: 'DELETE' });
        setWallets((await api('/arbitrage/wallets')).data);
    };

    const startDemo = async () => {
        setError(null);
        setDemoLoading(true);
        try {
            await api('/arbitrage/onboarding/demo', { method: 'POST' });
            onDone();
        } catch (err) { setError(err.message); setDemoLoading(false); }
    };

    const finish = async () => {
        setError(null);
        if ((settings.symbols || []).length === 0) { setError('Selecciona al menos un símbolo.'); return; }
        if (wallets.length === 0) { setError('Fondea al menos una wallet.'); return; }
        setSaving(true);
        try {
            await api('/arbitrage/settings', {
                method: 'PUT',
                body: {
                    symbols: settings.symbols,
                    min_net_profit: Number(settings.min_net_profit),
                    min_net_margin: Number(settings.min_net_margin),
                    min_base_volume: Number(settings.min_base_volume),
                    max_base_volume: Number(settings.max_base_volume),
                    freshness_ms: Number(settings.freshness_ms),
                    latency_max_ms: Number(settings.latency_max_ms),
                    circuit_breaker_enabled: !!settings.circuit_breaker_enabled,
                    onboarded: true,
                },
            });
            onDone();
        } catch (err) { setError(err.message); } finally { setSaving(false); }
    };

    return (
        <div className="main" style={{ maxHeight: 'none' }}>
            <header className="hdr">
                <BrandLogo tagline="Configuración inicial" style={{ padding: 0 }} />
                <div className="hdr-stats">
                    <button className="btn" onClick={onLogout}><I.logout />Salir</button>
                </div>
            </header>

            <div className="content">
                {error && <div className="form-error">{error}</div>}

                <div className="panel panel-pad" style={{ marginBottom: 16, display: 'flex', alignItems: 'center', gap: 16, flexWrap: 'wrap', justifyContent: 'space-between' }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                        <div className="brand-mark" style={{ flex: 'none' }}><I.bolt style={{ width: 16, height: 16, color: '#0a0710' }} /></div>
                        <div>
                            <div style={{ fontWeight: 600, color: 'var(--tx-hi)' }}>¿Solo quieres ver el motor en acción?</div>
                            <div className="cfg-desc">Empieza con datos de ejemplo listos: estrategia, wallets con saldos en USDT, USD, BTC y ETH (arbitraje de 2 patas y triangular) y simulación activa.</div>
                        </div>
                    </div>
                    <button className="btn primary" onClick={startDemo} disabled={demoLoading}>
                        <I.bolt style={{ width: 14, height: 14 }} />{demoLoading ? 'Preparando…' : 'Empezar con datos de ejemplo'}
                    </button>
                </div>

                <div className="col-2b">
                    <div className="panel panel-pad">
                        <div className="sec-title"><h3>Estrategia</h3><span className="ln" /></div>
                        <div style={{ marginTop: 12 }}>
                            <div className="nf-label" style={{ marginBottom: 8 }}>Símbolos a evaluar</div>
                            <div className="sym-grid">
                                {options.symbols.map((symbol) => (
                                    <span key={symbol} className={'sym-chip' + ((settings.symbols || []).includes(symbol) ? ' on' : '')} onClick={() => toggleSymbol(symbol)}>{symbol}</span>
                                ))}
                            </div>
                        </div>
                        <div className="cfg-grid" style={{ marginTop: 18 }}>
                            <NumField label="Profit neto mínimo" value={settings.min_net_profit} unit="USDT" step={0.01} onChange={(v) => setField('min_net_profit', v)} />
                            <NumField label="Margen neto mínimo" value={settings.min_net_margin} unit="frac" step={0.0001} onChange={(v) => setField('min_net_margin', v)} />
                            <NumField label="Volumen mínimo" value={settings.min_base_volume} unit="BTC" step={0.0001} onChange={(v) => setField('min_base_volume', v)} />
                            <NumField label="Volumen máximo" value={settings.max_base_volume} unit="BTC" step={0.01} onChange={(v) => setField('max_base_volume', v)} />
                            <NumField label="Frescura máx." value={settings.freshness_ms} unit="ms" step={100} onChange={(v) => setField('freshness_ms', v)} />
                            <NumField label="Latencia máx." value={settings.latency_max_ms} unit="ms" step={100} onChange={(v) => setField('latency_max_ms', v)} />
                        </div>
                        <div className="cfg-row" style={{ marginTop: 10, borderBottom: 'none' }}>
                            <div className="cfg-info"><div className="cfg-name">Circuit breaker</div><div className="cfg-desc">Pausar el motor ante condiciones de riesgo</div></div>
                            <label style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                <input type="checkbox" checked={!!settings.circuit_breaker_enabled} onChange={(e) => setField('circuit_breaker_enabled', e.target.checked)} />
                            </label>
                        </div>
                    </div>

                    <div className="panel panel-pad">
                        <div className="sec-title"><h3>Wallets simuladas</h3><span className="ln" /></div>
                        <form onSubmit={addWallet} style={{ display: 'flex', gap: 10, alignItems: 'flex-end', flexWrap: 'wrap', marginTop: 12 }}>
                            <div style={{ minWidth: 140 }}>
                                <div className="nf-label" style={{ marginBottom: 6 }}>Exchange</div>
                                <select className="input" value={walletForm.exchange} onChange={(e) => setWalletForm({ ...walletForm, exchange: e.target.value })}>
                                    {options.exchanges.map((ex) => <option key={ex} value={ex}>{exLabel(ex)}</option>)}
                                </select>
                            </div>
                            <div style={{ width: 100 }}>
                                <div className="nf-label" style={{ marginBottom: 6 }}>Asset</div>
                                <select className="input" value={walletForm.asset} onChange={(e) => setWalletForm({ ...walletForm, asset: e.target.value })}>
                                    {options.assets.map((a) => <option key={a} value={a}>{a}</option>)}
                                </select>
                            </div>
                            <div style={{ width: 140 }}>
                                <NumField label="Monto" value={walletForm.available} step={0.0001} onChange={(v) => setWalletForm({ ...walletForm, available: Number.isNaN(v) ? '' : v })} />
                            </div>
                            <button className="btn primary" type="submit">Añadir</button>
                        </form>
                        <div style={{ marginTop: 16, overflowX: 'auto' }}>
                            {wallets.length === 0 ? (
                                <div className="empty-note">Sin saldos todavía.</div>
                            ) : (
                                <table className="tbl">
                                    <thead><tr><th>Exchange</th><th>Asset</th><th>Disponible</th><th></th></tr></thead>
                                    <tbody>
                                        {wallets.map((w) => (
                                            <tr key={w.id}>
                                                <td><span className="ex-name"><span className="ex-dot" style={{ background: exColor(w.exchange) }} />{exLabel(w.exchange)}</span></td>
                                                <td className="mono">{w.asset}</td>
                                                <td className="mono" style={{ color: 'var(--tx-hi)' }}>{Number(w.available).toLocaleString()}</td>
                                                <td><a style={{ color: 'var(--loss)', fontSize: 12, cursor: 'pointer' }} onClick={() => removeWallet(w.id)}>Quitar</a></td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            )}
                        </div>
                    </div>
                </div>

                <div style={{ display: 'flex', justifyContent: 'flex-end' }}>
                    <button className="btn primary" onClick={finish} disabled={saving}>{saving ? 'Guardando…' : 'Guardar y continuar'}</button>
                </div>
            </div>
        </div>
    );
}
