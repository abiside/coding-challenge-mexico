/* NIFTY — Auth (login / registro) con el design system Nifty. */
import { useState } from 'react';
import { api, setToken } from '../client';
import { I } from '../nifty/icons';

export default function Auth({ onAuthenticated }) {
    const [mode, setMode] = useState('login');
    const [form, setForm] = useState({ name: '', email: '', password: '' });
    const [error, setError] = useState(null);
    const [loading, setLoading] = useState(false);

    const update = (key) => (e) => setForm({ ...form, [key]: e.target.value });

    const submit = async (e) => {
        e.preventDefault();
        setError(null);
        setLoading(true);
        try {
            const path = mode === 'login' ? '/auth/login' : '/auth/register';
            const payload = mode === 'login' ? { email: form.email, password: form.password } : form;
            const data = await api(path, { method: 'POST', body: payload });
            setToken(data.token);
            onAuthenticated();
        } catch (err) {
            setError(err.message);
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="auth-wrap">
            <div className="panel panel-pad auth-card hud">
                <div className="brand">
                    <div className="brand-mark"><I.bolt style={{ width: 16, height: 16, color: '#0a0710' }} /></div>
                    <div>
                        <div className="brand-name">Nifty</div>
                        <div className="brand-sub">Arbitrage Engine</div>
                    </div>
                </div>
                <h1 className="auth-title">{mode === 'login' ? 'Inicia sesión' : 'Crea tu cuenta'}</h1>
                <p className="auth-sub">{mode === 'login' ? 'Accede a tu consola de arbitraje simulado.' : 'Configura tu propio motor de arbitraje multi-exchange.'}</p>

                {error && <div className="form-error">{error}</div>}

                <form onSubmit={submit}>
                    {mode === 'register' && (
                        <div className="field">
                            <label>Nombre</label>
                            <input className="input" value={form.name} onChange={update('name')} required />
                        </div>
                    )}
                    <div className="field">
                        <label>Email</label>
                        <input type="email" className="input" value={form.email} onChange={update('email')} required />
                    </div>
                    <div className="field">
                        <label>Contraseña</label>
                        <input type="password" className="input" value={form.password} onChange={update('password')} required minLength={8} />
                    </div>
                    <button type="submit" className="btn primary block" disabled={loading}>
                        {loading ? 'Procesando…' : mode === 'login' ? 'Entrar' : 'Registrarme'}
                    </button>
                </form>

                <p className="auth-switch">
                    {mode === 'login' ? '¿No tienes cuenta? ' : '¿Ya tienes cuenta? '}
                    <a onClick={() => { setError(null); setMode(mode === 'login' ? 'register' : 'login'); }}>
                        {mode === 'login' ? 'Regístrate' : 'Inicia sesión'}
                    </a>
                </p>
            </div>
        </div>
    );
}
