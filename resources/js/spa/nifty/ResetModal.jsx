/* NIFTY — modal de confirmación para reiniciar el proceso (acción destructiva).
   Borra toda la data de transacciones y los challengers/champion del usuario y
   restaura las wallets. Se usa desde Configuración y desde el Engine. */
import { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { useNifty } from '../data/store';

function WarnIcon(p) {
    return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" {...p}>
            <path d="M10.3 3.6 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.6a2 2 0 0 0-3.4 0z" />
            <path d="M12 9v4M12 17h.01" />
        </svg>
    );
}

export function ResetProcessModal({ open, onClose }) {
    const { actions } = useNifty();
    const [working, setWorking] = useState(false);
    const [err, setErr] = useState(null);

    useEffect(() => {
        if (!open) return undefined;
        const onKey = (e) => { if (e.key === 'Escape' && !working) onClose(false); };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [open, working, onClose]);

    if (!open) return null;

    const close = (ok) => { if (!working) onClose(ok === true); };

    const confirm = async () => {
        setWorking(true);
        setErr(null);
        try {
            await actions.resetProcess();
            onClose(true);
        } catch (e) {
            setErr(e.message || 'No se pudo reiniciar el proceso.');
        } finally {
            setWorking(false);
        }
    };

    return createPortal(
        <div className="modal-overlay" onMouseDown={(e) => { if (e.target === e.currentTarget) close(false); }}>
            <div className="modal-card" role="dialog" aria-modal="true" aria-label="Reiniciar el proceso">
                <div className="modal-icon danger"><WarnIcon style={{ width: 22, height: 22 }} /></div>
                <h3 className="modal-title">Reiniciar el proceso</h3>
                <p className="modal-text">
                    Se borrará <b>toda</b> la data de transacciones (oportunidades y trades) y se
                    reiniciarán los <b>challengers</b> y el <b>champion</b> del autopilot. Las wallets
                    volverán a su saldo inicial. Todo vuelve a empezar.
                </p>
                <p className="modal-text danger-text">Esta acción no se puede deshacer.</p>
                {err && <div className="alert err" style={{ marginTop: 4, marginBottom: 0 }}><span className="ad" />{err}</div>}
                <div className="modal-actions">
                    <button className="btn" onClick={() => close(false)} disabled={working}>Cancelar</button>
                    <button className="btn danger" onClick={confirm} disabled={working}>
                        {working ? 'Reiniciando…' : 'Sí, borrar todo'}
                    </button>
                </div>
            </div>
        </div>,
        document.body,
    );
}
