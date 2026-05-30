/* NIFTY — Mercado: order book consolidado por exchange (datos reales de Redis). */
import { useNifty } from '../data/store';
import { I } from '../nifty/icons';
import { Lat } from '../nifty/widgets';
import { deriveMarketRows, fmt, relativeTime } from '../nifty/format';

export default function MarketScreen() {
    const { market } = useNifty();
    const rows = deriveMarketRows(market);
    const withData = rows.filter((r) => r.hasData && r.ask != null && r.bid != null);

    const bestAsk = withData.length ? withData.reduce((a, b) => (b.ask < a.ask ? b : a)) : null;
    const bestBid = withData.length ? withData.reduce((a, b) => (b.bid > a.bid ? b : a)) : null;
    const crossSpread = bestAsk && bestBid ? bestBid.bid - bestAsk.ask : null;
    const crossPct = crossSpread != null && bestAsk ? (crossSpread / bestAsk.ask) * 100 : null;
    const serverTime = market.server_time_ms;

    return (
        <div className="content">
            <div className="grid-3">
                <div className="panel mtile hud">
                    <div className="ml">Mejor precio de compra</div>
                    <div className="mv pos">{bestAsk ? '$' + fmt(bestAsk.ask) : '—'}</div>
                    <div className="mvsub">{bestAsk ? <><span style={{ color: bestAsk.color }}>●</span> {bestAsk.ex} · ask · {bestAsk.askQ != null ? bestAsk.askQ.toFixed(2) : '—'} BTC</> : 'sin datos'}</div>
                </div>
                <div className="panel mtile">
                    <div className="ml">Mejor precio de venta</div>
                    <div className="mv" style={{ color: 'var(--turq)' }}>{bestBid ? '$' + fmt(bestBid.bid) : '—'}</div>
                    <div className="mvsub">{bestBid ? <><span style={{ color: bestBid.color }}>●</span> {bestBid.ex} · bid · {bestBid.bidQ != null ? bestBid.bidQ.toFixed(2) : '—'} BTC</> : 'sin datos'}</div>
                </div>
                <div className="panel mtile" style={{ background: crossSpread > 0 ? 'linear-gradient(150deg, rgba(47,240,207,0.07), transparent 70%)' : 'transparent' }}>
                    <div className="ml">Spread cross-exchange</div>
                    <div className={'mv ' + (crossSpread > 0 ? 'pos' : 'neg')}>{crossSpread != null ? (crossSpread > 0 ? '+' : '−') + '$' + Math.abs(crossSpread).toFixed(2) : '—'}</div>
                    <div className="mvsub">{crossPct != null ? `${crossPct >= 0 ? '+' : ''}${crossPct.toFixed(3)}% · ${bestAsk.ex} → ${bestBid.ex}` : '—'}</div>
                </div>
            </div>

            <div className="panel">
                <div className="panel-h">
                    <I.market style={{ width: 16, height: 16, color: 'var(--turq)' }} />
                    <h2>Order book consolidado</h2>
                    <span className="sub">{market.symbol || 'BTC/USDT'}</span>
                    <div className="right">
                        <span className={'pill ' + (withData.length ? 'live' : 'demo')} style={{ fontSize: '10px', padding: '4px 9px' }}><span className="dot" />{withData.length ? 'en vivo' : 'sin feed'}</span>
                    </div>
                </div>
                <div style={{ overflowX: 'auto' }}>
                    <table className="tbl">
                        <thead>
                            <tr>
                                <th>Exchange</th><th>Par</th><th>Best Bid</th><th>Qty Bid</th>
                                <th>Best Ask</th><th>Qty Ask</th><th>Spread int.</th>
                                <th>Actualización</th><th>Latencia</th><th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.length === 0 ? (
                                <tr><td colSpan="10" className="empty-note">Sin datos de mercado. Levanta <span className="mono">market:feed</span>.</td></tr>
                            ) : rows.map((r) => (
                                <tr key={r.ex}>
                                    <td><span className="ex-name"><span className="ex-dot" style={{ background: r.color }} />{r.ex}</span></td>
                                    <td className="mono" style={{ color: 'var(--tx-lo)' }}>{market.symbol || 'BTC/USDT'}</td>
                                    <td className={'mono bid' + (r.bestBid ? ' best' : '')}>{r.bid != null ? '$' + fmt(r.bid) : '—'}</td>
                                    <td className="mono" style={{ color: 'var(--tx-mid)' }}>{r.bidQ != null ? r.bidQ.toFixed(2) : '—'}</td>
                                    <td className={'mono ask' + (r.bestAsk ? ' best' : '')}>{r.ask != null ? '$' + fmt(r.ask) : '—'}</td>
                                    <td className="mono" style={{ color: 'var(--tx-mid)' }}>{r.askQ != null ? r.askQ.toFixed(2) : '—'}</td>
                                    <td className="mono" style={{ color: 'var(--tx-hi)' }}>{r.bid != null && r.ask != null ? '$' + (r.ask - r.bid).toFixed(2) : '—'}</td>
                                    <td className="mono" style={{ color: r.conn === 'ok' ? 'var(--tx-mid)' : 'var(--warn)' }}>{r.lat != null && serverTime ? relativeTime(serverTime - r.lat) : '—'}</td>
                                    <td><Lat ms={r.lat} /></td>
                                    <td>
                                        <span className={'conn ' + r.conn}>
                                            <span className="d" />
                                            {r.conn === 'ok' ? 'Datos frescos' : r.conn === 'stale' ? 'Datos atrasados' : 'Sin datos'}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            <div className="sec-title"><span className="tag">Lectura</span><span className="ln" /></div>
            <div className="panel panel-pad muted" style={{ fontSize: '13px', lineHeight: 1.6 }}>
                {bestAsk && bestBid ? (
                    <>Comprar BTC más barato en <b style={{ color: 'var(--tx-hi)' }}>{bestAsk.ex}</b> (${fmt(bestAsk.ask)}) y venderlo más caro en <b style={{ color: 'var(--tx-hi)' }}>{bestBid.ex}</b> (${fmt(bestBid.bid)}) implica un spread bruto de <b className={crossSpread > 0 ? 'pos' : 'neg'}>{crossPct.toFixed(3)}%</b>. El motor aún debe descontar fees, slippage y latencia para confirmar si la oportunidad es realmente ejecutable.</>
                ) : (
                    'Esperando datos de al menos dos exchanges para calcular el spread cross-exchange.'
                )}
            </div>
        </div>
    );
}
