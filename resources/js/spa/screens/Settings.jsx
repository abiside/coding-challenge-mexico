/* NIFTY — Configuración (cuenta / general). Preferencias transversales a toda
   la cuenta, separadas de la configuración por-estrategia (reglas de arbitraje,
   TP/SL de cada trading, etc.). Hoy: simulación del engine, formato/zona de
   hora y reinicio de datos. */
import { useState } from 'react';
import { useNifty } from '../data/store';
import { I } from '../nifty/icons';
import { NumField, Toggle, Segmented } from '../nifty/widgets';
import { InfoTip } from '../nifty/InfoTip';
import { ResetProcessModal } from '../nifty/ResetModal';
import { formatClock, TIMEZONES } from '../nifty/prefs';

function SimulationCard() {
    const { settings, actions } = useNifty();
    const [working, setWorking] = useState(false);
    const [msg, setMsg] = useState(null);
    if (!settings) return null;

    const on = settings.simulation_enabled === true;
    const drift = settings.simulation_max_drift_pct ?? 0.5;

    const apply = async (patch) => {
        setWorking(true);
        setMsg(null);
        try {
            await actions.saveSettings({
                simulation_enabled: patch.simulation_enabled ?? on,
                simulation_max_drift_pct: patch.simulation_max_drift_pct ?? drift,
            });
            await actions.loadSettings();
            setMsg({ ok: true, t: 'Preferencia guardada.' });
        } catch (err) {
            setMsg({ ok: false, t: err.message });
        } finally {
            setWorking(false);
        }
    };

    return (
        <div className="panel panel-pad">
            <div className="sec-title"><h3>Simulación del engine</h3><span className="ln" /></div>
            <p className="cfg-desc">Genera oportunidades sintéticas derivando ligeramente los precios reales, útil para ver el motor operar cuando el mercado está plano. Afecta al engine de arbitraje cross-exchange.</p>

            <div className="cfg-row">
                <div className="cfg-info"><div className="cfg-name">Simulación de oportunidades<InfoTip g="modo_simulacion" /></div><div className="cfg-desc">Encender/apagar sin reiniciar el worker</div></div>
                <Toggle on={on} onChange={(v) => apply({ simulation_enabled: v })} />
            </div>

            <div className="cfg-grid" style={{ marginTop: 14, maxWidth: 280 }}>
                <NumField label="Deriva máxima" value={drift} unit="%" step={0.1}
                    onChange={(v) => !Number.isNaN(v) && apply({ simulation_max_drift_pct: v })} />
            </div>

            {working && <div className="muted" style={{ fontSize: 12, marginTop: 8 }}>Aplicando…</div>}
            {msg && <div className={'alert ' + (msg.ok ? 'ok' : 'err')} style={{ marginTop: 12 }}><span className="ad" />{msg.t}</div>}
        </div>
    );
}

function TimeCard() {
    const { prefs, actions } = useNifty();
    const now = new Date();

    return (
        <div className="panel panel-pad">
            <div className="sec-title"><h3>Formato y zona horaria</h3><span className="ln" /></div>
            <p className="cfg-desc">Cómo se muestran las horas en el reloj de la barra superior y en todas las tablas (señales, trades, posiciones).</p>

            <div className="cfg-row">
                <div className="cfg-info"><div className="cfg-name">Formato de hora</div><div className="cfg-desc">24 horas o 12 horas (AM/PM)</div></div>
                <Segmented value={prefs.timeFormat} onChange={(v) => actions.savePrefs({ timeFormat: v })}
                    options={[{ value: '24h', label: '24 h' }, { value: '12h', label: '12 h' }]} />
            </div>

            <div className="cfg-row" style={{ borderBottom: 'none', alignItems: 'flex-start' }}>
                <div className="cfg-info"><div className="cfg-name">Zona horaria</div><div className="cfg-desc">Por defecto usa la del navegador</div></div>
                <select className="input" style={{ maxWidth: 240 }} value={prefs.timezone} onChange={(e) => actions.savePrefs({ timezone: e.target.value })}>
                    {TIMEZONES.map(([tz, label]) => <option key={tz} value={tz}>{label}</option>)}
                </select>
            </div>

            <div className="mtile hud" style={{ marginTop: 14, padding: '12px 16px', borderRadius: 10 }}>
                <div className="ml">Vista previa</div>
                <div className="mv mono" style={{ fontSize: 22 }}>{formatClock(now, true)}</div>
            </div>
        </div>
    );
}

function DataCard() {
    const [open, setOpen] = useState(false);
    return (
        <div className="panel panel-pad">
            <div className="sec-title"><h3>Datos de la cuenta</h3><span className="ln" /></div>
            <p className="cfg-desc">Borra trades, posiciones, challengers del autopilot y vuelve los saldos simulados a su estado inicial. No se puede deshacer.</p>
            <button className="btn danger" onClick={() => setOpen(true)} style={{ marginTop: 4 }}>
                <I.reset style={{ width: 14, height: 14 }} />Reiniciar datos
            </button>
            <ResetProcessModal open={open} onClose={() => setOpen(false)} />
        </div>
    );
}

export default function SettingsScreen() {
    const { user } = useNifty();

    return (
        <div className="content">
            <div className="panel panel-pad" style={{ display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
                <span className="avatar-lg">{(user?.name || user?.email || '?').slice(0, 1).toUpperCase()}</span>
                <div style={{ flex: 1, minWidth: 200 }}>
                    <div style={{ fontWeight: 600, color: 'var(--tx-hi)' }}>{user?.name || 'Tu cuenta'}</div>
                    <div className="cfg-desc" style={{ margin: 0 }}>{user?.email || '—'}</div>
                </div>
                <span className="cfg-desc" style={{ margin: 0, fontSize: 11 }}>Preferencias generales · la configuración de cada estrategia vive en su dashboard</span>
            </div>

            <div className="col-2">
                <SimulationCard />
                <TimeCard />
            </div>

            <DataCard />
        </div>
    );
}
