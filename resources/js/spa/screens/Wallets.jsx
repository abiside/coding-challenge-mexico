/* NIFTY — Wallets: balances simulados por exchange + distribución de capital. */
import { useNifty } from '../data/store';
import { I } from '../nifty/icons';
import { deriveWallets, fmt } from '../nifty/format';

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
    const { wallets, btcPrice, trades } = useNifty();
    const { wallets: rows, totalValue, btcPrice: price } = deriveWallets(wallets, btcPrice);
    const realizedPnl = (trades || []).reduce((s, t) => s + (Number(t.realized_pnl) || 0), 0);

    const withStatus = rows.map((w) => ({ ...w, status: statusOf(w) }));

    const alerts = [];
    withStatus.forEach((x) => {
        if (x.status === 'usdt_low') alerts.push({ k: 'warn', m: `${x.ex}: USDT insuficiente para comprar — rebalanceo recomendado` });
        if (x.status === 'btc_low') alerts.push({ k: 'warn', m: `${x.ex}: BTC insuficiente para vender` });
        if (x.status === 'unbalanced') alerts.push({ k: 'err', m: `${x.ex}: wallet sin fondos` });
    });
    if (!alerts.length && withStatus.length) alerts.push({ k: 'ok', m: 'Todas las wallets dentro de los rangos configurados' });
    if (!withStatus.length) alerts.push({ k: 'warn', m: 'Sin wallets fondeadas. Configura saldos en Configuración.' });

    return (
        <div className="content">
            <div className="grid-3">
                <div className="panel mtile hud"><div className="ml">Valor total estimado</div><div className="mv">${fmt(totalValue)}</div><div className="mvsub">{withStatus.length} exchanges · BTC ${fmt(price)}</div></div>
                <div className="panel mtile"><div className="ml">P&L realizado</div><div className={'mv ' + (realizedPnl >= 0 ? 'pos' : 'neg')}>{realizedPnl >= 0 ? '+' : '−'}${fmt(Math.abs(realizedPnl))}</div><div className="mvsub">acumulado en simulación</div></div>
                <div className="panel mtile"><div className="ml">BTC de referencia</div><div className="mv">${fmt(price)}</div><div className="mvsub">mid cross-exchange</div></div>
            </div>

            <div className="col-2">
                <div className="panel">
                    <div className="panel-h"><I.wallet style={{ width: 16, height: 16, color: 'var(--turq)' }} /><h2>Balances por exchange</h2></div>
                    <div style={{ overflowX: 'auto' }}>
                        <table className="tbl">
                            <thead>
                                <tr><th>Exchange</th><th>BTC</th><th>USDT</th><th>Valor total</th><th>% capital</th><th>Estado</th></tr>
                            </thead>
                            <tbody>
                                {withStatus.length === 0 ? (
                                    <tr><td colSpan="6" className="empty-note">Sin saldos. Fondea wallets en Configuración.</td></tr>
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
                        <div className="ml" style={{ fontFamily: 'var(--mono)', fontSize: 10, letterSpacing: '0.13em', color: 'var(--tx-lo)', textTransform: 'uppercase', marginBottom: 14 }}>Distribución de capital</div>
                        <div className="dist-bar">
                            {withStatus.map((x) => <span key={x.ex} className="dist-seg" style={{ width: x.pctCapital + '%', background: x.color, opacity: 0.85 }} />)}
                        </div>
                        <div className="dist-legend">
                            {withStatus.map((x) => (
                                <span key={x.ex} className="dist-item"><span className="sw" style={{ background: x.color }} />{x.ex}<span className="dv">{x.pctCapital}%</span></span>
                            ))}
                        </div>
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
