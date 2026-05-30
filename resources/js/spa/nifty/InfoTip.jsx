/* NIFTY — InfoTip: icono "?" con tooltip explicativo (qué es, cómo se calcula, por qué).
   Usa posición fixed calculada en hover/focus para no recortarse dentro de tablas o
   paneles con overflow. Accesible: focusable y con aria-label. El contenido corto viene
   del glosario central (prop `g`) o se pasa a mano (`title`/`body`).

   Si para esa clave existe una explicación DETALLADA (EXPLAINERS) o se fuerza con
   `panel`, el icono se vuelve clicable y abre el panel lateral con fórmulas, ejemplos,
   etc. En ese caso el tooltip invita a "Ver el cálculo completo". */
import { useRef, useState } from 'react';
import { GLOSSARY } from './glossary';
import { useHelp, hasExplainer } from './HelpPanel';

export function InfoTip({ g, title, body, panel, className = '' }) {
    const entry = g ? GLOSSARY[g] : null;
    const t = title ?? entry?.title ?? null;
    const b = body ?? entry?.body ?? null;
    const ref = useRef(null);
    const [pos, setPos] = useState(null);
    const help = useHelp();
    const expandable = (panel || hasExplainer(g)) && !!help;

    if (!t && !b) return null;

    const show = () => {
        const el = ref.current;
        if (!el) return;
        const r = el.getBoundingClientRect();
        const below = r.bottom < window.innerHeight - 200;
        setPos({
            x: Math.min(Math.max(r.left + r.width / 2, 160), window.innerWidth - 160),
            y: below ? r.bottom + 8 : r.top - 8,
            below,
        });
    };
    const hide = () => setPos(null);
    const onClick = (e) => {
        e.stopPropagation();
        e.preventDefault();
        if (expandable) { hide(); help.open(g); }
    };

    return (
        <span
            ref={ref}
            className={'infotip ' + (expandable ? 'has-panel ' : '') + className}
            tabIndex={0}
            role={expandable ? 'button' : 'note'}
            aria-label={(expandable ? 'Ver explicación: ' : '') + [t, b].filter(Boolean).join(': ')}
            onMouseEnter={show}
            onMouseLeave={hide}
            onFocus={show}
            onBlur={hide}
            onClick={onClick}
            onKeyDown={(e) => { if (expandable && (e.key === 'Enter' || e.key === ' ')) onClick(e); }}
        >
            {expandable ? (
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
                    <circle cx="12" cy="12" r="9.2" />
                    <path d="M9 9.5a3 3 0 1 1 4.2 2.75c-0.8 0.4-1.2 0.9-1.2 1.75" strokeLinecap="round" strokeLinejoin="round" />
                    <circle cx="12" cy="17" r="0.5" fill="currentColor" stroke="none" />
                </svg>
            ) : (
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
                    <circle cx="12" cy="12" r="9.2" />
                    <path d="M12 11v5" strokeLinecap="round" />
                    <circle cx="12" cy="7.7" r="0.4" fill="currentColor" stroke="none" />
                </svg>
            )}
            {pos && (
                <span
                    className={'infotip-pop' + (pos.below ? '' : ' above')}
                    style={{ left: pos.x + 'px', top: pos.y + 'px' }}
                >
                    {t && <span className="it-title">{t}</span>}
                    {b && <span className="it-body">{b}</span>}
                    {expandable && <span className="it-more">Ver el cálculo completo →</span>}
                </span>
            )}
        </span>
    );
}
