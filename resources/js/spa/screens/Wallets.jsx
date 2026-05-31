/* NIFTY — Wallets (global): capital consolidado de TODAS las estrategias.
   • Billeteras de trading: caja USDT + valor invertido + equity por instancia.
   • Arbitraje cross-exchange: balances simulados por exchange + distribución. */
import { useNifty } from '../data/store';
import { I } from '../nifty/icons';
import { deriveWallets, fmt, signedMoney } from '../nifty/format';
import { Th } from '../nifty/widgets';
import { InfoTip } from '../nifty/InfoTip';

const WSTAT = {
    ok: { c: 'ok', t: 'Balanceada' },
    usdt_low: { c: 'warn', t: 'USDT bajo' },
    btc_low: { c: 'warn', t: 'BTC bajo' },
    unbalanced: { c: 'err', t: 'Desbalanceada' },
};

function statusOf(w) {
    if (w.btc <= 0 && w.usdt <= 0) return 'unbalanced';
    if (w.usdt < 100) return 'usdt_low';
    if (w.btc < 0.001) return 'btc_low';
    return 'ok';
}

export default function WalletsScreen() {
    const { wallets, btcPrice, trades, strategies, strategyLive } = useNifty();
    const { wallets: rows, totalValue, btcPrice: price } = deriveWallets(wallets, btcPrice);
    const arbRealized = (trades || []).reduce((s, t) => s + (Number(t.realized_pnl) || 0), 0);

    const withStatus = rows.map((w) => ({ ...w, status: statusOf(w) }));

    // Billeteras USDT de las estrategias de trading (una caja simulada por instancia).
    const tradingWallets = (strategies?.data || [])
        .filter((s) => s.type === 'trading')
        .map((s) => {
            const m = strategyLive?.[s.id] || s.metrics || {};
            return {
                id: s.id,
                name: s.name,
                active: s.active,
                usdt: Number(m.usdt_balance) || 0,
                deployed: Number(m.deployed_value) || 0,
                equity: Number(m.equity_value) || 0,
                unrealized: Number(m.unrealized_pnl) || 0,
                realized: Number(m.realized_pnl) || 0,
                open: m.open_positions ?? 0,
                running: !!(strategyLive?.[s.id] || s.metrics),
            };
        });

    const tradingEquity = tradingWallets.reduce((s, w) => s + w.equity, 0);
    const tradingRealized = tradingWallets.reduce((s, w) => s + w.realized, 0);
    const totalCapital = totalValue + tradingEquity;
    const totalRealized = arbRealized + tradingRealized;
    const walletCount = withStatus.length + tradingWallets.length;

    const alerts = [];
    withStatus.forEach((x) => {
        if (x.status === 'usdt_low') alerts.push({ k: 'warn', m: `${x.ex}: USDT insuficiente para comprar — rebalanceo recomendado` });
        if (x.status === 'btc_low') alerts.push({ k: 'warn', m: `${x.ex}: BTC insuficiente para vender` });
        if (x.status === 'unbalanced') alerts.push({ k: 'err', m: `${x.ex}: wallet sin fondos` });
    });
    if (!alerts.length && withStatus.length) alerts.push({ k: 'ok', m: 'Todas las wallets de arbitraje dentro de los rangos configurados' });
    if (!withStatus.length) alerts.push({ k: 'warn', m: 'Sin wallets de arbitraje fondeadas. Configura saldos en la estrategia cross-exchange.' });

    return (
        <div className="content">
            <div className="grid-3">
                <div className="panel mtile hud"><div className="ml">Capital total consolidado<InfoTip g="valor_total" /></div><div className="mv">${fmt(totalCapital)}</div><div className="mvsub">{walletCount} billeteras · {tradingWallets.length} trading + {withStatus.length} exchanges</div></div>
                <div className="panel mtile"><div className="ml">P&L realizado total<InfoTip g="pnl_realizado" /></div><div className={'mv ' + (totalRealized >= 0 ? 'pos' : 'neg')}>{signedMoney(totalRealized)}</div><div className="mvsub">arbitraje + trading · simulación</div></div>
                <div className="panel mtile"><div className="ml">Equity en trading<InfoTip g="equity_meanrev" /></div><div className="mv">${fmt(tradingEquity)}</div><div className="mvsub">{tradingWallets.length ? tradingWallets.length + ' estrategias' : 'sin estrategias de trading'}</div></div>
            </div>

            {tradingWallets.length > 0 && (
                <div className="panel">
                    <div className="panel-h"><I.vol style={{ width: 16, height: 16, color: 'var(--accent)' }} /><h2>Billeteras de trading (USDT)</h2></div>
                    <div style={{ overflowX: 'auto' }}>
                        <table className="tbl">
                            <thead>
                                <tr><th>Estrategia</th><Th info="slice_usdt">Caja USDT</Th><th>Invertido</th><Th info="equity_meanrev">Equity</Th><Th info="pnl_no_realizado">P&L no real.</Th><th>Posiciones</th><th>Estado</th></tr>
                            </thead>
                            <tbody>
                                {tradingWallets.map((w) => (
                                    <tr key={w.id}>
                                        <td style={{ fontWeight: 600 }}>{w.name}</td>
                                        <td className="mono" style={{ color: 'var(--tx-hi)' }}>${fmt(w.usdt)}</td>
                                        <td className="mono" style={{ color: 'var(--tx-mid)' }}>${fmt(w.deployed)}</td>
                                        <td className="mono" style={{ color: 'var(--tx-hi)' }}>${fmt(w.equity)}</td>
                                        <td className={'mono ' + (w.unrealized >= 0 ? 'pos' : 'neg')}>{signedMoney(w.unrealized)}</td>
                                        <td className="mono" style={{ color: 'var(--tx-mid)' }}>{w.open}</td>
                                        <td><span className={'wstat ' + (w.active ? 'ok' : 'warn')}>● {w.active ? (w.running ? 'Activa' : 'Calentando') : 'Detenida'}</span></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            <div className="col-2">
                <div className="panel">
                    <div className="panel-h"><I.opp style={{ width: 16, height: 16, color: 'var(--turq)' }} /><h2>Arbitraje · balances por exchange</h2></div>
                    <div style={{ overflowX: 'auto' }}>
                        <table className="tbl">
                            <thead>
                                <tr><th>Exchange</th><th>BTC</th><th>USDT</th><Th info="valor_total">Valor total</Th><Th info="pct_capital">% capital</Th><Th info="estado_wallet">Estado</Th></tr>
                            </thead>
                            <tbody>
                                {withStatus.length === 0 ? (
                                    <tr><td colSpan="6" className="empty-note">Sin saldos. Fondea wallets en la configuración del arbitraje.</td></tr>
                                ) : withStatus.map((x) => (
                                    <tr key={x.ex}>
                                        <td><span className="ex-name"><span className="ex-dot" style={{ background: x.color }} />{x.ex}</span></td>
                                        <td className="mono" style={{ color: 'var(--tx-hi)' }}>{x.btc.toFixed(4)}</td>
                                        <td className="mono" style={{ color: 'var(--tx-hi)' }}>${fmt(x.usdt)}</td>
                                        <td className="mono" style={{ color: 'var(--tx-hi)' }}>${fmt(x.value)}</td>
                                        <td className="mono" style={{ color: 'var(--tx-mid)' }}>{x.pctCapital}%</td>
                                        <td><span className={'wstat ' + WSTAT[x.status].c}>● {WSTAT[x.status].t}</span></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>

                <div style={{ display: 'flex', flexDirection: 'column', gap: 22 }}>
                    <div className="panel panel-pad">
                        <div className="ml" style={{ fontFamily: 'var(--mono)', fontSize: 10, letterSpacing: '0.13em', color: 'var(--tx-lo)', textTransform: 'uppercase', marginBottom: 14 }}>Distribución de capital (arbitraje)</div>
                        <div className="dist-bar">
                            {withStatus.map((x) => <span key={x.ex} className="dist-seg" style={{ width: x.pctCapital + '%', background: x.color, opacity: 0.85 }} />)}
                        </div>
                        <div className="dist-legend">
                            {withStatus.map((x) => (
                                <span key={x.ex} className="dist-item"><span className="sw" style={{ background: x.color }} />{x.ex}<span className="dv">{x.pctCapital}%</span></span>
                            ))}
                        </div>
                        <div className="muted" style={{ fontSize: 11.5, marginTop: 12 }}>BTC de referencia: ${fmt(price)} · mid cross-exchange</div>
                    </div>

                    <div className="panel panel-pad">
                        <div className="ml" style={{ fontFamily: 'var(--mono)', fontSize: 10, letterSpacing: '0.13em', color: 'var(--tx-lo)', textTransform: 'uppercase', marginBottom: 14 }}>Alertas de balance</div>
                        {alerts.map((a, i) => (
                            <div key={i} className={'alert ' + a.k}><span className="ad" />{a.m}</div>
                        ))}
                    </div>
                </div>
            </div>
        </div>
    );
}
