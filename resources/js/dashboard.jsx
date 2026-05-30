import '../css/app.css';
import './echo';
import React, { useEffect, useState, useCallback } from 'react';
import { createRoot } from 'react-dom/client';

const CHANNEL = 'arbitrage-dashboard';
const EVENT = '.arbitrage.opportunity.processed';

async function getJson(url) {
    const response = await fetch(url, { headers: { Accept: 'application/json' } });
    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }
    return response.json();
}

/**
 * Hook de presentación: trae estado inicial vía REST y escucha actualizaciones
 * por Reverb. NO calcula arbitraje; solo recibe estado ya procesado.
 */
function useArbitrageFeed() {
    const [live, setLive] = useState([]);
    const [wallets, setWallets] = useState([]);
    const [trades, setTrades] = useState([]);
    const [pnl, setPnl] = useState(0);
    const [connected, setConnected] = useState(false);

    const refresh = useCallback(async () => {
        try {
            const [walletsRes, tradesRes] = await Promise.all([
                getJson('/api/v1/arbitrage/wallets'),
                getJson('/api/v1/arbitrage/trades'),
            ]);
            setWallets(walletsRes.data ?? []);
            setTrades(tradesRes.data ?? []);
            setPnl(tradesRes.realized_pnl_total ?? 0);
        } catch (e) {
            // Silencioso: el dashboard sigue mostrando lo que tenga.
        }
    }, []);

    useEffect(() => {
        refresh();

        getJson('/api/v1/arbitrage')
            .then((data) => {
                const initial = Object.values(data.snapshots ?? {});
                setLive(initial);
            })
            .catch(() => {});

        const channel = window.Echo.channel(CHANNEL);
        setConnected(true);
        channel.listen(EVENT, (payload) => {
            setLive((current) => [payload, ...current].slice(0, 25));
            if (payload.decision === 'execute') {
                refresh();
            }
        });

        return () => {
            window.Echo.leave(CHANNEL);
        };
    }, [refresh]);

    return { live, wallets, trades, pnl, connected };
}

function DecisionBadge({ decision }) {
    const colors = {
        execute: '#16a34a',
        reject: '#dc2626',
        ignore: '#6b7280',
    };
    return (
        <span style={{
            background: colors[decision] ?? '#6b7280',
            color: 'white',
            padding: '2px 8px',
            borderRadius: 6,
            fontSize: 12,
            textTransform: 'uppercase',
        }}>{decision}</span>
    );
}

function OpportunityRow({ item }) {
    const opp = item.opportunity ?? {};
    return (
        <tr>
            <td><DecisionBadge decision={item.decision} /></td>
            <td>{opp.symbol}</td>
            <td>{opp.buy_exchange} → {opp.sell_exchange}</td>
            <td>{Number(opp.buy_ask).toLocaleString()} / {Number(opp.sell_bid).toLocaleString()}</td>
            <td>{Number(opp.gross_spread_bps).toFixed(2)} bps</td>
            <td>{Number(opp.base_volume).toFixed(6)}</td>
            <td style={{ color: Number(opp.net_profit) >= 0 ? '#16a34a' : '#dc2626' }}>
                {Number(opp.net_profit).toFixed(4)}
            </td>
        </tr>
    );
}

function ArbitrageDashboard() {
    const { live, wallets, trades, pnl, connected } = useArbitrageFeed();

    return (
        <main style={{ maxWidth: 1100, margin: '2rem auto', padding: '0 1rem', fontFamily: 'system-ui, sans-serif' }}>
            <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <h1 style={{ margin: 0 }}>Arbitrage Dashboard</h1>
                <span style={{ fontSize: 13, color: connected ? '#16a34a' : '#6b7280' }}>
                    {connected ? '● en vivo (Reverb)' : '○ desconectado'}
                </span>
            </header>
            <p style={{ color: '#374151' }}>
                P&amp;L simulado acumulado: <strong>{Number(pnl).toFixed(4)}</strong>
            </p>

            <section>
                <h2>Oportunidades en tiempo real</h2>
                <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 14 }}>
                    <thead>
                        <tr style={{ textAlign: 'left', borderBottom: '2px solid #e5e7eb' }}>
                            <th>Decisión</th><th>Símbolo</th><th>Ruta</th>
                            <th>Buy / Sell</th><th>Spread</th><th>Vol</th><th>Net P&amp;L</th>
                        </tr>
                    </thead>
                    <tbody>
                        {live.length === 0 && (
                            <tr><td colSpan={7} style={{ color: '#6b7280', padding: '1rem 0' }}>
                                Esperando datos del engine (arbitrage:run)...
                            </td></tr>
                        )}
                        {live.map((item, idx) => (
                            <OpportunityRow key={item.opportunity?.detected_at_ms ?? idx} item={item} />
                        ))}
                    </tbody>
                </table>
            </section>

            <section style={{ display: 'flex', gap: '2rem', marginTop: '2rem', flexWrap: 'wrap' }}>
                <div style={{ flex: 1, minWidth: 320 }}>
                    <h2>Balances</h2>
                    <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 14 }}>
                        <thead>
                            <tr style={{ textAlign: 'left', borderBottom: '2px solid #e5e7eb' }}>
                                <th>Exchange</th><th>Asset</th><th>Disponible</th>
                            </tr>
                        </thead>
                        <tbody>
                            {wallets.map((w) => (
                                <tr key={`${w.exchange}-${w.asset}`}>
                                    <td>{w.exchange}</td>
                                    <td>{w.asset}</td>
                                    <td>{Number(w.available).toLocaleString()}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div style={{ flex: 1, minWidth: 320 }}>
                    <h2>Trades simulados</h2>
                    <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 14 }}>
                        <thead>
                            <tr style={{ textAlign: 'left', borderBottom: '2px solid #e5e7eb' }}>
                                <th>Símbolo</th><th>Ruta</th><th>Vol</th><th>P&amp;L</th>
                            </tr>
                        </thead>
                        <tbody>
                            {trades.map((t) => (
                                <tr key={t.id}>
                                    <td>{t.symbol}</td>
                                    <td>{t.buy_exchange} → {t.sell_exchange}</td>
                                    <td>{Number(t.base_volume).toFixed(6)}</td>
                                    <td style={{ color: Number(t.realized_pnl) >= 0 ? '#16a34a' : '#dc2626' }}>
                                        {Number(t.realized_pnl).toFixed(4)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    );
}

const rootElement = document.getElementById('dashboard');
if (rootElement) {
    createRoot(rootElement).render(<ArbitrageDashboard />);
}
