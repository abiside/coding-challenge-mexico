# Arquitectura multiusuario del engine de arbitraje

> Estado: **propuesta / plan**. Este documento define la arquitectura objetivo
> para evaluar, en un mismo flujo de datos de mercado, los criterios de múltiples
> usuarios con wallets y configuraciones distintas, y traza el plan por fases para
> llegar ahí desde el estado actual del código.

---

## 1. Contexto y problema

El engine recibe un flujo continuo de order books de varios exchanges (vía
`market:feed` → Redis pub/sub). Cada update de order book puede representar una
oportunidad de arbitraje, pero **cuya viabilidad depende del usuario**: sus fees,
sus umbrales de rentabilidad, su latencia tolerada, los exchanges donde opera y,
sobre todo, su wallet disponible.

El reto: el **evento disparador es el mismo para todos** (un order book nuevo),
pero la **evaluación y la decisión son privadas por usuario**. Necesitamos un
modelo que comparta lo que es común (datos de mercado) y aísle lo que es privado
(config + wallet + decisiones + persistencia), sin sacrificar consistencia ni
escala.

**Extensión:** un mismo usuario debe poder correr **varias estrategias en
paralelo, cada una con sus propias reglas** (símbolos, umbrales, fees, exchanges,
objetivo de optimización). La unidad de evaluación deja de ser el *usuario* y pasa
a ser la *estrategia/run*. Esto encaja con el modelo de autopilot ya insinuado en
`ArbitrageSetting` (`autopilot_max_challengers`, `optimization_objective`): correr
N variantes ("challengers") simultáneas y compararlas. La consecuencia
arquitectónica más delicada es la **propiedad de la wallet** entre las estrategias
de un mismo usuario (ver §3.6).

---

## 2. Estado actual (as-is)

El multiusuario ya existe en una **primera forma single-process, multi-contexto**.

| Pieza | Implementación actual |
|-------|-----------------------|
| Proceso | `App\Console\Commands\RunArbitrageBot` (`arbitrage:run`), un proceso ReactPHP |
| Contextos | `array $contexts`: un `EngineRuntime` por usuario activo |
| Config por usuario | `ArbitrageSetting::toEngineConfig()` (symbols, fees, thresholds, freshness, latency, circuit breaker) |
| Wallet por usuario | `WalletManager` por contexto; persistencia en `WalletBalance` (scoped `user_id`, con `version`) |
| Reconciliación | Timer 3 s: levanta/baja engines según `SimulationRun` activos |
| Aislamiento de salida | Dashboard a canal privado `arbitrage.user.{id}`; recorder scoped `user_id` |
| Fan-out de evento | `onMessage()` recorre **todos** los contextos y filtra por símbolos del usuario |
| Ensamblado | `EngineFactory::make(config, …, userId)` cablea el pipeline por usuario |

### Flujo actual por evento

```
Redis pmessage
   └─ RunArbitrageBot::onMessage(payload)
        └─ SnapshotHydrator::tryFromPayload()
        └─ for each context (usuario):
             if symbol no está en context.symbols → skip
             context.engine.onSnapshot(snapshot)     ← incluye store.apply() + scan()
                └─ por candidato: liquidez → rentabilidad → wallet → riesgo → simulación
```

### Limitación estructural

`ArbitrageEngine::onSnapshot()` hace **`store->apply()` + `scanner->scan()` juntos**
y se invoca **una vez por usuario**. Esto implica:

- Cada usuario mantiene **su propio `OrderBookStore`** → la misma foto del mercado
  se duplica y se reaplica `U` veces por evento (memoria y CPU O(U)).
- La **detección de candidatos** (spread top-of-book, independiente del usuario) se
  recomputa por usuario.
- Todo corre en **un solo proceso/hilo** → techo de CPU al crecer `U`.

El modelo conceptual es correcto; la realización no escala y el aislamiento es
parcial.

---

## 3. Arquitectura objetivo (to-be)

### 3.1 Principios rectores

1. **Mercado = compartido e inmutable** durante la evaluación.
2. **Config de usuario = privada e inmutable** (snapshot versionado).
3. **Wallet de usuario = privada y single-writer** (la muta un solo proceso).
4. **Evaluación = función pura**: `evaluar(estadoMercado, configUsuario, walletUsuario) → decisión`.

Si se respetan, evaluar a N usuarios sobre el mismo evento es **seguro y sin locks**:
todos *leen* el mismo mercado inmutable; cada quien *escribe* solo su propia wallet.

### 3.2 Componentes

```
┌──────────────────────────────────────────────────────────────────┐
│ Proceso worker (ReactPHP, single-thread)                           │
│                                                                    │
│  RedisMarketSubscriber ─► MarketState (1 por worker, COMPARTIDO)   │
│                                │                                   │
│                                ▼                                   │
│                       CandidateDetector (1 vez por evento)         │
│                                │  candidatos independientes de user│
│                                ▼                                   │
│        SubscriptionIndex: (symbol, exchange) → [UserContext]       │
│                                │                                   │
│                 ┌──────────────┼───────────────┐                  │
│                 ▼              ▼               ▼                   │
│           UserContext A   UserContext B   UserContext C            │
│           (config+wallet) (config+wallet) (config+wallet)         │
│           liquidez→rentab→wallet→riesgo→simulación                 │
└──────────────────────────────────────────────────────────────────┘
```

- **`MarketState`** (nuevo): único por worker. Aplica snapshots una sola vez;
  expone lectura inmutable de books frescos. Reemplaza al `OrderBookStore`
  por-usuario.
- **`CandidateDetector`** (refactor de `ArbitrageScanner`): detecta cruces
  `buy_ask < sell_bid` **una vez por evento**, independiente del usuario.
- **`SubscriptionIndex`** (nuevo): enruta cada evento solo a los usuarios
  interesados en ese `(symbol, exchange)` y que operan ambos exchanges.
- **`UserContext`** (evolución de `EngineRuntime`): config inmutable + wallet
  single-writer + pipeline privado (liquidez → rentabilidad → wallet → riesgo →
  simulación → persistencia/dashboard scoped por `user_id`).

### 3.3 Separación mercado/usuario en el engine

`ArbitrageEngine::onSnapshot()` se divide en dos responsabilidades:

```php
// Compartido: una vez por evento, por worker.
public function applyMarket(OrderBookSnapshot $snapshot, ?int $receivedAtMs = null): BookState;

// Detección compartida: candidatos independientes del usuario.
/** @return array<int, OpportunityCandidate> */
public function detect(BookState $updated, int $nowMs): array;

// Privado: por usuario interesado, sobre candidatos ya detectados.
/** @return array<int, ProcessedOpportunity> */
public function evaluateForUser(UserContext $ctx, array $candidates, int $nowMs): array;
```

El `onSnapshot()` actual se conserva como **fachada** (`applyMarket` + `detect` +
`evaluateForUser` de un solo contexto) para no romper consumidores existentes.

### 3.4 Modelo de concurrencia y escala

| Dimensión | Mecanismo | Por qué |
|-----------|-----------|---------|
| I/O de mercado (N WebSockets, Redis) | ReactPHP event loop | I/O-bound; concurrencia cooperativa |
| Detección + evaluación por usuario | `foreach` secuencial dentro del worker | CPU trivial (µs); paralelizar costaría más que el cálculo |
| Escala con número de usuarios `U` | **Sharding por proceso**: `hash(userId) % M` | El CPU crece con `U`; cada worker dueño de un subconjunto disjunto |
| Ejecución de órdenes (futuro real) | Promesas async sobre el loop | I/O de red lento; cooperativo, no threads |

**Sharding (Fase 3):** un `Supervisor` lanza `M` workers. Cada worker:
- Se suscribe al **mismo** Redis pub/sub y mantiene **su propio** `MarketState`.
- Es **dueño exclusivo** de un subconjunto de usuarios (y por tanto de sus wallets
  → single-writer sin locks entre procesos).
- Persiste con verificación de `version` (optimistic lock) en `WalletBalance`.

Trade-off: cada worker duplica el `MarketState` en memoria. Se acepta a cambio de
cero contención entre procesos. Se activa **solo cuando el perfil de CPU lo exija**.

### 3.5 Modelo de identidad: usuario → árbol de estrategias

La unidad oficial es la **Estrategia** (`Strategy`): un conjunto de
**configuraciones (reglas) + monederos** de un usuario. Cada usuario tiene un
**árbol de estrategias**, porque **cada estrategia nace de otra** (fork).

```
User
 └─ Estrategia génesis (G)              ← sembrada de la dotación por defecto
      ├─ Estrategia A  (fork de G)      ← nace con los balances de G en ese instante
      │    └─ Estrategia A1 (fork de A)
      └─ Estrategia B  (fork de G)
```

- Cada `Strategy` tiene su **propio rule-set** (config inmutable versionada), su
  **propio monedero** y un **`parent_strategy_id`** (salvo la génesis).
- El **contexto se indexa por `strategyId`**. El `userId` se conserva para
  autorización, propiedad y agrupación del árbol.
- **Persistencia y dashboard** pasan a scope por `strategyId` (con `userId` como
  columna de agrupación): `Opportunity`, `Trade`, `WalletBalance`, canal de Reverb y
  prefijo de snapshot incluyen el `strategyId`.

Cambios de modelo de datos (alto nivel):
- Entidad `Strategy` (puede materializarse sobre `SimulationRun`): **N activas por
  usuario**, cada una con su `config_snapshot` (rule-set inmutable) y
  `parent_strategy_id`.
- Al crear una estrategia se **congela su rule-set** (clon del padre o de la plantilla
  `ArbitrageSetting`), de modo que editar la plantilla no altere estrategias vivas.
- El registro de contextos del comando pasa de clave `u{userId}` a `s{strategyId}`.

### 3.6 Propiedad de la wallet entre estrategias del mismo usuario (decisión crítica)

Cuando un usuario corre varias estrategias en paralelo, **¿comparten saldo o no?**
Esta es la decisión de diseño más importante de la extensión.

> **Naturaleza de la app:** plataforma de **evaluación de inversión (paper
> trading)**, no transaccional. Por tanto los "monederos" son **ledgers simulados**,
> no fondos reales, y no se reparte un capital finito: cada estrategia recibe su
> **propia dotación simulada auto-aprovisionada** (ver §3.7).

| Opción | Descripción | Pros | Contras |
|--------|-------------|------|---------|
| **A. Monedero simulado aislado por estrategia** (recomendada) | Cada `Strategy` opera su **propio monedero simulado**, sembrado por fork del padre | Single-writer por estrategia trivial; PnL por estrategia medible de forma independiente (ideal para comparar/challengers/autopilot); shard por `strategyId`; cero fricción para el usuario | Estado de monedero replicado por estrategia (irrelevante al ser simulado) |
| **B. Wallet compartida por usuario** | Todas las estrategias del usuario mutan la misma wallet | Capital plenamente utilizado (relevante solo en modo transaccional real) | Las estrategias se acoplan; PnL no aislado → **no comparable**; obliga a co-ubicar runs del usuario (shard por `userId`); requiere arbitrar el saldo común |

**Recomendación: Opción A (monedero simulado aislado por estrategia).** Da
aislamiento limpio, single-writer natural por estrategia, y —lo más importante para una app
de evaluación— **PnL comparable entre estrategias**. La Opción B solo tendría sentido
si la app pasara a ser transaccional con capital real compartido; entonces la clave
de sharding **debe** ser `userId` y se añade arbitraje de saldo.

Implicación de sharding según la opción:

| Opción de wallet | Clave de sharding | Frontera single-writer |
|------------------|-------------------|------------------------|
| A (aislada) | `hash(strategyId) % M` | un monedero por estrategia |
| B (compartida) | `hash(userId) % M` | una wallet por usuario; runs del usuario co-ubicados |

### 3.7 Monederos simulados auto-aprovisionados por fork

Como la app es de evaluación (paper trading), los monederos se **siembran solos** al
crear la estrategia; el usuario no crea ni configura cuentas. La siembra es **por
fork del padre**, no desde una plantilla global (salvo la génesis).

- **Estrategia génesis.** La primera estrategia del usuario se siembra desde la
  dotación por defecto (`config/arbitrage.php → initial_balances`).
- **Fork por herencia de balances.** Al crear una estrategia nueva, el sistema
  **copia los balances actuales del padre** (snapshot en el instante del fork) a filas
  `WalletBalance` scoped por `strategyId`. Es una **copia independiente**: a partir de
  ahí padre e hija divergen; el padre no se ve afectado.
- **Cero pasos manuales.** El usuario solo elige de qué estrategia bifurcar (o usa la
  génesis); el monedero nace solo.
- **Rule-set heredado y editable.** Por defecto la hija hereda el rule-set del padre
  como punto de partida, que el usuario puede modificar (el sentido del fork: probar
  reglas distintas desde el mismo capital del padre).
- **Single-writer por estrategia.** Cada estrategia muta solo su propio monedero.

> Consecuencia: como cada estrategia nace de los balances del padre **en momentos
> distintos**, las dotaciones iniciales **difieren** entre estrategias. La
> comparabilidad ya no viene de un capital idéntico, sino de **métricas normalizadas
> por retorno** (ver §3.8).

### 3.8 Evaluación de desempeño: estrategias y reglas

Objetivo de la app: **evaluar desempeño**, no repartir recursos. El aislamiento de
monederos (Opción A) existe precisamente para poder medir limpiamente. La evaluación
es en **dos niveles**:

- **Nivel estrategia:** desempeño del conjunto completo (reglas + monedero).
- **Nivel regla:** el **efecto de una regla/parámetro concreto** sobre el desempeño,
  habilitado por el fork (ver §3.9).

Como las estrategias nacen por fork en momentos y capitales distintos (§3.7), la
comparación a nivel estrategia debe ser **normalizada por retorno**, no por equity
absoluto.

1. **Equity de nacimiento.** Al crear la estrategia se registra su equity inicial
   (mark-to-market en el instante del fork). Es la **base** para normalizar.
2. **Equity marcado a mercado (mark-to-market).** El valor de un monedero se expresa
   en una **moneda común** (p. ej. USDT): el USDT cuenta directo y los demás activos
   (BTC, …) se valúan al **precio actual** tomado del `MarketState`. Equity = suma
   valuada de todos los activos de la estrategia.
3. **Serie temporal por estrategia.** Persistir periódicamente y/o tras cada ejecución
   un snapshot: `strategy_id, captured_at, equity_quote, realized_pnl, unrealized_pnl,
   trades_count`. Nueva tabla `strategy_performance_snapshots`.
4. **Métricas normalizadas (clave para comparar).** Retorno % = `(equity_actual /
   equity_nacimiento) - 1`; curva **indexada base 100** desde el nacimiento; drawdown
   máximo; hit-rate; alineadas con `optimization_objective` de `ArbitrageSetting`
   (`net_pnl`, `volume`, `risk_adjusted`).
5. **Vista comparativa.** El dashboard superpone las **curvas de retorno normalizado**
   de las estrategias del usuario (comparables aunque difieran sus dotaciones) y, en
   una vista de **genealogía**, el equity absoluto con los puntos de fork.

### 3.9 Evaluación a nivel de reglas (atribución vía fork)

El fork convierte el árbol de estrategias en un **registro de experimentos**: cada
arista padre→hija representa un cambio de reglas evaluado sobre el mismo capital de
arranque.

- **Diff de reglas por fork.** Al bifurcar, registrar qué reglas/parámetros cambian
  respecto al padre (`rule_diff`: claves, valor padre, valor hija). El árbol queda
  anotado con "qué se cambió en cada paso".
- **Comparación controlada padre↔hija.** Como la hija nace con el **mismo equity que
  el padre** en el instante del fork y opera el mismo mercado, comparar sus retornos
  **desde el punto de fork** aísla el efecto del cambio de reglas (experimento casi
  controlado: misma base de capital, mismo mercado, única diferencia = el `rule_diff`).
- **Atribución agregada por regla.** Acumulando forks, se agrega el retorno normalizado
  por **valor de cada regla** a través del árbol para responder preguntas a nivel
  regla (p. ej. el efecto de `min_net_margin` o `freshness_ms` en el retorno ajustado a
  riesgo).
- **Vínculo con autopilot.** Esto es la base del modelo challenger/`optimization_objective`:
  generar forks que varían reglas, medir su retorno normalizado y conservar los
  ganadores.

> Caveat de validez: la comparación controlada es fiable cuando el `rule_diff` es
> **pequeño** (idealmente una sola regla) y el periodo de mercado comparado es el
> mismo. Cambios múltiples simultáneos confunden la atribución.

---

## 4. Análisis de brechas

| # | Brecha | Riesgo | Sev. | Fase |
|---|--------|--------|------|------|
| B1 | `OrderBookStore` duplicado por usuario; `apply()` O(U) por evento | Memoria/CPU escalan con `U` | Alta | 1 |
| B2 | Detección de candidatos recomputada por usuario | Trabajo redundante en hot path | Alta | 1 |
| B3 | Todo en un proceso | Techo de CPU | Alta | 3 |
| B4 | Routing lineal (`in_array` por contexto) sin índice | O(U) por evento aunque no apliquen | Media | 2 |
| B5 | Filtrado por exchanges habilitados del usuario ausente en el scan | Evalúa cruces donde el usuario no opera | Media | 2 |
| B6 | Sin aislamiento de fallos por contexto en `onMessage()` | Config mala podría afectar el loop | Media | 2 |
| B7 | Reconcile no detecta cambios de config en sesiones vivas | Ediciones de settings no surten efecto sin reinicio | Media | 2 |
| B8 | Sin verificación de ownership de wallet entre procesos | Doble escritura al shardear | Alta (al shardear) | 3 |
| B9 | Sin coalescing/backpressure por símbolo | Tormenta de eventos en símbolos activos | Baja/Media | 4 |
| B10 | Contexto y `SimulationRun` atados a 1-por-usuario (clave `u{userId}`) | No se pueden correr estrategias paralelas por usuario | Alta | 1b |
| B11 | Wallet única por usuario, sin monedero aislado por estrategia | Estrategias del mismo usuario se acoplan; PnL no aislado | Alta | 1b |
| B12 | No hay fork ni `parent_strategy_id`; monedero no se siembra del padre | Friccion: requeriría crear monederos a mano; sin linaje | Alta | 1b |
| B13 | No hay equity mark-to-market ni serie temporal por estrategia | No se puede comparar desempeño en el tiempo | Alta | 1c |
| B14 | Dotaciones difieren por fork; sin normalización por retorno | Comparación por equity absoluto sería injusta | Media | 1c |
| B15 | No se registra el `rule_diff` por fork ni atribución a nivel regla | No se puede evaluar el efecto de reglas individuales | Media | 1c |

---

## 5. Plan por fases

Cada fase es entregable e independiente. El orden maximiza retorno arquitectónico
y minimiza riesgo.

### Fase 0 — Contratos y definición (sin cambios funcionales)

**Objetivo:** congelar interfaces antes de tocar el hot path.

Tareas:
- Definir interfaces `MarketStateInterface` (lectura inmutable) y
  `UserContext` (config + wallet + pipeline).
- Documentar el contrato de la función pura de evaluación.
- Definir la clave de sharding `hash(userId) % M` y la garantía de ownership.

Archivos: este documento + nuevos contratos en `app/Arbitrage/Contracts/`.

Criterio de aceptación: interfaces revisadas y aprobadas; sin cambios de
comportamiento.

---

### Fase 1 — Desacoplar mercado de evaluación (B1, B2)

**Objetivo:** un solo `MarketState` por proceso; detección una vez por evento.

Tareas:
1. Extraer `MarketState` a partir de `OrderBookStore` (aplica snapshots una vez,
   expone `freshExcept`/lectura inmutable).
2. Refactor de `ArbitrageEngine`:
   - `applyMarket()` y `detect()` compartidos.
   - `evaluateForUser(UserContext, candidates, nowMs)` privado.
   - `onSnapshot()` pasa a ser fachada.
3. Cambiar `RunArbitrageBot::onMessage()`:
   ```php
   $book = $market->apply($snapshot);          // 1 vez
   $candidates = $detector->detect($book, $now); // 1 vez
   foreach ($interested as $ctx) {
       $ctx->engine->evaluateForUser($ctx, $candidates, $now);
   }
   ```
4. Cada `UserContext` deja de tener su propio `OrderBookStore`; comparte el
   `MarketState` del worker (los `BookState` son de solo lectura durante la
   evaluación).

Archivos tocados: `app/Arbitrage/Engine/ArbitrageEngine.php`,
`app/Arbitrage/Engine/ArbitrageScanner.php` → `CandidateDetector`,
`app/Arbitrage/MarketData/OrderBookStore.php`, `EngineFactory`,
`RunArbitrageBot`.

Criterio de aceptación:
- `apply()` se invoca **una vez por evento** (no O(U)).
- Resultados de decisión por usuario idénticos a los actuales (test de regresión).
- Memoria estable al añadir usuarios (no crece el número de books).

Riesgo: tocar el hot path. Mitigación: fachada que preserva la API y tests de
regresión sobre decisiones.

---

### Fase 1b — Estrategias paralelas por usuario (B10, B11)

**Objetivo:** un usuario corre N estrategias simultáneas, cada una con sus reglas y
su wallet aislada (Opción A de §3.6).

Tareas:
1. **Modelo de datos**:
   - Entidad `Strategy` (sobre `SimulationRun`): **N activas por usuario**, con
     `config_snapshot` (rule-set inmutable) y **`parent_strategy_id`**.
   - `WalletBalance` pasa a scope por `strategy_id` (además de `user_id`).
2. **Operación de fork (§3.7)**:
   - **Génesis**: primera estrategia del usuario, monedero sembrado desde
     `initial_balances`.
   - **Fork**: estrategia nueva copia el **snapshot de balances actuales del padre** a
     `WalletBalance` scoped por `strategy_id`, y hereda su rule-set como base editable.
     Sin pasos manuales: el usuario solo elige el padre.
   - Registrar `birth_equity` (mark-to-market al instante del fork) para normalizar
     comparaciones (§3.8) y el `rule_diff` respecto al padre para atribución de reglas
     (§3.9).
3. **`StrategyContext`**: reorientar `EngineRuntime` para indexarse por `strategyId`;
   cada estrategia tiene su `WalletManager` sembrado del fork.
4. **Comando `arbitrage:run`**:
   - Cambiar la clave de `$contexts` de `u{userId}` a `s{strategyId}`.
   - Reconcile sobre estrategias activas: levanta/baja por estrategia.
   - `--user=` filtra las estrategias del usuario; añadir `--strategy=` para una sola.
5. **Aislamiento de salida**: canal de dashboard y prefijo de snapshot por
   `strategyId` (extender `ArbitrageCacheKeys`).
6. **Persistencia**: `Opportunity`/`Trade`/recorder scoped por `strategyId`.

Archivos: `SimulationRun`/nueva entidad `Strategy` (+ migración con
`parent_strategy_id`, `birth_equity`), `WalletBalance` (+ migración `strategy_id`),
`ArbitrageCacheKeys`, `EngineFactory` (param `strategyId`),
`EngineRuntime`→`StrategyContext`, `RunArbitrageBot`, `BufferedOpportunityRecorder`,
`ReverbDashboardPublisher`, servicio de fork.

Criterio de aceptación:
- Un usuario con 3 estrategias activas ve 3 evaluaciones independientes por evento,
  cada una con su propio monedero y su propio PnL.
- Crear una estrategia por fork copia los balances del padre en ese instante; padre e
  hija divergen sin afectarse.
- Detener una estrategia no afecta a las demás; editar la plantilla no altera
  estrategias en vuelo (rule-set congelado).

Decisión previa: confirmar Opción A vs B de §3.6 (impacta la clave de sharding de la
Fase 3).

---

### Fase 1c — Evaluación de desempeño: estrategias y reglas (B13, B14, B15)

**Objetivo:** medir y comparar el rendimiento de las estrategias de un usuario a lo
largo del tiempo (§3.8) y atribuir desempeño a reglas individuales vía fork (§3.9).

Tareas:
1. **Valuación mark-to-market**: servicio que toma el `snapshot()` del `WalletManager`
   de una estrategia y lo valúa en moneda común (USDT) usando precios actuales del
   `MarketState` (equity = USDT + activos valuados).
2. **Serie temporal**: tabla `strategy_performance_snapshots`
   (`strategy_id`, `captured_at`, `equity_quote`, `realized_pnl`, `unrealized_pnl`,
   `trades_count`). Snapshot periódico (timer del loop) y/o tras cada ejecución.
3. **Normalización por retorno**: usar el `birth_equity` (Fase 1b) para derivar
   retorno % = `(equity_actual / birth_equity) - 1` y curva **indexada base 100**;
   más drawdown máximo y hit-rate, alineadas con `optimization_objective`.
4. **Evaluación a nivel regla (§3.9)**: persistir el `rule_diff` por fork (en la
   entidad `Strategy`); comparación controlada padre↔hija desde el punto de fork; y
   agregación de retorno normalizado por valor de regla a través del árbol.
5. **API + dashboard comparativo**: endpoint que devuelve las curvas (retorno
   normalizado y equity absoluto) de las estrategias del usuario y una tabla de
   métricas; vista que las superpone + vista de genealogía con puntos de fork y el
   `rule_diff` de cada arista.

Archivos: nueva tabla/migración (`strategy_performance_snapshots`, `rule_diff` en
`Strategy`), nuevo `PerformanceTracker`/`EquityValuator`, servicio de atribución de
reglas, ajustes en `MetricsAggregator`, endpoint REST y vista de dashboard.

Criterio de aceptación:
- Para un usuario con varias estrategias se obtienen curvas de **retorno normalizado**
  comparables aunque sus dotaciones iniciales difieran (por fork).
- El equity refleja activos no-USDT valuados a mercado, no solo el saldo en USDT.
- La vista de genealogía muestra de qué estrategia se bifurcó cada una y qué regla
  cambió (`rule_diff`).
- Una comparación padre↔hija con un único cambio de regla atribuye la diferencia de
  retorno a esa regla.

---

### Fase 2 — Routing, filtrado e isolation (B4, B5, B6, B7)

**Objetivo:** enrutar solo a usuarios afectados; aislar fallos; hot-reload de config.

Tareas:
1. **`SubscriptionIndex`**: `(symbol, exchange) → [contextos]`, reconstruido en
   reconcile. `onMessage` consulta el índice en vez de recorrer todos.
2. **Exchanges habilitados por usuario**: añadir campo a `ArbitrageSetting`
   (p. ej. `enabled_exchanges`), propagarlo a `toEngineConfig()` y filtrar
   contrapartes en `CandidateDetector` / `freshExcept`.
3. **Aislamiento de fallos**: `try/catch` por contexto en el fan-out + métrica de
   error por usuario; un contexto que lanza no detiene a los demás.
4. **Hot-reload de config**: reconcile compara `updated_at`/hash de
   `ArbitrageSetting` y rehidrata el `UserContext` afectado (preservando wallet en
   vuelo).

Archivos: nuevo `app/Arbitrage/Engine/SubscriptionIndex.php`,
`ArbitrageSetting` (+ migración), `RunArbitrageBot`, `CandidateDetector`,
`OrderBookStore::freshExcept`.

Criterio de aceptación:
- Un evento de un exchange que el usuario no opera **no** genera evaluación para él.
- Cambiar settings de un usuario activo surte efecto en ≤ 1 ciclo de reconcile.
- Una excepción en un contexto queda registrada y aislada.

---

### Fase 3 — Sharding multiproceso (B3, B8)

**Objetivo:** escalar CPU con el número de usuarios preservando single-writer.

Tareas:
1. **`ArbitrageSupervisor`**: lanza `M` workers (extiende el patrón de procesos
   hijos de `ReactParallelRunner`), reinicia caídos, reparte la carga según la clave
   de sharding definida en §3.6:
   - Opción A (monedero aislado por estrategia): `hash(strategyId) % M`.
   - Opción B (wallet compartida por usuario): `hash(userId) % M` (todas las
     estrategias del usuario co-ubicadas en el mismo worker).
2. Cada worker `psubscribe` a Redis y mantiene su propio `MarketState`.
3. **Ownership de wallet** por shard + **optimistic lock** con la columna
   `version` al escribir `WalletBalance` (rechazar escritura si la versión cambió).
4. Routing de reconcile respeta el shard: cada worker solo levanta las estrategias que
   le corresponden.

Archivos: nuevo `app/Arbitrage/Support/ArbitrageSupervisor.php`, ajustes en
`RunArbitrageBot` (modo worker con `--shard`/`--shards`), persistencia de wallet.

Criterio de aceptación:
- Un usuario es atendido por **exactamente un** worker.
- Bajo carga, el CPU se reparte entre workers.
- Ninguna doble escritura de wallet (verificada con `version`).

Decisión previa: umbral para activar sharding (p. ej. `> X` usuarios activos o
`> Y%` CPU sostenido).

---

### Fase 4 — Robustez operativa (B9 + hardening)

**Objetivo:** estabilidad bajo carga y observabilidad fina.

Tareas:
- **Coalescing/debounce por símbolo** opcional: procesar el último estado cada
  `X` ms en símbolos muy activos.
- Confirmar **circuit breaker** y límites de riesgo **por usuario**.
- Observabilidad: heartbeat y métricas por shard y por usuario; alertas de lag de
  evaluación.

Criterio de aceptación: el engine mantiene latencia de evaluación acotada bajo
ráfagas; métricas por usuario y por shard disponibles.

---

## 6. Decisiones abiertas

1. **Propiedad de la wallet entre estrategias del mismo usuario (§3.6)**: Opción A
   (monedero aislado por estrategia) vs Opción B (wallet compartida con arbitraje).
   *Recomendación:* Opción A. **Define la clave de sharding de la Fase 3**, así que
   debe confirmarse antes de la Fase 1b.
2. **Siembra por fork (§3.7)**: la génesis se siembra de `initial_balances`; el resto
   **hereda los balances del padre** al instante del fork. ¿El fork copia también el
   rule-set del padre como base editable? *Recomendación:* sí (heredar reglas del
   padre, editable). Comparabilidad resuelta por **retorno normalizado** (§3.8), no
   por dotación idéntica.
3. **Memoria vs simplicidad del `MarketState`**: ¿un store por worker (duplicado) o
   un store compartido en proceso único hasta que la carga lo justifique?
   *Recomendación:* Fase 1 ya; sharding (Fase 3) solo bajo presión de CPU.
4. **Umbral de sharding**: ¿a partir de cuántos runs activos/qué CPU se activa?
5. **Modelado de exchanges por usuario/run**: ¿en `ArbitrageSetting`/`config_snapshot`
   o vía cuentas/keys reales?
6. **Hot-reload de config**: con rule-set congelado por estrategia (Fase 1b), editar la
   plantilla no afecta runs vivos; ¿se ofrece "reiniciar run con nuevas reglas"?

---

## 7. Riesgos y mitigaciones

| Riesgo | Mitigación |
|--------|------------|
| Refactor del hot path (Fase 1) introduce regresiones | Fachada que preserva API + tests de regresión de decisiones |
| Duplicación de `MarketState` en sharding | Activar sharding solo bajo necesidad; medir memoria |
| Doble escritura de wallet al shardear | Ownership por shard + optimistic lock por `version` |
| Config mala de un usuario afecta a otros | Aislamiento `try/catch` por contexto (Fase 2) |
| Tormenta de eventos en símbolos activos | Coalescing por símbolo (Fase 4) |
| Estrategias del mismo usuario se acoplan por la wallet | Opción A: monedero aislado por estrategia (§3.6, Fase 1b) |
| Muchos runs por usuario multiplican el fan-out | Índice de suscripción + filtro por exchanges/símbolos del run (Fase 2) |

---

## 8. Estrategia de pruebas

- **Regresión de decisiones (Fase 1):** mismo set de snapshots → mismas decisiones
  antes/después del refactor.
- **Estrategias paralelas (Fase 1b):** un usuario con varios runs produce
  evaluaciones y PnL independientes; detener un run no afecta a los demás; editar la
  plantilla no altera runs en vuelo; el monedero de un run no consume saldo de otro;
  al abrir un run se auto-siembra su monedero desde la dotación estándar.
- **Desempeño (Fase 1c):** el equity valúa activos no-USDT a mercado; el retorno
  normalizado (base 100 desde `birth_equity`) hace comparables estrategias con
  dotaciones distintas; una comparación padre↔hija con un solo cambio de regla atribuye
  la diferencia de retorno a esa regla (§3.9).
- **Aislamiento (Fase 2):** evento de exchange no habilitado no dispara evaluación;
  excepción en un contexto no afecta a otros.
- **Sharding (Fase 3):** un usuario atendido por un solo worker; sin doble escritura
  de wallet bajo concurrencia simulada.
- **Carga (Fase 4):** ráfagas de eventos mantienen latencia de evaluación acotada.

---

## 9. Resumen ejecutivo

El multiusuario ya funciona en modo single-process multi-contexto, pero **duplica
estado de mercado y recomputa la detección por usuario**, y la unidad de ejecución
está atada a *un run por usuario*. El plan formaliza el modelo *mercado compartido /
estado privado por estrategia* y lo lleva, por fases, a una arquitectura
**desacoplada (Fase 1), con estrategias paralelas por usuario y wallet aislada
(Fase 1b), enrutada y aislada (Fase 2), escalable por sharding (Fase 3) y robusta
bajo carga (Fase 4)**.

La unidad de evaluación pasa de **usuario** a **Estrategia** (`Strategy` = reglas +
monederos): cada usuario tiene un **árbol de estrategias** donde **cada estrategia
nace por fork de otra**, heredando los balances del padre en ese instante (la génesis
se siembra de la dotación por defecto). Cada estrategia tiene **monedero simulado
aislado y single-writer** (Opción A, §3.6–3.7), auto-aprovisionado sin fricción. Como
la app es de **evaluación, no transaccional**, y las dotaciones difieren por fork, la
comparación de desempeño se hace por **retorno normalizado** (curva base 100 desde el
`birth_equity`, §3.8, Fase 1c), no por equity absoluto. La evaluación es en **dos
niveles**: de **estrategias** completas y de **reglas** individuales, esto último
gracias a que cada fork registra su `rule_diff` y permite comparar padre↔hija como un
experimento controlado (§3.9). La Fase 1 es la de mayor
retorno y se recomienda primero, seguida de 1b (estrategias por fork) y 1c
(comparación); el sharding queda "preparado pero no activado" hasta que el volumen de
estrategias lo exija.
