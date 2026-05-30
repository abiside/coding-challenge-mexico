import '../css/app.css';
import './echo';
import React, { useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';

function FrontendCommunicationApp() {
    const [health, setHealth] = useState('Pendiente');
    const [message, setMessage] = useState('');
    const [events, setEvents] = useState([]);
    const [error, setError] = useState('');

    useEffect(() => {
        fetch('/api/v1/health')
            .then((response) => response.json())
            .then((data) => setHealth(`${data.service}: OK`))
            .catch(() => setHealth('Error al consultar API'));

        const channel = window.Echo.channel('frontend-messages');
        channel.listen('.frontend.message.sent', (event) => {
            setEvents((current) => [event, ...current].slice(0, 10));
        });

        return () => {
            window.Echo.leave('frontend-messages');
        };
    }, []);

    const sendMessage = async (submitEvent) => {
        submitEvent.preventDefault();
        setError('');

        try {
            const response = await fetch('/api/v1/messages', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                },
                body: JSON.stringify({ message, source: 'react-frontend' }),
            });

            if (!response.ok) {
                throw new Error('No se pudo enviar el mensaje.');
            }

            setMessage('');
        } catch (sendError) {
            setError(sendError.message);
        }
    };

    return (
        <main style={{ maxWidth: 720, margin: '2rem auto', padding: '0 1rem' }}>
            <h1>Arquitectura base API + React + Reverb</h1>
            <p>Estado API: {health}</p>

            <form onSubmit={sendMessage} style={{ margin: '1rem 0' }}>
                <input
                    type="text"
                    value={message}
                    onChange={(event) => setMessage(event.target.value)}
                    placeholder="Escribe un mensaje"
                    maxLength={250}
                    required
                    style={{ width: '100%', padding: '0.5rem' }}
                />
                <button type="submit" style={{ marginTop: '0.5rem', padding: '0.5rem 1rem' }}>
                    Enviar al API
                </button>
            </form>

            {error && <p style={{ color: '#b91c1c' }}>{error}</p>}

            <h2>Mensajes en tiempo real</h2>
            <ul>
                {events.map((event) => (
                    <li key={event.id}>
                        <strong>{event.source}:</strong> {event.message}
                    </li>
                ))}
            </ul>
        </main>
    );
}

const rootElement = document.getElementById('app');

if (rootElement) {
    createRoot(rootElement).render(<FrontendCommunicationApp />);
}
