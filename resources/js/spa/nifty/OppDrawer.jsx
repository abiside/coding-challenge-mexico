/* NIFTY — drawer de detalle de oportunidad (portado del diseño, cableado a datos reales) */
import { I } from './icons';
import { fmt } from './format';

function Book({ side, levels, label }) {
    if (!levels || levels.length === 0) {
        return (
            <div className={'book ' + side}>
                <div className="bt">{label}</div>
                <div className="num" style={{ fontSize: 11, color: 'var(--tx-faint)' }}>sin datos de niveles</div>
            </div>
        );
    }
    const maxAvail = Math.max(...levels.map((l) => l.avail)) || 1;
    return (
        <div className={'book ' + side}>
            <div className="bt">{label}</div>
            {levels.map((l, i) => (
                <div key={i} className={'lvl' + (l.used > 0 ? ' used' : '')}>
                    <span className="fill" style={{ width: (l.avail / maxAvail * 100) + '%' }} />
                    <span className="p">${fmt(l.p)}</span>
                    <span className="q">{l.used.toFixed(2)} / {l.avail.toFixed(2)}</span>
                </div>
            ))}
        </div>
    );
}

export function OppDrawer({ o, onClose }) {
    if (!o) return null;
    const f = o.fin;
    const isExec = o.status === 'exec';
    const isEval = o.status === 'eval';
    const dClass = isEval ? 'eval' : isExec ? 'exec' : 'reject';
    const dHead = isEval ? '◴ EN EVALUACIÓN' : isExec ? '✓ EJECUTADA' : o.status === 'expired' ? '⊘ IGNORADA' : '✕ RECHAZADA';
    const profitClass = f.net > 0 ? 'pos' : f.net < 0 ? 'neg' : '';
    const hasFees = f.buyFee != null && f.sellFee != null;

    // El desglose itemizado solo se muestra si reconcilia con el neto:
    // neto = spread teórico − slippage − fees − latencia − fijo. Para datos
    // antiguos (sin componentes persistidos) caemos al formato agregado.
    const reconciles =
        f.theoreticalGross != null &&
        f.breakdownTotal != null &&
        Math.abs(f.theoreticalGross - f.breakdownTotal - f.net) <= Math.max(0.01, Math.abs(f.net) * 0.005);

    return (
        <div className="overlay" onClick={onClose}>
            <div className="drawer" onClick={(e) => e.stopPropagation()}>
                <div className="drawer-h">
                    <div>
                        <div className="route">
                            {o.buy}<span className="arrow">→</span>{o.sell}
                        </div>
                        <div className="num" style={{ fontSize: '11px', color: 'var(--tx-lo)', marginTop: '5px', letterSpacing: '0.04em' }}>
                            {o.pair} · {o.time} · ID #{String(o.id).slice(-6)}
                        </div>
                    </div>
                    <span className={'badge ' + o.status} style={{ alignSelf: 'center' }}><span className="d" />{o.statusLabel}</span>
                    <button className="x-btn" onClick={onClose}><I.x /></button>
                </div>

                <div className="drawer-sec">
                    <div className="sec-t">Resumen de oportunidad</div>
                    <div className="kv-grid">
                        <div className="kv"><span className="k">Volumen evaluado</span><span className="v">{o.vol.toFixed(4)} BTC</span></div>
                        <div className="kv"><span className="k">Rentabilidad neta</span><span className={'v ' + profitClass}>{o.netPct >= 0 ? '+' : ''}{o.netPct.toFixed(2)}%</span></div>
                        <div className="kv"><span className="k">Precio compra prom.</span><span className="v">${fmt(o.buyPrice)}</span></div>
                        <div className="kv"><span className="k">Precio venta prom.</span><span className="v">${fmt(o.sellPrice)}</span></div>
                        <div className="kv"><span className="k">Spread bruto</span><span className="v">+{o.grossPct.toFixed(2)}%</span></div>
                        <div className="kv"><span className="k">Profit neto final</span><span className={'v ' + profitClass}>{f.net >= 0 ? '+' : '−'}${fmt(Math.abs(f.net))}</span></div>
                    </div>
                </div>

                <div className="drawer-sec">
                    <div className="sec-t">Desglose financiero</div>
                    {reconciles ? (
                        <>
                            <div className="fin">
                                <div className="frow gain"><span className="fl">Spread bruto teórico (mejores precios)</span><span className="fv">+${fmt(f.theoreticalGross)}</span></div>
                                {f.slippage != null && <div className="frow cost"><span className="fl">Slippage por profundidad</span><span className="fv">−${fmt(f.slippage)}</span></div>}
                                {f.buyFee != null && <div className="frow cost"><span className="fl">Fee de compra</span><span className="fv">−${fmt(f.buyFee)}</span></div>}
                                {f.sellFee != null && <div className="frow cost"><span className="fl">Fee de venta</span><span className="fv">−${fmt(f.sellFee)}</span></div>}
                                {f.latency != null && f.latency > 0 && <div className="frow cost"><span className="fl">Penalización por latencia</span><span className="fv">−${fmt(f.latency)}</span></div>}
                                {f.fixedCost != null && f.fixedCost > 0 && <div className="frow cost"><span className="fl">Costo fijo</span><span className="fv">−${fmt(f.fixedCost)}</span></div>}
                                <div className="frow cost" style={{ opacity: 0.9 }}><span className="fl">Costos totales</span><span className="fv">−${fmt(f.breakdownTotal)}</span></div>
                                <div className="frow total"><span className="fl">Ganancia neta final</span><span className="fv" style={{ color: f.net >= 0 ? 'var(--profit)' : 'var(--loss)' }}>{f.net >= 0 ? '+' : '−'}${fmt(Math.abs(f.net))}</span></div>
                            </div>
                            <div className="num" style={{ fontSize: '10px', color: 'var(--tx-faint)', marginTop: '10px', textAlign: 'center' }}>
                                neto = spread teórico − slippage − fees − latencia − costo fijo
                            </div>
                        </>
                    ) : (
                        <div className="fin">
                            <div className="frow cost"><span className="fl">Costo bruto de compra</span><span className="fv">−${fmt(f.grossCost)}</span></div>
                            {hasFees && <div className="frow cost"><span className="fl">Fee de compra</span><span className="fv">−${fmt(f.buyFee)}</span></div>}
                            <div className="frow gain"><span className="fl">Ingreso bruto de venta</span><span className="fv">+${fmt(f.sellGross)}</span></div>
                            {hasFees && <div className="frow cost"><span className="fl">Fee de venta</span><span className="fv">−${fmt(f.sellFee)}</span></div>}
                            {hasFees
                                ? <div className="frow cost"><span className="fl">Slippage + latencia</span><span className="fv">−${fmt(f.otherCosts)}</span></div>
                                : <div className="frow cost"><span className="fl">Costos totales (fees + slippage + latencia)</span><span className="fv">−${fmt(f.totalCosts)}</span></div>}
                            <div className="frow total"><span className="fl">Ganancia neta final</span><span className="fv" style={{ color: f.net >= 0 ? 'var(--profit)' : 'var(--loss)' }}>{f.net >= 0 ? '+' : '−'}${fmt(Math.abs(f.net))}</span></div>
                        </div>
                    )}
                </div>

                {f.realized != null && (
                    <div className="drawer-sec">
                        <div className="sec-t">Identificada vs. ejecutada</div>
                        <div className="fin">
                            <div className="frow"><span className="fl">Profit neto identificado (evaluado)</span><span className="fv" style={{ color: f.net >= 0 ? 'var(--profit)' : 'var(--loss)' }}>{f.net >= 0 ? '+' : '−'}${fmt(Math.abs(f.net))}</span></div>
                            <div className="frow"><span className="fl">P&L realmente realizado</span><span className="fv" style={{ color: f.realized >= 0 ? 'var(--profit)' : 'var(--loss)' }}>{f.realized >= 0 ? '+' : '−'}${fmt(Math.abs(f.realized))}</span></div>
                            <div className="frow total"><span className="fl">Diferencia (slippage de ejecución)</span><span className="fv" style={{ color: (f.executionDelta || 0) >= 0 ? 'var(--profit)' : 'var(--loss)' }}>{(f.executionDelta || 0) >= 0 ? '+' : '−'}${fmt(Math.abs(f.executionDelta || 0))}</span></div>
                        </div>
                        <div className="num" style={{ fontSize: '10px', color: 'var(--tx-faint)', marginTop: '10px', textAlign: 'center' }}>
                            el precio se movió entre la detección y el trade
                        </div>
                    </div>
                )}

                <div className="drawer-sec">
                    <div className="sec-t">Liquidez usada · order book</div>
                    <div className="books">
                        <Book side="buy" levels={o.books.buy} label={'Compra · ' + o.buy} />
                        <Book side="sell" levels={o.books.sell} label={'Venta · ' + o.sell} />
                    </div>
                    <div className="num" style={{ fontSize: '10px', color: 'var(--tx-faint)', marginTop: '10px', textAlign: 'center' }}>
                        nivel VWAP ejecutado (BTC){o.partial ? ' · fill parcial' : ''}
                    </div>
                </div>

                <div className="drawer-sec" style={{ borderBottom: 'none' }}>
                    <div className="sec-t">Decisión del bot</div>
                    <div className={'decision ' + dClass}>
                        <div className="dh">{dHead}</div>
                        {o.decision}
                        <div className="rule">{o.rule}</div>
                    </div>
                </div>
            </div>
        </div>
    );
}
