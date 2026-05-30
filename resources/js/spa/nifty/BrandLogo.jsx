/* NIFTY — logo de marca (wordmark servido desde /logo.svg). */

export function BrandLogo({ height = 26, tagline = null, center = false, style }) {
    return (
        <div className={'brand-logo-wrap' + (center ? ' brand-logo-center' : '')} style={style}>
            <img src="/logo.svg" alt="Nifty Arbitrage Engine" className="brand-logo" style={{ height }} />
            {tagline ? <div className="brand-sub">{tagline}</div> : null}
        </div>
    );
}
