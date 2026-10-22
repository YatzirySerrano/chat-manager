# Reportes, panel principal y exportaciones

Este documento describe el módulo de **Reportes operativos** (incremento F), el **Panel principal** y la
arquitectura de **exportaciones a Excel y PDF** que ambos reutilizan.

Relacionados: [bandejas-y-asignacion.md](bandejas-y-asignacion.md) (ciclo de vida de conversaciones),
[mensajeria-whatsapp.md](mensajeria-whatsapp.md) (estados de mensajes),
[notificaciones-y-horarios.md](notificaciones-y-horarios.md) (horarios laborales), [tiempo-real.md](tiempo-real.md),
[decisiones.md](decisiones.md) (ADR-REP-*).

---

## 1. Qué ofrece

| Pantalla            | Ruta                                 | Permiso                           | Contenido                                                                                                                                                                    |
| ------------------- | ------------------------------------ | --------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Reportes operativos | `GET /reports` (`reports.index`)     | `reports.view`                    | Indicadores con comparación contra el periodo anterior, tendencia, estado actual, mapa de calor por hora, tablas por agente, departamento y conexión, exportación XLSX/PDF.  |
| Panel principal     | `GET /dashboard`                     | `dashboard.view`                  | En vivo: en atención, pendientes, cerradas hoy, agentes disponibles ahora. Del periodo: primera respuesta humana con comparación y recibidas con tendencia. Filtros ligeros. |
| Exportación         | `GET /exports/analytics/{xlsx\|pdf}` | `reports.view` + `reports.export` | El mismo reporte, con los mismos filtros aplicados en el servidor.                                                                                                           |

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

Todas las duraciones son **tiempo calendario** (segundos reales). **No** se implementó tiempo dentro de horario
laboral; "fuera de horario" solo clasifica el momento de llegada.

**Cohorte del periodo.** Las cifras del periodo se calculan sobre las conversaciones **recibidas** (creadas) dentro del
periodo, en la zona horaria de la plataforma (`general.timezone`): "de las recibidas del 1 al 30 de septiembre,
cuántas se atendieron, cuánto tardó la primera respuesta…". Una reapertura no es una conversación nueva: cuenta en el
periodo en que llegó por primera vez.

| Métrica                               | Definición exacta                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          | Fuente                                                                                              |
| ------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------- |
| Recibidas                             | Conversaciones creadas en el periodo.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      | `conversations.created_at`                                                                          |
| Atendidas                             | De las recibidas, las que tuvieron aceptación, asignación manual, asignación automática o transferencia a un agente concreto.                                                                                                                                                                                                                                                                                                                                                                                              | eventos `accepted`, `assigned`, `auto_assigned`, `transferred` con `to_user_id`                     |
| Respondidas                           | De las recibidas, las que tienen al menos una respuesta humana (ver primera respuesta).                                                                                                                                                                                                                                                                                                                                                                                                                                    | mensajes                                                                                            |
| Resueltas                             | De las recibidas, las cerradas al menos una vez (aunque después se reabrieran).                                                                                                                                                                                                                                                                                                                                                                                                                                            | evento `closed`                                                                                     |
| Pendientes ahora                      | En estado _Pendiente_ en este momento (no depende del periodo).                                                                                                                                                                                                                                                                                                                                                                                                                                                            | `conversations.status`                                                                              |
| En atención / en espera ahora         | Estado actual `open` / `on_hold`.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          | `conversations.status`                                                                              |
| Por departamento                      | Agrupado por el departamento **actual**.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   | `conversations.department_id`                                                                       |
| Por agente                            | Agrupado por el responsable actual; al cerrar se conserva el último. "Sin agente" agrupa las nunca asignadas.                                                                                                                                                                                                                                                                                                                                                                                                              | `conversations.assigned_user_id`                                                                    |
| Por conexión                          | Número de WhatsApp por el que llegó.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       | `conversations.connection_id`                                                                       |
| Tiempo hasta primera atención         | Creación → primer evento de atención (ver _Atendidas_). Promedio de las que lo tienen.                                                                                                                                                                                                                                                                                                                                                                                                                                     | eventos                                                                                             |
| Tiempo hasta primera respuesta humana | Creación → `created_at` del primer mensaje **saliente** con `sender_user_id` (escrito por una persona), **sin** `metadata.automatic = true`, cuyo estado demuestra que el proveedor lo aceptó: `accepted`, `sent`, `delivered` o `read`. Se excluyen automáticos (bienvenida/ausencia), fallidos (`failed`) y los que siguen en `queued`/`sending`. Se mide desde que el agente lo escribió, no desde la entrega.                                                                                                          | mensajes                                                                                            |
| Tiempo de resolución                  | Creación → **primer** cierre.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              | evento `closed`                                                                                     |
| Reabiertas                            | De las recibidas, cuántas se reabrieron al menos una vez (la fila guarda además el número de reaperturas).                                                                                                                                                                                                                                                                                                                                                                                                                 | evento `reopened`                                                                                   |
| Transferencias                        | Suma de transferencias de las recibidas.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   | evento `transferred`                                                                                |
| Carga por agente                      | Conversaciones `open` + `on_hold` que tiene ahora cada agente, dentro del alcance y filtros.                                                                                                                                                                                                                                                                                                                                                                                                                               | `conversations` en vivo                                                                             |
| Agentes disponibles ahora             | Usuarios activos con permiso de responder, dentro del alcance, con `users.availability = available` (disponibilidad declarada, no presencia en línea).                                                                                                                                                                                                                                                                                                                                                                     | `users`                                                                                             |
| Volumen de mensajes                   | Entrantes y salientes de las conversaciones recibidas en el periodo (salientes incluye automáticos).                                                                                                                                                                                                                                                                                                                                                                                                                       | mensajes                                                                                            |
| Errores de envío                      | Salientes en `failed` de esas conversaciones; tasa = fallidos / salientes.                                                                                                                                                                                                                                                                                                                                                                                                                                                 | mensajes                                                                                            |
| Horarios de mayor demanda             | Recibidas por día de la semana (lunes primero) y hora local.                                                                                                                                                                                                                                                                                                                                                                                                                                                               | `received_at`                                                                                       |
| Fuera de horario                      | Recibidas fuera del horario del **departamento que la recibió** (el origen de su primera transferencia si se transfirió; si no, el actual): su horario propio si `uses_custom_hours`, si no el de la plataforma si `business_hours.enabled`. Los días no laborables configurados (`business_hours.holidays`) cuentan como fuera de horario todo el día. Sin horario aplicable (atención continua) **no se clasifica** (`null`) y la tarjeta dice "Sin horario laboral configurado". Se usa el horario vigente al calcular. | `BusinessHoursCalendar` → `App\Services\BusinessHours` (mismas reglas que los mensajes de ausencia) |
| Distribución de estados               | Estado actual de las recibidas en el periodo.                                                                                                                                                                                                                                                                                                                                                                                                                                                                              | `conversations.status`                                                                              |
| Tendencia                             | Recibidas por día (rangos ≤ 92 días) o por mes, contra el bucket alineado del periodo anterior; en la hoja "Tendencia" las resueltas se agrupan por la fecha de su primer cierre.                                                                                                                                                                                                                                                                                                                                          | métricas                                                                                            |
| Comparación                           | El periodo anterior tiene la misma duración y termina donde empieza el actual. "Este mes" se compara con los mismos días del mes anterior; "Mes anterior", con el mes previo completo.                                                                                                                                                                                                                                                                                                                                     | —                                                                                                   |

**Nunca se inventan valores.** Un promedio sin muestras es `null` y la interfaz muestra "Sin datos suficientes" (o "—"
en tablas); la comparación solo aparece cuando ambos periodos tienen datos; cada promedio indica cuántas
conversaciones se midieron.

**Periodos:** Hoy, Ayer, Últimos 7/30/90 días, Este mes, Mes anterior y rango personalizado (fechas locales, máximo
366 días; uno mayor se recorta a los últimos 366). Periodo y filtros viven en la URL
(`?period=custom&from=2026-09-01&to=2026-09-30&department=3`). Filtros inválidos no rompen la página: se muestran los
últimos 30 días con un aviso. El panel ofrece Hoy, 7 y 30 días.

**Zona horaria.** Las agrupaciones por día/hora usan el desfase UTC de la zona de la plataforma al inicio del periodo.
En zonas sin horario de verano (como `America/Mexico_City` desde 2022) es exacto; en zonas con cambio de horario, los
días posteriores al cambio dentro del mismo periodo pueden desplazarse una hora.

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
  duraciones precalculadas en segundos, contadores (cierres, reaperturas, transferencias, mensajes entrantes,
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
  últimos N días) y `--conversation=ID` (repetible). Segura de interrumpir y repetir. Programada a diario a las 03:30
  con `--days=2` para reconciliar recálculos perdidos (p. ej. un worker caído).
  **Tras aplicar la migración hay que ejecutar `php artisan analytics:rebuild` una vez para poblar el histórico.**
  La reconciliación filtra por `conversations.updated_at`, sin índice dedicado (recorrido diario de la tabla de
  conversaciones; revisar si crece a decenas de millones).
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
  (`'analytics' => AnalyticsReport::class`). La ruta `GET exports/{report}/{format}`, la autorización
  (`reports.export` + `authorize()` del reporte), los límites de filas, la generación síncrona o en cola
  (`App\Jobs\GenerateReport`), la auditoría (`export.requested/generated/failed`), el almacenamiento privado y la
  descarga autorizada los da `App\Services\Exports\ReportExporter`.
- Render: `XlsxRenderer` (hoja "Resumen" con métricas y gráficas nativas; hoja principal con encabezados, autofiltro,
  paneles congelados y totales; hojas extra) y `PdfRenderer` (Browsershot/Chrome con la plantilla empresarial
  existente: encabezado, filtros aplicados, tarjetas, gráficas SVG de `ChartGeometry`, tablas con totales).
- **`AnalyticsReport`** (`app/Reports/Definitions/AnalyticsReport.php`, clave `analytics`, horizontal):
    - Filtros = `AnalyticsFilters::rules()`, idénticos a la pantalla, resueltos con la misma clase y alcance, y
      descritos en el encabezado (periodo, comparación, alcance, departamento/agente/conexión).
    - Métricas: recibidas, atendidas, resueltas, primera respuesta humana, primera atención y resolución (con
      conversaciones medidas y valor previo), reabiertas, transferencias, fuera de horario, mensajes, errores de envío y
      pendientes ahora.
    - Gráficas: recibidas (periodo vs. anterior), demanda por hora, estado actual y top 10 de departamentos.
    - Tabla principal "Desempeño por agente" (tiempos en minutos con un decimal, operables en Excel) y secciones "Por
      departamento" y "Por conexión" (también en PDF), "Tendencia" y "Demanda por hora" (solo XLSX).
    - Todas las cifras provienen de `OperationalAnalytics::report()`, la misma llamada de la pantalla.
- **`DashboardReport`** (clave `dashboard`) acepta los mismos filtros que el panel y agrega "Recibidas en el periodo" y
  "Agentes disponibles".

### 6.1 Catálogo, límites y ciclo de vida

- **Reportes registrados** en `config/exports.php` (una clave fuera del catálogo responde 404): `dashboard`,
  `analytics`, `conversations`, `conversation-dossier` (expediente desde el panel de detalles), `contacts`, `tags`,
  `quick-replies`, `departments`, `users`, `roles`, `connections`, `audit` y
  `message-templates`. Cada definición autoriza con su propio `authorize()` además de su permiso de exportación
  (`reports.export`; la bitácora usa `audit.export` en su lugar) y aplica el
  alcance del usuario (las conversaciones pasan por `ConversationAccess::restrictToVisible`). El chat interno no se
  exporta.
- **Síncrono o en cola:** hasta `sync_rows` filas el archivo se genera en la petición (XLSX 2 000, PDF 500); por encima
  se encola `GenerateReport` en la cola de exportaciones (`EXPORTS_QUEUE`, por defecto `exports`); por encima de
  `max_rows` (XLSX 50 000, PDF 5 000) se rechaza y hay que acotar filtros. Variables `EXPORTS_{XLSX,PDF}_{SYNC,MAX}_ROWS`.
- **Consistencia:** `ReportExporter::render()` lee dentro de una sola transacción REPEATABLE READ sin bloqueos, así
  que una exportación por páginas no duplica ni omite filas aunque lleguen mensajes mientras se genera.
- **Almacenamiento y descarga:** disco privado `EXPORTS_DISK` (por defecto `local`) en `exports/`; solo el
  solicitante descarga su archivo por ruta autorizada. Caducan a las `EXPORTS_RETENTION_HOURS` (24) y
  `exports:prune` (cada hora) los borra; la lista los muestra como expirados. Al terminar en cola se notifica al dueño
  (`export.finished`) y la página de exportaciones se actualiza con `.transfer.changed`.
- **Seguridad:** límite de 10 solicitudes por minuto por ruta y usuario; las celdas que empiezan con `= + - @`,
  tabulador o retorno se neutralizan contra inyección de fórmulas; se auditan `export.requested`, `export.generated` y
  `export.failed`.

## 7. Pruebas

- `tests/Feature/Analytics/ConversationMetricsTest.php`: línea de tiempo con acciones reales (aceptar, transferir,
  cerrar, reabrir, cerrar) y mensajes automáticos, fallidos y en cola que no cuentan como respuesta humana; fuera de
  horario con horario de departamento, de plataforma o ninguno; origen de la transferencia; idempotencia y
  restauración de `analytics:rebuild`; opciones `--days`/`--conversation`; las notas no recalculan.
- `tests/Feature/Reports/OperationalReportsTest.php`: acceso (invitado, sin permiso), cifras globales con comparación,
  promedios, tasas y mapa de calor, alcance por departamentos con filtros ajenos descartados, alcance asignado (solo
  lo propio), persistencia de filtros y rango inválido, rangos largos por mes, autorización de exportación, contenido
  XLSX/PDF dentro del alcance y PDF real con Chrome (grupo `chrome`).
- `tests/Feature/DashboardTest.php`: se actualizó una aserción ("agentes disponibles" es ahora un conteo real, 0, en
  lugar de `null`) y se agregaron pruebas de disponibilidad, filtros y primera respuesta humana.

## 8. Limitaciones conocidas

- Solo tiempo calendario; sin tiempo en horario laboral ni SLA.
- "Fuera de horario" usa el horario y los días no laborables vigentes al calcular (reconstruir tras editar horarios
  reclasifica el histórico).
- Agente y departamento son los actuales/últimos de la conversación, no el historial de quién la tuvo en cada momento.
- Volumen de mensajes y errores de envío se atribuyen a la cohorte de conversaciones recibidas, no a la fecha de cada
  mensaje.
- Desfase horario fijo por periodo (ver zona horaria).
- Las métricas se actualizan de forma asíncrona: con los workers detenidos, los reportes quedan desactualizados hasta
  que se procese la cola o corra la reconciliación diaria.
