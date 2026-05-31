/* NIFTY — Trading: una sola sección que SIEMPRE se titula "Trading". No hay
   panel general: al entrar se muestra directo el dashboard de la primera
   estrategia y el usuario cambia de estrategia con el selector del header
   (dropdown + flechas). Si no existe ninguna, se muestra un empty-state grande
   con el CTA para crear la primera. */
import { useNifty } from '../data/store';
import { I } from '../nifty/icons';
import { TradingDetail } from './StrategyDetail';

/* Selector de estrategia que vive en la barra de título (header). Lista solo
   estrategias de trading; el punto de estado indica si la seleccionada está en
   ejecución o pausada. Cambia la selección sin navegar de ruta. */
export function TradingHeaderNav({ instances, value, onSelect }) {
    const idx = Math.max(0, instances.findIndex((s) => s.id === value));
    const single = instances.length < 2;
    const cur = instances[idx];
    const running = !!cur?.active;
    const go = (delta) => {
        const next = instances[(idx + delta + instances.length) % instances.length];
        if (next) onSelect(next.id);
    };

    return (
        <div className="trade-switch">
            <button className="btn icon" onClick={() => go(-1)} disabled={single} aria-label="Anterior" title="Estrategia anterior"><I.chevL style={{ width: 16, height: 16 }} /></button>
            <div className="trade-select" title={running ? 'Estrategia en ejecución' : 'Estrategia pausada'}>
                <span className={'st-dot ' + (running ? 'on' : 'off')} />
                <select value={value ?? ''} onChange={(e) => onSelect(Number(e.target.value))} aria-label="Elegir estrategia de trading">
                    {instances.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                </select>
                <I.chevD style={{ width: 15, height: 15, color: 'var(--tx-lo)' }} />
            </div>
            <button className="btn icon" onClick={() => go(1)} disabled={single} aria-label="Siguiente" title="Estrategia siguiente"><I.chevR style={{ width: 16, height: 16 }} /></button>
            <span className="muted" style={{ fontSize: 12, marginLeft: 2 }}>{idx + 1} / {instances.length}</span>
        </div>
    );
}

function EmptyTrading({ enabled, onNew }) {
    return (
        <div className="content">
            <div className="empty-hero">
                <span className="empty-hero-ico"><I.vol style={{ width: 30, height: 30 }} /></span>
                <h2>Aún no tienes estrategias de trading</h2>
                <p>Crea tu primera estrategia long/short simulada sobre monedas volátiles (USDT). Podrás iniciarla, pausarla y seguir su dashboard en vivo desde aquí.</p>
                <button className="btn primary btn-lg" onClick={onNew} disabled={!enabled}>
                    <I.bolt style={{ width: 16, height: 16 }} />Crear estrategia
                </button>
                {!enabled && <div className="muted" style={{ marginTop: 10 }}>El módulo de trading está deshabilitado en tu plan.</div>}
            </div>
        </div>
    );
}

export default function TradingScreen({ selectedId, onNewStrategy }) {
    const { strategies, strategySignals } = useNifty();
    const enabled = !!strategies?.enabled;
    const trading = (strategies?.data || []).filter((s) => s.type === 'trading');

    if (!trading.length) return <EmptyTrading enabled={enabled} onNew={onNewStrategy} />;

    const selected = trading.find((s) => s.id === selectedId) || trading[0];
    const signals = strategySignals?.[selected.id] || [];
    return <TradingDetail strategy={selected} signals={signals} />;
}
