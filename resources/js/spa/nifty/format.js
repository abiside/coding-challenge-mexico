/* NIFTY — adaptadores de datos reales → formas que consume el diseño.
   Aquí NO se inventan datos: todo deriva de las respuestas de la API
   (/arbitrage/*) y del feed en vivo de Reverb. Lo que el backend no expone
   se deja en null y la UI lo muestra como "—". */

const EX_LABEL = {
    binance: 'Binance', kraken: 'Kraken', coinbase: 'Coinbase',
    bybit: 'Bybit', okx: 'OKX', bitget: 'Bitget', bitfinex: 'Bitfinex',
};

const EX_COLOR = {
    binance: '#f3ba2f', kraken: '#7df6e3', coinbase: '#2f7bff',
    bybit: '#ffae34', okx: '#7c5cff', bitget: '#00c9a7', bitfinex: '#8fd14f',
};

export function exLabel(ex) {
    if (!ex) return '—';
    const key = String(ex).toLowerCase();
    return EX_LABEL[key] || (ex.charAt(0).toUpperCase() + ex.slice(1));
}

/* Razones del embudo de descartes (scanner + engine). Las claves vienen del
   backend (MetricsAggregator.discards). Lo desconocido se muestra tal cual. */
const DISCARD_LABEL = {
    not_crossed: 'Sin spread cruzado',
    no_best_quote: 'Sin mejor bid/ask',
    other_no_liquidity: 'Contraparte sin liquidez',
    updated_stale: 'Book disparador stale',
    updated_no_liquidity: 'Book disparador sin liquidez',
    not_executable: 'Sin volumen ejecutable',
    'risk:low_net_profit': 'Profit neto bajo',
    'risk:low_net_margin': 'Margen neto bajo',
    'risk:book_stale': 'Book stale (riesgo)',
    'risk:high_latency': 'Latencia alta',
    'risk:insufficient_volume': 'Volumen insuficiente',
    'risk:insufficient_balance': 'Balance insuficiente',
    'risk:circuit_breaker_open': 'Circuit breaker abierto',
};

export function discardLabel(key) {
    if (!key) return '—';
    return DISCARD_LABEL[key] || String(key);
}

export function exColor(ex) {
    const key = String(ex || '').toLowerCase();
    return EX_COLOR[key] || '#8b93b0';
}

export function fmt(n, d = 2) {
    if (n == null || Number.isNaN(n)) return '—';
    return Number(n).toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d });
}

export function fmtCompact(n) {
    const v = Math.abs(Number(n) || 0);
    if (v >= 1e6) return '$' + (n / 1e6).toFixed(2) + 'M';
    if (v >= 1e3) return '$' + (n / 1e3).toFixed(1) + 'K';
    return '$' + fmt(n, 2);
}

export function signedMoney(n) {
    const v = Number(n) || 0;
    return (v >= 0 ? '+' : '−') + '$' + fmt(Math.abs(v), 2);
}

const STAGES = [
    'Order book capturado',
    'Validando frescura del book',
    'Calculando fees + slippage',
    'Evaluación de risk manager',
];

export function stageLabel(p) {
    if (p < 25) return STAGES[0];
    if (p < 55) return STAGES[1];
    if (p < 82) return STAGES[2];
    return STAGES[3];
}

export function timeFromMs(ms) {
    const d = ms ? new Date(ms) : new Date();
    return d.toTimeString().slice(0, 8);
}

function pad(n) {
    return String(n).padStart(2, '0');
}

export function relativeTime(ms) {
    if (!ms) return '—';
    const diff = Math.max(0, Date.now() - ms);
    if (diff < 1000) return 'ahora';
    if (diff < 60000) return 'hace ' + (diff / 1000).toFixed(1) + 's';
    if (diff < 3600000) return 'hace ' + Math.floor(diff / 60000) + 'm';
    return 'hace ' + Math.floor(diff / 3600000) + 'h';
}

const STATUS_FROM_DECISION = {
    execute: { status: 'exec', label: 'Ejecutada' },
    reject: { status: 'reject', label: 'Rechazada' },
    ignore: { status: 'expired', label: 'Ignorada' },
};

/* --- Normaliza una oportunidad (live payload Reverb o fila histórica) --- */
export function normalizeOpportunity(input) {
    // Live payload: { decision, reasons, opportunity:{...}, simulation:{...}, published_at }
    // History row : { id, decision, reasons, ...campos planos, created_at }
    const isLive = !!input.opportunity;
    const op = isLive ? input.opportunity : input;
    const sim = isLive ? input.simulation : null;
    const decision = input.decision || op.decision || 'reject';
    const reasons = Array.isArray(input.reasons) ? input.reasons : (Array.isArray(op.reasons) ? op.reasons : []);
    const map = STATUS_FROM_DECISION[decision] || STATUS_FROM_DECISION.reject;

    const vol = Number(op.base_volume) || 0;
    const buyPrice = Number(op.weighted_buy_price ?? op.buy_ask) || 0;
    const sellPrice = Number(op.weighted_sell_price ?? op.sell_bid) || 0;
    const grossPct = (Number(op.gross_spread_bps) || 0) / 100;
    const netPct = (Number(op.net_margin) || 0) * 100;
    const net = Number(op.net_profit) || 0;
    const gross = Number(op.gross_profit) || 0;
    const totalCosts = Number(op.total_costs) || 0;

    const buyNotional = sim?.buy_fill?.notional ?? (buyPrice * vol);
    const sellGross = sim?.sell_fill?.notional ?? (sellPrice * vol);

    // Desglose de costos: preferimos los campos del payload de la oportunidad
    // (presentes para execute y reject), con fallback a los fills simulados.
    const num = (v) => (v == null || v === '' ? null : Number(v));
    const buyAsk = num(op.buy_ask);
    const sellBid = num(op.sell_bid);
    const buyFee = num(op.buy_fee) ?? num(sim?.buy_fill?.fee);
    const sellFee = num(op.sell_fee) ?? num(sim?.sell_fill?.fee);
    const slippage = num(op.slippage_cost);
    const latency = num(op.latency_penalty);
    const fixedCost = num(op.fixed_cost);
    const theoreticalGross = num(op.theoretical_gross_profit)
        ?? (buyAsk != null && sellBid != null ? (sellBid - buyAsk) * vol : null);
    // Suma de costos que erosionan el spread teórico hasta el neto.
    const costParts = [slippage, buyFee, sellFee, latency, fixedCost].filter((c) => c != null);
    const breakdownTotal = costParts.length ? costParts.reduce((s, c) => s + c, 0) : null;
    const otherCosts = buyFee != null && sellFee != null ? Math.max(0, totalCosts - buyFee - sellFee) : null;

    // Resultado realmente ejecutado (modo simulación con slippage): preferimos
    // el campo persistido en la oportunidad y caemos al fill simulado en vivo.
    const realizedPnl = num(op.realized_pnl) ?? num(sim?.realized_pnl);
    const executionDelta = num(op.execution_delta)
        ?? (realizedPnl != null ? realizedPnl - net : null);

    const detectedMs = Number(op.detected_at_ms) || (input.published_at ? Date.parse(input.published_at) : null) || (input.created_at ? Date.parse(input.created_at) : Date.now());

    const reasonText = reasons.length ? reasons.join(' · ') : (
        decision === 'execute' ? 'Profit neto sobre umbral'
            : decision === 'ignore' ? 'Sin spread cruzado suficiente'
                : 'Profit neto insuficiente tras costos'
    );

    // Ladder simplificada: el nivel VWAP realmente usado (no tenemos niveles
    // crudos del order book en el payload procesado).
    const books = {
        buy: vol > 0 ? [{ p: buyPrice, avail: vol, used: vol }] : [],
        sell: vol > 0 ? [{ p: sellPrice, avail: vol, used: vol }] : [],
    };

    return {
        id: input.id ?? detectedMs,
        pair: op.symbol || 'BTC/USDT',
        time: timeFromMs(detectedMs),
        buy: exLabel(op.buy_exchange),
        sell: exLabel(op.sell_exchange),
        grossPct,
        netPct,
        vol: Number(vol.toFixed(4)),
        buyPrice,
        sellPrice,
        profit: Number(net.toFixed(2)),
        shownEst: Number(gross.toFixed(2)),
        status: map.status,
        statusLabel: map.label,
        reason: reasonText,
        progress: 100,
        decision: reasonText,
        rule: 'RISK MANAGER · ' + decision.toUpperCase() + (reasons.length ? ' · ' + reasons[0] : ''),
        partial: !!op.partial_fill,
        fin: {
            grossCost: buyNotional,
            sellGross,
            buyFee,
            sellFee,
            slippage,
            latency,
            fixedCost,
            theoreticalGross,
            breakdownTotal,
            otherCosts,
            totalCosts,
            gross,
            net,
            realized: realizedPnl,
            executionDelta,
        },
        books,
        _flashAt: input._flashAt,
    };
}

/* --- KPIs del dashboard a partir de trades reales --- */
function tradeVolumeQuote(t) {
    const fills = t.fills || [];
    const buy = fills.find((f) => f.side === 'buy');
    if (buy && buy.notional != null) return Number(buy.notional);
    return 0;
}

function isToday(dateStr) {
    if (!dateStr) return false;
    const d = new Date(dateStr);
    const now = new Date();
    return d.getFullYear() === now.getFullYear() && d.getMonth() === now.getMonth() && d.getDate() === now.getDate();
}

export function deriveKpis(trades) {
    const list = trades || [];
    const pnlAll = list.reduce((s, t) => s + (Number(t.realized_pnl) || 0), 0);
    const today = list.filter((t) => isToday(t.created_at));
    const pnlDay = today.reduce((s, t) => s + (Number(t.realized_pnl) || 0), 0);
    const wins = list.filter((t) => Number(t.realized_pnl) > 0).length;
    const winRate = list.length ? (wins / list.length) * 100 : 0;
    const volume = list.reduce((s, t) => s + tradeVolumeQuote(t), 0);

    return {
        pnl: { value: signedMoney(pnlAll), raw: pnlAll, detail: `${list.length} ops`, dir: pnlAll >= 0 ? 'up' : 'down' },
        day: { value: signedMoney(pnlDay), raw: pnlDay, detail: `${today.length} ops hoy`, dir: pnlDay >= 0 ? 'up' : 'down' },
        winRate: { value: winRate.toFixed(1) + '%', detail: `${wins} / ${list.length} trades` },
        volume: { value: volume > 0 ? fmtCompact(volume) : '$0', detail: `${list.length} ops` },
    };
}

// Win rate móvil sobre los trades cronológicos: por cada operación calcula el
// porcentaje de aciertos en la ventana deslizante previa. Alimenta el sparkline
// del KPI de win rate para mostrar la tendencia, no solo el valor agregado.
export function deriveWinRateSpark(trades, win = 12) {
    const chrono = [...(trades || [])]
        .map((t) => ({
            ts: t.executed_at_ms || (t.created_at ? Date.parse(t.created_at) : 0),
            w: (Number(t.realized_pnl) || 0) > 0 ? 1 : 0,
        }))
        .sort((a, b) => a.ts - b.ts);
    if (chrono.length < 2) return [];

    const out = [];
    for (let i = 0; i < chrono.length; i++) {
        const start = Math.max(0, i - win + 1);
        let wins = 0;
        let count = 0;
        for (let j = start; j <= i; j++) {
            wins += chrono[j].w;
            count++;
        }
        out.push(Number(((wins / count) * 100).toFixed(1)));
    }
    return out.slice(-28);
}

/* --- Serie acumulada de P&L por ventana de tiempo --- */
const TF_WINDOW_MS = { h24: 3600e3, day: 86400e3, week: 604800e3 };

// Serie de P&L acumulado con marcas de tiempo por punto, para alimentar la
// gráfica grande con ejes (X temporal) y tooltips. `step` es el P&L aportado
// por la operación que generó ese punto (útil para el indicador puntual).
export function deriveChartSeries(trades, tf) {
    const windowMs = TF_WINDOW_MS[tf] || TF_WINDOW_MS.week;
    const now = Date.now();
    const sorted = [...(trades || [])]
        .map((t) => ({ ts: t.executed_at_ms || (t.created_at ? Date.parse(t.created_at) : now), pnl: Number(t.realized_pnl) || 0 }))
        .sort((a, b) => a.ts - b.ts);

    // Base acumulada anterior a la ventana, para no perder el offset.
    let base = 0;
    const inWindow = [];
    for (const row of sorted) {
        if (row.ts < now - windowMs) base += row.pnl;
        else inWindow.push(row);
    }

    const windowStart = now - windowMs;
    const values = [base];
    const times = [windowStart];
    const steps = [0];
    let acc = base;
    for (const row of inWindow) {
        acc += row.pnl;
        values.push(Number(acc.toFixed(2)));
        times.push(row.ts);
        steps.push(Number(row.pnl.toFixed(2)));
    }
    // Ancla el borde derecho al presente: el P&L se mantiene plano desde la
    // última operación hasta "ahora". Así el eje X cubre exactamente la ventana
    // seleccionada [windowStart, now] y los tiempos son proporcionales.
    if (times[times.length - 1] < now) {
        values.push(values[values.length - 1]);
        times.push(now);
        steps.push(0);
    }
    return { values, times, steps, windowMs, domain: [windowStart, now] };
}

export function deriveChart(trades, tf) {
    return deriveChartSeries(trades, tf).values;
}

export function windowTotal(trades, tf) {
    const windowMs = TF_WINDOW_MS[tf] || TF_WINDOW_MS.week;
    const now = Date.now();
    return (trades || []).reduce((s, t) => {
        const ts = t.executed_at_ms || (t.created_at ? Date.parse(t.created_at) : now);
        return ts >= now - windowMs ? s + (Number(t.realized_pnl) || 0) : s;
    }, 0);
}

/* --- Métricas de rendimiento --- */
export function derivePerf(trades, opportunities) {
    const list = trades || [];
    const opps = opportunities || [];
    const nets = list.map((t) => Number(t.realized_pnl) || 0);
    const wins = nets.filter((n) => n > 0);
    const losses = nets.filter((n) => n < 0);
    const grossWin = wins.reduce((s, n) => s + n, 0);
    const grossLoss = Math.abs(losses.reduce((s, n) => s + n, 0));
    const pnlAcc = nets.reduce((s, n) => s + n, 0);
    const pnlDay = list.filter((t) => isToday(t.created_at)).reduce((s, t) => s + (Number(t.realized_pnl) || 0), 0);

    const byExMap = {};
    const byDirMap = {};
    for (const t of list) {
        const pnl = Number(t.realized_pnl) || 0;
        const buy = exLabel(t.buy_exchange);
        const sell = exLabel(t.sell_exchange);
        byExMap[buy] = (byExMap[buy] || 0) + pnl;
        const route = `${buy} → ${sell}`;
        byDirMap[route] = (byDirMap[route] || 0) + pnl;
    }
    const byExchange = Object.entries(byExMap).map(([ex, pnl]) => ({ ex, pnl })).sort((a, b) => b.pnl - a.pnl);
    const byDirection = Object.entries(byDirMap).map(([route, pnl]) => ({ route, pnl })).sort((a, b) => b.pnl - a.pnl).slice(0, 6);

    const chrono = [...list].sort((a, b) => (a.executed_at_ms || 0) - (b.executed_at_ms || 0));
    const profitPerOp = chrono.slice(-34).map((t) => Number((Number(t.realized_pnl) || 0).toFixed(2)));

    const hourlyVolume = new Array(24).fill(0);
    for (const t of list) {
        const ts = t.executed_at_ms || (t.created_at ? Date.parse(t.created_at) : null);
        if (!ts) continue;
        hourlyVolume[new Date(ts).getHours()] += tradeVolumeQuote(t);
    }

    const detected = opps.length;
    const executed = opps.filter((o) => o.decision === 'execute').length;
    const rejected = opps.filter((o) => o.decision === 'reject').length;
    const expired = opps.filter((o) => o.decision === 'ignore').length;

    const rejReasons = {};
    for (const o of opps) {
        if (o.decision !== 'reject') continue;
        const r = Array.isArray(o.reasons) && o.reasons.length ? o.reasons[0] : 'profit insuficiente';
        rejReasons[r] = (rejReasons[r] || 0) + 1;
    }
    const mainReject = Object.entries(rejReasons).sort((a, b) => b[1] - a[1])[0]?.[0] || '—';

    const avgGross = opps.length ? opps.reduce((s, o) => s + (Number(o.gross_spread_bps) || 0) / 100, 0) / opps.length : 0;
    const avgNet = opps.length ? opps.reduce((s, o) => s + (Number(o.net_margin) || 0) * 100, 0) / opps.length : 0;

    return {
        pnlAcc, pnlDay,
        winRate: list.length ? +((wins.length / list.length) * 100).toFixed(1) : 0,
        profitFactor: grossLoss > 0 ? grossWin / grossLoss : grossWin,
        profitAvg: wins.length ? grossWin / wins.length : 0,
        lossAvg: losses.length ? -(grossLoss / losses.length) : 0,
        best: nets.length ? Math.max(...nets) : 0,
        worst: nets.length ? Math.min(...nets) : 0,
        avgGross, avgNet,
        rejectPct: detected ? Math.round((rejected / detected) * 100) : 0,
        mainReject,
        detected, executed: list.length, rejected, expired,
        byExchange, byDirection, profitPerOp, hourlyVolume,
        totalTrades: list.length,
    };
}

/* --- Filas de la tabla de mercado --- */
export function deriveMarketRows(market) {
    const rows = (market?.rows) || [];
    return rows.map((r) => ({
        ex: exLabel(r.exchange),
        rawEx: r.exchange,
        color: exColor(r.exchange),
        bid: r.bid,
        ask: r.ask,
        bidQ: r.bid_qty,
        askQ: r.ask_qty,
        spread: r.spread,
        lat: r.age_ms,
        conn: r.conn,
        bestBid: !!r.best_bid,
        bestAsk: !!r.best_ask,
        hasData: r.has_data,
    }));
}

/* --- Wallets agrupadas por exchange (BTC + USDT) --- */
export function deriveWallets(walletData, btcPrice) {
    const byEx = {};
    for (const w of walletData || []) {
        const ex = w.exchange;
        byEx[ex] = byEx[ex] || { ex, btc: 0, usdt: 0 };
        const amount = Number(w.available) || 0;
        if (String(w.asset).toUpperCase() === 'BTC') byEx[ex].btc += amount;
        else if (String(w.asset).toUpperCase() === 'USDT') byEx[ex].usdt += amount;
        else byEx[ex].usdt += amount; // otros assets se cuentan como quote aprox.
    }
    const price = btcPrice || 0;
    const wallets = Object.values(byEx).map((w) => {
        const value = w.btc * price + w.usdt;
        return {
            ex: exLabel(w.ex),
            rawEx: w.ex,
            color: exColor(w.ex),
            btc: w.btc,
            usdt: w.usdt,
            value,
        };
    });
    const totalValue = wallets.reduce((s, w) => s + w.value, 0) || 1;
    wallets.forEach((w) => { w.pctCapital = +((w.value / totalValue) * 100).toFixed(1); });
    return { wallets, totalValue: wallets.reduce((s, w) => s + w.value, 0), btcPrice: price };
}
