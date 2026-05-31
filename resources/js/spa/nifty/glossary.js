/* NIFTY — Glosario central de explicaciones para los tooltips (InfoTip).
   Cada entrada: { title, body }. La idea es que un usuario nuevo entienda QUÉ es
   cada métrica, CÓMO se calcula y POR QUÉ importa. Mantener el texto conciso y en
   español. Las claves se referencian desde los componentes con <InfoTip g="clave" />. */

export const GLOSSARY = {
    // ---------- KPIs Dashboard ----------
    pnl_acumulado: {
        title: 'P&L acumulado',
        body: 'Ganancia/pérdida neta sumada de todos los trades simulados desde el inicio. Se calcula sumando el P&L realizado de cada operación ejecutada. Es la métrica maestra de si la estrategia gana dinero.',
    },
    pnl_dia: {
        title: 'Ganancia neta del día',
        body: 'P&L realizado acumulado solo de las operaciones de hoy. Sirve para ver el desempeño reciente sin el arrastre histórico.',
    },
    win_rate: {
        title: 'Win rate',
        body: '% de trades ejecutados que cerraron en ganancia. Se calcula como trades_positivos / trades_totales. Un win rate alto con profit factor bajo puede seguir perdiendo dinero, y viceversa.',
    },
    volumen_simulado: {
        title: 'Volumen simulado',
        body: 'Suma del valor (en USDT) negociado por las operaciones simuladas. Mide cuánto capital movió la estrategia, no la ganancia.',
    },

    // ---------- Mercado / order book ----------
    best_bid: {
        title: 'Best Bid',
        body: 'El precio de compra más alto disponible en el order book del exchange: lo máximo que alguien paga ahora por el activo. Vendes contra el bid.',
    },
    best_ask: {
        title: 'Best Ask',
        body: 'El precio de venta más bajo disponible en el order book: lo mínimo a lo que alguien vende ahora. Compras contra el ask.',
    },
    qty: {
        title: 'Cantidad (Qty)',
        body: 'Volumen disponible en el mejor nivel de precio (bid o ask). Limita cuánto puedes ejecutar a ese precio antes de pasar al siguiente nivel (slippage).',
    },
    spread: {
        title: 'Spread',
        body: 'Diferencia entre el best ask y el best bid de un mismo exchange (ask − bid). Refleja el costo implícito de cruzar el libro. No confundir con el spread de arbitraje entre exchanges.',
    },
    latencia: {
        title: 'Latencia',
        body: 'Antigüedad del último mensaje recibido de ese exchange (cuán fresco está su order book). Latencia alta = datos viejos = mayor riesgo de evaluar precios que ya cambiaron.',
    },
    estado_feed: {
        title: 'Estado del feed',
        body: 'Frescura de los datos: "Datos frescos" (dentro del umbral de frescura), "Atrasado" (superó el umbral) o "Sin datos" (sin mensajes recientes). Los datos atrasados se ignoran al evaluar.',
    },

    // ---------- Oportunidades (2 patas) ----------
    oportunidad: {
        title: 'Oportunidad de arbitraje',
        body: 'Un cruce de precios entre dos exchanges donde el ask (compra) en uno es menor que el bid (venta) en otro para el mismo símbolo. Cada update de order book puede generar una.',
    },
    comprar: {
        title: 'Comprar (exchange)',
        body: 'Exchange donde se compraría barato (contra su best ask). Es la "pata" de compra de la operación de arbitraje.',
    },
    vender: {
        title: 'Vender (exchange)',
        body: 'Exchange donde se vendería caro (contra su best bid). Es la "pata" de venta de la operación de arbitraje.',
    },
    precio_compra: {
        title: 'Precio de compra promedio',
        body: 'Precio promedio ponderado (VWAP) realmente pagado al comprar el volumen, recorriendo los niveles del ask. Incluye el slippage de profundidad, no solo el best ask.',
    },
    precio_venta: {
        title: 'Precio de venta promedio',
        body: 'Precio promedio ponderado (VWAP) realmente obtenido al vender el volumen, recorriendo los niveles del bid. Incluye slippage.',
    },
    spread_bruto: {
        title: 'Spread bruto',
        body: 'Diferencia porcentual entre el precio de venta y el de compra ANTES de costos: (precio_venta − precio_compra) / precio_compra. Es el potencial teórico; los fees y costos aún no se descuentan.',
    },
    spread_neto: {
        title: 'Spread neto',
        body: 'Spread después de descontar fees, slippage, penalización por latencia y costo fijo. Si es negativo, la oportunidad pierde dinero aunque el bruto sea positivo. Es lo que de verdad decide.',
    },
    volumen_op: {
        title: 'Volumen',
        body: 'Cantidad de activo (BTC) que se ejecutaría. Se limita por la liquidez disponible en ambos libros y por el saldo de tu wallet (USDT para comprar, BTC para vender).',
    },
    profit: {
        title: 'Profit',
        body: 'Ganancia neta absoluta (en USDT) estimada para la oportunidad: spread neto × volumen. Mientras está "en evaluación" se muestra un estimado (≈); al cerrar, el valor final.',
    },
    estado_opp: {
        title: 'Estado / decisión',
        body: 'Veredicto del motor: Ejecutada (cumple todas las reglas), Rechazada (falla un guard de riesgo), Ignorada (no accionable, p. ej. datos viejos) o En evaluación (en proceso).',
    },
    detectadas_hora: {
        title: 'Detectadas / hora',
        body: 'Número de oportunidades detectadas en la última hora. Mide la actividad del mercado y de los conectores, no la rentabilidad.',
    },

    // ---------- Desglose financiero (drawer) ----------
    spread_teorico: {
        title: 'Spread bruto teórico',
        body: 'Ganancia usando los mejores precios (top of book) sin recorrer profundidad. Es el punto de partida del desglose; de aquí se restan todos los costos.',
    },
    slippage: {
        title: 'Slippage por profundidad',
        body: 'Costo de mover volumen a través de varios niveles del order book: al comprar/vender más, el precio promedio empeora respecto al mejor precio. Mayor volumen o libros delgados = más slippage.',
    },
    fee_compra: {
        title: 'Fee de compra',
        body: 'Comisión taker del exchange donde se compra, aplicada al monto operado. Configurable por exchange en Configuración.',
    },
    fee_venta: {
        title: 'Fee de venta',
        body: 'Comisión taker del exchange donde se vende, aplicada al monto operado.',
    },
    penalizacion_latencia: {
        title: 'Penalización por latencia',
        body: 'Costo que penaliza datos menos frescos: a mayor latencia del order book, mayor riesgo de que el precio ya se haya movido, así que se descuenta del profit estimado.',
    },
    costo_fijo: {
        title: 'Costo fijo',
        body: 'Costo fijo por operación (p. ej. comisiones de red/retiro simuladas). Se resta una vez por trade, independientemente del volumen.',
    },
    profit_neto_final: {
        title: 'Ganancia neta final',
        body: 'neto = spread teórico − slippage − fees − penalización por latencia − costo fijo. Es el número que el risk manager compara contra tus umbrales mínimos.',
    },
    realizado: {
        title: 'P&L realmente realizado',
        body: 'Resultado tras simular la ejecución de ambas patas con slippage de ejecución (el precio se mueve entre la decisión y el fill). Puede diferir del profit identificado.',
    },
    slippage_ejecucion: {
        title: 'Slippage de ejecución',
        body: 'Diferencia entre el profit identificado al evaluar y el P&L realmente realizado, por el movimiento de precio entre la detección y el trade. Puede ser favorable o desfavorable.',
    },
    liquidez_usada: {
        title: 'Liquidez usada',
        body: 'Niveles del order book consumidos para llenar el volumen, mostrando usado/disponible por nivel. Visualiza el VWAP y los fills parciales cuando no hay profundidad suficiente.',
    },
    decision_bot: {
        title: 'Decisión del bot',
        body: 'Resultado del risk manager con el motivo concreto (guard que falló o se cumplió). Explica por qué se ejecutó, rechazó o ignoró la oportunidad.',
    },
    latencia_evaluacion: {
        title: 'Latencia de evaluación',
        body: 'Tiempo que tardó el motor en evaluar la oportunidad completa (en memoria), desde el update hasta la decisión. Mide el rendimiento del engine, no del mercado.',
    },

    // ---------- Configuración / reglas ----------
    fee_taker: {
        title: 'Fee taker (%)',
        body: 'Comisión que cobra el exchange por órdenes que toman liquidez (market). Se descuenta en cada pata. Déjalo vacío para usar el valor por defecto del backend para ese exchange.',
    },
    volumen_minimo: {
        title: 'Volumen mínimo',
        body: 'Tamaño mínimo de operación (en BTC). Por debajo de esto la oportunidad se rechaza: operaciones diminutas no compensan los costos fijos.',
    },
    volumen_maximo: {
        title: 'Volumen máximo',
        body: 'Tope de tamaño por operación (en BTC). Limita la exposición y evita asumir más slippage del tolerable aunque haya liquidez y saldo.',
    },
    min_net_profit: {
        title: 'Profit neto mínimo',
        body: 'Ganancia neta absoluta mínima (en USDT) para ejecutar. Si el profit estimado es menor, se rechaza. Evita operar por ganancias marginales.',
    },
    min_net_margin: {
        title: 'Margen neto mínimo',
        body: 'Rentabilidad neta mínima como fracción (0.0005 = 0.05%). Filtra por rentabilidad relativa además de la absoluta, para no depender solo del tamaño.',
    },
    freshness: {
        title: 'Edad máx. del order book',
        body: 'Antigüedad máxima (ms) que puede tener un order book para considerarlo válido. Más viejo que esto = se ignora, porque el precio podría haber cambiado.',
    },
    latency_max: {
        title: 'Latencia máxima',
        body: 'Latencia tolerada (ms) por exchange. Si un conector está por encima, sus oportunidades se descartan por riesgo de datos obsoletos.',
    },
    simbolos: {
        title: 'Símbolos a evaluar',
        body: 'Pares de mercado que el motor vigila (p. ej. BTC/USDT). Solo se evalúan oportunidades en estos símbolos; menos símbolos = foco y menos carga.',
    },
    circuit_breaker: {
        title: 'Circuit breaker',
        body: 'Protección que pausa el motor ante rachas de rechazos o errores, evitando seguir operando bajo condiciones anómalas. Se reactiva cuando las condiciones se normalizan.',
    },
    autopilot_flag: {
        title: 'Autopilot',
        body: 'Optimizador champion-challenger que prueba variantes de tus reglas en paralelo (shadow) y promueve la mejor. Se administra en la pestaña Autopilot.',
    },
    objetivo_optimizacion: {
        title: 'Objetivo de optimización',
        body: 'Métrica que el autopilot maximiza al elegir el champion: net_pnl (ganancia neta), volume (volumen) o risk_adjusted (ajustado a riesgo).',
    },

    // ---------- Wallets ----------
    valor_total: {
        title: 'Valor total estimado',
        body: 'Valor de todas las wallets simuladas marcado a mercado: USDT cuenta directo y el BTC se valúa al precio de referencia actual. Es el equity total de tu simulación.',
    },
    pnl_realizado: {
        title: 'P&L realizado',
        body: 'Ganancia/pérdida acumulada efectivamente materializada por los trades cerrados. No incluye variaciones de valor de activos sin operar (no realizado).',
    },
    btc_referencia: {
        title: 'BTC de referencia',
        body: 'Precio mid de BTC promediado entre exchanges, usado para valuar los balances de BTC en una moneda común (USDT).',
    },
    pct_capital: {
        title: '% capital',
        body: 'Porción del valor total que representa esa wallet/exchange. Sirve para ver la distribución y detectar concentración o desbalance.',
    },
    estado_wallet: {
        title: 'Estado de la wallet',
        body: 'Salud del balance: Balanceada, USDT bajo (no podría comprar), BTC bajo (no podría vender) o Desbalanceada (sin fondos). Las patas se limitan al saldo disponible.',
    },

    // ---------- Engine ----------
    estado_engine: {
        title: 'Estado del engine',
        body: 'Si tu simulación está corriendo (Activo) o detenida. El engine es un proceso que consume el feed de mercado y evalúa oportunidades en vivo contra tus reglas.',
    },
    exchanges_conectados: {
        title: 'Exchanges conectados',
        body: 'Conectores WebSocket con datos frescos sobre el total configurado. Menos conexiones = menos rutas de arbitraje posibles.',
    },
    funnel_snapshots: {
        title: 'Snapshots',
        body: 'Updates de order book procesados por el motor. Es la entrada del pipeline: cada snapshot puede disparar una evaluación.',
    },
    funnel_candidatos: {
        title: 'Candidatos',
        body: 'Cruces de precio detectados (buy_ask < sell_bid) antes de aplicar liquidez, costos y riesgo. Subconjunto de los snapshots.',
    },
    funnel_descartes: {
        title: 'Descartes',
        body: 'Candidatos que no llegaron a ejecutarse, agrupados por motivo (frescura, volumen, margen, latencia, circuit breaker, etc.). El embudo muestra dónde se pierden las oportunidades.',
    },
    embudo_descartes: {
        title: 'Embudo de descartes',
        body: 'Desglose de por qué se descartan los candidatos. Cada barra es un motivo y su peso relativo; ayuda a ajustar reglas (p. ej. demasiados descartes por margen = umbral muy alto).',
    },
    trades_totales: {
        title: 'Trades totales',
        body: 'Operaciones simuladas ejecutadas desde el inicio del proceso actual.',
    },
    oportunidades_totales: {
        title: 'Oportunidades totales',
        body: 'Total de oportunidades registradas (de cualquier decisión) desde el inicio del proceso.',
    },
    modo_simulacion: {
        title: 'Inyección de precio sintético',
        body: 'En mercado real los spreads casi nunca cubren los fees. Este modo desplaza aleatoriamente los precios del order book para crear escenarios rentables y ver el motor operar. Solo para simulación.',
    },
    deriva_orderbook: {
        title: 'Deriva del order book',
        body: '% máximo (±) que se modifica el precio real ANTES de evaluar. Mayor deriva = spreads más amplios y más oportunidades rentables (artificiales).',
    },
    slippage_config: {
        title: 'Slippage de ejecución (config)',
        body: '% máximo (±) que se desvía el precio de fill respecto al evaluado, simulando el movimiento entre la decisión y la ejecución. Afecta el P&L realizado, no la decisión.',
    },

    // ---------- Autopilot ----------
    champion: {
        title: 'Champion',
        body: 'La estrategia (conjunto de reglas) actualmente aplicada y que opera tu wallet. Es la referencia "base" contra la que se comparan los challengers.',
    },
    challenger: {
        title: 'Challenger',
        body: 'Variante de reglas que opera en "shadow" (en paralelo, sobre la misma data de mercado) sin tocar tu wallet real. Si supera al champion, puede promoverse.',
    },
    score: {
        title: 'Score',
        body: 'Puntaje de la estrategia según el objetivo de optimización (p. ej. net_pnl). El autopilot compara scores de champion y challengers para decidir promociones.',
    },
    max_challengers: {
        title: 'Max challengers',
        body: 'Cuántas variantes shadow corren en paralelo. Más challengers exploran más reglas pero consumen más cómputo.',
    },
    periodo_promocion: {
        title: 'Periodo de promoción',
        body: 'Intervalo mínimo entre promociones automáticas de un nuevo champion. Evita cambiar de estrategia demasiado seguido por ruido de corto plazo.',
    },
    auto_promote: {
        title: 'Promoción automática',
        body: 'Si está activo, el ganador se promueve solo (respetando el periodo). Si no, el optimizador solo recomienda y tú promueves manualmente.',
    },

    // ---------- Rendimiento ----------
    profit_factor: {
        title: 'Profit factor',
        body: 'Suma de ganancias / suma de pérdidas (en valor absoluto). > 1 significa que las ganancias superan a las pérdidas. Complementa al win rate.',
    },
    profit_promedio: {
        title: 'Profit neto promedio',
        body: 'Ganancia media de los trades positivos. Junto con la pérdida promedio define la esperanza matemática de la estrategia.',
    },
    perdida_promedio: {
        title: 'Pérdida promedio',
        body: 'Pérdida media de los trades negativos (en valor absoluto).',
    },
    pct_rechazos: {
        title: '% rechazos',
        body: '% de oportunidades detectadas que el risk manager rechazó. Alto puede indicar umbrales muy estrictos o mercado poco rentable.',
    },
    razon_principal: {
        title: 'Razón principal',
        body: 'Motivo de rechazo más frecuente. Es la palanca más útil para ajustar reglas y aumentar la tasa de ejecución.',
    },

    // ---------- Triangular ----------
    triangular: {
        title: 'Arbitraje triangular (ciclos)',
        body: 'Ciclo cerrado de 3 patas dentro de un mismo exchange (p. ej. USDT→BTC→ETH→USDT). Aprovecha desalineaciones entre tres pares sin transferir entre exchanges.',
    },

    // ---------- Reversión a la media ----------
    meanrev_estrategia: {
        title: 'Reversión a la media',
        body: 'Estrategia spot que asume que el precio tiende a volver a su media móvil de 1h: compra cuando cae muy por debajo y vende cuando regresa hacia la media. Opera con billetera USDT propia y aislada.',
    },
    equity_meanrev: {
        title: 'Equity (USDT)',
        body: 'Valor total de tu sesión marcado a mercado: caja en USDT + valor de mercado actual de las posiciones abiertas (cantidad × precio actual). Sube y baja en tiempo real con el precio, reflejando el riesgo de lo invertido.',
    },
    pnl_no_realizado: {
        title: 'P&L no realizado',
        body: 'Ganancia/pérdida que tendrías si vendieras ahora todo lo desplegado: valor de mercado actual − costo base. Es "en papel" hasta que cierras la posición; ahí se vuelve P&L realizado.',
    },
    valor_mercado: {
        title: 'Valor de mercado',
        body: 'Valor actual de una posición: cantidad × último precio observado del activo. A diferencia del costo base (lo invertido), cambia con cada tick del mercado.',
    },
    precio_actual: {
        title: 'Precio actual',
        body: 'Último precio mid (promedio de bid/ask) observado por el motor para ese activo. Es el precio usado para marcar la posición a mercado y calcular su valor y P&L no realizado.',
    },
    zscore: {
        title: 'Z-score',
        body: 'Cuántas desviaciones estándar se aleja el precio de su media de 1h. Negativo = barato (posible compra), positivo = caro (posible venta). Es el disparador de las señales.',
    },
    volatilidad_pct: {
        title: 'Volatilidad %',
        body: 'Dispersión reciente del precio (desviación estándar relativa). Mayor volatilidad amplía los umbrales de entrada/salida porque el ruido normal es más grande.',
    },
    costo_base: {
        title: 'Costo base',
        body: 'USDT invertido en una posición (cantidad × costo promedio). Es el capital comprometido que se recupera —con ganancia o pérdida— al vender.',
    },
    posiciones_abiertas: {
        title: 'Posiciones abiertas',
        body: 'Activos comprados que aún no se han vendido. Mientras estén abiertas, su capital está "desplegado" y no disponible como caja.',
    },
    motivo_senal: {
        title: 'Motivo',
        body: 'Por qué se generó la señal: entrada/salida por z-score, reversión a la media, take-profit o stop-loss.',
    },

    // ---------- Hub de Estrategias / trading long-short ----------
    estrategias_hub: {
        title: 'Hub de Estrategias',
        body: 'Sección central que agrupa todas tus estrategias: las de trading (long y short simulado sobre monedas volátiles) y la de arbitraje cross-exchange. Cada una tiene su billetera y dashboard; aquí ves su rendimiento consolidado.',
    },
    ai_supervisor: {
        title: 'AI Supervisor',
        body: 'Un modelo de lenguaje que analiza el régimen de mercado, las señales recientes y el desempeño, y emite recomendaciones (focos, alertas, ajustes de parámetros). NUNCA ejecuta operaciones ni toca balances: solo opina.',
    },
    agente_ia: {
        title: 'Agente sobre tus estrategias',
        body: 'Un único agente por encima de todas tus estrategias con dos modos: ASESOR (analiza y recomienda focos, alertas y ajustes; tú aplicas) y AUTÓNOMO (ejecuta por ti). Hoy el modo autónomo corre el champion-challenger del arbitraje; en trading, auto-aplicar sugerencias será opt-in por estrategia.',
    },
    confidence: {
        title: 'Confianza de la señal',
        body: 'Puntaje 0–100% que estima qué tan fuerte es la señal según sus features (z-score, volumen, spread, etc.). El risk manager exige una confianza mínima antes de abrir una posición.',
    },
    trades_consolidado: {
        title: 'Transacciones consolidadas',
        body: 'Vista global con TODAS las operaciones del usuario: posiciones de trading (long/short) y trades de arbitraje cross-exchange, unificadas y etiquetadas por estrategia, con su P&L neto, fees, slippage y razón de cierre.',
    },
    estrategia_columna: {
        title: 'Estrategia',
        body: 'Instancia que originó la transacción (su nombre y tipo: trading o cross-exchange). Permite atribuir cada operación a la estrategia responsable y filtrar por ella.',
    },
    short_simulado: {
        title: 'Short simulado',
        body: 'Posición que gana si el precio baja: P&L = (precio_entrada − precio_salida) × tamaño, menos fees y funding opcional. Se simula con USDT como colateral y apalancamiento bajo configurable; no hay venta en corto real.',
    },
    slice_usdt: {
        title: 'Tamaño por posición (USDT)',
        body: 'Capital en USDT que se compromete al abrir cada posición. Acota la exposición por trade; el total invertido depende de cuántas posiciones simultáneas permitas.',
    },
    take_profit_pct: {
        title: 'Take-profit %',
        body: 'Objetivo de ganancia: al alcanzar este % a favor, la posición se cierra automáticamente y materializa el profit. Salida obligatoria del simulador.',
    },
    stop_loss_pct: {
        title: 'Stop-loss %',
        body: 'Límite de pérdida: al alcanzar este % en contra, la posición se cierra para acotar el daño. Salida obligatoria que protege la billetera.',
    },
    max_open_positions: {
        title: 'Máx. posiciones abiertas',
        body: 'Número máximo de posiciones simultáneas de la estrategia. Limita la exposición agregada y el capital desplegado a la vez.',
    },
    leverage: {
        title: 'Apalancamiento',
        body: 'Multiplicador del tamaño efectivo de la posición (solo short simulado). Más apalancamiento amplifica ganancias y pérdidas, y acerca el precio de liquidación simulada.',
    },
    min_confidence: {
        title: 'Confianza mínima',
        body: 'Umbral de confianza (0–1) que una señal debe superar para considerarse. Más alto = menos operaciones pero de mayor calidad esperada.',
    },
    max_spread_pct: {
        title: 'Spread máximo %',
        body: 'Spread bid/ask máximo tolerado para operar un símbolo. Por encima, el costo de cruzar el libro erosiona el edge y la señal se rechaza.',
    },
};
