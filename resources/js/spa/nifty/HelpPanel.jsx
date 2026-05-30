/* NIFTY — Panel lateral de ayuda para conceptos/números complejos.
   Provee un contexto (HelpProvider) con `open(key)` y monta el drawer (HelpPanel).
   Reutiliza el estilo .overlay/.drawer del detalle de oportunidad, con z-index mayor
   para poder abrirse incluso sobre el OppDrawer. El contenido viene de EXPLAINERS. */
import { createContext, useCallback, useContext, useState } from 'react';
import { I } from './icons';
import { EXPLAINERS } from './explainers';

const HelpContext = createContext(null);

export function useHelp() {
    return useContext(HelpContext);
}

export function hasExplainer(key) {
    return !!(key && EXPLAINERS[key]);
}

export function HelpProvider({ children }) {
    const [key, setKey] = useState(null);
    const open = (k) => { if (EXPLAINERS[k]) setKey(k); };
    const close = () => setKey(null);
    return (
        <HelpContext.Provider value={{ open, close, current: key }}>
            {children}
            <HelpPanel keyName={key} onClose={close} />
        </HelpContext.Provider>
    );
}

function Block({ block }) {
    if (block.type === 'formula') {
        return (
            <div className="help-block">
                {block.label && <div className="help-bl">{block.label}</div>}
                <div className="help-formula">{block.value}</div>
            </div>
        );
    }
    if (block.type === 'list') {
        return (
            <div className="help-block">
                {block.label && <div className="help-bl">{block.label}</div>}
                <ul className="help-list">
                    {block.items.map((it, i) => <li key={i}>{it}</li>)}
                </ul>
            </div>
        );
    }
    if (block.type === 'example') {
        return (
            <div className="help-block">
                {block.label && <div className="help-bl">{block.label}</div>}
                <div className="help-example">
                    {block.rows.map((r, i) => (
                        <div className="help-erow" key={i}>
                            <span className="help-ek">{r[0]}</span>
                            <span className={'help-ev ' + (r[2] || '')}>{r[1]}</span>
                        </div>
                    ))}
                    {block.result && (
                        <div className="help-erow help-eresult">
                            <span className="help-ek">{block.result[0]}</span>
                            <span className={'help-ev ' + (block.result[2] || '')}>{block.result[1]}</span>
                        </div>
                    )}
                </div>
            </div>
        );
    }
    if (block.type === 'note') {
        return <div className="help-note">{block.value}</div>;
    }
    return null;
}

function HelpPanel({ keyName, onClose }) {
    const data = keyName ? EXPLAINERS[keyName] : null;
    if (!data) return null;
    return (
        <div className="overlay help-overlay" onClick={onClose}>
            <div className="drawer help-drawer" onClick={(e) => e.stopPropagation()}>
                <div className="drawer-h">
                    <div>
                        <div className="help-kicker"><I.bolt style={{ width: 13, height: 13 }} /> Cómo funciona</div>
                        <div className="help-title">{data.title}</div>
                    </div>
                    <button className="x-btn" onClick={onClose} aria-label="Cerrar"><I.x /></button>
                </div>
                <div className="drawer-sec" style={{ borderBottom: 'none' }}>
                    {data.lead && <p className="help-lead">{data.lead}</p>}
                    {(data.blocks || []).map((b, i) => <Block key={i} block={b} />)}
                </div>
            </div>
        </div>
    );
}
