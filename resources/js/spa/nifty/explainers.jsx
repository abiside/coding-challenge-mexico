/* NIFTY — Explicaciones detalladas para el panel lateral de ayuda (HelpPanel).
   A diferencia del glosario (tooltip de 1-2 líneas), aquí van los conceptos y
   números COMPLEJOS con fórmula, paso a paso, ejemplo numérico y "por qué importa".
   Se referencian con la misma clave que el glosario: <InfoTip g="clave" /> abre el
   panel automáticamente si existe una entrada aquí.

   Esquema de cada entrada:
     { title, lead, blocks: [
        { type: 'formula', label, value },
        { type: 'list',    label, items: [] },
        { type: 'example', label, rows: [[k,v,cls?]], result: [k,v,cls?] },
        { type: 'note',    value },
     ] } */

export const EXPLAINERS = {
    // ---------- El cálculo central de arbitraje ----------
    profit_neto_final: {
        title: 'Profit neto final',
        lead: 'Es el número que decide si una oportunidad se ejecuta. Parte del spread teórico (usando los mejores precios) y le resta todos los costos reales de operar.',
        blocks: [
            { type: 'formula', label: 'Fórmula', value: 'neto = spread teórico − slippage − fee compra − fee venta − penalización latencia − costo fijo' },
            {
                type: 'list', label: 'Cómo se calcula, paso a paso', items: [
                    '1. Spread teórico: ganancia con el mejor bid de venta y el mejor ask de compra, sin mover volumen.',
                    '2. Slippage por profundidad: al ejecutar el volumen real se recorren varios niveles del libro; el precio promedio (VWAP) empeora.',
                    '3. Fees: se aplica el fee taker de cada exchange sobre el monto operado en cada pata.',
                    '4. Penalización por latencia: castiga datos menos frescos (mayor riesgo de que el precio ya cambió).',
                    '5. Costo fijo: comisión fija por operación (p. ej. red/retiro simulado).',
                ],
            },
            {
                type: 'example', label: 'Ejemplo (1 BTC)', rows: [
                    ['Spread bruto teórico', '+$120.00', 'pos'],
                    ['Slippage por profundidad', '−$22.00', 'neg'],
                    ['Fee de compra (0.1%)', '−$42.00', 'neg'],
                    ['Fee de venta (0.1%)', '−$42.10', 'neg'],
                    ['Penalización por latencia', '−$3.50', 'neg'],
                    ['Costo fijo', '−$1.00', 'neg'],
                ], result: ['Profit neto final', '+$9.40', 'pos'],
            },
            { type: 'note', value: 'El risk manager compara este neto contra "Profit neto mínimo" y "Margen neto mínimo". Si no los supera, la oportunidad se rechaza aunque el bruto fuera positivo.' },
        ],
    },
    spread_neto: {
        title: 'Spread neto',
        lead: 'Rentabilidad relativa de la oportunidad después de todos los costos. Es el spread bruto menos fees, slippage, latencia y costo fijo, expresado como porcentaje.',
        blocks: [
            { type: 'formula', label: 'Fórmula', value: 'spread neto % = (profit neto / costo de compra) × 100' },
            { type: 'list', label: 'Por qué dos métricas (bruto vs neto)', items: [
                'El spread bruto es el "atractivo" aparente: cuánto separan los precios entre exchanges.',
                'El spread neto es lo real: un bruto de +0.30% puede volverse neto negativo si los fees suman 0.20% en cada pata.',
                'El motor decide por el neto; el bruto solo orienta.',
            ] },
            { type: 'example', label: 'Ejemplo', rows: [
                ['Spread bruto', '+0.28%', 'pos'],
                ['Fees + slippage + latencia', '−0.25%', 'neg'],
            ], result: ['Spread neto', '+0.03%', 'pos'] },
            { type: 'note', value: 'Se compara contra "Margen neto mínimo". Con 0.03% y volumen pequeño, suele quedar por debajo del umbral y se rechaza.' },
        ],
    },
    slippage: {
        title: 'Slippage por profundidad',
        lead: 'El order book tiene volumen limitado en cada nivel de precio. Al operar un tamaño grande consumes varios niveles, y el precio promedio que pagas/recibes empeora respecto al mejor precio.',
        blocks: [
            { type: 'formula', label: 'Idea', value: 'precio efectivo = VWAP de los niveles consumidos (no el best price)' },
            { type: 'list', label: 'Qué lo aumenta', items: [
                'Mayor volumen de operación (recorres más niveles).',
                'Libros "delgados" (poca cantidad disponible por nivel).',
                'Momentos de baja liquidez.',
            ] },
            { type: 'example', label: 'Ejemplo de compra de 1 BTC', rows: [
                ['0.4 BTC @ $60,000', '$24,000'],
                ['0.6 BTC @ $60,050', '$36,030'],
            ], result: ['VWAP pagado', '$60,030 (vs best $60,000)', 'neg'] },
            { type: 'note', value: 'En el desglose de cada oportunidad puedes ver los niveles realmente usados (usado/disponible) en la sección "Liquidez usada".' },
        ],
    },
    slippage_ejecucion: {
        title: 'Slippage de ejecución (identificada vs realizada)',
        lead: 'Entre el instante en que el motor DETECTA la oportunidad y el instante en que EJECUTA el trade, el precio se mueve. Esa diferencia es el slippage de ejecución.',
        blocks: [
            { type: 'formula', label: 'Fórmula', value: 'diferencia = P&L realizado − profit neto identificado' },
            { type: 'list', label: 'Cómo se simula', items: [
                'El "Slippage de ejecución" configurable (en Engine › Modo simulación) desvía aleatoriamente el precio de fill ±X%.',
                'Si el precio se mueve a favor, el realizado supera al identificado (diferencia positiva).',
                'Si se mueve en contra, el realizado queda por debajo (diferencia negativa).',
            ] },
            { type: 'example', label: 'Ejemplo', rows: [
                ['Profit neto identificado', '+$9.40', 'pos'],
                ['P&L realmente realizado', '+$6.10', 'pos'],
            ], result: ['Diferencia (slippage ejec.)', '−$3.30', 'neg'] },
            { type: 'note', value: 'Por eso una estrategia puede verse rentable "en papel" y rendir menos al ejecutar: este es el costo de la realidad del mercado.' },
        ],
    },
    precio_compra: {
        title: 'Precio promedio (VWAP)',
        lead: 'Los precios de compra/venta mostrados no son el mejor precio del libro, sino el promedio ponderado por volumen (VWAP) realmente conseguido al llenar la orden.',
        blocks: [
            { type: 'formula', label: 'Fórmula', value: 'VWAP = Σ(precio_nivel × cantidad_nivel) / Σ(cantidad_nivel)' },
            { type: 'note', value: 'El VWAP incorpora el slippage de profundidad. Por eso al comprar es ≥ best ask y al vender es ≤ best bid.' },
        ],
    },

    // ---------- Métricas de calidad ----------
    win_rate: {
        title: 'Win rate',
        lead: '% de operaciones ejecutadas que cerraron en ganancia. Mide consistencia, pero por sí solo NO dice si ganas dinero.',
        blocks: [
            { type: 'formula', label: 'Fórmula', value: 'win rate = trades positivos / trades ejecutados × 100' },
            { type: 'list', label: 'Léelo junto con el profit factor', items: [
                'Win rate alto + ganancias pequeñas + pérdidas grandes = puede perder dinero.',
                'Win rate bajo + ganancias grandes = puede ganar dinero.',
                'La métrica que combina ambos es el profit factor.',
            ] },
            { type: 'example', label: 'Ejemplo', rows: [
                ['Trades ejecutados', '120'],
                ['Trades positivos', '78'],
            ], result: ['Win rate', '65%', 'pos'] },
        ],
    },
    profit_factor: {
        title: 'Profit factor',
        lead: 'Cuánto ganas por cada unidad que pierdes. Es el indicador de rentabilidad más robusto porque pondera tamaño, no solo frecuencia.',
        blocks: [
            { type: 'formula', label: 'Fórmula', value: 'profit factor = Σ ganancias / |Σ pérdidas|' },
            { type: 'list', label: 'Cómo interpretarlo', items: [
                '> 1: la estrategia es rentable (las ganancias superan a las pérdidas).',
                '= 1: punto de equilibrio.',
                '< 1: pierde dinero, sin importar el win rate.',
            ] },
            { type: 'example', label: 'Ejemplo', rows: [
                ['Suma de ganancias', '+$1,250', 'pos'],
                ['Suma de pérdidas', '−$780', 'neg'],
            ], result: ['Profit factor', '1.60', 'pos'] },
        ],
    },

    // ---------- Autopilot ----------
    score: {
        title: 'Score y promoción (champion-challenger)',
        lead: 'El autopilot prueba variantes de tus reglas ("challengers") en paralelo sobre la misma data de mercado, sin tocar tu wallet. El score las ordena según el objetivo de optimización.',
        blocks: [
            { type: 'formula', label: 'Objetivo', value: 'score = función del objetivo (net_pnl, volume o risk_adjusted)' },
            { type: 'list', label: 'Cómo opera el ciclo', items: [
                '1. El champion es la estrategia activa que mueve tu wallet (referencia base).',
                '2. Cada challenger evalúa las mismas oportunidades con parámetros distintos, en modo shadow.',
                '3. El juez compara scores cada ciclo y calcula la ventaja del challenger vs champion.',
                '4. Si la promoción automática está activa, el mejor challenger se vuelve champion (respetando el periodo de promoción).',
            ] },
            { type: 'note', value: 'La gráfica "Optimizado vs base" muestra el P&L acumulado de cada estrategia: línea sólida = champion, punteadas = challengers, línea amarilla = una promoción.' },
        ],
    },
    champion: {
        title: 'Champion',
        lead: 'La estrategia (conjunto de reglas) actualmente aplicada y que opera tu wallet real. Es la base contra la que compiten los challengers.',
        blocks: [
            { type: 'list', label: 'Qué define a una estrategia', items: [
                'Profit neto mínimo y margen neto mínimo.',
                'Rango de volumen [min, max].',
                'Umbrales de frescura y latencia.',
            ] },
            { type: 'note', value: 'Promover un challenger reemplaza estos parámetros activos. Cada promoción incrementa la "generación" (gen) del champion.' },
        ],
    },
    challenger: {
        title: 'Challenger',
        lead: 'Variante de reglas que el optimizador propone y ejecuta en "shadow": evalúa las mismas oportunidades que el champion pero NO mueve tu wallet.',
        blocks: [
            { type: 'list', label: 'Para qué sirven', items: [
                'Probar parámetros más agresivos o más conservadores sin riesgo real.',
                'Medir, con data real, si una configuración distinta habría rendido mejor.',
                'Alimentar la decisión de promoción (manual o automática).',
            ] },
            { type: 'note', value: 'El "edge vs champ" en cada tarjeta es la diferencia de score del challenger respecto al champion.' },
        ],
    },

    // ---------- Riesgo / configuración ----------
    circuit_breaker: {
        title: 'Circuit breaker',
        lead: 'Protección que pausa el motor automáticamente ante condiciones anómalas, para no seguir operando "a ciegas" cuando algo va mal.',
        blocks: [
            { type: 'list', label: 'Qué lo dispara', items: [
                'Rachas de rechazos consecutivos del risk manager.',
                'Errores repetidos de ejecución o de datos.',
                'Condiciones fuera de los rangos esperados.',
            ] },
            { type: 'note', value: 'Es una salvaguarda de riesgo: prioriza no perder dinero por encima de capturar cada oportunidad. Se reactiva cuando las condiciones se normalizan.' },
        ],
    },
    min_net_margin: {
        title: 'Margen neto mínimo',
        lead: 'Filtro de rentabilidad RELATIVA. Se expresa como fracción: 0.0005 = 0.05% = 5 puntos básicos (bps).',
        blocks: [
            { type: 'formula', label: 'Equivalencias', value: '0.0005 frac = 0.05% = 5 bps' },
            { type: 'list', label: 'Por qué complementa al profit mínimo', items: [
                'El profit neto mínimo filtra por monto absoluto (USDT).',
                'El margen neto mínimo filtra por % de rentabilidad.',
                'Juntos evitan tanto ganancias diminutas como operaciones grandes con margen pobre.',
            ] },
        ],
    },
    freshness: {
        title: 'Frescura y latencia de datos',
        lead: 'El arbitraje vive de precios actuales. Si el order book está viejo, evaluarías sobre precios que ya cambiaron: ganancias fantasma.',
        blocks: [
            { type: 'list', label: 'Dos guardas distintas', items: [
                'Edad máx. del order book: cuán viejo puede ser el snapshot para considerarlo válido (ms).',
                'Latencia máxima: cuánto puede tardar el feed de un exchange antes de descartarlo (ms).',
            ] },
            { type: 'note', value: 'Más estricto (valores bajos) = más seguro pero menos oportunidades. Más laxo = más oportunidades pero mayor riesgo de datos obsoletos.' },
        ],
    },

    // ---------- Wallets ----------
    valor_total: {
        title: 'Valor total estimado (equity)',
        lead: 'El valor de todas tus wallets simuladas marcado a mercado, en una moneda común (USDT). Es el equity total de tu simulación.',
        blocks: [
            { type: 'formula', label: 'Fórmula', value: 'valor total = Σ USDT + Σ (BTC × precio BTC de referencia)' },
            { type: 'list', label: 'Detalles', items: [
                'El USDT cuenta a su valor nominal.',
                'El BTC se valúa al precio mid promediado entre exchanges (BTC de referencia).',
                'Es valor a mercado: sube/baja con el precio aunque no operes.',
            ] },
            { type: 'note', value: 'No confundir con el P&L realizado, que solo cuenta lo materializado por trades cerrados.' },
        ],
    },
    pct_capital: {
        title: '% de capital',
        lead: 'Qué porción de tu equity total está en cada wallet/exchange. Sirve para detectar concentración y planear rebalanceos.',
        blocks: [
            { type: 'formula', label: 'Fórmula', value: '% capital = valor de la wallet / valor total × 100' },
            { type: 'note', value: 'Para arbitraje conviene tener capital distribuido: necesitas USDT donde compras y BTC donde vendes. Una distribución muy concentrada limita las rutas ejecutables.' },
        ],
    },

    // ---------- Reversión a la media ----------
    equity_meanrev: {
        title: 'Equity y P&L no realizado',
        lead: 'El equity es cuánto vale tu sesión AHORA mismo. A diferencia de sumar lo invertido a su costo, las posiciones abiertas se valoran al precio de mercado actual, así ves en tiempo real cómo va el balance por el riesgo colocado.',
        blocks: [
            { type: 'formula', label: 'Fórmula', value: 'equity = caja USDT + Σ (cantidad × precio actual)\nP&L no realizado = valor de mercado − costo base' },
            { type: 'list', label: 'Tres números, tres significados', items: [
                'Caja USDT: lo que tienes líquido, sin invertir.',
                'Valor de mercado desplegado: lo que valdrían tus posiciones si las vendieras ahora.',
                'Costo base desplegado: lo que pagaste por esas posiciones (su costo de entrada).',
            ] },
            { type: 'example', label: 'Ejemplo', rows: [
                ['Caja USDT', '$7,000.00'],
                ['Costo base desplegado', '$3,000.00'],
                ['Valor de mercado desplegado', '$3,180.00', 'pos'],
            ], result: ['Equity (mercado)', '$10,180.00', 'pos'] },
            { type: 'note', value: 'En el ejemplo el P&L no realizado es +$180 (3,180 − 3,000): aún no lo cobras, pero refleja que las posiciones se movieron a tu favor. Si el precio cae por debajo del costo, el equity baja del capital invertido aunque el P&L realizado siga en cero.' },
        ],
    },
    pnl_no_realizado: {
        title: 'P&L no realizado',
        lead: 'La ganancia o pérdida "en papel" de tus posiciones abiertas: lo que ganarías o perderías si las cerraras a precio de mercado en este instante.',
        blocks: [
            { type: 'formula', label: 'Fórmula', value: 'no realizado = (precio actual − costo promedio) × cantidad' },
            { type: 'list', label: 'Realizado vs no realizado', items: [
                'No realizado: cambia con cada tick mientras la posición está abierta. No es dinero cobrado todavía.',
                'Realizado: se materializa al VENDER; ahí el no realizado "se convierte" en realizado.',
                'El equity total ya incluye el no realizado (porque valora a mercado).',
            ] },
            { type: 'note', value: 'Es la métrica clave para entender el riesgo vivo: un P&L realizado positivo puede convivir con un no realizado negativo si tienes posiciones abiertas en pérdida esperando revertir.' },
        ],
    },
    zscore: {
        title: 'Z-score (reversión a la media)',
        lead: 'Mide qué tan "anormal" es el precio actual frente a su comportamiento reciente. Es el corazón de la estrategia: dispara compras cuando el precio está estadísticamente barato y ventas cuando vuelve a la normalidad.',
        blocks: [
            { type: 'formula', label: 'Fórmula', value: 'z = (precio − media 1h) / desviación estándar 1h' },
            { type: 'list', label: 'Cómo se interpreta', items: [
                'z = 0: el precio está justo en su media de 1h.',
                'z negativo (p. ej. −2): el precio está 2 desviaciones por debajo → candidato a COMPRA (barato).',
                'z positivo (p. ej. +2): el precio está muy por encima → candidato a VENTA / salida.',
                'Cuanto más extremo el z, más fuerte la señal de que el precio "debería" revertir.',
            ] },
            { type: 'example', label: 'Ejemplo', rows: [
                ['Precio actual', '$59,400'],
                ['Media 1h', '$60,000'],
                ['Desviación estándar', '$300'],
            ], result: ['Z-score', '−2.00 (compra)', 'neg'] },
            { type: 'note', value: 'La volatilidad (desviación estándar) ajusta el umbral: en mercados volátiles un z de −1 es ruido normal; en mercados calmos puede ser una señal clara. Por eso la estrategia normaliza por desviación en lugar de usar un % fijo.' },
        ],
    },
    meanrev_estrategia: {
        title: 'Reversión a la media',
        lead: 'Estrategia spot de un solo activo (no es arbitraje). Asume que las desviaciones de precio frente a la media de 1h son temporales y tienden a corregirse.',
        blocks: [
            { type: 'list', label: 'Ciclo de operación', items: [
                '1. Calcula la media móvil de 1h y la desviación estándar de cada símbolo.',
                '2. Cuando el z-score cae por debajo del umbral de entrada (precio barato), compra con USDT de la caja.',
                '3. Mantiene la posición hasta que el precio revierte hacia la media (z sube) o se dispara take-profit / stop-loss.',
                '4. Al vender, materializa el P&L y devuelve el capital a la caja USDT.',
            ] },
            { type: 'note', value: 'Cada usuario tiene su propia billetera y sesión aisladas: tus posiciones y P&L son privados y no afectan al arbitraje ni a otros usuarios. Necesita ~10 min de cobertura por símbolo para calcular medias antes de operar.' },
        ],
    },

    // ---------- Triangular ----------
    triangular: {
        title: 'Arbitraje triangular (ciclos)',
        lead: 'A diferencia del arbitraje de 2 patas (cruza dos exchanges), el triangular cierra un ciclo de 3 pares DENTRO de un mismo exchange.',
        blocks: [
            { type: 'formula', label: 'Ejemplo de ciclo', value: 'USDT → BTC → ETH → USDT' },
            { type: 'list', label: 'Cómo se detecta', items: [
                'Se buscan desalineaciones entre tres pares relacionados (BTC/USDT, ETH/BTC, ETH/USDT).',
                'Si al recorrer el ciclo terminas con más USDT del que empezaste (tras fees), hay oportunidad.',
                'No requiere transferir fondos entre exchanges: todo ocurre en el mismo libro.',
            ] },
            { type: 'note', value: 'Por eso los ciclos triangulares no aparecen en el flujo de 2 patas; tienen su propio panel y métricas.' },
        ],
    },
};
