/* NIFTY — wizard de creación de estrategias de trading (long/short simulado).
   El arbitraje cross-exchange ya existe por defecto, así que el wizard se centra
   en crear instancias de trading: elegir algoritmo y configurar sus parámetros. */
import { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { api } from '../client';
import { useNifty } from '../data/store';
import { I } from './icons';
import { NumField } from './widgets';

const SIDE_BADGE = {
    long: { cls: 'exec', label: 'LONG' },
    short: { cls: 'reject', label: 'SHORT' },
    both: { cls: 'eval', label: 'LONG/SHORT' },
};

const PARAM_FIELDS = [
    ['slice_usdt', 'Tamaño por posición', 'USDT', 10, 'slice_usdt'],
    ['take_profit_pct', 'Take-profit', '%', 0.1, 'take_profit_pct'],
    ['stop_loss_pct', 'Stop-loss', '%', 0.1, 'stop_loss_pct'],
    ['max_open_positions', 'Máx. posiciones', '', 1, 'max_open_positions'],
    ['leverage', 'Apalancamiento (short)', 'x', 0.5, 'leverage'],
    ['min_confidence', 'Confianza mínima', 'frac', 0.05, 'min_confidence'],
];

export function StrategyWizard({ open, onClose }) {
    const { actions } = useNifty();
    const [catalog, setCatalog] = useState(null);
    const [algorithm, setAlgorithm] = useState(null);
    const [name, setName] = useState('');
    const [initialUsdt, setInitialUsdt] = useState(10000);
    const [cfg, setCfg] = useState({});
    const [working, setWorking] = useState(false);
    const [err, setErr] = useState(null);

    useEffect(() => {
        if (!open) return;
        setAlgorithm(null); setName(''); setErr(null);
        api('/strategies/catalog').then((res) => {
            setCatalog(res);
            setInitialUsdt(res.initial_usdt || 10000);
            setCfg(res.defaults || {});
        }).catch((e) => setErr(e.message));
    }, [open]);

    if (!open) return null;

    const pick = (algo) => {
        setAlgorithm(algo);
        const meta = (catalog?.algorithms || []).find((a) => a.algorithm === algo);
        if (meta && !name) setName(meta.name);
    };

    const setParam = (key, v) => setCfg((c) => ({ ...c, [key]: Number.isNaN(v) ? '' : v }));

    const create = async () => {
        setWorking(true);
        setErr(null);
        try {
            await actions.createStrategy({
                name: name || 'Estrategia de trading',
                type: 'trading',
                algorithm,
                initial_usdt: Number(initialUsdt),
                config: cfg,
            });
            onClose(true);
        } catch (e) {
            setErr(e.message || 'No se pudo crear la estrategia.');
        } finally {
            setWorking(false);
        }
    };

    return createPortal(
        <div className="modal-overlay" onMouseDown={(e) => { if (e.target === e.currentTarget && !working) onClose(false); }}>
            <div className="modal-card" role="dialog" aria-modal="true" aria-label="Nueva estrategia" style={{ maxWidth: 640, width: '92vw' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 4 }}>
                    <div className="brand-mark" style={{ flex: 'none' }}><I.bolt style={{ width: 16, height: 16, color: '#0a0710' }} /></div>
                    <h3 className="modal-title" style={{ margin: 0 }}>Nueva estrategia de trading</h3>
                    <button className="btn" style={{ marginLeft: 'auto' }} onClick={() => onClose(false)} disabled={working}><I.x style={{ width: 14, height: 14 }} /></button>
                </div>
                <p className="modal-text" style={{ marginTop: 0 }}>
                    Elige un algoritmo. Opera una billetera simulada propia (USDT) sobre monedas volátiles de
                    Binance, con salidas obligatorias de take-profit / stop-loss / timeout.
                </p>

                {err && <div className="alert err" style={{ marginBottom: 8 }}><span className="ad" />{err}</div>}

                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8, maxHeight: 220, overflowY: 'auto', marginBottom: 12 }}>
                    {(catalog?.algorithms || []).map((a) => {
                        const badge = SIDE_BADGE[a.side] || SIDE_BADGE.both;
                        const on = algorithm === a.algorithm;
                        return (
                            <button key={a.algorithm} type="button" onClick={() => pick(a.algorithm)}
                                className="panel panel-pad" style={{ textAlign: 'left', cursor: 'pointer', borderColor: on ? 'var(--accent)' : undefined, borderLeft: on ? '3px solid var(--accent)' : '3px solid transparent' }}>
                                <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                                    <span style={{ fontWeight: 600, color: 'var(--tx-hi)', fontSize: 13 }}>{a.name}</span>
                                    <span className={'badge ' + badge.cls} style={{ marginLeft: 'auto' }}><span className="d" />{badge.label}</span>
                                </div>
                                <div className="cfg-desc" style={{ margin: '4px 0 0', fontSize: 11 }}>{a.description}</div>
                            </button>
                        );
                    })}
                </div>

                {algorithm && (
                    <>
                        <div className="cfg-grid" style={{ marginBottom: 14 }}>
                            <label className="numfield">
                                <span className="nf-label">Nombre</span>
                                <span className="nf-input"><input value={name} onChange={(e) => setName(e.target.value)} /></span>
                            </label>
                            <NumField label="Capital inicial" unit="USDT" step={100}
                                value={initialUsdt} onChange={(v) => setInitialUsdt(Number.isNaN(v) ? '' : v)} />
                        </div>
                        <div className="cfg-grid cols-3">
                            {PARAM_FIELDS.map(([key, label, unit, step, info]) => (
                                <NumField key={key} label={label} info={info} unit={unit} step={step}
                                    value={cfg[key] ?? ''} onChange={(v) => setParam(key, v)} />
                            ))}
                        </div>
                    </>
                )}

                <div className="modal-actions" style={{ marginTop: 14 }}>
                    <button className="btn" onClick={() => onClose(false)} disabled={working}>Cancelar</button>
                    <button className="btn primary" onClick={create} disabled={working || !algorithm}>
                        {working ? 'Creando…' : 'Crear estrategia'}
                    </button>
                </div>
            </div>
        </div>,
        document.body,
    );
}
