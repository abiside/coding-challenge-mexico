# Documento de implementación  
## Módulo de estrategias de trading sobre USDT y AI Supervisor

## 1. Objetivo del módulo

Extender la herramienta actual para que, además del módulo de arbitraje ya implementado, pueda evaluar estrategias de trading de corto plazo sobre monedas volátiles usando USDT como capital base o colateral simulado.

El sistema debe operar inicialmente como **simulador**, no como ejecutor real. Cada estrategia debe calcular rentabilidad neta considerando:

- Fees de entrada y salida.
- Spread.
- Slippage.
- Latencia.
- Liquidez.
- Tamaño de posición.
- Stop loss.
- Take profit.
- Tiempo máximo en posición.
- Reglas de riesgo.

El objetivo no es predecir el mercado, sino construir un motor que detecte escenarios operables, descarte señales débiles y explique cada decisión.

---

## 2. Alcance funcional

Este módulo debe permitir:

- Monitorear monedas volátiles contra USDT.
- Calcular features de mercado por símbolo.
- Generar señales long y short simuladas.
- Evaluar señales con reglas de riesgo.
- Simular entrada y salida de posiciones.
- Registrar P&L neto por estrategia.
- Comparar desempeño entre estrategias.
- Usar un agente AI como supervisor explicativo y recomendador.
- Mostrar señales, posiciones, rendimiento y recomendaciones en dashboard.

---

## 3. Tipos de operación soportados

### 3.1 Spot long con USDT

Flujo:

```txt
USDT → activo volátil → USDT
```

Es compatible con una wallet spot de USDT.

Ejemplo:

```txt
Comprar SOL con USDT
Vender SOL por USDT
```

Debe soportar:

- Compra simulada.
- Venta simulada.
- Fees.
- Slippage.
- Stop loss.
- Take profit.
- Timeout.
- P&L neto.

---

### 3.2 Short simulado con USDT como colateral

Con spot puro no existe short real. Este módulo debe tratar el short como una **posición simulada**, equivalente a operar perpetual futures o margin, pero sin ejecución real.

Flujo conceptual:

```txt
USDT como colateral
Abrir short sobre activo
Cerrar short recomprando más barato o más caro
```

Debe soportar:

- Entry price.
- Exit price.
- Position size.
- P&L simulado.
- Fees de apertura.
- Fees de cierre.
- Funding fee opcional.
- Riesgo de liquidación opcional.
- Stop loss obligatorio.
- Take profit obligatorio.
- Máximo tiempo en posición.

Recomendación inicial:

```txt
Short simulado sin apalancamiento
o apalancamiento bajo configurable
```

---

## 4. Feature Engine

El Feature Engine calcula métricas derivadas a partir de datos de mercado.

### 4.1 Features de precio

- Current price.
- Mid price.
- Return 30s.
- Return 1m.
- Return 3m.
- Return 5m.
- Return 15m.
- High/low por ventana.
- Distancia contra moving average.
- Z-score.
- Volatilidad reciente.

### 4.2 Features de volumen

- Volumen reciente.
- Volumen promedio por ventana.
- Volume spike ratio.
- Número de trades por minuto.
- Buy/sell volume, si está disponible.
- Cambio de volumen contra ventana anterior.

### 4.3 Features de order book

- Best bid.
- Best ask.
- Spread absoluto.
- Spread porcentual.
- Bid depth.
- Ask depth.
- Order book imbalance.
- Liquidez disponible para tamaño objetivo.
- Slippage estimado.

### 4.4 Features de riesgo

- Book age.
- Latencia.
- Spread anormal.
- Liquidez insuficiente.
- Volatilidad extrema.
- Cambio de precio durante ventana reciente.
- Riesgo de ejecución parcial.

---

## 5. Estrategias iniciales

### 5.1 Volatility Breakout Long

#### Objetivo

Comprar cuando una moneda rompe un rango reciente con volumen fuerte.

#### Entrada

```txt
price > high_last_N_minutes
volume > 2x average_volume
spread_pct <= max_spread
liquidity >= min_liquidity
book_age <= max_book_age
```

#### Salida

```txt
take_profit alcanzado
stop_loss alcanzado
timeout alcanzado
pérdida de momentum
spread se vuelve demasiado alto
```

#### Uso

Ideal para spot long con USDT.

---

### 5.2 Mean Reversion Long

#### Objetivo

Comprar después de una caída excesiva esperando un rebote.

#### Entrada

```txt
z_score < -2.5
return_5m < -X%
volume_spike = true
spread_ok = true
liquidity_ok = true
```

#### Salida

```txt
z_score vuelve a -1 o 0
take_profit alcanzado
stop_loss alcanzado
timeout alcanzado
```

#### Riesgo

Puede intentar comprar activos que siguen cayendo. Requiere stop loss y filtro de volatilidad.

---

### 5.3 Pump Exhaustion Short

#### Objetivo

Abrir short simulado cuando una moneda sube demasiado rápido y muestra señales de agotamiento.

#### Entrada

```txt
return_5m > 4%
volume_5m > 3x average_volume
z_score > 2.5
bid_depth decreasing
spread_pct <= max_spread
```

#### Salida

```txt
take_profit alcanzado
stop_loss alcanzado
timeout alcanzado
precio rompe nuevo máximo
riesgo de liquidación aumenta
```

#### Riesgo

Alto. Una moneda en pump puede seguir subiendo violentamente. Stop loss obligatorio.

---

### 5.4 Mean Reversion Short

#### Objetivo

Abrir short simulado cuando el precio se aleja demasiado por encima de su media reciente.

#### Fórmula

```txt
z_score = (mid_price - rolling_mean) / rolling_std
```

#### Entrada

```txt
z_score > 2.5
volume_spike = true
spread_ok = true
liquidity_ok = true
```

#### Salida

```txt
z_score < 1.0
take_profit alcanzado
stop_loss alcanzado
timeout alcanzado
```

#### Ventaja

Es medible, compacta y fácil de explicar.

---

### 5.5 Momentum Breakdown Short

#### Objetivo

Abrir short simulado cuando una moneda pierde estructura después de un impulso alcista.

#### Entrada

```txt
return_5m > 3%
current_price < lowest_price_last_60s
sell_pressure increasing
bid_depth_down = true
```

#### Salida

```txt
take_profit alcanzado
stop_loss alcanzado
timeout alcanzado
precio recupera nivel roto
```

#### Ventaja

Evita intentar adivinar el techo. Espera confirmación bajista.

---

### 5.6 Liquidity Shock Strategy

#### Objetivo

Detectar cambios bruscos en la profundidad del order book.

#### Imbalance

```txt
imbalance = bid_depth / (bid_depth + ask_depth)
```

#### Entrada long

```txt
imbalance > 0.65
spread stable
price breaking up
volume increasing
```

#### Entrada short

```txt
imbalance < 0.35
bid_depth falling
price failing highs
volume exhaustion
```

#### Salida

```txt
take_profit alcanzado
stop_loss alcanzado
timeout alcanzado
imbalance se normaliza
```

---

### 5.7 Statistical Opportunity Ranking

#### Objetivo

Rankear señales de distintas estrategias antes de convertirlas en posiciones simuladas.

#### Score sugerido

```txt
score =
  expected_profit_score
+ liquidity_score
+ volatility_score
+ momentum_score
- spread_penalty
- latency_penalty
- risk_penalty
```

#### Uso

Permite priorizar señales cuando varias estrategias disparan al mismo tiempo.

---

## 6. Strategy Engine

Cada estrategia debe implementar una interfaz común.

```php
interface TradingStrategy
{
    public function name(): string;

    public function evaluate(MarketContext $context): StrategySignal;
}
```

### StrategySignal

Debe incluir:

```txt
strategy_name
symbol
side: long | short
confidence_score
entry_price
suggested_size
take_profit
stop_loss
max_holding_time
reasons[]
risk_flags[]
created_at
```

### Estrategias iniciales

```txt
VolatilityBreakoutLongStrategy
MeanReversionLongStrategy
PumpExhaustionShortStrategy
MeanReversionShortStrategy
MomentumBreakdownShortStrategy
LiquidityShockStrategy
StatisticalOpportunityRankingStrategy
```

---

## 7. Risk Manager

Toda señal debe pasar por Risk Manager antes de convertirse en posición simulada.

### Reglas mínimas

- No operar si el spread es demasiado alto.
- No operar si la liquidez es insuficiente.
- No operar si el book está stale.
- No operar si la latencia supera el umbral.
- No operar si el profit esperado no supera fees y slippage.
- No operar si el tamaño de posición excede límite.
- No operar si ya hay demasiadas posiciones abiertas.
- No operar si hay demasiadas pérdidas consecutivas.
- No operar si se alcanzó pérdida diaria máxima.
- No operar sin stop loss.
- No operar si el confidence score es bajo.

### Circuit breakers

Activar pausa si:

```txt
3-5 pérdidas consecutivas
drawdown diario supera límite
latencia promedio supera máximo
exchange deja de actualizar
spread promedio se vuelve anormal
error recurrente de conexión
```

---

## 8. Position & Execution Simulator

El simulador debe modelar posiciones long y short sin operar dinero real.

### 8.1 Spot long

Entrada:

```txt
USDT disminuye
Activo aumenta
Fee de entrada se descuenta
```

Salida:

```txt
Activo disminuye
USDT aumenta
Fee de salida se descuenta
P&L neto se calcula
```

### 8.2 Short simulado

Entrada:

```txt
USDT queda como colateral
Se abre posición short
Se registra entry_price
```

Salida:

```txt
Se cierra posición short
P&L = (entry_price - exit_price) * size
Fees se descuentan
USDT disponible se actualiza
```

### 8.3 Debe calcular

- Entry price.
- Exit price.
- Position size.
- Gross P&L.
- Fees de entrada.
- Fees de salida.
- Slippage.
- Net P&L.
- Holding time.
- Razón de entrada.
- Razón de salida.
- Estado final.

### 8.4 Estados de posición

```txt
pending
open
closed
stopped_out
take_profit_hit
expired
rejected
liquidated_simulated
```

---

## 9. AI Supervisor Agent

### 9.1 Propósito

El AI Supervisor Agent analiza datos procesados, señales, desempeño y condiciones de mercado para ayudar a priorizar oportunidades y explicar decisiones.

No debe ejecutar operaciones directamente.

### 9.2 Funciones permitidas

- Resumir condiciones de mercado.
- Explicar señales fuertes o débiles.
- Priorizar oportunidades.
- Detectar cambios de régimen.
- Sugerir ajustes de parámetros.
- Detectar estrategias con mejor desempeño.
- Detectar patrones de pérdida.
- Generar alertas narrativas.
- Recomendar pausar estrategias riesgosas.
- Crear reportes de desempeño.

### 9.3 Funciones no permitidas

- Ejecutar operaciones reales.
- Saltarse el Risk Manager.
- Modificar balances.
- Abrir o cerrar posiciones directamente.
- Inventar datos no presentes en el sistema.
- Tomar decisiones no auditables.

---

## 10. Entrada de datos al AI Supervisor

El agente debe recibir datos resumidos, no ticks crudos.

Ejemplo:

```json
{
  "market_regime": "high_volatility",
  "top_signals": [
    {
      "strategy": "MeanReversionShort",
      "symbol": "SOL/USDT",
      "z_score": 3.1,
      "return_5m": 4.8,
      "volume_spike": 3.4,
      "spread_pct": 0.06,
      "expected_net_profit_pct": 0.42,
      "risk_flags": ["high_volatility"]
    }
  ],
  "recent_performance": {
    "win_rate": 0.58,
    "net_pnl": 124.5,
    "max_drawdown": 42.1,
    "loss_streak": 1
  },
  "engine_health": {
    "avg_latency_ms": 180,
    "stale_books": 0,
    "circuit_breaker": false
  }
}
```

---

## 11. Salida esperada del AI Supervisor

```json
{
  "summary": "El mercado muestra alta volatilidad con oportunidades principalmente de reversión.",
  "recommended_focus": ["MeanReversionShort", "VolatilityBreakoutLong"],
  "avoid": ["LowLiquidityScalping"],
  "parameter_suggestions": [
    {
      "parameter": "min_confidence_score",
      "current": 0.65,
      "suggested": 0.72,
      "reason": "Aumentaron señales falsas durante los últimos 20 minutos."
    }
  ],
  "alerts": [
    "SOL/USDT tiene buen momentum pero el spread subió; reducir tamaño."
  ]
}
```

---

## 12. Cómo el AI Supervisor acelera decisiones

### 12.1 Priorización

Reduce ruido destacando las mejores señales:

```txt
De 40 señales detectadas, solo 3 tienen buen balance entre profit esperado, liquidez y riesgo.
```

### 12.2 Explicabilidad

Convierte métricas en explicación clara:

```txt
Esta señal es fuerte porque el z-score está en 3.2, el volumen es 4x el promedio y el spread sigue bajo.
```

### 12.3 Detección de malas condiciones

Ejemplo:

```txt
La latencia está aumentando y las señales están expirando rápido. Conviene pausar nuevas entradas.
```

### 12.4 Ajuste de parámetros

Puede sugerir:

```txt
Subir min_confidence_score
Bajar position_size
Reducir max_holding_time
Desactivar estrategia temporalmente
Aumentar umbral de volumen
```

### 12.5 Post-mortem de pérdidas

Ejemplo:

```txt
Las últimas pérdidas ocurrieron en símbolos con spread mayor a 0.15%. Recomiendo bloquear símbolos con spread alto.
```

---

## 13. Dashboard sugerido

### 13.1 Dashboard general

- Estado del módulo de estrategias.
- P&L acumulado.
- Señales activas.
- Posiciones abiertas.
- Estrategias activas.
- Win rate.
- Drawdown.
- Latencia.
- Alertas AI.

### 13.2 Estrategias

- Nombre.
- Estado.
- Señales detectadas.
- Posiciones abiertas.
- Operaciones cerradas.
- Win rate.
- P&L.
- Profit promedio.
- Pérdida promedio.
- Última recomendación AI.

### 13.3 Señales

- Símbolo.
- Estrategia.
- Side.
- Confidence score.
- Entry esperado.
- Take profit.
- Stop loss.
- Riesgo.
- Estado.

Estados:

```txt
detected
approved
rejected
executed
expired
closed
```

### 13.4 Posiciones

- Símbolo.
- Side.
- Entry price.
- Current price.
- Size.
- Unrealized P&L.
- Stop loss.
- Take profit.
- Duración.
- Estado.

### 13.5 Historial

- Entrada.
- Salida.
- Fees.
- Slippage.
- P&L neto.
- Duración.
- Razón de entrada.
- Razón de salida.

### 13.6 AI Supervisor

- Resumen de mercado.
- Estrategias recomendadas.
- Estrategias a evitar.
- Alertas.
- Sugerencias de parámetros.
- Explicaciones de operaciones recientes.

---

## 14. Modelos de datos sugeridos

### strategies

```txt
id
name
type
enabled
config_json
created_at
updated_at
```

### strategy_signals

```txt
id
strategy_id
symbol
side
confidence_score
entry_price
suggested_size
take_profit
stop_loss
max_holding_time
status
reasons_json
risk_flags_json
created_at
```

### simulated_positions

```txt
id
strategy_signal_id
symbol
side
entry_price
exit_price
size
gross_pnl
fees
slippage
net_pnl
status
opened_at
closed_at
close_reason
```

### ai_recommendations

```txt
id
type
summary
payload_json
severity
status
created_at
```

### market_features

```txt
id
symbol
exchange
mid_price
return_1m
return_5m
volume_spike
z_score
spread_pct
bid_depth
ask_depth
imbalance
volatility
created_at
```

---

## 15. Implementación por fases

### Fase 1: Feature Engine

- Calcular returns.
- Calcular volatility.
- Calcular z-score.
- Calcular volume spike.
- Calcular spread.
- Calcular liquidity.
- Calcular imbalance.

### Fase 2: Strategy Engine básico

Implementar:

```txt
VolatilityBreakoutLong
MeanReversionLong
MeanReversionShort
PumpExhaustionShort
```

### Fase 3: Risk Manager

- Min confidence score.
- Max spread.
- Min liquidity.
- Max latency.
- Max position size.
- Max open positions.
- Stop loss obligatorio.
- Take profit obligatorio.
- Max holding time.

### Fase 4: Position Simulator

- Abrir/cerrar posiciones long.
- Abrir/cerrar shorts simulados.
- Calcular P&L neto.
- Aplicar fees y slippage.
- Registrar razones de entrada/salida.

### Fase 5: AI Supervisor

- Resumen de mercado.
- Explicador de señales.
- Priorizador de oportunidades.
- Recomendador de parámetros.
- Análisis post-trade.

### Fase 6: Dashboard

- Señales.
- Posiciones.
- Estrategias.
- Rendimiento.
- AI Supervisor.

---

## 16. Criterios de éxito

El módulo debe demostrar que:

- Genera señales explicables.
- Evalúa señales netas de costos.
- Rechaza oportunidades de baja calidad.
- Simula posiciones long y short correctamente.
- Calcula P&L neto.
- Compara estrategias.
- Usa AI como supervisor, no como ejecutor.
- Mantiene decisiones auditables y trazables.

---

## 17. Nota arquitectónica final

Este módulo debe vivir como **módulo experimental separado** del flujo principal de arbitraje ya implementado. No debe contaminar el core del challenge.

La integración recomendada es:

```txt
Market Data existente
        ↓
Feature Engine
        ↓
Strategy Engine
        ↓
Risk Manager
        ↓
Position Simulator
        ↓
Dashboard + AI Supervisor
```

El AI Supervisor debe ser una capa de análisis y explicación, no una capa de ejecución. Las decisiones finales deben mantenerse determinísticas, trazables y auditables.
