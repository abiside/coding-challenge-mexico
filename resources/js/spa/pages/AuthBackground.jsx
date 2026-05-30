/* NIFTY — Fondo animado 3D para login/registro.
   Constelación esférica de nodos de divisas que rota de forma aleatoria (deriva
   orgánica en los 3 ejes), con perspectiva y profundidad. Cada cierto tiempo se
   "descubre" un ciclo de arbitraje triangular y un pulso de luz recorre el
   triángulo proyectado en 3D. Metáfora del motor de arbitraje triangular. */
import { useEffect, useRef } from 'react';

/* Cada cripto: color de marca + glyph (logo dibujado en canvas) o char unicode. */
const COINS = [
    { sym: 'BTC', color: '#f7931a', char: '\u20BF' },
    { sym: 'ETH', color: '#627eea', glyph: 'eth' },
    { sym: 'USDT', color: '#26a17b', char: '\u20AE' },
    { sym: 'SOL', color: '#9945ff', glyph: 'sol' },
    { sym: 'BNB', color: '#f3ba2f', glyph: 'bnb' },
    { sym: 'XRP', color: '#23a8e0', glyph: 'xrp' },
    { sym: 'USDC', color: '#2775ca', char: '$' },
    { sym: 'ADA', color: '#0a8ad6', char: 'A' },
    { sym: 'DOGE', color: '#c2a633', char: '\u00D0' },
    { sym: 'AVAX', color: '#e84142', char: 'A' },
    { sym: 'LINK', color: '#2a5ada', glyph: 'link' },
    { sym: 'TRX', color: '#eb0029', char: 'T' },
    { sym: 'DOT', color: '#e6007a', glyph: 'dot' },
    { sym: 'POL', color: '#8247e5', char: 'P' },
    { sym: 'LTC', color: '#5b88c9', char: '\u0141' },
    { sym: 'ATOM', color: '#5064fb', char: 'A' },
    { sym: 'NEAR', color: '#00c08b', char: 'N' },
    { sym: 'OP', color: '#ff0420', char: 'O' },
];
const FUCHSIA = [255, 57, 168];
const TURQ = [47, 240, 207];

/* Logos dibujados con primitivas, normalizados a un radio s alrededor de (cx,cy). */
const GLYPHS = {
    eth(ctx, cx, cy, s) {
        ctx.beginPath();
        ctx.moveTo(cx, cy - 0.62 * s);
        ctx.lineTo(cx + 0.38 * s, cy + 0.05 * s);
        ctx.lineTo(cx, cy + 0.3 * s);
        ctx.lineTo(cx - 0.38 * s, cy + 0.05 * s);
        ctx.closePath();
        ctx.fill();
        ctx.beginPath();
        ctx.moveTo(cx, cy + 0.4 * s);
        ctx.lineTo(cx + 0.38 * s, cy + 0.14 * s);
        ctx.lineTo(cx, cy + 0.66 * s);
        ctx.lineTo(cx - 0.38 * s, cy + 0.14 * s);
        ctx.closePath();
        ctx.fill();
    },
    sol(ctx, cx, cy, s) {
        const w = 0.7 * s, h = 0.16 * s, sk = 0.18 * s;
        for (let i = -1; i <= 1; i++) {
            const y = cy + i * 0.32 * s;
            const dir = i === 0 ? -1 : 1;
            ctx.beginPath();
            ctx.moveTo(cx - w + dir * sk, y - h);
            ctx.lineTo(cx + w + dir * sk, y - h);
            ctx.lineTo(cx + w - dir * sk, y + h);
            ctx.lineTo(cx - w - dir * sk, y + h);
            ctx.closePath();
            ctx.fill();
        }
    },
    bnb(ctx, cx, cy, s) {
        const d = (x, y, r) => {
            ctx.beginPath();
            ctx.moveTo(x, y - r);
            ctx.lineTo(x + r, y);
            ctx.lineTo(x, y + r);
            ctx.lineTo(x - r, y);
            ctx.closePath();
            ctx.fill();
        };
        const o = 0.42 * s, r = 0.2 * s;
        d(cx, cy, 0.26 * s);
        d(cx, cy - o, r);
        d(cx, cy + o, r);
        d(cx - o, cy, r);
        d(cx + o, cy, r);
    },
    xrp(ctx, cx, cy, s) {
        ctx.lineWidth = 0.14 * s;
        ctx.lineCap = 'round';
        ctx.beginPath();
        ctx.arc(cx, cy - 0.18 * s, 0.4 * s, Math.PI * 0.18, Math.PI * 0.82);
        ctx.stroke();
        ctx.beginPath();
        ctx.arc(cx, cy + 0.18 * s, 0.4 * s, Math.PI * 1.18, Math.PI * 1.82);
        ctx.stroke();
    },
    link(ctx, cx, cy, s) {
        const r = 0.5 * s;
        ctx.beginPath();
        for (let i = 0; i < 6; i++) {
            const a = (Math.PI / 3) * i - Math.PI / 2;
            const x = cx + Math.cos(a) * r, y = cy + Math.sin(a) * r;
            i ? ctx.lineTo(x, y) : ctx.moveTo(x, y);
        }
        ctx.closePath();
        ctx.fill();
    },
    dot(ctx, cx, cy, s) {
        const dot = (x, y, r) => { ctx.beginPath(); ctx.arc(x, y, r, 0, Math.PI * 2); ctx.fill(); };
        dot(cx, cy - 0.4 * s, 0.16 * s);
        dot(cx - 0.35 * s, cy + 0.18 * s, 0.16 * s);
        dot(cx + 0.35 * s, cy + 0.18 * s, 0.16 * s);
        dot(cx, cy, 0.13 * s);
    },
};

const lerp = (a, b, t) => a + (b - a) * t;
const mix = (c1, c2, t) => [lerp(c1[0], c2[0], t), lerp(c1[1], c2[1], t), lerp(c1[2], c2[2], t)];
const rgba = (c, a) => `rgba(${c[0] | 0},${c[1] | 0},${c[2] | 0},${a})`;
const hexA = (hex, a) => {
    const h = hex.replace('#', '');
    const n = parseInt(h.length === 3 ? h.split('').map((c) => c + c).join('') : h, 16);
    return `rgba(${(n >> 16) & 255},${(n >> 8) & 255},${n & 255},${a})`;
};

export default function AuthBackground() {
    const canvasRef = useRef(null);

    useEffect(() => {
        const canvas = canvasRef.current;
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        let width = 0;
        let height = 0;
        let dpr = Math.min(window.devicePixelRatio || 1, 2);
        let nodes = [];
        let cycles = [];
        let raf = 0;
        let last = performance.now();
        let elapsed = 0;
        let nextCycleAt = 500;

        // Estado de rotación 3D + velocidades angulares que derivan al azar.
        const rot = { x: 0.3, y: 0.2, z: 0 };
        const vel = { x: 0.18, y: 0.24, z: 0.05 };
        const targetVel = { x: 0.18, y: 0.24, z: 0.05 };
        let radius = 240;
        let fov = 620;

        const resize = () => {
            width = canvas.clientWidth;
            height = canvas.clientHeight;
            dpr = Math.min(window.devicePixelRatio || 1, 2);
            canvas.width = Math.max(1, Math.floor(width * dpr));
            canvas.height = Math.max(1, Math.floor(height * dpr));
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
            radius = Math.hypot(width, height) * 0.46;
            fov = Math.max(680, Math.hypot(width, height) * 0.9);
            buildNodes();
        };

        // Distribución uniforme sobre una esfera (espiral de Fibonacci).
        const buildNodes = () => {
            const count = Math.max(22, Math.min(44, Math.round((width * height) / 32000)));
            nodes = [];
            const golden = Math.PI * (3 - Math.sqrt(5));
            for (let i = 0; i < count; i++) {
                const y = 1 - (i / (count - 1)) * 2;
                const r = Math.sqrt(Math.max(0, 1 - y * y));
                const theta = golden * i;
                nodes.push({
                    bx: Math.cos(theta) * r,
                    by: y,
                    bz: Math.sin(theta) * r,
                    coin: COINS[i % COINS.length],
                    phase: Math.random() * Math.PI * 2,
                });
            }
        };

        // Rota un punto base (unitario) y proyecta en perspectiva. Cachea en el nodo.
        const project = (n) => {
            const sx = Math.sin(rot.x), cx = Math.cos(rot.x);
            const sy = Math.sin(rot.y), cy = Math.cos(rot.y);
            const sz = Math.sin(rot.z), cz = Math.cos(rot.z);
            let x = n.bx * radius, y = n.by * radius, z = n.bz * radius;
            // X
            let y1 = y * cx - z * sx;
            let z1 = y * sx + z * cx;
            y = y1; z = z1;
            // Y
            let x1 = x * cy + z * sy;
            let z2 = -x * sy + z * cy;
            x = x1; z = z2;
            // Z
            let x2 = x * cz - y * sz;
            let y2 = x * sz + y * cz;
            x = x2; y = y2;

            const scale = fov / (fov + z);
            n.sx = width / 2 + x * scale;
            n.sy = height / 2 + y * scale;
            n.depth = z;
            n.scale = scale;
        };

        const drawNode = (n) => {
            // 0 (lejos) → 1 (cerca)
            const dz = (n.depth + radius) / (radius * 2);
            const glow = 0.5 + 0.5 * Math.sin(n.phase);
            const r = (7 + 9 * dz) * n.scale;
            const a = 0.45 + dz * 0.5;
            const coin = n.coin;

            // Halo con el color de marca de la cripto
            const grad = ctx.createRadialGradient(n.sx, n.sy, 0, n.sx, n.sy, r * 2.4);
            grad.addColorStop(0, hexA(coin.color, (0.22 + glow * 0.22) * dz));
            grad.addColorStop(1, hexA(coin.color, 0));
            ctx.fillStyle = grad;
            ctx.beginPath();
            ctx.arc(n.sx, n.sy, r * 2.4, 0, Math.PI * 2);
            ctx.fill();

            // Ficha (token) circular
            ctx.beginPath();
            ctx.arc(n.sx, n.sy, r, 0, Math.PI * 2);
            ctx.fillStyle = hexA(coin.color, a);
            ctx.fill();
            ctx.lineWidth = 1;
            ctx.strokeStyle = `rgba(255,255,255,${0.18 * dz})`;
            ctx.stroke();

            // Símbolo / logo en blanco
            const gAlpha = 0.55 + dz * 0.45;
            ctx.fillStyle = `rgba(255,255,255,${gAlpha})`;
            ctx.strokeStyle = `rgba(255,255,255,${gAlpha})`;
            if (coin.glyph && GLYPHS[coin.glyph]) {
                GLYPHS[coin.glyph](ctx, n.sx, n.sy, r);
            } else {
                ctx.font = `700 ${(r * 1.15) | 0}px "Space Grotesk", system-ui, sans-serif`;
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText(coin.char || coin.sym[0], n.sx, n.sy + r * 0.06);
                ctx.textAlign = 'left';
                ctx.textBaseline = 'alphabetic';
            }
        };

        const spawnCycle = () => {
            if (nodes.length < 3) return;
            const a = (Math.random() * nodes.length) | 0;
            const byDist = nodes
                .map((n, i) => ({ i, d: (n.bx - nodes[a].bx) ** 2 + (n.by - nodes[a].by) ** 2 + (n.bz - nodes[a].bz) ** 2 }))
                .filter((o) => o.i !== a)
                .sort((p, q) => p.d - q.d);
            if (byDist.length < 4) return;
            const b = byDist[1 + ((Math.random() * 3) | 0)].i;
            const c = byDist[1 + ((Math.random() * 3) | 0)].i;
            if (b === c) return;
            cycles.push({ tri: [a, b, c], t: 0, life: 3800, profit: Math.random() > 0.4 });
        };

        const drawCycle = (cy) => {
            const p = cy.t / cy.life;
            const fade = p < 0.12 ? p / 0.12 : p > 0.72 ? (1 - p) / 0.28 : 1;
            const a = nodes[cy.tri[0]], b = nodes[cy.tri[1]], c = nodes[cy.tri[2]];
            if (!a || !b || !c) return;
            const base = cy.profit ? TURQ : FUCHSIA;

            ctx.lineWidth = 1.4;
            ctx.strokeStyle = rgba(base, 0.28 * fade);
            ctx.beginPath();
            ctx.moveTo(a.sx, a.sy);
            ctx.lineTo(b.sx, b.sy);
            ctx.lineTo(c.sx, c.sy);
            ctx.closePath();
            ctx.stroke();

            const pts = [a, b, c, a];
            const loop = ((cy.t / cy.life) * 3) % 3;
            const seg = Math.floor(loop);
            const st = loop - seg;
            const from = pts[seg], to = pts[seg + 1];
            const px = lerp(from.sx, to.sx, st);
            const py = lerp(from.sy, to.sy, st);
            const col = mix(FUCHSIA, TURQ, (Math.sin(cy.t * 0.004) + 1) / 2);

            const grad = ctx.createRadialGradient(px, py, 0, px, py, 30);
            grad.addColorStop(0, rgba(col, 0.7 * fade));
            grad.addColorStop(1, rgba(col, 0));
            ctx.fillStyle = grad;
            ctx.beginPath();
            ctx.arc(px, py, 30, 0, Math.PI * 2);
            ctx.fill();

            ctx.beginPath();
            ctx.arc(px, py, 3, 0, Math.PI * 2);
            ctx.fillStyle = rgba(col, fade);
            ctx.fill();
        };

        const drawScene = () => {
            ctx.clearRect(0, 0, width, height);
            for (const n of nodes) project(n);

            // Aristas entre nodos cercanos en 3D
            for (let i = 0; i < nodes.length; i++) {
                for (let j = i + 1; j < nodes.length; j++) {
                    const a = nodes[i], b = nodes[j];
                    const d2 = (a.bx - b.bx) ** 2 + (a.by - b.by) ** 2 + (a.bz - b.bz) ** 2;
                    if (d2 < 0.45) {
                        const dz = ((a.depth + b.depth) / 2 + radius) / (radius * 2);
                        const al = (1 - d2 / 0.45) * 0.16 * dz;
                        ctx.strokeStyle = rgba([130, 150, 190], al);
                        ctx.lineWidth = 1;
                        ctx.beginPath();
                        ctx.moveTo(a.sx, a.sy);
                        ctx.lineTo(b.sx, b.sy);
                        ctx.stroke();
                    }
                }
            }

            // Nodos de atrás hacia adelante
            const order = nodes.slice().sort((p, q) => p.depth - q.depth);
            for (const n of order) drawNode(n);
            for (const cy of cycles) drawCycle(cy);
        };

        const frame = (now) => {
            const dt = Math.min(0.05, (now - last) / 1000);
            last = now;
            elapsed += dt * 1000;

            // Deriva aleatoria de la velocidad angular (rotación "viva")
            if (Math.random() < 0.012) {
                targetVel.x = (Math.random() - 0.5) * 0.5;
                targetVel.y = (Math.random() - 0.5) * 0.6;
                targetVel.z = (Math.random() - 0.5) * 0.18;
            }
            vel.x = lerp(vel.x, targetVel.x, 0.02);
            vel.y = lerp(vel.y, targetVel.y, 0.02);
            vel.z = lerp(vel.z, targetVel.z, 0.02);
            rot.x += vel.x * dt;
            rot.y += vel.y * dt;
            rot.z += vel.z * dt;

            for (const n of nodes) n.phase += dt * 1.6;

            if (elapsed > nextCycleAt) {
                spawnCycle();
                nextCycleAt = elapsed + 700 + Math.random() * 1300;
            }
            for (const cy of cycles) cy.t += dt * 1000;
            cycles = cycles.filter((cy) => cy.t < cy.life);

            drawScene();
            raf = requestAnimationFrame(frame);
        };

        const onVisibility = () => {
            if (document.hidden) {
                cancelAnimationFrame(raf);
            } else if (!reduced) {
                last = performance.now();
                raf = requestAnimationFrame(frame);
            }
        };

        resize();
        window.addEventListener('resize', resize);
        document.addEventListener('visibilitychange', onVisibility);

        if (reduced) {
            rot.x = 0.5; rot.y = 0.7;
            spawnCycle();
            if (cycles[0]) cycles[0].t = cycles[0].life * 0.4;
            drawScene();
        } else {
            raf = requestAnimationFrame(frame);
        }

        return () => {
            cancelAnimationFrame(raf);
            window.removeEventListener('resize', resize);
            document.removeEventListener('visibilitychange', onVisibility);
        };
    }, []);

    return <canvas ref={canvasRef} className="auth-bg" aria-hidden="true" />;
}
