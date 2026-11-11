# Reportes, panel principal y exportaciones

Este documento describe el módulo de **Reportes operativos** (incremento F), el **Panel principal** y la
arquitectura de **exportaciones a Excel y PDF** que ambos reutilizan.

Relacionados: [bandejas-y-asignacion.md](bandejas-y-asignacion.md) (ciclo de vida de conversaciones),
[mensajeria-whatsapp.md](mensajeria-whatsapp.md) (estados de mensajes),
[notificaciones-y-horarios.md](notificaciones-y-horarios.md) (horarios laborales), [tiempo-real.md](tiempo-real.md),
[decisiones.md](decisiones.md) (ADR-REP-*).

---

## 1. Qué ofrece

| Pantalla            | Ruta                                                                                   | Permiso                                                               | Contenido                                                                                                                                                                                                       |
| ------------------- | -------------------------------------------------------------------------------------- | --------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Reportes operativos | `GET /reports` (`reports.index`)                                                       | `reports.view`                                                        | Cuatro vistas: **Resumen** (volumen, evolución, tiempos, demanda), **Productividad** (por colaborador), **Cumplimiento de SLA** y **Desgloses** (departamento, categoría, conexión, canal, responsable actual). |
| Panel principal     | `GET /dashboard`                                                                       | `dashboard.view`                                                      | Ejecutivo, por preguntas: «Ahora» (en vivo), «Resultado del periodo», «Tiempos y cumplimiento», «Carga de trabajo», «Demanda», «Distribución» y «Equipo».                                                       |
| Exportación         | `POST /exports/{analytics\|agent-productivity\|sla-compliance\|dashboard}/{xlsx\|pdf}` | `reports.view` + `reports.export` (`dashboard.view` para `dashboard`) | Los mismos indicadores, con los mismos filtros aplicados en el servidor (formulario con token CSRF; un GET responde 405).                                                                                       |

`reports.view` existía en el catálogo como permiso "futuro"; desde este incremento está **disponible** (se otorga desde
Roles). El rol _Supervisor_ ya lo tenía; _Agente_ no (un rol de alcance "Solo lo asignado" con `reports.view` ve solo
sus propias métricas). Administrador y Superadministrador lo tienen por ser globales.

## 2. Alcance (quién ve qué)

`App\Services\Analytics\AnalyticsScope`, por **alcance del rol** (nunca por nombre de rol):

| Alcance              | Métricas que agrega                                                                                                                                                          |
| -------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Toda la organización | Todas.                                                                                                                                                                       |
| Sus departamentos    | Conversaciones cuyo departamento **actual** es suyo, más las que atiende personalmente.                                                                                      |
| Solo lo asignado     | Solo las conversaciones de las que es (o fue, si se cerraron) responsable. No ve disponibilidad de compañeros, ni filtros de departamento/agente, ni tabla por departamento. |

Las cifras por colaborador (`conversation_agent_metrics`) y los ciclos de respuesta (`response_cycles`) siguen el mismo
criterio sobre sus propias columnas: global, todo; departamentos, las filas de sus departamentos más las propias;
asignado, **solo las filas de la propia persona** (nunca cifras de compañeros: prueba «a personal reach receives nothing
about colleagues through the page props»).

Los filtros (`department`, `agent`, `connection`, `channel`, `category`, `status`) se **validan contra las opciones del usuario**: un ID fuera de su
alcance se descarta (no se aplica, no se devuelve en `filters`, no se revela su nombre), lo que evita IDOR y
enumeración por conteos. Las exportaciones usan la misma clase con el usuario solicitante, y el trabajo en cola vuelve
a autorizar al ejecutarse (comportamiento del motor de exportación).

## 3. Definición de cada métrica

> **Glosario vigente (fase de reportes e indicadores).** Cada indicador se define **una sola vez** en
> `App\Services\Analytics` y el panel, los reportes y las exportaciones leen esa misma definición. La tabla de §3.0
> manda sobre la tabla histórica que le sigue cuando difieran los nombres («Resueltas» ahora se llama «Finalizadas»).

### 3.0 Glosario de indicadores

Unidad de conteo: la **solicitud** (una conversación). Cuenta **una vez** sin importar reasignaciones, cambios de estado
o número de mensajes. Los conteos de **mensajes** se rotulan siempre como mensajes y nunca se suman con solicitudes.

| Indicador                                       | Definición exacta                                                                                                                                                                                                                                                                                       | Fuente                                                                  | Excluye / notas                                                                                                                                                          |
| ----------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Recibidas                                       | Solicitudes creadas en el periodo (zona horaria de la plataforma).                                                                                                                                                                                                                                      | `conversation_metrics.received_at`                                      | Historial importado. Una reapertura no es una recibida nueva.                                                                                                            |
| Atendidas                                       | De las recibidas, las que tuvieron atención humana: aceptación, asignación manual, transferencia a un agente o respuesta humana.                                                                                                                                                                        | `first_assigned_at`                                                     | La distribución automática sola no es atención.                                                                                                                          |
| Pendientes ahora                                | Solicitudes en estado _Pendiente_ en este momento.                                                                                                                                                                                                                                                      | `conversations` en vivo                                                 | No depende del periodo ni del filtro de estado; sin historial importado.                                                                                                 |
| En atención ahora                               | Estado `open` (y el heredado `on_hold`) en este momento.                                                                                                                                                                                                                                                | `conversations` en vivo                                                 | Ídem.                                                                                                                                                                    |
| Finalizadas (de las recibidas)                  | De las recibidas en el periodo, las cerradas al menos una vez. **% resueltas** = finalizadas / recibidas.                                                                                                                                                                                               | `resolved_at` no nulo en la cohorte                                     | Una reapertura posterior no la quita (se cuenta en Reabiertas).                                                                                                          |
| Finalizadas en el periodo                       | Solicitudes cuyo **primer cierre** ocurrió en el periodo, sin importar cuándo llegaron. Es la única cifra agrupada por fecha de cierre (línea «Finalizadas» de la gráfica de volumen).                                                                                                                  | `resolved_at` en el rango                                               | Un recierre tras reapertura no vuelve a contarla.                                                                                                                        |
| Finalizadas hoy (panel, en vivo)                | Cerradas desde las 00:00 de hoy y que **siguen cerradas**, entre las que la persona ve en su bandeja.                                                                                                                                                                                                   | `conversations.closed_at`                                               | Cifra de bandeja en vivo, rotulada aparte de las dos anteriores.                                                                                                         |
| Acumuladas sin atender (backlog)                | Solicitudes recibidas que, en un momento dado, no tenían atención humana ni cierre. Tendencia: valor al cierre de cada día/semana/mes; los periodos sin terminar quedan vacíos.                                                                                                                         | `left_backlog_at` (= lo primero entre primera atención y primer cierre) | No aplica el filtro de estado.                                                                                                                                           |
| (a) Espera hasta la primera respuesta           | Creación → primer mensaje saliente escrito por una persona, no automático, aceptado por el proveedor. Promedio, **mínimo y máximo**, n.                                                                                                                                                                 | `seconds_to_first_human_response`                                       | Automáticos, fallidos y en cola. Sin respuesta = sin dato (no 0).                                                                                                        |
| (b) Tiempo de respuesta por mensaje del cliente | Por ciclo de respuesta: mensaje del cliente que espera → respuesta humana. Promedio, mínimo, máximo, n.                                                                                                                                                                                                 | `response_cycles.response_seconds` (ciclos `answered`)                  | Solo existe desde esta versión (§3.3).                                                                                                                                   |
| (c) Duración total hasta la resolución          | Creación → primer cierre. Promedio, mínimo, máximo, n.                                                                                                                                                                                                                                                  | `seconds_to_resolution`                                                 | Sin cierre = sin dato.                                                                                                                                                   |
| (d) Tiempo de atención por colaborador          | Suma de los tramos en que la persona fue responsable de la solicitud; promedio por solicitud sobre los tramos **concluidos**.                                                                                                                                                                           | `conversation_agent_metrics.handling_seconds`                           | Un tramo en curso (`open_since`) no se promedia ni se congela.                                                                                                           |
| Espera hasta la primera atención                | Creación → primera atención humana.                                                                                                                                                                                                                                                                     | `seconds_to_first_attention`                                            | Solo calendario.                                                                                                                                                         |
| Tiempo hábil                                    | (a) y (c) contando solo el horario del departamento receptor (§3.1).                                                                                                                                                                                                                                    | `business_seconds_*`                                                    | Congelado al calcularse.                                                                                                                                                 |
| Cumplimiento de SLA                             | Ciclos de respuesta **iniciados en el periodo** con objetivo: cumplido = respondido con `responded_at ≤ due_at`; incumplido = venció (`breached_at`, respondido tarde, o abierto con `due_at` ya pasado). **% = cumplidos / (cumplidos + incumplidos)**, siempre con n.                                 | `response_cycles`                                                       | Sin objetivo (`target_seconds` nulo), en curso dentro del plazo y cerrados sin respuesta antes del plazo: no se evalúan. Sin ciclos evaluados: «Sin datos», nunca 100 %. |
| Retrasos por departamento / categoría           | Ciclos incumplidos por el departamento / categoría **congelados en el ciclo**.                                                                                                                                                                                                                          | `response_cycles`                                                       | —                                                                                                                                                                        |
| Vencidas ahora                                  | Ciclos abiertos ahora con el plazo vencido, por persona asignada (o en cola).                                                                                                                                                                                                                           | `response_cycles` (`active = 1`)                                        | En vivo.                                                                                                                                                                 |
| Atendidas por colaborador                       | Solicitudes de la cohorte en las que la persona participó con atención humana (aceptó, recibió por asignación manual o transferencia, o respondió).                                                                                                                                                     | `conversation_agent_metrics.attended_at`                                | **Una solicitud atendida por dos personas cuenta para ambas**: la suma puede superar el total; se muestra además el total de solicitudes distintas.                      |
| Asignadas por colaborador                       | Solicitudes de las que fue responsable en algún momento (incluye distribución automática).                                                                                                                                                                                                              | `was_responsible`                                                       | —                                                                                                                                                                        |
| Finalizadas por colaborador                     | Cierres ocurridos mientras la persona era responsable; reabierta y recerrada cuenta cada cierre real, para quien era responsable entonces.                                                                                                                                                              | `closes_count`                                                          | —                                                                                                                                                                        |
| Respuestas enviadas (mensajes)                  | Mensajes humanos aceptados por el proveedor escritos por la persona.                                                                                                                                                                                                                                    | `replies_count`                                                         | Automáticos y fallidos. Son mensajes, no solicitudes.                                                                                                                    |
| Pendientes actuales por colaborador             | Solicitudes asignadas ahora y sin cerrar.                                                                                                                                                                                                                                                               | `conversations` en vivo                                                 | No depende del periodo.                                                                                                                                                  |
| SLA por colaborador                             | Cumplidos → quien respondió (`responder_user_id`); incumplidos → responsable al vencer (`breach_user_id`; nulo = en cola, no se atribuye a nadie). «Respondió tarde» es informativo.                                                                                                                    | `response_cycles`                                                       | Cada ciclo evaluado cuenta para una sola persona como máximo.                                                                                                            |
| Cartera por responsable actual                  | Cifras por conversación agrupadas por el responsable actual o último (`agent_id`).                                                                                                                                                                                                                      | `conversation_metrics`                                                  | **No mide trabajo individual** (para eso, Productividad). Sus mensajes salientes incluyen automáticos y se rotulan así.                                                  |
| Agentes activos                                 | Cuentas activas con permiso de responder dentro del alcance. Una sola definición (`OperationalAnalytics::activeAgents()`), usada por panel y reportes.                                                                                                                                                  | `users`                                                                 | Alcance personal: no se muestra.                                                                                                                                         |
| Horarios de mayor demanda                       | Recibidas por día de la semana y hora local.                                                                                                                                                                                                                                                            | `received_at`                                                           | —                                                                                                                                                                        |
| Semana                                          | Semana ISO 8601 (lunes a domingo) en la zona horaria de la plataforma; clave `2026-W43`.                                                                                                                                                                                                                | `AnalyticsPeriod::buckets()` / `bucketSql()`                            | Respeta cambios de horario de verano.                                                                                                                                    |
| Comparación                                     | Periodo anterior de igual duración. **Si el periodo actual no ha terminado, se compara contra el mismo tramo transcurrido del anterior** (`AnalyticsPeriod::comparison()`), y la interfaz lo dice. La línea «periodo anterior» de las gráficas usa el periodo anterior completo, alineado por posición. | —                                                                       | —                                                                                                                                                                        |

**Qué significa cada filtro** (idéntico en pantalla, gráficas y exportaciones; `AnalyticsDimensions`): departamento,
categoría y estado son los **actuales** de la solicitud; agente es el responsable actual o último en las cifras por
conversación y la fila de la propia persona en Productividad; conexión/canal es por donde llegó. El **estado no aplica**
a las cifras en vivo, al acumulado ni a los ciclos de respuesta, y cada panel afectado lo indica. En los ciclos, las
dimensiones son las congeladas al iniciar el ciclo y «agente» es a quién pertenece el ciclo (ver SLA por colaborador).

### 3.2 Metodología de atribución por colaborador

`App\Services\Analytics\AgentAttributionCalculator` reconstruye, por conversación, una fila por persona en
`conversation_agent_metrics` a partir de `conversation_events` (solo se agregan, nunca se editan) y de
`messages.sender_user_id`. Lo invoca el mismo `ConversationMetricsCalculator::recompute()` (mismo job, mismo
`analytics:rebuild`), reemplaza las filas completas y es idempotente.

- **Línea de responsabilidad** (eventos en orden de creación e id): `accepted`, `assigned`, `auto_assigned` y `reopened`
  con `to_user_id` → esa persona pasa a ser responsable; `transferred` → el agente destino (nadie si va a un
  departamento), con transferencia de salida para el origen y de entrada para el destino; `unassigned` → nadie;
  `closed` → termina el tramo y el cierre se acredita a `from_user_id` (responsable en ese instante).
- **Tiempo de atención**: suma de tramos concluidos. El tiempo en que la conversación estuvo cerrada no es de nadie.
  Un tramo en curso se guarda como `open_since` solo si la conversación lo confirma (no cerrada y asignada a esa
  persona) y nunca se congela como duración.
- **Atención**: aceptar, recibir por asignación manual o transferencia, o responder. La distribución automática hace
  responsable pero no es atención (ADR-REP-4).
- **Supervisión sin asignación**: quien responde sin ser responsable cuenta por sus respuestas (fila sin tramos).
- **Primera respuesta**: se acredita, con su demora, al autor del primer mensaje humano aceptado.
- **Historial que no explica al responsable actual** (datos anteriores a la bitácora de eventos o creados fuera de las
  acciones normales): la persona asignada recibe su fila (`was_responsible`, `responsible_now`) **sin tramos**: el
  tiempo es desconocido y queda fuera de todo promedio en lugar de inventarse.
- `conversation_metrics.agent_id` conserva su significado (responsable actual o último) para el alcance, el filtro y la
  «Cartera por responsable actual»; ningún consumidor lo usa ya como medida de trabajo individual.

### 3.3 SLA por ciclos de respuesta y por qué no se reconstruye el historial

`App\Services\Analytics\ServiceLevelAnalytics` lee `response_cycles` (la escribe el módulo de SLA; aquí solo se
lee). El objetivo queda **congelado en la fila** al iniciar el ciclo, así que cambiar un objetivo no reescribe el
cumplimiento pasado, y una solicitud sin responder que superó su plazo **cuenta como incumplida** (antes era invisible).

Los ciclos existen **solo desde esta versión**. No se reconstruyen hacia atrás porque el objetivo vigente en cada
momento pasado, la categoría y el responsable al vencer no quedaron registrados: recalcularlos con la configuración de
hoy produciría cifras falsas. Por eso `available_since` (fecha del primer ciclo) viaja con las cifras y la interfaz
muestra «Disponible desde <fecha>» cuando el periodo empieza antes, o «Sin datos» cuando no hay ciclos evaluados —
nunca ceros presentados como hechos.

El cálculo anterior (objetivo general vigente hoy sobre las conversaciones ya respondidas o resueltas,
`summary.first_response_sla` / `resolution_sla`) se conserva solo como **referencia** rotulada así («Referencia con el
objetivo general de hoy»), con sus dos sesgos indicados en la propia tarjeta: usa el objetivo actual y no cuenta las
que siguen sin respuesta o sin cierre. La resolución no tiene ciclos propios: su única cifra es esa referencia.

### Tabla histórica (incremento F)

Cada duración existe en **tiempo calendario** (segundos reales, columnas `seconds_to_*`) y, para la primera respuesta
humana y la resolución, también en **tiempo hábil** (columnas `business_seconds_to_*`, ver §3.1). La interfaz y las
exportaciones etiquetan siempre cuál es cuál ("calendario" / "hábil").

**Cohorte del periodo.** Las cifras del periodo se calculan sobre las conversaciones **recibidas** (creadas) dentro del
periodo, en la zona horaria de la plataforma (`general.timezone`): "de las recibidas del 1 al 30 de septiembre,
cuántas se atendieron, cuánto tardó la primera respuesta…". Una reapertura no es una conversación nueva: cuenta en el
periodo en que llegó por primera vez.

| Métrica                                 | Definición exacta                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          | Fuente                                                                                              |
| --------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------- |
| Recibidas                               | Conversaciones creadas en el periodo.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      | `conversations.created_at`                                                                          |
| Atendidas                               | De las recibidas, las que tuvieron **atención humana**: aceptación por un agente, asignación manual, transferencia a un agente concreto o una respuesta humana (lo primero que ocurra). La **asignación automática no es atención**: una conversación distribuida automáticamente cuenta como atendida cuando su agente la acepta o le responde.                                                                                                                                                                           | eventos `accepted`, `assigned`, `transferred` con `to_user_id`; primera respuesta humana            |
| Respondidas                             | De las recibidas, las que tienen al menos una respuesta humana (ver primera respuesta).                                                                                                                                                                                                                                                                                                                                                                                                                                    | mensajes                                                                                            |
| Resueltas                               | De las recibidas, las cerradas al menos una vez (aunque después se reabrieran).                                                                                                                                                                                                                                                                                                                                                                                                                                            | evento `closed`                                                                                     |
| Pendientes ahora                        | En estado _Pendiente_ en este momento (no depende del periodo).                                                                                                                                                                                                                                                                                                                                                                                                                                                            | `conversations.status`                                                                              |
| En atención / en espera ahora           | Estado actual `open` / `on_hold`.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          | `conversations.status`                                                                              |
| Por departamento                        | Agrupado por el departamento **actual**.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   | `conversations.department_id`                                                                       |
| Por agente                              | Agrupado por el responsable actual; al cerrar se conserva el último. "Sin agente" agrupa las nunca asignadas.                                                                                                                                                                                                                                                                                                                                                                                                              | `conversations.assigned_user_id`                                                                    |
| Por conexión                            | Número de WhatsApp por el que llegó.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       | `conversations.connection_id`                                                                       |
| Tiempo hasta primera atención           | Creación → primera atención humana (ver _Atendidas_). Promedio de las que la tienen; las que solo fueron distribuidas automáticamente no tienen valor (no cuentan como 0 s). Se guarda en `first_assigned_at` / `seconds_to_first_attention`.                                                                                                                                                                                                                                                                              | eventos y mensajes                                                                                  |
| Tiempo hasta primera respuesta humana   | Creación → `created_at` del primer mensaje **saliente** con `sender_user_id` (escrito por una persona), **sin** `metadata.automatic = true`, cuyo estado demuestra que el proveedor lo aceptó: `accepted`, `sent`, `delivered` o `read`. Se excluyen automáticos (bienvenida/ausencia), fallidos (`failed`) y los que siguen en `queued`/`sending`. Se mide desde que el agente lo escribió, no desde la entrega.                                                                                                          | mensajes                                                                                            |
| Tiempo de resolución                    | Creación → **primer** cierre (una reapertura posterior no cambia la resolución; se cuenta en _Reabiertas_).                                                                                                                                                                                                                                                                                                                                                                                                                | evento `closed`                                                                                     |
| Tiempo hábil (1ª respuesta, resolución) | Los mismos intervalos contando solo los segundos dentro del horario de atención del departamento que recibió la conversación (ver §3.1).                                                                                                                                                                                                                                                                                                                                                                                   | `BusinessHoursCalendar::businessSecondsBetween()`                                                   |
| Cumplimiento de objetivo (SLA)          | Solo si hay objetivo configurado (Configuración → Objetivos de servicio, minutos de tiempo hábil): de las conversaciones **medidas** (respondidas o resueltas), % cuyo tiempo hábil fue ≤ objetivo. Sin objetivo no hay cifra de cumplimiento (`null`). Las aún no respondidas/resueltas no se califican.                                                                                                                                                                                                                  | `sla.first_response_minutes`, `sla.resolution_minutes`                                              |
| Reabiertas                              | De las recibidas, cuántas se reabrieron al menos una vez (la fila guarda además el número de reaperturas).                                                                                                                                                                                                                                                                                                                                                                                                                 | evento `reopened`                                                                                   |
| Transferencias                          | Suma de transferencias de las recibidas (a departamento o a agente). Una transferencia a un agente concreto cuenta además como atención humana.                                                                                                                                                                                                                                                                                                                                                                            | evento `transferred`                                                                                |
| Carga por agente                        | Conversaciones `open` + `on_hold` que tiene ahora cada agente, dentro del alcance y filtros.                                                                                                                                                                                                                                                                                                                                                                                                                               | `conversations` en vivo                                                                             |
| Agentes disponibles ahora               | Usuarios activos con permiso de responder, dentro del alcance, con `users.availability = available` (disponibilidad declarada, no presencia en línea).                                                                                                                                                                                                                                                                                                                                                                     | `users`                                                                                             |
| Volumen de mensajes                     | Entrantes y salientes de las conversaciones recibidas en el periodo (salientes incluye automáticos).                                                                                                                                                                                                                                                                                                                                                                                                                       | mensajes                                                                                            |
| Errores de envío                        | Salientes en `failed` de esas conversaciones; tasa = fallidos / salientes.                                                                                                                                                                                                                                                                                                                                                                                                                                                 | mensajes                                                                                            |
| Horarios de mayor demanda               | Recibidas por día de la semana (lunes primero) y hora local.                                                                                                                                                                                                                                                                                                                                                                                                                                                               | `received_at`                                                                                       |
| Fuera de horario                        | Recibidas fuera del horario del **departamento que la recibió** (el origen de su primera transferencia si se transfirió; si no, el actual): su horario propio si `uses_custom_hours`, si no el de la plataforma si `business_hours.enabled`. Los días no laborables configurados (`business_hours.holidays`) cuentan como fuera de horario todo el día. Sin horario aplicable (atención continua) **no se clasifica** (`null`) y la tarjeta dice "Sin horario laboral configurado". Se usa el horario vigente al calcular. | `BusinessHoursCalendar` → `App\Services\BusinessHours` (mismas reglas que los mensajes de ausencia) |
| Distribución de estados                 | Estado actual de las recibidas en el periodo.                                                                                                                                                                                                                                                                                                                                                                                                                                                                              | `conversations.status`                                                                              |
| Tendencia                               | Recibidas por día (rangos ≤ 92 días) o por mes, contra el bucket alineado del periodo anterior; en la hoja "Tendencia" las resueltas se agrupan por la fecha de su primer cierre.                                                                                                                                                                                                                                                                                                                                          | métricas                                                                                            |
| Comparación                             | El periodo anterior tiene la misma duración y termina donde empieza el actual. "Este mes" se compara con los mismos días del mes anterior; "Mes anterior", con el mes previo completo.                                                                                                                                                                                                                                                                                                                                     | —                                                                                                   |

**Nunca se inventan valores.** Un promedio sin muestras es `null` y la interfaz muestra "Sin datos suficientes" (o "—"
en tablas); la comparación solo aparece cuando ambos periodos tienen datos; cada promedio indica cuántas
conversaciones se midieron.

**Mensajes automáticos** (bienvenida, ausencia: `metadata.automatic = true`) cuentan en el volumen de salientes, pero
nunca como primera respuesta humana ni como atención. **Casos sin información suficiente:** conversación sin respuesta
humana aceptada por el proveedor (sin tiempo de respuesta), sin cierre (sin resolución), sin horario aplicable (fuera
de horario `null`) o sin objetivo configurado (sin cumplimiento): la cifra es `null`, nunca 0.

**Webhooks repetidos.** Un mensaje entrante repetido por WhatsApp se descarta por su `wamid` antes de guardarse, y la
fila de métricas se recalcula entera desde la fuente: no hay doble conteo (prueba en `MetricsConsistencyTest`).

### 3.1 Tiempo hábil, horarios y objetivos

- **Horario aplicado:** el del departamento que recibió la conversación (origen de su primera transferencia, si se
  transfirió; si no, el actual): su horario propio si `uses_custom_hours`; si no, el de la plataforma si
  `business_hours.enabled`; los días no laborables (`business_hours.holidays`, también los recurrentes) cierran el día
  completo. Se recorre día por día en la zona horaria de la plataforma, así que los cambios de horario de verano se
  respetan (la apertura sigue siendo la hora local configurada).
- **Atención continua** (sin horario aplicable): todas las horas son de atención y el tiempo hábil **coincide con el
  calendario** (mismas reglas que `App\Services\BusinessHours`, que en ese modo considera la plataforma siempre
  abierta). "Fuera de horario", en cambio, sigue sin clasificarse (`null`).
- **Turnos que cruzan medianoche:** el formato de horarios no los admite (un tramo por día, cierre posterior a la
  apertura, validado al guardar). Un día guardado con cierre ≤ apertura (p. ej. editado a mano) cuenta como cerrado:
  el cálculo no falla. Tampoco hay varios tramos por día ni festivos por departamento.
- **Política ante cambios de horario: congelado.** El tiempo hábil se calcula **una vez**, cuando se alcanza el hito
  (primera respuesta o primer cierre), con el horario vigente en ese momento, y queda guardado. Los recálculos
  posteriores (listener, reconciliación, `analytics:rebuild`) lo conservan mientras el hito no cambie, así que editar
  horarios **no reescribe el histórico**. Solo `php artisan analytics:rebuild --business-time` lo recalcula con el
  horario vigente (decisión explícita del administrador; combinable con `--days` y `--conversation`). Las filas sin
  valor (p. ej. las existentes al aplicar la migración) se calculan en el siguiente rebuild con el horario vigente.
  "Fuera de horario" mantiene su política anterior (horario vigente al recalcular).
- **Objetivos (SLA) opcionales:** `sla.first_response_minutes` y `sla.resolution_minutes` en la configuración de la
  plataforma (1 a 43 200 min; vacío = sin objetivo). Se evalúan al consultar (no se guardan por fila), así que cambiar
  el objetivo se refleja de inmediato sin rebuild. No hay objetivo por departamento.

**Periodos:** Hoy, Ayer, Últimos 7/30/90 días, Este mes, Mes anterior y rango personalizado (fechas locales, máximo
366 días; uno mayor se recorta a los últimos 366). Periodo y filtros viven en la URL
(`?period=custom&from=2026-09-01&to=2026-09-30&department=3`). Filtros inválidos no rompen la página: se muestran los
últimos 30 días con un aviso. El panel ofrece Hoy, 7 y 30 días.

**Zona horaria.** Las agrupaciones por día/hora (tendencia, mapa de calor) convierten cada marca UTC a hora local con
el desfase **vigente en esa fecha**: `AnalyticsPeriod::localTimeSql()` calcula en PHP las transiciones de la zona
dentro del periodo y genera un `CASE` por tramo, sin depender de las tablas de zonas horarias de MariaDB. Es exacto
también en zonas con horario de verano.

## 4. Arquitectura de agregación

```
conversation_events ─┐                                                      ┌─> OperationalAnalytics (reportes, exportación)
messages ────────────┼─> ConversationMetricsCalculator ─> conversation_metrics ┤
conversations ───────┘   (recalcula la fila completa)                         └─> DashboardMetrics (panel)
        ▲
        │ RefreshConversationMetrics (job en cola, único mientras espera)
RefreshMetricsOnActivity (listener) ◄── ConversationChanged / MessageRecorded / MessageStatusChanged
```

- **`conversation_metrics`** (una fila por conversación): dimensiones desnormalizadas (conexión, departamento, agente,
  estado), marcas (`received_at`, `first_assigned_at`, `first_human_response_at`, `resolved_at`, `last_closed_at`),
  duraciones precalculadas en segundos (calendario y, desde la migración `2026_10_22_165047`, hábiles:
  `business_seconds_to_first_human_response`, `business_seconds_to_resolution`), contadores (cierres, reaperturas, transferencias, mensajes entrantes,
  salientes y fallidos) y `out_of_hours`. Índices: `received_at`, `resolved_at`, `(department_id, received_at)`,
  `(agent_id, received_at)`, `(connection_id, received_at)`. Las dimensiones no tienen llaves foráneas a propósito:
  solo filtran agregados y nunca deben bloquear tablas operativas. La fila es dato derivado (se elimina con su
  conversación, que de todas formas nunca se borra físicamente).
- **Recalcular, no sumar.** Cada actualización recalcula la fila completa desde la fuente (3 consultas agregadas por
  `conversation_id`, indexadas). Es idempotente: eventos duplicados, tardíos o desordenados dan el mismo resultado.
- **Listener `App\Listeners\Analytics\RefreshMetricsOnActivity`** (autodescubierto): ante un cambio de conversación
  (excepto notas, lecturas y etiquetas), un mensaje registrado o un cambio de estado de mensaje, despacha
  `App\Jobs\Analytics\RefreshConversationMetrics` en la cola `default` (`Queues::DEFAULT`). El job es
  `ShouldBeUniqueUntilProcessing` (mientras uno espera no se encola otro para la misma conversación: una ráfaga de
  mensajes = un recálculo), 3 intentos con espera de 5 y 30 s y un lock por conversación para que dos workers no
  escriban en desorden. El listener solo despacha: no agrega latencia al webhook ni a la petición.
- **`php artisan analytics:rebuild`** (`App\Actions\Analytics\RebuildConversationMetrics`): recorre conversaciones en
  bloques de 500 (`chunkById`), recalcula y limpia la caché. Opciones `--days=N` (creadas o actualizadas en los
  últimos N días, **o con algún mensaje actualizado** en ese lapso, p. ej. un estado de entrega tardío que no toca la
  conversación), `--conversation=ID` (repetible) y `--business-time` (recalcula el tiempo hábil congelado, §3.1).
  Segura de interrumpir y repetir. Programada a diario a las 03:30 con `--days=2` para reconciliar recálculos perdidos
  (p. ej. un worker caído). Nunca se recalcula por solicitud de la pantalla: los reportes solo agregan filas ya
  calculadas.
  **Tras aplicar las migraciones es obligatorio ejecutar `php artisan analytics:rebuild` completo, una vez** (sin
  `--days`: la reconciliación diaria `--days=2` solo recorre lo reciente y nunca poblaría el histórico). Puebla las
  filas por colaborador (`conversation_agent_metrics`) y `category_id` de todo el histórico. Costo esperado: recorre
  **todas** las conversaciones no importadas en bloques de 500, con unas pocas consultas indexadas y escrituras por
  conversación; medido en 16,9 s para 10 000 conversaciones antes de añadir las filas por colaborador
  ([rendimiento-y-carga.md](rendimiento-y-carga.md)), es decir, del orden de minutos por cada 100 000. No bloquea
  tablas, puede ejecutarse con la aplicación en servicio, y puede interrumpirse y repetirse.
  **Entre `migrate` y el final de esa reconstrucción:** el acumulado sin atender ya es correcto (la migración
  `2026_11_10_201846` rellena `left_backlog_at` de las filas existentes con la misma definición del calculador: el
  primero entre `first_assigned_at` y `resolved_at`); la **productividad** del histórico aún no existe, y la pantalla
  y las exportaciones lo dicen en vez de mostrar ceros (`productivity.complete = false`: aviso «Productividad sin
  calcular para parte del periodo», «Sin datos» en lugar de cero y totales sin cifra); el desglose por categoría del
  histórico queda en «Sin categoría» (las categorías no existían).
  La reconciliación filtra por `conversations.updated_at` (sin índice dedicado: recorrido diario de la tabla de
  conversaciones; revisar si crece a decenas de millones) y por `messages.updated_at` (índice
  `messages_updated_conversation_index`, migración `2026_10_22_165048`).
- **`conversation_agent_metrics`** (migración `2026_11_10_201845`): una fila por (conversación, persona). Justificación:
  atribuir el trabajo a quien lo hizo sin recorrer eventos y mensajes en cada consulta. Copias de dimensiones
  (conexión, departamento, categoría, estado, `received_at`) sin llaves foráneas; índices `(conversation_id, user_id)`
  único, `(user_id, received_at)`, `received_at` y `(department_id, received_at)`.
- **Columnas nuevas en `conversation_metrics`** (migración `2026_11_10_201846`): `category_id` (desglose y filtro por
  categoría) y `left_backlog_at` (acumulado sin atender sin recorrer el historial), con índices
  `(category_id, received_at)`, `(status, received_at)` y `left_backlog_at`. Ambas son dato derivado.
  `left_backlog_at` se **rellena en la propia migración** para las filas existentes (una sentencia sobre columnas que
  ya existían; prueba `tests/Feature/Database/ReportingDimensionsMigrationTest.php`, que la compara con el
  calculador), de modo que el acumulado nunca cuenta el histórico entero como sin atender. `category_id` y la
  productividad histórica sí requieren el `php artisan analytics:rebuild` completo descrito arriba.
- **`productivity.complete`** (`AgentProductivity::complete()`): falso mientras alguna conversación del periodo con
  responsable o con respuesta humana no tenga todavía filas en `conversation_agent_metrics` (una consulta de
  anti-unión acotada al periodo, que se detiene en la primera que encuentra). Describe el estado del dato derivado,
  no lo que ve cada persona: no aplica alcance ni filtros y solo expone un sí o un no.
- **Planes medidos** (EXPLAIN con 300 000 filas por tabla repartidas en 2 años, MariaDB, periodo de 30 días): cohorte
  → `range` sobre `received_at` (≈ 25 000 filas, `Using index`); con filtro de estado → `(status, received_at)`
  (≈ 1 000); con agente → `(agent_id, received_at)` (≈ 300); alcance por departamentos → `index_merge` de
  `(agent_id, received_at)` y `(department_id, received_at)`; acumulado → `ref`/`range` sobre `left_backlog_at`;
  finalizadas en el periodo → `range` sobre `resolved_at`; filas por colaborador → `range` sobre `received_at` o
  `(user_id, received_at)`; ciclos → `range` sobre `started_at`; vencidas ahora → `ref` sobre `active` (prefijo de `(active, breached_at, due_at)`);
  primer ciclo → resuelto desde el índice. Ningún recorrido completo. El reporte entero: 30 días ≈ 0,8 s sin caché;
  365 días ≈ 4,4 s sin caché (después, 120 s en caché). Con filtro de categoría o canal se usa el rango por fecha y se
  filtra (no hay índice compuesto por canal). Prueba permanente: `tests/Feature/Performance/AnalyticsQueriesTest.php`.
- **Sin tabla de acumulados diarios (`analytics_daily`).** Se evaluó y no se creó: los reportes agregan sobre
  conversaciones (no sobre mensajes) con índices compuestos por fecha de recepción y caché corta. Si el volumen lo
  exigiera, puede construirse a partir de `conversation_metrics` sin cambiar las definiciones.
- **Caché** (`App\Services\Analytics\AnalyticsCache`): reportes 120 s, panel 30 s; llave = huella del alcance
  (alcance, departamentos, permisos efectivos y, si no es global, el ID del usuario) + filtros; los usuarios globales
  comparten la entrada. Cada recálculo invalida incrementando una versión, como máximo una vez cada 10 s; una cifra
  nunca es más antigua que el TTL. En producción requiere un _cache store_ compartido (database o redis), igual que
  `PlatformSettings`.
- **Panel.** Las cifras en vivo salen de `conversations` con la misma visibilidad que la bandeja
  (`Conversation::visibleTo`) e índices por estado; las del periodo, de `conversation_metrics`. "Primera respuesta
  (promedio)" ahora es la **primera respuesta humana aceptada por WhatsApp** (antes usaba
  `conversations.first_response_at`, que se marca al encolar). Mientras ningún número tenga tráfico confirmado, los
  indicadores de conversaciones se muestran como no disponibles (esa comprobación se cachea 60 s).

## 5. Interfaz

- **Gráficas (fase 5.1):** SVG propias, sin dependencias nuevas, en `resources/js/components/charts/` (`ChartCard`,
  `LineChart`, `BarChart`, `ChartLegend`, `ChartTooltip`, `ChartDataTable` y las compuestas `ChannelChart`,
  `StatusChart`, `FirstResponseChart`, `TeamBarsChart`), con colores y formatos de `composables/useChartTheme.ts` (lee
  los _tokens_ `--chart-*` de `app.css` y se recalcula al cambiar tema o paleta). Cada tarjeta tiene esqueleto, estado
  vacío, leyenda con dos o más series, tooltip con ratón, teclado (←/→) y toque, y «Ver como tabla» (tabla accesible).
  Un tiempo promedio sin conversaciones medidas es `null`: la línea se corta y nunca se dibuja un 0 falso. Las
  categorías usan colores fijos (con contraste ≥ 3:1 en claro y oscuro) y el canal siempre el mismo color.
  `TrendChart.vue` y `DistributionBars.vue` se retiraron.
- `resources/js/pages/reports/Index.vue`: filtros (`components/reports/AnalyticsFilterBar.vue`, compartido con el
  panel), tarjetas con comparación (`KpiCard.vue`: flecha y texto según el sentido de mejora de cada métrica),
  gráficas de tendencia (periodo actual y anterior discontinuo), estado, tiempo de primera respuesta (`activity`) y por
  canal (`by_channel`), mapa de calor por hora (`HourHeatmap.vue`, escala de un solo tono, valor exacto en tooltip y
  texto accesible) y desgloses con barras encima de las tablas (`BreakdownTable.vue`). Las cifras son _deferred props_
  con esqueletos; al cambiar filtros solo se recargan `filters`, `period`, `invalidFilters` y `analytics`.
- `resources/js/pages/Dashboard.vue`: filtros de periodo, departamento, agente y conexión; cuatro indicadores
  («Pendientes», «En atención», «Finalizadas hoy», «Primera respuesta (promedio)» con comparación); y un segundo grupo
  diferido `charts` (`DashboardMetrics::chartsFor()`, nunca retrasa los indicadores) con hasta seis gráficas:
  actividad (recibidas y atendidas por hora si el periodo dura un día o menos, si no por día o mes), por canal (solo
  canales con datos), estado de lo recibido, tiempo de primera respuesta y, si el servidor lo permite, atención por
  agente y por departamento (las 8 primeras filas y «Otros» con el resto, sin perder volumen; `null` sin permiso). El
  alcance lo decide el servidor: un agente con alcance asignado no recibe agentes ni departamentos. Enlace a Reportes
  con `reports.view`.
- Navegación: entrada "Reportes" en General cuando el usuario tiene `reports.view`.

### 5.1 Panel y reportes de esta fase

- **Filtros** (`AnalyticsFilterBar`, estado en la URL, validados en el servidor y limitados al alcance): periodo,
  departamento, agente responsable, conexión, canal, categoría y estado actual. `granularity=day|week|month` (interruptor
  en la gráfica de volumen; por día solo hasta 92 días).
- **Panel** (`Dashboard.vue`): «Ahora» (Pendientes y En atención con enlace a la bandeja; Finalizadas hoy), «Resultado
  del periodo» (Recibidas, Atendidas, Finalizadas, Espera hasta la primera respuesta, con variación), «Tiempos y
  cumplimiento» (¿Estamos respondiendo más rápido? · ¿Qué porcentaje cumplió el SLA? · ¿Qué departamento acumula más
  retrasos?), «Carga de trabajo» (¿Quién tiene más solicitudes pendientes?, en vivo · ¿Se nos están acumulando
  solicitudes?), «Demanda» (volumen con día/semana/mes · ¿En qué horario llegan más solicitudes?), «Distribución»
  (categoría, canal, estado; 8 primeras + «Otros») y «Equipo». Se conservan todos los indicadores anteriores; la
  gráfica de primera respuesta ahora incluye el periodo anterior.
- **Reportes** (`pages/reports/Index.vue`): pestañas Resumen / Productividad / Cumplimiento de SLA / Desgloses (la
  pestaña vive en el `#hash`); el botón de exportar corresponde a la pestaña abierta.
- **Componentes nuevos** (SVG propio, mismos tooltips, leyendas, teclado y «Ver como tabla»):
  `charts/SlaComplianceChart`, `RankedBarsChart`, `ResponseTrendChart`, `BacklogChart`;
  `reports/ProductivityTable` (ordenable, `aria-sort`), `AgentEvolutionChart`, `GranularityToggle`.
- **Navegación al detalle**: `BarItem.href` (el nombre de la barra es un enlace real) y `KpiCard.href`. Solo se
  ofrece donde la bandeja puede filtrar de verdad: cifras **en vivo** (pendientes, en atención, carga por persona),
  con `view=all` y `status`, `agent`, `connection`, `channel`, `category` (`lib/analyticsLinks.ts`). Las cifras de un
  periodo no enlazan a la bandeja porque esta no filtra por fecha de recepción.
- **Paneles que no aplican un filtro lo dicen**: estado en cifras en vivo, acumulado y SLA.

## 6. Exportaciones (motor existente)

No hay infraestructura paralela: se usa el motor de `app/Reports`.

- Cada reporte extiende `App\Reports\ReportDefinition` y se registra con una línea en `config/exports.php`
  (`'analytics' => AnalyticsReport::class`). La ruta `POST exports/{report}/{format}` (desde la estabilización; el
  menú «Exportar» envía un formulario nativo efímero con `_token` y los filtros, así el navegador sigue descargando el
  archivo; `GET` responde 405 y la descarga de un archivo ya generado sigue siendo `GET exports/transfers/{id}/download`), la autorización
  (`reports.export` + `authorize()` del reporte), los límites de filas, la generación síncrona o en cola
  (`App\Jobs\GenerateReport`), la auditoría (`export.requested/generated/failed`), el almacenamiento privado y la
  descarga autorizada los da `App\Services\Exports\ReportExporter`.
- Render: `XlsxRenderer` (hoja "Resumen" con métricas y gráficas nativas; hoja principal con encabezados, autofiltro,
  paneles congelados y totales; hojas extra) y `PdfRenderer` (Browsershot/Chrome con la plantilla empresarial
  existente: encabezado, filtros aplicados, tarjetas, gráficas SVG de `ChartGeometry`, tablas con totales).
- **`AnalyticsReport`** (`app/Reports/Definitions/AnalyticsReport.php`, clave `analytics`, horizontal):
    - Filtros = `AnalyticsFilters::rules()`, idénticos a la pantalla, resueltos con la misma clase y alcance, y
      descritos en el encabezado (periodo, comparación, alcance, departamento/agente/conexión).
    - Métricas: recibidas, atendidas, resueltas, primera respuesta humana (calendario y hábil), primera atención
      (calendario), resolución (calendario y hábil), con conversaciones medidas y valor previo; cumplimiento de cada
      objetivo configurado (solo si existe); reabiertas, transferencias, fuera de horario, mensajes, errores de envío y
      pendientes ahora.
    - Gráficas: recibidas (periodo vs. anterior), demanda por hora, estado actual y top 10 de departamentos.
    - Tabla principal "Desempeño por agente" (tiempos en minutos con un decimal, operables en Excel) y secciones "Por
      departamento" y "Por conexión" (también en PDF), "Tendencia" y "Demanda por hora" (solo XLSX).
    - Todas las cifras salen del mismo cálculo que la pantalla (`OperationalAnalytics`), pero **sin la caché** de 120 s
      (`compute()`), dentro de la instantánea REPEATABLE READ de la exportación: filas, totales y tarjetas leen los
      mismos datos y nunca aparece una fila "Sin datos". El orden de la tabla es el de la pantalla (más recibidas
      primero; empate por ID). La pantalla puede mostrar cifras hasta 120 s más antiguas que el archivo.
- **Fase de indicadores.** `AnalyticsReport` añade las métricas «Finalizadas (de las recibidas)», «Finalizadas en el
  periodo», «Acumuladas sin atender», mínimo/máximo, «Cumplimiento de SLA» (ciclos) y «Tiempo de respuesta por mensaje
  del cliente»; las hojas «Productividad», «Por categoría», «SLA por departamento» y «Acumulado sin atender»; y la
  tabla principal pasa a llamarse «Cartera por responsable actual». Dos definiciones nuevas, registradas en
  `config/exports.php`:
    - **`AgentProductivityReport`** (`agent-productivity`, horizontal): una fila por colaborador con las columnas de
      la pantalla (duraciones en minutos como número, cumplimiento como porcentaje real), gráficas de atendidas y
      finalizadas y de pendientes actuales, y hoja «Evolución».
    - **`SlaComplianceReport`** (`sla-compliance`, horizontal): tarjetas de cumplimiento con n, gráficas (evaluados,
      tendencia, retrasos por departamento), hojas «Por tipo», «Por departamento», «Por categoría», «Por colaborador»
      y «Tendencia», y como tabla principal el **detalle de incumplimientos** (una fila por ciclo vencido, con fechas
      reales de Excel en la zona de la plataforma, objetivo y exceso en minutos y situación).
      Los filtros aplicados (incluidos canal, categoría y estado, y el tramo de comparación) se describen en el encabezado
      con `App\Reports\Concerns\DescribesAnalytics`.
- **`DashboardReport`** (clave `dashboard`) acepta los mismos filtros que el panel. Desde la fase 5.1 exporta las mismas
  gráficas y cifras que muestra la pantalla, desde el mismo `chartsFor()` (una hoja de datos por gráfica), con los
  mismos nombres («En atención», «Pendientes», «Finalizadas hoy», «Primera respuesta (promedio)»). Antes exportaba
  «Conversaciones nuevas por mes · últimos 12 meses», que no coincidía con el periodo filtrado.
  `AnalyticsReport` añade la actividad y el desglose por canal (prueba `DashboardConsistencyTest`).
- **`QuickRepliesReport`** (clave `quick-replies`, fase 5.1): el menú «Exportar» de Respuestas rápidas ofrece
  «Reporte general» (todas las que la persona puede consultar: corporativas, las suyas y, si las supervisa, las de
  otros usuarios, sin importar la pestaña abierta) y «Solo la vista actual» (la pestaña y sus filtros, descritos en el
  documento), en Excel o PDF, con las mismas columnas: Atajo, Título, Contenido (texto completo), Tipo, Propietario,
  Departamento, Estado y Fecha de creación, más un resumen por tipo. Prueba `QuickRepliesExportTest`.

### Flujo de descarga (fase 5.2)

Exportar ya no es un módulo propio: **«Exportaciones» salió del menú lateral**. El archivo se pide y se recoge desde el
módulo donde se generó.

1. **Archivo pequeño** (hasta `EXPORTS_XLSX_SYNC_ROWS`, 2000 filas en Excel; `EXPORTS_PDF_SYNC_ROWS`, 500 en PDF): se
   genera durante la petición y se descarga en el acto.
2. **Archivo grande:** se prepara en la cola `exports`. El aviso dice «Preparando Excel…» / «Preparando PDF…» sin
   mandar a otra pantalla. Al terminar llega la notificación «Tu exportación está lista» con la acción **«Descargar»**,
   que descarga el archivo directamente (`GET exports/transfers/{id}/download`; desde una visita Inertia la respuesta
   es una navegación completa del navegador, porque un archivo no puede ser una página). Si falló: «No se pudo generar
   tu exportación. Inténtalo de nuevo desde el módulo o reduce los filtros.»; si venció: «El archivo ya expiró. Vuelve a
   exportarlo desde el módulo.», sin enlace.
3. **Archivos preparados recientemente:** el menú «Exportar» de cada módulo lista los últimos 5 archivos de ese
   reporte que siguen preparándose o se pueden descargar (`GET exports/recent?report=<clave>`, `exports.recent`; solo
   del dueño, con el permiso de descarga vigente) y enlaza «Ver todos tus archivos preparados».
4. **«Archivos preparados»** (`/exports`, antes «Exportaciones») sigue existiendo para los enlaces antiguos y para ver
   todos los archivos propios; solo se llega desde los menús «Exportar».

La descarga vuelve a comprobar dueño, permiso y vencimiento; un archivo de otra persona responde 404 y uno sin permiso o
vencido explica el motivo con un aviso en la página actual. Las notificaciones de este tipo se llaman ahora «Archivos
preparados» en las preferencias. Prueba: `tests/Feature/Exports/ExportDeliveryTest.php`; navegador:
`tests/e2e/toast-oscuro.mjs`. Decisión: [ADR-UX-3](decisiones.md#adr-ux-3--exportaciones-sin-módulo-propio-archivos-preparados-desde-cada-módulo).

**Avisos legibles en modo oscuro.** Los _toasts_ (`components/ui/sonner/Sonner.vue`) siguen el tema que la aplicación
muestra de verdad (la clase `.dark` de `<html>`, también con «Sistema» y cambios en otra pestaña) y toman los colores de
los _tokens_ del diseño en lugar de los grises fijos de `vue-sonner`, ilegibles sobre el fondo oscuro: título ≥ 15:1,
descripción ≥ 5,5:1 e iconos ≥ 4,5:1 en claro y oscuro; el botón de cerrar muestra un anillo de foco.

### 6.1 Catálogo, límites y ciclo de vida

- **Reportes registrados** en `config/exports.php` (una clave fuera del catálogo responde 404): `dashboard`,
  `analytics`, `conversations`, `conversation-dossier` (expediente desde el panel de detalles), `contacts`, `tags`,
  `quick-replies`, `departments`, `users`, `roles`, `connections`, `audit` y
  `message-templates`. Cada definición autoriza con su propio `authorize()` además de su permiso de exportación
  (`reports.export`; la bitácora usa `audit.export` en su lugar) y aplica el
  alcance del usuario (las conversaciones pasan por `ConversationAccess::restrictToVisible`). El chat interno no se
  exporta. La descarga de un archivo terminado vuelve a comprobar el permiso de **ese** reporte
  (`DataTransferPolicy::download` → `ReportExporter::allowsStored`): si el dueño lo perdió, 403. El export de
  conversaciones describe el filtro «Búsqueda en el contenido de los mensajes: «…»» cuando se buscó en mensajes. El
  expediente de una conversación (`conversation-dossier`) incluye como máximo 500 eventos y 500 notas y lo indica en
  sus hojas; el historial completo se consulta en la bandeja.
- **Síncrono o en cola:** hasta `sync_rows` filas el archivo se genera en la petición (XLSX 2 000, PDF 500); por encima
  se encola `GenerateReport` en la cola de exportaciones (`EXPORTS_QUEUE`, por defecto `exports`); por encima de
  `max_rows` (XLSX 50 000, PDF 5 000) se rechaza y hay que acotar filtros. Variables `EXPORTS_{XLSX,PDF}_{SYNC,MAX}_ROWS`.
- **Consistencia:** `ReportExporter::render()` lee dentro de una sola transacción REPEATABLE READ sin bloqueos, así
  que una exportación por páginas no duplica ni omite filas aunque lleguen mensajes mientras se genera.
- **Periodos relativos en cola:** al encolar se guarda `options.requested_at`; el trabajo resuelve "Hoy", "Últimos 7
  días", etc. contra ese momento (`ReportContext::asOf()`), no contra la hora del worker. Un export pedido a las 23:59
  y procesado a las 00:01 conserva el día solicitado. "Generado el" sigue siendo la hora real de generación.
- **Memoria del XLSX:** PhpSpreadsheet arma el libro en memoria (medido: ~0,8 KB por celda; 50 000 filas × 12
  columnas ≈ 480 MB de pico, × 21 columnas ≈ 840 MB). Además de `max_rows`, el máximo de filas XLSX se acota a
  `max_cells / columnas del reporte` (`EXPORTS_XLSX_MAX_CELLS`, 800 000 por defecto ≈ 650 MB), por debajo del
  `memory_limit` del trabajo (`EXPORTS_MEMORY_LIMIT`, 1024M). Con los reportes actuales (≤ 13 columnas) el máximo
  efectivo sigue siendo 50 000 filas. Los PDF grandes (> 500 filas) siempre van en cola.
- **Saltos de página del PDF:** la fila de totales comparte un grupo de filas indivisible con las dos últimas filas de
  datos (`tbody.table-end`, parcial `reports/partials/table-rows.blade.php`), así que nunca empieza sola una página;
  el encabezado de la tabla se repite en cada página y no queda huérfano; los títulos de grupo, de sección y su
  descripción se mantienen con las filas que siguen. Las secciones extra del PDF ("Por departamento", "Por conexión")
  llevan la misma fila de totales que su hoja XLSX. Una tabla larga sin resumen ya no deja la primera página en
  blanco (solo las tablas de ≤ 12 filas se mantienen enteras).
- **Almacenamiento y descarga:** disco privado `EXPORTS_DISK` (por defecto `local`) en `exports/`; solo el
  solicitante descarga su archivo por ruta autorizada. Caducan a las `EXPORTS_RETENTION_HOURS` (24) y
  `exports:prune` (cada hora) los borra; la lista los muestra como expirados. Al terminar en cola se notifica al dueño
  (`export.finished`) y la página de exportaciones se actualiza con `.transfer.changed`.
- **Seguridad:** límite de 10 solicitudes por minuto por ruta y usuario; las celdas que empiezan con `= + - @`,
  tabulador o retorno se neutralizan contra inyección de fórmulas; se auditan `export.requested`, `export.generated` y
  `export.failed`.

## 7. Pruebas

- `tests/Feature/Analytics/MetricsConsistencyTest.php`: cálculo por eventos vs. reconstrucción vs. consulta directa
  sobre las tablas fuente para un conjunto de casos (distribución automática respondida y sin responder, aceptada
  con cierre/reapertura, transferida a un agente); la asignación automática no es atención; un webhook repetido no
  duplica; la reconciliación `--days` detecta cambios solo de mensajes.
- `tests/Feature/Analytics/BusinessTimeTest.php`: tiempo hábil en el mismo día, noches, fin de semana, festivo
  recurrente, atención continua, horario de departamento con cambio de horario de verano, día mal formado (no falla),
  congelamiento ante cambios de horario y `--business-time`.
- `tests/Feature/Analytics/ConversationMetricsTest.php`: línea de tiempo con acciones reales (aceptar, transferir,
  cerrar, reabrir, cerrar) y mensajes automáticos, fallidos y en cola que no cuentan como respuesta humana; fuera de
  horario con horario de departamento, de plataforma o ninguno; origen de la transferencia; idempotencia y
  restauración de `analytics:rebuild`; opciones `--days`/`--conversation`; las notas no recalculan.
- `tests/Feature/Reports/OperationalReportsTest.php`: acceso (invitado, sin permiso), cifras globales con comparación,
  promedios, tasas y mapa de calor, alcance por departamentos con filtros ajenos descartados, alcance asignado (solo
  lo propio), persistencia de filtros y rango inválido, rangos largos por mes, autorización de exportación, contenido
  XLSX/PDF dentro del alcance y PDF real con Chrome (grupo `chrome`); cumplimiento solo con objetivo; agrupación por
  día/hora con horario de verano; orden y origen de la tabla exportada; totales del PDF junto a las últimas filas y en
  las secciones; export en cola con periodo relativo resuelto al solicitarse.
- `tests/Feature/Exports/ReportExportTest.php`: además, grupo indivisible de totales en el HTML del PDF y límite de
  filas XLSX por celdas.
- `tests/Feature/Admin/PlatformSettingsTest.php`: validación y borrado de los objetivos de servicio.
- Fase 5.1: `tests/Feature/Analytics/AnalyticsActivityTest.php` y `AnalyticsChannelTest.php` (Σ de puntos = KPI,
  zona horaria y horario de verano, buckets sin muestras en `null`, canales sin datos fuera y conexiones eliminadas en
  su canal), `tests/Feature/Dashboard/DashboardChartsTest.php` (alcance y permisos: sin `dashboard.view` no se calcula
  `charts`, ni con recarga parcial) y `DashboardConsistencyTest.php` (exportación = pantalla).
- `tests/Feature/DashboardTest.php`: se actualizó una aserción ("agentes disponibles" es ahora un conteo real, 0, en
  lugar de `null`) y se agregaron pruebas de disponibilidad, filtros y primera respuesta humana.

- Fase de indicadores: `tests/Feature/Analytics/AgentAttributionTest.php` (transferencia entre dos agentes, respuesta
  de supervisión sin asignación, reapertura y recierre, distribución automática, responsable sin eventos,
  reconstrucción idempotente e igual a la incremental, alcance), `ServiceLevelAnalyticsTest.php` (un ciclo de cada
  tipo, sin respuesta vencida = incumplida, sin objetivo y cerrada sin respuesta excluidas, «Sin datos» sin 100 %,
  disponibilidad, objetivo congelado, pertenencia por colaborador, alcance y filtros),
  `tests/Feature/Reports/AnalyticsIndicatorsTest.php` (semanas ISO y horario de verano, comparación del mismo tramo,
  promedios sin nulos con mínimo y máximo, finalizadas de las recibidas frente a finalizadas en el periodo,
  acumulado, filtros de categoría/estado/canal, cifras en vivo sin importados, agentes activos, panel = reportes =
  exportaciones, fuga de alcance por props, libros XLSX releídos con tipos y PDF reales con Chrome) y
  `tests/Feature/Performance/AnalyticsQueriesTest.php`.

## 8. Limitaciones conocidas

- El SLA por ciclo y el tiempo de respuesta por mensaje del cliente existen **desde esta versión**; no hay ciclos
  históricos. El cumplimiento de resolución solo existe como referencia con el objetivo general de hoy.
- La productividad depende de `conversation_events`: una conversación cuyo responsable no está explicado por sus
  eventos aporta «asignada» pero no tiempo de atención.
- Las cifras de un periodo no enlazan a la bandeja (esta no filtra por fecha de recepción); solo las cifras en vivo.
- «Finalizadas hoy» del panel es una cifra de bandeja en vivo, distinta por definición de «Finalizadas» de reportes.
- Cambiar la categoría de una solicitud se refleja en las métricas con el siguiente recálculo de esa conversación o,
  a más tardar, en la reconciliación nocturna.
- La comparación del mismo tramo transcurrido aplica a los indicadores; la línea «periodo anterior» de las gráficas
  muestra el periodo anterior completo.

- Tiempo hábil solo para primera respuesta humana y resolución (la primera atención es solo calendario). Horarios de un
  tramo por día, sin turnos que crucen medianoche, sin festivos por departamento y con una sola zona horaria de
  plataforma (§3.1). Objetivos de servicio globales (sin objetivo por departamento); las conversaciones aún sin
  respuesta o sin cierre no se califican en la **referencia** con el objetivo general (sí en el SLA por ciclos, §3.3).
- "Fuera de horario" usa el horario y los días no laborables vigentes al calcular (reconstruir tras editar horarios
  reclasifica el histórico); el tiempo hábil, en cambio, queda congelado (§3.1).
- En las cifras por conversación, agente y departamento son los actuales/últimos; el historial de quién la tuvo está
  en Productividad (§3.2).
- Volumen de mensajes y errores de envío se atribuyen a la cohorte de conversaciones recibidas, no a la fecha de cada
  mensaje.
- Las métricas se actualizan de forma asíncrona: con los workers detenidos, los reportes quedan desactualizados hasta
  que se procese la cola o corra la reconciliación diaria.
