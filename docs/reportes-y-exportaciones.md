# Reportes, panel principal y exportaciones

Este documento describe el módulo de **Reportes operativos** (incremento F), el **Panel principal** y la
arquitectura de **exportaciones a Excel y PDF** que ambos reutilizan.

Relacionados: [bandejas-y-asignacion.md](bandejas-y-asignacion.md) (ciclo de vida de conversaciones),
[mensajeria-whatsapp.md](mensajeria-whatsapp.md) (estados de mensajes),
[notificaciones-y-horarios.md](notificaciones-y-horarios.md) (horarios laborales), [tiempo-real.md](tiempo-real.md),
[decisiones.md](decisiones.md) (ADR-REP-*).

---

## 1. Qué ofrece

| Pantalla            | Ruta                                  | Permiso                           | Contenido                                                                                                                                                                    |
| ------------------- | ------------------------------------- | --------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Reportes operativos | `GET /reports` (`reports.index`)      | `reports.view`                    | Indicadores con comparación contra el periodo anterior, tendencia, estado actual, mapa de calor por hora, tablas por agente, departamento y conexión, exportación XLSX/PDF.  |
| Panel principal     | `GET /dashboard`                      | `dashboard.view`                  | En vivo: en atención, pendientes, cerradas hoy, agentes disponibles ahora. Del periodo: primera respuesta humana con comparación y recibidas con tendencia. Filtros ligeros. |
| Exportación         | `POST /exports/analytics/{xlsx\|pdf}` | `reports.view` + `reports.export` | El mismo reporte, con los mismos filtros aplicados en el servidor (formulario con token CSRF; un GET responde 405).                                                          |

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

Los filtros (`department`, `agent`, `connection`) se **validan contra las opciones del usuario**: un ID fuera de su
alcance se descarta (no se aplica, no se devuelve en `filters`, no se revela su nombre), lo que evita IDOR y
enumeración por conteos. Las exportaciones usan la misma clase con el usuario solicitante, y el trabajo en cola vuelve
a autorizar al ejecutarse (comportamiento del motor de exportación).

## 3. Definición de cada métrica

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
  **Tras aplicar las migraciones hay que ejecutar `php artisan analytics:rebuild` una vez** (puebla el histórico, la
  nueva definición de primera atención y el tiempo hábil de las filas existentes).
  La reconciliación filtra por `conversations.updated_at` (sin índice dedicado: recorrido diario de la tabla de
  conversaciones; revisar si crece a decenas de millones) y por `messages.updated_at` (índice
  `messages_updated_conversation_index`, migración `2026_10_22_165048`).
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
  (prom.)" ahora es la **primera respuesta humana aceptada por WhatsApp** (antes usaba
  `conversations.first_response_at`, que se marca al encolar). Mientras ningún número tenga tráfico confirmado, los
  indicadores de conversaciones se muestran como no disponibles (esa comprobación se cachea 60 s).

## 5. Interfaz

- `resources/js/pages/reports/Index.vue`: filtros (`components/reports/AnalyticsFilterBar.vue`, compartido con el
  panel), tarjetas con comparación (`KpiCard.vue`: flecha y tono según el sentido de mejora de cada métrica),
  tendencia SVG sin dependencias (`TrendChart.vue`: periodo actual en el color de la plataforma, anterior punteado,
  tooltip con ratón y teclado), estados (`DistributionBars.vue`), mapa de calor por hora (`HourHeatmap.vue`, escala de
  un solo tono, valor exacto en tooltip y texto accesible) y tablas (`BreakdownTable.vue`). Las cifras son _deferred
  props_ con esqueletos; al cambiar filtros solo se recargan `filters`, `period`, `invalidFilters` y `analytics`.
- `resources/js/pages/Dashboard.vue`: filtros de periodo, departamento, agente y conexión; tarjetas en vivo; primera
  respuesta humana con comparación; recibidas con _sparkline_ (`Sparkline.vue`); enlace a Reportes con `reports.view`.
- Navegación: entrada "Reportes" en General cuando el usuario tiene `reports.view`.

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
- **`DashboardReport`** (clave `dashboard`) acepta los mismos filtros que el panel y agrega "Recibidas en el periodo" y
  "Agentes disponibles".

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
- `tests/Feature/DashboardTest.php`: se actualizó una aserción ("agentes disponibles" es ahora un conteo real, 0, en
  lugar de `null`) y se agregaron pruebas de disponibilidad, filtros y primera respuesta humana.

## 8. Limitaciones conocidas

- Tiempo hábil solo para primera respuesta humana y resolución (la primera atención es solo calendario). Horarios de un
  tramo por día, sin turnos que crucen medianoche, sin festivos por departamento y con una sola zona horaria de
  plataforma (§3.1). Objetivos de servicio globales (sin objetivo por departamento); las conversaciones aún sin
  respuesta o sin cierre no se califican aunque ya hayan superado el objetivo.
- "Fuera de horario" usa el horario y los días no laborables vigentes al calcular (reconstruir tras editar horarios
  reclasifica el histórico); el tiempo hábil, en cambio, queda congelado (§3.1).
- Agente y departamento son los actuales/últimos de la conversación, no el historial de quién la tuvo en cada momento.
- Volumen de mensajes y errores de envío se atribuyen a la cohorte de conversaciones recibidas, no a la fecha de cada
  mensaje.
- Las métricas se actualizan de forma asíncrona: con los workers detenidos, los reportes quedan desactualizados hasta
  que se procese la cola o corra la reconciliación diaria.
