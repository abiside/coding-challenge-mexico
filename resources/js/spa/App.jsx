import { useEffect, useState } from 'react';
import { api, getToken, setToken } from './client';
import { resetEcho } from './realtime';
import { NiftyProvider } from './data/store';
import { I } from './nifty/icons';
import Auth from './pages/Auth';
import Onboarding from './pages/Onboarding';
import AppShell from './AppShell';

export default function App() {
    // view: loading | auth | onboarding | app
    const [view, setView] = useState('loading');
    const [user, setUser] = useState(null);

    const bootstrap = async () => {
        if (!getToken()) {
            setView('auth');
            return;
        }
        try {
            const me = await api('/auth/me');
            setUser(me.user);
            setView(me.onboarded ? 'app' : 'onboarding');
        } catch {
            setToken(null);
            setView('auth');
        }
    };

    useEffect(() => { bootstrap(); }, []);

    const logout = async () => {
        try { await api('/auth/logout', { method: 'POST' }); } catch { /* noop */ }
        setToken(null);
        resetEcho();
        setUser(null);
        setView('auth');
    };

    if (view === 'loading') {
        return (
            <div className="loading-screen">
                <div className="brand-mark" style={{ background: 'var(--accent)', display: 'grid', placeItems: 'center' }}>
                    <I.bolt style={{ width: 22, height: 22, color: '#0a0710' }} />
                </div>
                Cargando consola…
            </div>
        );
    }

    if (view === 'auth') {
        return <Auth onAuthenticated={bootstrap} />;
    }

    if (view === 'onboarding') {
        return <Onboarding onDone={() => setView('app')} onLogout={logout} />;
    }

    return (
        <NiftyProvider user={user}>
            <AppShell user={user} onLogout={logout} />
        </NiftyProvider>
    );
}
