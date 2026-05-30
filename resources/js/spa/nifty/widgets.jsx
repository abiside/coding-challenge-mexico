/* NIFTY — shared widgets: charts, controls, indicators (portado del diseño) */
import { useRef, useState } from 'react';
import { stageLabel, signedMoney, fmtCompact } from './format';

/* ---------- Sparkline ---------- */
export function Sparkline({ data, color = 'var(--turq)', h = 30 }) {
    const w = 130;
    if (!data || data.length < 2) {
        return <svg className="spark" viewBox={`0 0 ${w} ${h}`} preserveAspectRatio="none" />;
    }
    const min = Math.min(...data), max = Math.max(...data);
    const range = max - min || 1;
    const pts = data.map((v, i) => {
        const x = (i / (data.length - 1)) * w;
        const y = h - 3 - ((v - min) / range) * (h - 6);
        return [x, y];
    });
    const line = pts.map((p, i) => (i ? 'L' : 'M') + p[0].toFixed(1) + ' ' + p[1].toFixed(1)).join(' ');
    const area = line + ` L${w} ${h} L0 ${h} Z`;
    const id = 'sg' + Math.round(min) + data.length + color.length;
    return (
        <svg className="spark" viewBox={`0 0 ${w} ${h}`} preserveAspectRatio="none">
            <defs>
                <linearGradient id={id} x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor={color} stopOpacity="0.28" />
                    <stop offset="100%" stopColor={color} stopOpacity="0" />
                </linearGradient>
            </defs>
            <path d={area} fill={`url(#${id})`} />
            <path d={line} fill="none" stroke={color} strokeWidth="1.6" strokeLinejoin="round" strokeLinecap="round" />
        </svg>
    );
}

/* ---------- KPI card ---------- */
export function Kpi({ hero, label, icon, value, delta, deltaDir, detail, spark, sparkColor }) {
    const Icon = icon;
    return (
        <div className={'panel kpi' + (hero ? ' hero hud' : '')}>
            <div className="kpi-top">
                <span className="label">{label}</span>
                <span className="ico"><Icon /></span>
            </div>
            <div className="big">{value}</div>
            <div className="meta">
                {delta && <span className={'delta ' + deltaDir}>{deltaDir === 'up' ? '▲' : '▼'} {delta}</span>}
                {detail && <span>{detail}</span>}
            </div>
            {spark && spark.length > 1 && <Sparkline data={spark} color={sparkColor} />}
        </div>
    );
}

/* ---------- Big P&L chart (con escalas + indicador puntual) ---------- */
function fmtAxisTime(ms, spanMs) {
    const d = new Date(ms);
    if (spanMs >= 2 * 864e5) return d.toLocaleDateString('es-MX', { day: '2-digit', month: 'short' });
    return d.getHours().toString().padStart(2, '0') + ':' + d.getMinutes().toString().padStart(2, '0');
}

function fmtTipTime(ms) {
    const d = new Date(ms);
    return d.toLocaleString('es-MX', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', second: '2-digit' });
}

export function BigChart({ data, times, steps, markers = [], domain }) {
    const W = 760, H = 290, padL = 50, padR = 16, padT = 18, padB = 30;
    const series = data && data.length > 1 ? data : [0, 0];
    const min = Math.min(...series, 0), max = Math.max(...series);
    const range = max - min || 1;
    const innerW = W - padL - padR, innerH = H - padT - padB;
    const n = series.length;

    const wrapRef = useRef(null);
    const [hover, setHover] = useState(null);

    // Eje X como escala temporal lineal real: cada punto se ubica según su
    // timestamp dentro del dominio [t0, t1] (la ventana seleccionada), no por su
    // índice. Esto hace que las marcas de tiempo sean proporcionales al ancho.
    const hasTimes = Array.isArray(times) && times.length === n;
    const t0 = hasTimes ? (domain?.[0] ?? times[0]) : 0;
    const t1 = hasTimes ? (domain?.[1] ?? times[n - 1]) : 1;
    const tspan = Math.max(1, t1 - t0);
    const spanMs = tspan;
    const Xt = (ms) => padL + ((Math.min(Math.max(ms, t0), t1) - t0) / tspan) * innerW;
    const X = hasTimes ? ((i) => Xt(times[i])) : ((i) => padL + (i / (n - 1)) * innerW);
    const Y = (v) => padT + innerH - ((v - min) / range) * innerH;

    const pts = series.map((v, i) => [X(i), Y(v)]);
    let d = `M ${pts[0][0]} ${pts[0][1]}`;
    for (let i = 0; i < pts.length - 1; i++) {
        const p0 = pts[i - 1] || pts[i], p1 = pts[i], p2 = pts[i + 1], p3 = pts[i + 2] || p2;
        const c1x = p1[0] + (p2[0] - p0[0]) / 6, c1y = p1[1] + (p2[1] - p0[1]) / 6;
        const c2x = p2[0] - (p3[0] - p1[0]) / 6, c2y = p2[1] - (p3[1] - p1[1]) / 6;
        d += ` C ${c1x.toFixed(1)} ${c1y.toFixed(1)}, ${c2x.toFixed(1)} ${c2y.toFixed(1)}, ${p2[0].toFixed(1)} ${p2[1].toFixed(1)}`;
    }
    const area = d + ` L ${pts[pts.length - 1][0]} ${H - padB} L ${pts[0][0]} ${H - padB} Z`;
    const last = pts[pts.length - 1];
    const gridFracs = [0, 0.25, 0.5, 0.75, 1];

    // Eje Y: valor monetario en cada línea de grilla (top = max, bottom = min).
    const yTicks = gridFracs.map((f) => ({
        topPct: ((padT + innerH * f) / H) * 100,
        value: min + (1 - f) * range,
    }));

    // Eje X: 5 marcas en tiempos de reloj equiespaciados sobre el dominio.
    const xTicks = hasTimes
        ? [0, 0.25, 0.5, 0.75, 1].map((f) => {
            const ms = t0 + f * tspan;
            return { leftPct: (Xt(ms) / W) * 100, label: fmtAxisTime(ms, spanMs) };
        })
        : [];

    const promoLines = hasTimes
        ? (markers || [])
            .filter((m) => m && typeof m.ms === 'number' && m.ms >= t0 && m.ms <= t1)
            .map((m) => {
                const x = Xt(m.ms);
                return { svgX: x, leftPct: (x / W) * 100, label: fmtAxisTime(m.ms, spanMs), ms: m.ms, manual: !!m.manual };
            })
        : [];

    // Punto más cercano por distancia en píxeles (el eje ya no es uniforme).
    const nearestIdx = (svgX) => {
        let best = 0, bestD = Infinity;
        for (let i = 0; i < n; i++) {
            const dx = Math.abs(pts[i][0] - svgX);
            if (dx < bestD) { bestD = dx; best = i; }
        }
        return best;
    };

    const onMove = (e) => {
        const el = wrapRef.current;
        if (!el) return;
        const rect = el.getBoundingClientRect();
        const svgX = ((e.clientX - rect.left) / rect.width) * W;
        setHover(nearestIdx(svgX));
    };

    const hv = hover != null ? {
        leftPct: (X(hover) / W) * 100,
        topPct: (Y(series[hover]) / H) * 100,
        value: series[hover],
        step: steps && steps[hover] != null ? steps[hover] : null,
        time: hasTimes ? times[hover] : null,
    } : null;

    return (
        <div className="chart-canvas" ref={wrapRef} onMouseMove={onMove} onMouseLeave={() => setHover(null)}>
            <svg className="chart-svg" viewBox={`0 0 ${W} ${H}`} preserveAspectRatio="none">
                <defs>
                    <linearGradient id="cstroke" x1="0" y1="0" x2="1" y2="0">
                        <stop offset="0%" stopColor="#ff39a8" />
                        <stop offset="55%" stopColor="#b964e0" />
                        <stop offset="100%" stopColor="#2ff0cf" />
                    </linearGradient>
                    <linearGradient id="carea" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stopColor="#2ff0cf" stopOpacity="0.22" />
                        <stop offset="100%" stopColor="#2ff0cf" stopOpacity="0" />
                    </linearGradient>
                    <filter id="glow" x="-20%" y="-20%" width="140%" height="140%">
                        <feGaussianBlur stdDeviation="1.4" result="b" />
                        <feMerge><feMergeNode in="b" /><feMergeNode in="SourceGraphic" /></feMerge>
                    </filter>
                </defs>
                {gridFracs.map((f, i) => {
                    const y = padT + innerH * f;
                    return <line key={i} x1={padL} y1={y} x2={W - padR} y2={y} stroke="rgba(255,255,255,0.045)" strokeWidth="1" strokeDasharray="2 5" />;
                })}
                {min < 0 && max > 0 && (
                    <line x1={padL} y1={Y(0)} x2={W - padR} y2={Y(0)} stroke="rgba(255,255,255,0.18)" strokeWidth="1" />
                )}
                <path d={area} fill="url(#carea)" />
                <path d={d} fill="none" stroke="url(#cstroke)" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round" filter="url(#glow)" />
                {promoLines.map((p, i) => (
                    <line key={'pl' + i} x1={p.svgX} y1={padT - 2} x2={p.svgX} y2={H - padB} stroke="#f5b73d" strokeWidth="1.3" strokeDasharray="4 3" opacity="0.9" />
                ))}
                {hv && <line x1={X(hover)} y1={padT} x2={X(hover)} y2={H - padB} stroke="rgba(47,240,207,0.45)" strokeWidth="1" strokeDasharray="3 3" />}
                <circle cx={last[0]} cy={last[1]} r="4" fill="#2ff0cf" filter="url(#glow)" />
                <circle cx={last[0]} cy={last[1]} r="8.5" fill="none" stroke="#2ff0cf" strokeWidth="1" opacity="0.28" />
            </svg>

            <div className="chart-axis-y">
                {yTicks.map((t, i) => (
                    <span key={i} className="ax-y" style={{ top: t.topPct + '%' }}>{fmtCompact(t.value)}</span>
                ))}
            </div>

            {xTicks.length > 0 && (
                <div className="chart-axis-x">
                    {xTicks.map((t, i) => (
                        <span key={i} className="ax-x" style={{ left: t.leftPct + '%' }}>{t.label}</span>
                    ))}
                </div>
            )}

            {promoLines.map((p, i) => (
                <span key={'pm' + i} className="chart-promo" style={{ left: p.leftPct + '%' }}
                    title={'Nuevo champion · ' + fmtTipTime(p.ms) + (p.manual ? ' (manual)' : '')}>
                    <span className="cp-flag" />
                    <span className="cp-label">{p.label}</span>
                </span>
            ))}

            {hv && (
                <>
                    <span className="chart-dot" style={{ left: hv.leftPct + '%', top: hv.topPct + '%' }} />
                    <div className="chart-tip" style={{ left: hv.leftPct + '%', top: hv.topPct + '%' }}>
                        <span className={'tip-val ' + (hv.value >= 0 ? 'pos' : 'neg')}>{signedMoney(hv.value)}</span>
                        {hv.step != null && hv.step !== 0 && (
                            <span className={'tip-step ' + (hv.step >= 0 ? 'pos' : 'neg')}>{signedMoney(hv.step)} en esta op</span>
                        )}
                        {hv.time != null && <span className="tip-time">{fmtTipTime(hv.time)}</span>}
                    </div>
                </>
            )}
        </div>
    );
}

/* ---------- Multi-line chart (comparación de estrategias) ---------- */
export const LINE_PALETTE = ['#ff39a8', '#b964e0', '#ffae34', '#2f7bff', '#00c9a7', '#8fd14f'];

export function lineColor(series, idx) {
    if (series.color) return series.color;
    if (series.status === 'champion') return '#2ff0cf';
    return LINE_PALETTE[idx % LINE_PALETTE.length];
}

export function MultiLineChart({ axis, series, markers = [], h = 290 }) {
    const W = 760, padL = 8, padR = 8, padT = 18, padB = 22;
    const lines = (series || []).filter((s) => Array.isArray(s.points) && s.points.length > 1);
    const n = axis?.length || 0;
    if (!lines.length || n < 2) {
        return <div className="empty-note" style={{ padding: '46px 16px', textAlign: 'center' }}>Aún no hay ventanas de evaluación para comparar. Enciende el autopilot con la simulación activa.</div>;
    }
    const allVals = lines.flatMap((s) => s.points).concat([0]);
    const min = Math.min(...allVals), max = Math.max(...allVals);
    const range = (max - min) || 1;
    const innerW = W - padL - padR, innerH = h - padT - padB;
    const X = (i) => padL + (i / (n - 1)) * innerW;
    const Y = (v) => padT + innerH - ((v - min) / range) * innerH;
    const zeroY = Y(0);
    const toPath = (pts) => pts.map((v, i) => (i ? 'L' : 'M') + X(i).toFixed(1) + ' ' + Y(v).toFixed(1)).join(' ');
    const gridLines = [0, 0.25, 0.5, 0.75, 1].map((f) => padT + innerH * f);

    // Convierte un timestamp (ms) a una posición X interpolando sobre el eje de
    // ventanas, para ubicar los marcadores de promoción donde realmente caen.
    const msToX = (ms) => {
        if (ms <= axis[0]) return X(0);
        if (ms >= axis[n - 1]) return X(n - 1);
        for (let i = 1; i < n; i++) {
            if (axis[i] >= ms) {
                const span = (axis[i] - axis[i - 1]) || 1;
                return X((i - 1) + (ms - axis[i - 1]) / span);
            }
        }
        return X(n - 1);
    };
    const promoMarkers = (markers || []).filter((m) => m && typeof m.ms === 'number');

    return (
        <svg className="chart-svg" viewBox={`0 0 ${W} ${h}`} preserveAspectRatio="none">
            {gridLines.map((y, i) => (
                <line key={i} x1={padL} y1={y} x2={W - padR} y2={y} stroke="rgba(255,255,255,0.045)" strokeWidth="1" strokeDasharray="2 5" />
            ))}
            <line x1={padL} y1={zeroY} x2={W - padR} y2={zeroY} stroke="rgba(255,255,255,0.18)" strokeWidth="1" />
            {promoMarkers.map((m, i) => {
                const x = msToX(m.ms);
                return (
                    <g key={'mk' + i}>
                        <line x1={x} y1={padT - 4} x2={x} y2={h - padB} stroke="#f5b73d" strokeWidth="1.4" strokeDasharray="3 3" opacity="0.9" />
                        <circle cx={x} cy={padT - 4} r="3" fill="#f5b73d" />
                        <text x={x + 4} y={padT + 6} fill="#f5b73d" fontSize="9" opacity="0.95">{m.label || 'Promoción'}</text>
                    </g>
                );
            })}
            {lines.map((s, idx) => {
                const isChamp = s.status === 'champion';
                const c = lineColor(s, idx);
                const last = s.points.length - 1;
                return (
                    <g key={s.id}>
                        <path d={toPath(s.points)} fill="none" stroke={c} strokeWidth={isChamp ? 2.6 : 1.5} strokeLinejoin="round" strokeLinecap="round" opacity={isChamp ? 1 : 0.85} strokeDasharray={isChamp ? '' : '4 3'} />
                        <circle cx={X(last)} cy={Y(s.points[last])} r={isChamp ? 3.5 : 2.5} fill={c} />
                    </g>
                );
            })}
        </svg>
    );
}

/* ---------- Opportunity row (live lifecycle) ---------- */
export function OppRow({ o, onClick }) {
    const evaluating = o.status === 'eval';
    const flashing = o._flashAt && (Date.now() - o._flashAt < 1150);
    const pclass = o.profit > 0 ? 'pos' : o.profit < 0 ? 'neg' : 'zero';
    const finalVal = o.profit === 0 ? '—' : (o.profit > 0 ? '+' : '−') + '$' + Math.abs(o.profit).toFixed(2);
    const provVal = '≈ $' + Math.abs(o.shownEst || 0).toFixed(2);
    return (
        <div className={'opp' + (evaluating ? ' evaluating' : '')} onClick={onClick}>
            {flashing && <span className={'opp-flash ' + o.status} />}
            <div className="opp-row">
                <div className="route">
                    <span className="ex">{o.buy}</span>
                    <span className="arrow">→</span>
                    <span className="ex">{o.sell}</span>
                </div>
                {evaluating
                    ? <span className="profit prov">{provVal}</span>
                    : <span className={'profit ' + pclass}>{finalVal}</span>}
            </div>
            <div className="opp-row">
                <span className="time">{o.time} · {o.grossPct.toFixed(2)}% bruto / {o.netPct >= 0 ? '+' : ''}{o.netPct.toFixed(2)}% neto · {o.vol} BTC</span>
                <span className={'badge ' + o.status}><span className="d" />{o.statusLabel}</span>
            </div>
            {evaluating ? (
                <div className="opp-stage"><span className="sd" /><span>{stageLabel(o.progress)}</span><span className="dots" /></div>
            ) : (
                <div className="reason">{o.reason}</div>
            )}
            {evaluating && (
                <div className="prog"><span className="prog-fill" style={{ width: o.progress + '%' }} /></div>
            )}
        </div>
    );
}

/* ---------- Latency indicator ---------- */
export function Lat({ ms }) {
    if (ms == null) {
        return <span className="lat"><span className="mono" style={{ color: 'var(--tx-lo)' }}>—</span></span>;
    }
    const cls = ms <= 40 ? 'good' : ms <= 70 ? 'mid' : 'bad';
    const heights = [5, 8, 11].map((h, i) => {
        const active = (cls === 'good') || (cls === 'mid' && i < 2) || (cls === 'bad' && i < 1);
        return <i key={i} style={{ height: h + 'px', opacity: active ? 1 : 0.3 }} />;
    });
    return (
        <span className={'lat ' + cls}>
            <span className="mono">{ms}ms</span>
            <span className="bars">{heights}</span>
        </span>
    );
}

/* ---------- Bar chart (supports +/- around a baseline) ---------- */
export function BarChart({ data, h = 120, posColor = '#2fe3b6', negColor = '#ff476f', gap = 2 }) {
    const W = 600;
    const series = data && data.length ? data : [0];
    const max = Math.max(...series, 0);
    const min = Math.min(...series, 0);
    const range = (max - min) || 1;
    const zeroY = (max / range) * h;
    const bw = (W - gap * (series.length - 1)) / series.length;
    return (
        <svg viewBox={`0 0 ${W} ${h}`} preserveAspectRatio="none" style={{ width: '100%', height: h + 'px', display: 'block' }}>
            <line x1="0" y1={zeroY} x2={W} y2={zeroY} stroke="rgba(255,255,255,0.12)" strokeWidth="1" />
            {series.map((v, i) => {
                const x = i * (bw + gap);
                const barH = (Math.abs(v) / range) * h;
                const y = v >= 0 ? zeroY - barH : zeroY;
                return <rect key={i} x={x} y={y} width={bw} height={Math.max(barH, 1)} rx="1.5" fill={v >= 0 ? posColor : negColor} opacity="0.85" />;
            })}
        </svg>
    );
}

/* ---------- Mini columns (volume etc.) ---------- */
export function Columns({ data, h = 90, color = 'var(--turq)', gap = 3 }) {
    const W = 600;
    const series = data && data.length ? data : [0];
    const max = Math.max(...series) || 1;
    const bw = (W - gap * (series.length - 1)) / series.length;
    return (
        <svg viewBox={`0 0 ${W} ${h}`} preserveAspectRatio="none" style={{ width: '100%', height: h + 'px', display: 'block' }}>
            {series.map((v, i) => {
                const barH = (v / max) * (h - 2);
                return <rect key={i} x={i * (bw + gap)} y={h - barH} width={bw} height={barH} rx="1.5" fill={color} opacity={0.35 + (v / max) * 0.55} />;
            })}
        </svg>
    );
}

/* ---------- Donut ---------- */
export function Donut({ segments, size = 132, thickness = 16, label, sub }) {
    const r = (size - thickness) / 2;
    const c = 2 * Math.PI * r;
    const total = segments.reduce((s, x) => s + x.value, 0) || 1;
    let acc = 0;
    return (
        <div style={{ position: 'relative', width: size, height: size }}>
            <svg width={size} height={size} style={{ transform: 'rotate(-90deg)' }}>
                <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke="rgba(255,255,255,0.06)" strokeWidth={thickness} />
                {segments.map((s, i) => {
                    const len = (s.value / total) * c;
                    const el = <circle key={i} cx={size / 2} cy={size / 2} r={r} fill="none" stroke={s.color} strokeWidth={thickness} strokeDasharray={`${len} ${c - len}`} strokeDashoffset={-acc} strokeLinecap="butt" />;
                    acc += len;
                    return el;
                })}
            </svg>
            <div style={{ position: 'absolute', inset: 0, display: 'grid', placeItems: 'center', textAlign: 'center' }}>
                <div>
                    <div className="num" style={{ fontSize: 22, fontWeight: 600, color: 'var(--tx-hi)' }}>{label}</div>
                    {sub && <div style={{ fontFamily: 'var(--mono)', fontSize: 9.5, letterSpacing: '0.1em', color: 'var(--tx-lo)', textTransform: 'uppercase', marginTop: 2 }}>{sub}</div>}
                </div>
            </div>
        </div>
    );
}

/* ---------- Toggle switch ---------- */
export function Toggle({ on, onChange }) {
    return (
        <button className={'toggle' + (on ? ' on' : '')} onClick={() => onChange(!on)} aria-pressed={on}>
            <span className="knob" />
        </button>
    );
}

/* ---------- Segmented control ---------- */
export function Segmented({ value, options, onChange }) {
    return (
        <div className="seg">
            {options.map((o) => {
                const v = typeof o === 'string' ? o : o.value;
                const l = typeof o === 'string' ? o : o.label;
                return <button key={v} className={value === v ? 'on' : ''} onClick={() => onChange(v)}>{l}</button>;
            })}
        </div>
    );
}

/* ---------- Numeric field with unit ---------- */
export function NumField({ label, value, unit, onChange, step = 1 }) {
    return (
        <label className="numfield">
            <span className="nf-label">{label}</span>
            <span className="nf-input">
                <input type="number" value={value} step={step} onChange={(e) => onChange(parseFloat(e.target.value))} />
                {unit && <span className="nf-unit">{unit}</span>}
            </span>
        </label>
    );
}
