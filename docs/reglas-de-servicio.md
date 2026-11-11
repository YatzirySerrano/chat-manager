# Reglas de servicio

Las reglas de servicio definen **cuánto puede tardar** el equipo en responder (SLA), **si una solicitud debe
confirmarse como completa** antes de atenderla y **cuándo y en qué orden** puede tomarse de la cola. Este documento
describe la base sobre la que se construyen el seguimiento de SLA, las reglas de aceptación de la cola, las analíticas
y las alertas: la jerarquía, cada regla, las tablas nuevas, los permisos y el catálogo de categorías.

> Estado: **entregado y en vigor.** Las reglas ya miden y ya restringen:
>
> - **SLA:** cada espera del cliente es un ciclo de respuesta (`response_cycles`) con el objetivo vigente congelado al
>   iniciar; el evaluador `sla:evaluate` (cada minuto) marca «por vencer» y «vencida», registra el incumplimiento con
>   su evento y su auditoría y envía las alertas y recordatorios configurados; el semáforo, el monitor de SLA, los
>   reportes y las exportaciones leen esos ciclos ([SLA y alertas](#sla-y-alertas)).
> - **Cola:** la confirmación «Lista para atención», la espera mínima y el orden cronológico se validan en el servidor
>   al aceptar, asignar, transferir y distribuir, con excepciones autorizadas y auditadas
>   ([Reglas de la cola](#reglas-de-la-cola)).
> - **Lo que no cambia sin configurarlo:** las reglas de la cola llegan apagadas, y sin un objetivo de SLA no hay
>   plazo, alerta ni cifra de cumplimiento (el ciclo se registra igual, para tener tiempos de respuesta). Los
>   objetivos de primera respuesta que ya estuvieran guardados en la plataforma empiezan a medirse por ciclo y a
>   alertar en cuanto se despliega.
>
> §1 a §8 describen la configuración, el modelo de datos y el resolutor; las limitaciones conocidas están al final de
> cada parte.

## 1. Jerarquía y precedencia

Una regla se resuelve en este orden; gana el primer nivel que la define:

1. **Categoría de solicitud** de la conversación (`conversations.category_id`).
2. **Departamento** de la conversación (`conversations.department_id`).
3. **Plataforma** (Configuración › Objetivos de servicio).

- La plataforma siempre tiene un valor (el guardado o el predeterminado).
- Departamentos y categorías guardan solo lo que personalizan, en su columna `service_rules`: un mapa JSON plano
  `clave con puntos → valor` (por ejemplo `{"sla.first_response_minutes": 15}`). Una clave ausente (o `null`)
  significa **heredar**.
- No todas las reglas se pueden personalizar en todos los niveles (ver la tabla). Una clave no permitida en un nivel
  se **rechaza al validar** y, si aun así estuviera guardada, el resolutor la **ignora**. Lo mismo ocurre con valores
  de tipo incorrecto o fuera de rango.
- Las reglas propias de una **categoría eliminada** dejan de aplicarse (vuelven a aplicarse si se restaura).

La única fuente de verdad es `App\Services\ServiceRules`; nadie más lee `service_rules` ni los grupos de
configuración directamente.

```php
$rules = app(ServiceRules::class)->forConversation($conversation); // ResolvedRules
$rules->firstResponseSeconds();   // ?int  (null = sin objetivo)
$rules->responseSeconds();        // ?int
$rules->resolutionSeconds();      // ?int
$rules->warningPercent();         // int
$rules->businessHoursOnly();      // bool
$rules->confirmationRequired();   // bool
$rules->minWaitSeconds();         // int   (0 = sin espera mínima)
$rules->chronologicalOrder();     // bool
$rules->source('sla.first_response_minutes'); // 'platform' | 'department' | 'category'
```

| Método de `ServiceRules`                               | Uso                                                                                                      |
| ------------------------------------------------------ | -------------------------------------------------------------------------------------------------------- |
| `resolve(?Department, ?ConversationCategory)`          | Reglas vigentes para esos modelos, leídos tal como están.                                                |
| `forConversation(Conversation)` / `forIds(?int, ?int)` | Igual, a partir de `department_id` y `category_id`. Memoizado por ese par.                               |
| `preload(iterable $conversations)`                     | Opcional: adelanta la carga antes de recorrer una página.                                                |
| `describe(?Department, ?ConversationCategory)`         | Para la interfaz: por regla, valor, origen, valor heredado y su origen, nombre, unidad, mínimo y máximo. |
| `validationRules(string $level, string $prefix = '')`  | Reglas de validación de Laravel para `platform`, `department` o `category`.                              |
| `overridesFrom()`, `overridesOf()`, `diff()`           | Normalizar lo enviado por un formulario, leer lo guardado y obtener el antes/después para la auditoría.  |
| `alerts()`                                             | Ajustes de las alertas automáticas (solo plataforma).                                                    |
| `flush()`                                              | Olvida lo memoizado.                                                                                     |

**Sin consultas por fila.** `forConversation()` carga una sola vez las reglas propias de todos los departamentos y de
todas las categorías activas (dos consultas pequeñas por petición) y resuelve en memoria; puede llamarse por cada fila
de una página sin precargar relaciones. La instancia es _scoped_: vive una petición o un trabajo de cola.

## 2. Reglas

| Clave                          | Qué define                                                                                          | Unidad / tipo     | Rango       | Predeterminado | Se personaliza en       |
| ------------------------------ | --------------------------------------------------------------------------------------------------- | ----------------- | ----------- | -------------- | ----------------------- |
| `sla.first_response_minutes`   | Tiempo máximo para la primera respuesta de una persona del equipo                                   | minutos, opcional | 1 a 43 200  | sin objetivo   | departamento, categoría |
| `sla.response_minutes`         | Tiempo máximo para responder cada mensaje posterior del cliente                                     | minutos, opcional | 1 a 43 200  | sin objetivo   | departamento, categoría |
| `sla.resolution_minutes`       | Tiempo máximo hasta el primer cierre                                                                | minutos, opcional | 1 a 525 600 | sin objetivo   | departamento, categoría |
| `sla.warning_percent`          | Porcentaje del plazo consumido a partir del cual la solicitud está «próxima a vencer»               | % del plazo       | 50 a 95     | 80             | departamento, categoría |
| `sla.business_hours_only`      | Contar solo el horario de atención (se detiene fuera de horario y en días no laborables)            | sí / no           | —           | sí             | departamento            |
| `intake.confirmation_required` | Exigir la confirmación «Solicitud completa / Lista para atención» antes de poder tomar la solicitud | sí / no           | —           | no             | departamento, categoría |
| `queue.min_wait_minutes`       | Tiempo mínimo en la cola de listas antes de poder aceptar la solicitud (0 = desactivado)            | minutos           | 0 a 1440    | 0              | departamento, categoría |
| `queue.chronological_order`    | Los agentes deben tomar primero la solicitud lista más antigua del departamento                     | sí / no           | —           | no             | departamento            |

Notas:

- En un departamento o una categoría, «vacío» siempre significa heredar: un nivel inferior **no puede quitar** un
  objetivo que un nivel superior define, solo cambiarlo.
- `sla.business_hours_only` y `queue.chronological_order` describen cómo trabaja un departamento (su horario y su
  cola), por eso una categoría no las cambia.
- `sla.resolution_minutes` admitía hasta 43 200 en la plataforma; el máximo sube a 525 600 (un año). Los valores ya
  guardados siguen siendo válidos.

Los valores de plataforma viven en `PlatformSettings` (tabla `settings`), en los grupos `sla` (que conserva
`first_response_minutes` y `resolution_minutes`, usados por Reportes), `intake` y `queue`.

### 2.1 Alertas automáticas de SLA (solo plataforma)

Grupo `sla_alerts` de `PlatformSettings`. No forma parte de la jerarquía.

| Clave                | Qué define                                                             | Rango    | Predeterminado    |
| -------------------- | ---------------------------------------------------------------------- | -------- | ----------------- |
| `agent_warning`      | Avisar al agente responsable cuando la solicitud está próxima a vencer | sí / no  | sí                |
| `agent_breach`       | Avisar al agente responsable cuando la solicitud vence                 | sí / no  | sí                |
| `supervisor_warning` | Avisar a supervisión cuando la solicitud está próxima a vencer         | sí / no  | no                |
| `supervisor_breach`  | Avisar a supervisión cuando la solicitud vence                         | sí / no  | sí                |
| `reminder_minutes`   | Cada cuántos minutos se recuerda una solicitud vencida                 | 5 a 1440 | sin recordatorios |
| `reminder_limit`     | Máximo de recordatorios por solicitud                                  | 1 a 10   | 3                 |

Son distintas de las alertas manuales de «Seguimiento de atención» (`attention_alerts`), que escribe una persona.

## 3. Cuándo surte efecto un cambio

Las reglas se leen **en el momento de cada decisión**. Un cambio vale para las decisiones que se tomen desde entonces
y **nunca reescribe lo ya registrado**.

| Grupo                                       | Efecto de un cambio                                                                                                                        |
| ------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------ |
| Tiempo máximo de respuesta (SLA)            | Se aplica a las solicitudes nuevas; las que ya están en curso conservan su plazo (el objetivo queda congelado en `response_cycles`).       |
| Solicitud completa / Lista para atención    | Solo las solicitudes nuevas nacerán como pendientes de información. Las existentes siguen `ready`: activar la regla no bloquea nada atrás. |
| Tiempo mínimo de espera y orden de atención | Se aplica de inmediato a la cola.                                                                                                          |

Quien cambia reglas o categorías llama a `ServiceRules::flush()`. Ya lo hacen las acciones de esta fase, y además
`PlatformSettings::update()` y los modelos `Department` y `ConversationCategory` al guardarse, de modo que un cambio
hecho por cualquier vía se ve en la siguiente decisión de la misma petición.

## 4. Dónde se configura

| Nivel        | Pantalla                                                    | Ruta                                                | Autorización                 | Auditoría                             |
| ------------ | ----------------------------------------------------------- | --------------------------------------------------- | ---------------------------- | ------------------------------------- |
| Plataforma   | Configuración › Objetivos de servicio y Alertas automáticas | `PUT /admin/settings` (formulario único de siempre) | `settings.update`            | `settings.updated`                    |
| Departamento | Ficha del departamento › Reglas de servicio                 | `PUT /departments/{department}/service-rules`       | la de editar el departamento | `department.service_rules_updated`    |
| Categoría    | Categorías de solicitud › Reglas de servicio                | `PUT /categories/{category}/rules`                  | `categories.manage`          | `conversation_category.rules_updated` |

- Los formularios de departamento y categoría envían `rules` agrupado como la configuración
  (`rules.sla.first_response_minutes`, `rules.queue.min_wait_minutes`, …) y **reemplazan el conjunto completo**: una
  regla enviada como `null` u omitida vuelve a heredar. Guardar lo mismo no escribe ni audita nada.
- La auditoría guarda, por regla, el valor anterior y el nuevo (`null` = hereda).
- El formulario de Configuración sigue aceptando envíos que no conocen las claves nuevas: una clave omitida conserva
  su valor.
- Interfaz: `resources/js/components/service-rules/ServiceRulesCard.vue` pinta cualquier nivel a partir de
  `describe()`, siempre en los mismos tres bloques. En departamento y categoría cada regla muestra «Usa el valor
  general: X» o «Valor propio». Los textos de ayuda están en `resources/js/lib/serviceRules.ts`.

## 5. Categorías de solicitud

Catálogo de tipos de solicitud (`conversation_categories`), modelado como las etiquetas:

- Nombre único sin distinguir mayúsculas (hasta 120 caracteres), descripción opcional y color.
- **Eliminación lógica** (`is_active`, `App\Concerns\DeletesLogically`): una categoría eliminada no puede elegirse, sus
  reglas dejan de aplicarse y se conserva en las conversaciones que ya la tenían. Quien tiene `records.view-deleted`
  la consulta y la restaura.
- Pantalla `resources/js/pages/categories/Index.vue` (Administración › Categorías de solicitud), rutas en
  `routes/categories.php`, política `ConversationCategoryPolicy`, acciones en `app/Actions/Categories`.
- Auditoría: `conversation_category.created`, `.updated`, `.deleted`, `.restored` y `.rules_updated`, con el sujeto
  «Categoría de solicitud».
- Otras pantallas obtienen el catálogo activo con `ConversationCategory::options()` (`[{id, name, color}]`).

La categoría se asigna desde la bandeja (panel de detalles): ver [Reglas de la cola](#reglas-de-la-cola).

## 6. Modelo de datos

Todas las migraciones son aditivas (fecha `2026_11_10`); ninguna modifica una migración anterior.

| Migración                                      | Qué agrega                                                                                              | Por qué                                                                                                                              |
| ---------------------------------------------- | ------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------ |
| `create_conversation_categories_table`         | Tabla `conversation_categories` (`name` único, `description`, `color`, `is_active`, `service_rules`)    | No existía el concepto de categoría; es el nivel más específico de la jerarquía.                                                     |
| `add_service_rules_to_departments_table`       | `departments.service_rules` (JSON, nulo)                                                                | Reglas propias del departamento. Nulo = hereda todo, así los departamentos existentes no cambian.                                    |
| `add_service_readiness_to_conversations_table` | `category_id`, `readiness`, `readiness_changed_at`, `readiness_changed_by_user_id`, `queued_at`, índice | Clasificar la conversación, saber si está lista y desde cuándo espera en la cola (reloj de la espera mínima y del orden de llegada). |
| `create_response_cycles_table`                 | Tabla `response_cycles`                                                                                 | Registrar cada espera «el cliente escribió → el equipo debe responder» con el objetivo vigente congelado.                            |
| `add_service_rules_permissions`                | Tres permisos y su asignación a los roles raíz y al Administrador                                       | Ver §7.                                                                                                                              |

### 6.1 `conversations`

- `category_id`: clave foránea a `conversation_categories`, `set null` (las categorías solo se eliminan lógicamente).
- `readiness` (`ready` | `needs_info`, enum `App\Enums\ConversationReadiness`: «Lista para atención» / «Necesita
  información»), predeterminado `ready`. **Todas las filas existentes quedan `ready`.**
- `readiness_changed_at` y `readiness_changed_by_user_id` (`set null`): quién confirmó o devolvió la solicitud.
- `queued_at`: cuándo entró a la cola en la que espera. La migración lo rellena con `created_at` **solo** para las
  conversaciones `pending` (una sola sentencia acotada por el índice de estado).
- Índice `conversations_queue_order_index (department_id, status, queued_at)`: la cola de un departamento por
  antigüedad.
- `open_response_cycle_id`: el ciclo de respuesta abierto de la conversación (nulo si el cliente no está esperando).
  Puntero sin llave foránea ni índice que solo escribe y lee `ResponseClock`, dentro de la transacción que bloquea
  la fila; existe para encontrar el ciclo abierto con una lectura actual de esta fila (ver «Transacciones» en
  [Qué es un ciclo de respuesta](#qué-es-un-ciclo-de-respuesta)).

### 6.2 `response_cycles`

Una fila por ciclo. Es una **tabla de hechos**: nunca se borra ni se recalcula.

- `conversation_id` con `cascade`, como `conversation_events` (hecho por conversación; las conversaciones nunca se
  borran físicamente, así que la cascada no elimina historial; [arquitectura.md §3.3](arquitectura.md#33-eliminación-lógica-e-integridad-histórica)).
- `kind`: `first_response` | `response` (`App\Enums\ResponseCycleKind`).
- Dimensiones **sin clave foránea**, como `conversation_metrics`: `connection_id`, `department_id`, `category_id`,
  `assigned_user_id`. Solo filtran agregados y no deben bloquear las tablas operativas.
- Objetivo congelado al iniciar: `started_at`, `target_seconds` (nulo = sin objetivo, el ciclo no se mide),
  `business_hours_only`, `warn_at`, `due_at`.
- Seguimiento: `warned_at`, `breached_at`, `breach_user_id`, `reminders_count`, `last_alert_at`.
- Cierre: `responded_at`, `responder_user_id`, `ended_at`, `outcome` (`answered` | `closed`), `response_seconds`,
  `business_response_seconds`, `exceeded_seconds`.
- `active`: `1` mientras el ciclo está abierto y `NULL` al terminar, con `UNIQUE (conversation_id, active)`: la base
  de datos garantiza **un solo ciclo abierto por conversación** (los `NULL` no chocan entre sí).
- Índices: `(active, breached_at, due_at)`, `(active, warned_at, warn_at)`, `(started_at)`, `(department_id, started_at)`,
  `(responder_user_id, started_at)`, `(breached_at)`.

Modelo `App\Models\ResponseCycle` (relaciones `conversation`, `department`, `category`, `assignee`, `responder`;
_scopes_ `active()` y `breached()`), con fábrica y estados `withTarget()`, `breached()`, `answered()` y
`closedUnanswered()`.

### 6.3 Plazos en horario de atención

`App\Services\Analytics\BusinessHoursCalendar::addBusinessSeconds($from, $seconds, $department)` devuelve el instante
en que han transcurrido esos segundos de atención. Es la inversa exacta de `businessSecondsBetween()`:

- Con atención continua es una suma simple.
- Si el inicio cae fuera de horario, empieza a contar en la siguiente apertura; salta noches, fines de semana y días
  no laborables y respeta los cambios de horario de verano.
- Devuelve el **primer** instante que cumple (un plazo que termina justo al cierre vence al cierre, no en la
  siguiente apertura).
- Un horario que nunca abre, o un plazo a más de `MAX_DAYS` (1100) días, no se puede medir en tiempo de atención:
  degrada a tiempo de calendario en lugar de recorrer días sin fin.

## 7. Permisos

| Permiso                              | Qué permite                                                                     | Notas                                   |
| ------------------------------------ | ------------------------------------------------------------------------------- | --------------------------------------- |
| `categories.manage`                  | Administrar el catálogo de categorías de solicitud y sus reglas propias         | Solo roles que ven toda la organización |
| `conversations.confirm-ready`        | Confirmar que una solicitud está completa o devolverla a «Necesita información» |                                         |
| `conversations.override-queue-rules` | Hacer excepciones justificadas a la espera mínima y al orden de llegada         | Permiso sensible                        |

Roles predeterminados (instalación nueva):

| Rol                | `categories.manage` | `conversations.confirm-ready` | `conversations.override-queue-rules` | `attention.view` |
| ------------------ | ------------------- | ----------------------------- | ------------------------------------ | ---------------- |
| Superadministrador | sí                  | sí                            | sí                                   | sí               |
| Administrador      | sí                  | sí                            | sí                                   | sí               |
| Supervisor         | no                  | sí                            | sí                                   | sí (nuevo)       |
| Agente             | no                  | no                            | no                                   | no               |

**Instalaciones existentes.** La migración crea los tres permisos y los otorga a los roles raíz y al Administrador;
`php artisan app:sync-permissions` también los conoce (salen del enum `Permission`). Los roles **Supervisor** ya
creados no cambian solos, porque los roles se administran desde la interfaz. Para ponerlos al día con su definición:

```bash
php artisan app:sync-permissions --upgrade-default-roles            # muestra lo que falta, no escribe
php artisan app:sync-permissions --upgrade-default-roles --apply    # lo aplica, auditado
```

`conversations.override-queue-rules` es sensible: la actualización lo retiene salvo que se añada
`--include-sensitive`.

`conversations.confirm-ready` y `conversations.override-queue-rules` se aplican en [Reglas de la cola](#reglas-de-la-cola).

## 8. Pruebas

`tests/Feature/ServiceRules/*` (resolutor, esquema, departamentos, categorías, configuración) y
`tests/Feature/Analytics/BusinessDeadlineTest.php` (plazos en horario de atención).

## SLA y alertas

Seguimiento en vivo del tiempo de respuesta, alertas automáticas, historial de incumplimientos y monitoreo para
supervisión. Todo se apoya en `response_cycles` (§6.2).

### Qué es un ciclo de respuesta

**Un ciclo = un periodo en el que un cliente espera la respuesta de una PERSONA.** Lo administra una sola clase,
`App\Services\Conversations\ResponseClock`:

| Método                                | Cuándo se llama                                                                                           | Qué hace                                                                                         |
| ------------------------------------- | --------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------ |
| `customerWrote($conversation, $at)`   | `ReceiveInboundMessage`, al registrar un mensaje del cliente                                              | Abre un ciclo si la conversación es viva, no está cerrada y no tiene uno abierto                 |
| `answered($conversation, $responder)` | `QueueOutboundMessage::handle()` y `Templates\QueueTemplateMessage`, al **encolar** una respuesta humana  | Termina el ciclo con `outcome = answered`                                                        |
| `closed($conversation)`               | Evento `closed` del motor (cierre manual y cierre automático por inactividad)                             | Termina el ciclo con `outcome = closed`                                                          |
| `sync($conversation)`                 | Cualquier otro evento del motor (asignación, transferencia, liberación, reapertura, cambio de categoría…) | Copia responsable, departamento y categoría actuales al ciclo abierto; **nunca** toca los plazos |
| `afterEvent($conversation, $type)`    | Único gancho, en `ConversationLifecycle::record()`                                                        | Decide entre `closed()` y `sync()`                                                               |

Reglas:

- **Apertura.** Más mensajes del cliente dentro de la misma espera no abren otro ciclo. `kind` es `first_response`
  mientras la conversación nunca ha tenido una respuesta humana (`conversations.first_response_at` nulo) y `response`
  después. El ciclo se registra **siempre**, haya o no objetivo (`target_seconds` nulo = no se mide), así los tiempos
  de respuesta existen aunque no se configure ningún SLA. `started_at` es la hora del mensaje del cliente (nunca
  futura). Un mensaje entregado tarde, enviado antes de la última respuesta del equipo, no abre una espera nueva.
  Las conversaciones importadas no tienen ciclos.
- **Congelado al iniciar.** El objetivo (`ServiceRules::forConversation()` en ese instante: categoría > departamento >
  plataforma), `business_hours_only`, `warn_at` (inicio + `warning_percent` del objetivo) y `due_at`. Con
  `business_hours_only` ambos instantes se calculan con `BusinessHoursCalendar::addBusinessSeconds()` y el horario del
  departamento de la conversación. **Cambiar la configuración, transferir o recategorizar nunca mueve el plazo de un
  ciclo abierto o cerrado**: los valores nuevos aplican a los ciclos que inicien después.
- **Qué detiene el reloj.** Solo una respuesta humana encolada (misma regla que `first_response_at`:
  `sender_user_id` no nulo, no automática, no de la API de integración) o el cierre de la conversación. **No lo
  detienen**: marcar como leído, los mensajes automáticos (bienvenida, ausencia, despedida), las respuestas de
  integraciones ni las notas internas.
- **Reapertura.** Una conversación reabierta inicia un ciclo nuevo con el siguiente mensaje del cliente.
- **Responsabilidad.** `assigned_user_id`, `department_id` y `category_id` del ciclo abierto siguen a la conversación.
  Reasignar **no reinicia el reloj**: el cliente lleva esperando todo ese tiempo. `breach_user_id` congela quién era
  responsable en el instante del incumplimiento (`NULL` = estaba en una cola).
- **Transacciones.** Toda mutación ocurre dentro de la transacción que ya tiene bloqueada la fila de la conversación
  (recepción, encolado de respuesta o plantilla, cierre, eventos del motor); el índice único
  `(conversation_id, active)` es la última barrera. El costo en la recepción es una lectura indexada, un `INSERT`
  y la actualización del puntero `conversations.open_response_cycle_id`.
- **Cómo se encuentra el ciclo abierto.** `sync()`, `closed()` y `answered()` leen primero
  `conversations.open_response_cycle_id` con una lectura bloqueante **por clave primaria** de la fila de la
  conversación (que la operación ya tiene bloqueada) y después bloquean el ciclo por su clave primaria y vuelven a
  comprobar `active`. Es una lectura actual: ve el ciclo confirmado más reciente aunque la transacción que llama haya
  tomado su instantánea (REPEATABLE READ) antes de que el cliente escribiera —por ejemplo, la liberación de
  solicitudes dentro de un cambio de usuario o de rol, o un cierre decidido sobre datos anteriores—. Con solo la
  lectura de instantánea ese ciclo no se veía: su responsable quedaba apuntando a quien ya había perdido la solicitud
  (y un incumplimiento posterior se le atribuía) y un cierre podía dejarlo abierto. **No se usa** una lectura
  bloqueante ni un `UPDATE` por `(conversation_id, active)`: cuando no encuentra fila deja un bloqueo de hueco en ese
  índice único hasta el fin de la transacción y frena los ciclos de otras conversaciones que se están recibiendo. Si
  el puntero está vacío se conserva la lectura simple anterior (ciclos escritos fuera del motor). Pruebas con dos
  conexiones reales: `tests/Feature/Database/ConcurrencyTest.php`.

### Cumplimiento

Solo cuentan los ciclos con objetivo.

| Resultado      | Condición                                                                            |
| -------------- | ------------------------------------------------------------------------------------ |
| Cumplido       | `outcome = answered` y `responded_at <= due_at` (responder justo en el plazo cumple) |
| Incumplido     | `breached_at` no nulo. Un incumplimiento por ciclo, para siempre                     |
| Ni uno ni otro | Cerrado sin respuesta antes del plazo: la ausencia de dato no es cumplimiento        |
| No medido      | `target_seconds` nulo                                                                |

- `breached_at` vale siempre `due_at` (el instante real del incumplimiento). Lo fija el evaluador cuando el plazo pasa
  con el ciclo abierto o, si el evaluador aún no había pasado, el propio `ResponseClock` al llegar la respuesta o el
  cierre tardíos: **el hecho nunca depende de cuándo corrió el programador**.
- `response_seconds` es tiempo de calendario; `business_response_seconds`, tiempo de atención (nulo si el periodo
  supera `MAX_DAYS`).
- `exceeded_seconds` es el tiempo desde `due_at` hasta el fin del ciclo, **en la unidad del objetivo** (tiempo de
  atención si `business_hours_only`, calendario si no). Nulo si no hubo incumplimiento y también cuando el tiempo
  de atención no puede calcularse (tramo mayor que `MAX_DAYS`): lo desconocido se guarda como desconocido, nunca
  como una cifra en otra unidad. Mientras el ciclo sigue abierto se calcula al vuelo
  (`ResponseClock::exceededSeconds()`). Como la unidad depende del objetivo de cada ciclo, el **exceso promedio**
  de un grupo se calcula y rotula por separado —«tiempo hábil» y «tiempo corrido»— en pantalla, XLSX y PDF
  (`exceeded_business_seconds`, `exceeded_calendar_seconds`); nunca se promedian juntos. El detalle por ciclo
  indica en qué se contó su plazo («Plazo contado en»).

### Evaluador (`sla:evaluate`)

`App\Services\Sla\SlaEvaluator`, comando `php artisan sla:evaluate`, programado **cada minuto**
(`->everyMinute()->withoutOverlapping(5)` en `routes/console.php`). No requiere proceso ni dependencia nuevos. Un plazo
puede vencer sin que nadie envíe nada y con el agente desconectado: la notificación lo espera en su campana.

En cada pasada, en este orden:

1. **Incumplimientos**: ciclos abiertos con `due_at < ahora` y `breached_at` nulo. Por cada uno, dentro de una
   transacción que bloquea primero la conversación (orden de bloqueo del motor): fija `breached_at = due_at`,
   `breach_user_id = assigned_user_id` y `last_alert_at`, y deja **un** evento en la línea de tiempo
   (`ConversationEventType::SlaBreached`, `sla_breached`, «Objetivo de respuesta incumplido») y **una** entrada de
   auditoría (`AuditAction::ConversationSlaBreached`, `conversation.sla_breached`, con `detected_on` =
   `evaluator` | `answer` | `close`). El evento viaja por `ConversationChanged`, así que las bandejas y el monitoreo se
   refrescan en tiempo real.
2. **Avisos de «por vencer»**: ciclos abiertos con `warn_at <= ahora` y `warned_at` nulo. Si el ciclo ya venció (el
   programador estuvo detenido) solo se marca: se anuncia como vencido, no como «por vencer».
3. **Recordatorios**: ciclos vencidos aún abiertos, si `sla_alerts.reminder_minutes` tiene valor, cuando pasó ese
   intervalo desde `last_alert_at` y `reminders_count < reminder_limit`.

**Idempotencia por construcción, no por calendario.** Cada transición se **reclama** con un `UPDATE` condicional
atómico sobre la fila del ciclo (`… WHERE active = 1 AND breached_at IS NULL`, `… AND warned_at IS NULL`,
`… AND reminders_count = <visto>`), y solo quien cambió la fila actúa. Dos ejecuciones solapadas, dos servidores o un
reintento no pueden producir dos avisos, dos eventos ni dos entradas de auditoría. Los recibos de notificación
(`notification_receipts`, uno por destinatario, ciclo y etapa) son una segunda barrera.

**Acotado.** Cada etapa lee como máximo 200 candidatos por pasada a través de los índices
`(active, breached_at, due_at)` y `(active, warned_at, warn_at)`, hasta 10 pasadas por ejecución; el resto se atiende
al minuto siguiente. Las etapas pendientes buscan por igualdad sobre la marca aún nula (`breached_at IS NULL`,
`warned_at IS NULL`) y por rango sobre la fecha, de modo que los ciclos **ya procesados que siguen abiertos no se
vuelven a leer** cada minuto: con miles de solicitudes vencidas sin responder, vencimientos y avisos siguen siendo dos
lecturas de índice que no devuelven filas. Los recordatorios (solo si hay frecuencia configurada) recorren, por rango
sobre `breached_at`, los ciclos vencidos que siguen abiertos —el conjunto sobre el que tratan—, incluidos los que ya
agotaron su límite de recordatorios. Prueba permanente (plan y filas leídas):
`tests/Feature/Performance/SlaEvaluatorQueriesTest.php`.

Las notificaciones se envían después de confirmar el reclamo. Una caída justo en medio pierde ese aviso, nunca el
hecho (el incumplimiento queda registrado y visible en todas partes); el fallo al notificar un ciclo se reporta y no
detiene los demás.

### Alertas automáticas

Tipos de notificación nuevos (ver [notificaciones-y-horarios.md §1.2](notificaciones-y-horarios.md)):
`sla.warning` (informativa, se anuncia con _toast_ y sonido) y `sla.breach` (urgente). Clase
`App\Notifications\SlaAlertNotification`.

- **Destinatarios**, según `ServiceRules::alerts()`: el **agente responsable** (`agent_warning`, `agent_breach`), si
  aún puede ver la conversación, y **supervisión** (`supervisor_warning`, `supervisor_breach`) = usuarios activos con
  `attention.view` que pueden ver esa conversación con la regla de visibilidad de siempre
  (`NotificationRecipients::slaSupervisors()`). El agente nunca recibe el aviso dos veces y nadie fuera del alcance de
  la conversación lo recibe. Una solicitud en cola no tiene responsable: solo se avisa a supervisión.
- **Deduplicación**: `sourceKey = sla:{ciclo}:{warning|breach}[:r{n}]`.
- **Agrupación**: supervisión recibe **una** notificación sin leer por etapa y departamento que reúne los ciclos
  (hasta 20 recordados); el agente, una por ciclo, en la que se pliegan los recordatorios.
- **Texto al leer**: se arma contra el estado **actual** del ciclo. Una solicitud ya respondida o cerrada lo dice
  («Solicitud vencida ya respondida: …»), enlaza a la conversación y deja de presentarse como «Requiere acción»
  (nivel informativo). La agrupada cuenta cuántas siguen sin respuesta y enlaza al monitoreo.
- **Sonido**: como cualquier notificación, uno por lote y sin bucles; respetan las preferencias del usuario (en la
  app, sonido, navegador) y Web Push.
- **Qué detiene una alerta**: la respuesta humana o el cierre terminan el ciclo; desde ese momento no hay más avisos
  ni recordatorios, y los ya enviados se muestran como resueltos.
- Son distintas de los **recordatorios manuales** de «Seguimiento de atención» (`attention_alerts`), que escribe una
  persona, y del **control de conversaciones pendientes** (`PendingAttention`), que bloquea aceptar y no avisa. La
  pantalla lo explica.

### En la interfaz

- **Contrato**: `ConversationResource` añade `sla` (`App\Services\Sla\SlaSnapshot`):
  `{ kind, kind_label, started_at, warn_at, due_at, target_seconds, business_hours_only, breached } | null` (`null`
  cuando no hay ciclo abierto con objetivo). La lista lo carga para toda la página en **una** consulta
  (`SlaSnapshot::load()`, relación `Conversation::openResponseCycle`). Lo ve quien puede ver la fila.
- **Semáforo** `resources/js/components/sla/SlaBadge.vue` (+ `resources/js/lib/sla.ts`): «En tiempo» (verde), «Por
  vencer» (amarillo), «Vencida» (rojo), siempre con ícono y texto. Se clasifica **en el navegador** con `warn_at` y
  `due_at` contra el reloj compartido `useServerNow()`; ningún contador hace peticiones. Está en la fila de la
  bandeja y el encabezado del chat en su forma compacta (estado y tiempo, sin controles propios: el encabezado es
  a su vez el botón «Ver detalles»), y en el panel de detalles con el desglose completo: tiempo transcurrido, tiempo
  restante o vencido, fecha límite, objetivo aplicado y si solo cuenta el horario de atención.
- **Monitoreo**: pestaña «Monitoreo de SLA» de «Seguimiento de atención» (`/attention?tab=sla`,
  `App\Services\Sla\SlaMonitor`): contadores (en tiempo, por vencer, vencidas, sin objetivo configurado), tabla de
  ciclos abiertos ordenada por urgencia y paginada en el servidor (25 por página), filtros en la URL validados por el
  servidor (`sla_state`, `sla_department`, `sla_category`, `sla_agent`, `sla_connection`, `sla_page`) y el «Historial
  de incumplimientos» (`sla_from`, `sla_to`, `sla_history_page`; 20 por página, rango máximo de 92 días, últimos 7 por
  defecto, en la zona horaria de la plataforma). El alcance es estrictamente `Conversation::visibleTo()`; requiere
  `attention.view`. Las propiedades `sla` y `slaBreaches` son diferidas (con esqueletos) y se refrescan con
  `useLiveReload` en los canales de bandeja del usuario, además del sondeo de respaldo.

### Limitaciones conocidas

- **Una respuesta cuenta al encolarse**, igual que `first_response_at`, no cuando el proveedor la acepta o la entrega.
- Un incumplimiento detectado por una respuesta o un cierre tardíos (antes de que pasara el evaluador) queda
  registrado con su evento y su auditoría, pero **no genera notificación**: la solicitud ya está resuelta. En esa
  entrada de auditoría el autor es quien respondió o cerró (`detected_on` lo aclara); en las del evaluador es
  «Sistema».
- Con `business_hours_only`, el semáforo muestra el tiempo de **calendario** que falta para la fecha límite (que ya
  considera el horario), no los minutos de atención restantes.
- No hay SLA de **resolución** en vivo: `sla.resolution_minutes` sigue siendo un objetivo de Reportes.
- Si el programador de tareas está detenido no se envían alertas; al volver, los plazos vencidos se registran con su
  instante real y se anuncian una sola vez.
- Las alertas en cola usan el departamento de la conversación para elegir a supervisión; una conversación sin
  departamento solo avisa a quienes tienen alcance global.

Pruebas: `tests/Feature/Sla/*` (ciclo de vida, evaluador y notificaciones, monitoreo y contrato de la lista).

## Reglas de la cola

Tres reglas **separadas** deciden si una solicitud puede tomarse de su cola. Nunca se mezclan, ni en el código ni en la
interfaz, y todas están apagadas por defecto. La única implementación es `App\Services\Conversations\QueueRules`.

| #   | Regla                   | Clave                          | Código (`code`) | Mensaje                                                                                        |
| --- | ----------------------- | ------------------------------ | --------------- | ---------------------------------------------------------------------------------------------- |
| 1   | Solicitud completa      | `intake.confirmation_required` | `needs_info`    | «Esta solicitud todavía necesita información. Podrá tomarse cuando se confirme como completa.» |
| 2   | Tiempo mínimo de espera | `queue.min_wait_minutes`       | `min_wait`      | «Podrás tomarla dentro de 3 minutos.» (con `available_at` exacto)                              |
| 3   | Orden de llegada        | `queue.chronological_order`    | `queue_order`   | «Existe una solicitud anterior que debe atenderse primero.» (con `blocking_conversation_id`)   |

El **límite de capacidad** y el **control de conversaciones pendientes de atención** son reglas del agente, anteriores
a esta fase, con sus propios mecanismos ([bandejas-y-asignacion.md §4](bandejas-y-asignacion.md)). Se evalúan después y
una excepción a las reglas de la cola **nunca** las omite.

### Preparación de la solicitud (`readiness`)

Es un estado de preparación **independiente** del estado de la conversación (`pending`, `open`, `closed`):

- Una conversación nueva nace `needs_info` (con `queued_at` nulo) **solo** si `confirmationRequired()` está activa para
  su departamento en ese momento; si no, nace `ready` con `queued_at = ahora`.
- Activar la regla después **no toca** las conversaciones existentes.
- `needs_info` solo bloquea mientras la regla está activa **ahora** para esa conversación (categoría > departamento >
  plataforma): si un administrador la apaga, la cola se libera sin reescribir nada.
- La conversación sigue recibiendo los mensajes del cliente y nunca pierde historial.

**Confirmar o devolver:** `POST /conversations/{conversation}/readiness` con `{readiness: ready|needs_info, reason?,
version?}` (`SetConversationReadiness`).

- Requiere `conversations.confirm-ready` y el **alcance operativo** sobre la conversación (el mismo de las demás
  operaciones: `ConversationPolicy::permits('confirmReadiness')`). Fuera de alcance: `404`; visible sin permiso: `403`.
- Se permite en conversaciones pendientes y abiertas. Cerrada: `409` (`closed`). Importada: `403` (solo consulta).
- `reason` es obligatorio (mínimo 5 caracteres) al devolver a `needs_info`; opcional al confirmar.
- Corre bajo el bloqueo de la conversación. Es **idempotente**: pedir el estado que ya tiene no cambia ni registra
  nada, aunque la petición traiga una versión vieja (un reintento encuentra su trabajo hecho). Si el estado sí cambia y
  la versión enviada es vieja, responde `409` (`state_changed`). El cambio incrementa `version`.
- Registra un evento de conversación (`readiness_confirmed` / `readiness_reverted`, visibles en la línea de tiempo del
  chat con el motivo) **y** una entrada de auditoría (`conversation.readiness_confirmed` /
  `conversation.readiness_reverted`, con `previous_readiness`, `new_readiness` y `reason`).
- Al confirmar una solicitud en cola de un departamento **automático**, su cola se vuelve a ofrecer tras el commit.
- Marcar `needs_info` una conversación **abierta** la deja con su agente: importa cuando vuelva a una cola.

### El reloj de la cola: `queued_at`

**Decisión:** `queued_at` es la **entrada efectiva a la cola de solicitudes listas** y es el **único reloj** tanto del
tiempo mínimo de espera como del orden de llegada. Se fija:

- al **crear** la conversación, si nace lista;
- al **confirmarla** como completa (la espera mínima y el orden cuentan desde la confirmación, no desde la creación);
- cada vez que **vuelve a una cola** como lista: devolver a Pendientes, transferir a una cola o a otro departamento,
  reabrir hacia la cola y liberar por pérdida de acceso (`ConversationLifecycle::queued($conversation)`).

Si vuelve a una cola mientras `needs_info` bloquea ahí, `queued_at` queda nulo hasta que se confirme. Al asignarse, el
valor se conserva (dice cuándo entró a la cola de la que salió). Una fila sin `queued_at` que resulta elegible (nació
`needs_info` y luego se apagó la regla, o datos antiguos) usa `created_at` como reloj.

### Orden de validación al aceptar

`AcceptConversation`, con la conversación bloqueada y después de permiso (`403`), visibilidad (`404`) y estado (`409`),
que no cambian. El retorno idempotente (ya es tuya y está abierta) va primero.

1. Solicitud completa → `needs_info`.
2. Tiempo mínimo de espera → `min_wait`.
3. Orden de llegada → `queue_order`.
4. Capacidad (existente; código `conflict`).
5. Conversaciones pendientes de atención (existente; código `conflict`).

Cada rechazo es un `ConversationConflict` (`409`) con su propia explicación; las tres primeras son
`App\Exceptions\QueueRuleConflict`:

- JSON: `{code, message, available_at, blocking_conversation_id, overridable}`.
- Inertia: `errors.conversation` (mensaje), `errors.conversation_conflict` (código) y, cuando aplican,
  `errors.conversation_available_at`, `errors.conversation_blocking_id` y `errors.conversation_overridable`.

### Qué solicitudes se comparan (orden de llegada)

El orden solo existe entre solicitudes **comparables y realmente elegibles**: pendientes, sin agente, en vivo (no
importadas ni archivadas), del **mismo departamento** (sin departamento es su propia cola), completas (o sin exigir
confirmación), con la espera mínima cumplida, ordenadas por `queued_at` y luego `id`. Con la regla activa, un agente
solo puede aceptar la **primera** de ese conjunto. Las de otros departamentos, las incompletas, las que siguen
esperando y las ya tomadas **nunca** bloquean.

**Una sola definición de «elegible» en SQL.** `QueueRules::eligibleQuery($departmentId)` expresa en la consulta la
parte estática de la elegibilidad: pendiente, sin agente, en vivo, completa donde se exige confirmación y con la espera
mínima cumplida (`COALESCE(queued_at, created_at) <= ahora − espera`). El predicado se arma con `ServiceRules`: el
valor del departamento (o de la plataforma) para todas las filas, y una rama propia para las pocas categorías cuyas
reglas resuelven distinto ahí. La usan **las cuatro** lecturas de «qué se puede tomar», así que nunca discrepan:

- aceptar (`earlierEligible()`), la primera de la cola que muestra la bandeja (`head()`),
- el reparto automático (`AssignAutomatically::distributeQueue()`) y
- el comando programado (`hasEligible()`).

Como solo se leen filas **elegibles**, un atraso de solicitudes incompletas o que siguen esperando (por largo que sea)
nunca oculta a una que sí puede tomarse. El límite sigue existiendo como red de seguridad: `QueueRules::HEAD_SCAN` (50)
candidatas elegibles por rama (con reloj, por el índice `conversations_queue_order_index`; y sin reloj, por
`created_at`), que solo acota cuántas se releen bajo bloqueo. El orden de toda lectura es el mismo:
`COALESCE(queued_at, created_at)` y luego `id` (`QueueRules::eligible()`).

**Concurrencia.** Las candidatas salen de una lectura normal del índice; después, cada candidata elegible se **relee
por clave primaria con bloqueo compartido**, de la más antigua a la más nueva, y se juzga sobre esa fila fresca:

- Si otra transacción está aceptando o cambiando la solicitud anterior, esta **espera** y decide con el resultado: si
  se confirmó, la anterior ya no es elegible y deja de bloquear (quien toma la segunda mientras se acepta la primera,
  entra); si se revirtió, sigue bloqueando (conflicto limpio).
- Dos agentes nunca se saltan a la vez la primera de la cola sin que se detecte, y la fila de la conversación sigue
  bloqueada en exclusiva, así que nunca hay doble asignación.
- No hay ciclos: quien tiene bloqueada una fila de la cola solo espera filas **anteriores** en el orden, y toma los
  bloqueos de departamento y de agente **después** (las anteriores son filas de conversación, el primer paso del orden
  de bloqueos del motor). Las lecturas por clave primaria no toman bloqueos de hueco sobre el índice de la cola.
- Ninguna solicitud puede volverse «anterior y elegible» a espaldas de la lectura: toda entrada a la cola de listas
  sella `queued_at` con la hora actual, posterior al reloj de quien ya esperaba.

### Excepciones autorizadas

Quien tiene `conversations.override-queue-rules` puede enviar `override_reason` (mínimo 10 caracteres) al aceptar,
asignar o transferir, para omitir **solo** las reglas 1 a 3. Nunca la capacidad ni las conversaciones pendientes.

- Las reglas omitidas, el motivo, quién y el resultado quedan en el evento de la conversación
  (`metadata.queue_override = {rules, reason}`) **y** en la auditoría (`conversation.queue_rules_overridden`, con
  `bypassed_rules`, `reason`, `override_result` y `assigned_to`).
- Sin el permiso el parámetro se **ignora** y las reglas aplican. No hay excepción implícita por nombre de rol ni una
  ruta alternativa que las omita: todas las formas de tomar una solicitud pasan por `QueueRules::enforce()`.
- Un motivo demasiado corto responde `422` (`override_reason`). Si ninguna regla bloqueaba, no se registra nada.
- El permiso se comprueba antes de validar: quien ve la solicitud pero no puede tomarla recibe `403`, nunca `422`.
- Al asignar o transferir con una regla por omitir, el permiso de excepción se lee de la fila **bloqueada** de quien
  la hace (como al aceptar), para respetar uno revocado mientras tanto. La fila del actor y la del agente receptor se
  bloquean en el paso de usuarios del orden de bloqueos, en orden ascendente de id
  (`ConversationLifecycle::freshAgentAndActor()`).

### Otras formas de tomar una solicitud

| Vía                                              | Regla 1 (completa)                      | Reglas 2 y 3 (espera y orden)                         |
| ------------------------------------------------ | --------------------------------------- | ----------------------------------------------------- |
| Aceptar                                          | Aplica                                  | Aplican                                               |
| Asignar una **pendiente** (`AssignConversation`) | Aplica siempre (o excepción con motivo) | Solo si quien asigna se la asigna **a sí mismo**      |
| Transferir una **pendiente** directo a un agente | Aplica siempre (o excepción con motivo) | Solo si quien transfiere se la entrega **a sí mismo** |
| Transferir a una cola / otro departamento        | No aplica (no la toma nadie)            | No aplican; reinicia `queued_at`                      |
| Reasignar una conversación ya **en atención**    | No aplican (no sale de una cola)        | No aplican                                            |
| Distribución automática (`AssignAutomatically`)  | Nunca asigna una incompleta             | Nunca asigna una que sigue esperando                  |
| Reabrir hacia su agente anterior                 | Sin cambios                             | Sin cambios                                           |

- **Decisión:** autoasignarse es «tomar» (aplican las tres reglas); un supervisor que reparte trabajo a otra persona
  hace distribución deliberada, no elige a su gusto, así que solo aplica la regla de completitud.
- `distributeQueue` lee solo las **elegibles** (`QueueRules::eligible()`, hasta 100 por pasada) en el orden de la cola
  (`COALESCE(queued_at, created_at)`, luego `id`: una fila lista sin reloj ocupa el lugar de su creación, igual que
  para la regla de orden), vuelve a juzgar cada una bajo su bloqueo y se detiene en la primera que nadie puede tomar
  (sin capacidad).
- La API `/api/v1` **no** tiene operación para aceptar; expone `category`, `readiness` y `queued_at` de solo lectura.

### Cuando una solicitud ya puede tomarse y nadie actúa

`php artisan conversations:offer-waiting-queues` (cada minuto, sin solaparse) vuelve a ofrecer la cola de cada
departamento **automático** activo que tenga **alguna solicitud elegible ahora** (un `EXISTS` indexado por
departamento, `QueueRules::hasEligible()`), y solo entonces reparte (acotado; se detiene en la primera que nadie puede
tomar). No depende de **cuándo** se volvió elegible: tras una caída del programador, o si alguien acortó una espera o
apagó la confirmación, lo que es elegible ahora se ofrece ahora. Ejecutarlo tarde o dos veces es inofensivo: cada
asignación se decide bajo los bloqueos del motor.

Además, **cambiar una regla de la cola** (`intake.confirmation_required` o `queue.min_wait_minutes`) vuelve a ofrecer
las colas automáticas tras el commit, sin esperar al comando: en la plataforma (`UpdatePlatformSettings`, todas las
colas), en un departamento (`UpdateDepartmentServiceRules`, la suya) y en una categoría
(`UpdateConversationCategoryRules`, todas). Es barato cuando nada espera o nadie tiene capacidad.

La bandeja **no sondea**: el cliente cuenta hacia atrás con el reloj del servidor (`serverTime`,
`useServerNow`) y relee la lista una sola vez al llegar `available_at` (mismo patrón que `next_change_at`).

### Categoría de la solicitud

`PUT /conversations/{conversation}/category` con `{category_id | null}` (`SetConversationCategory`): el responsable
(con permiso de responder) o quien puede asignar o confirmar solicitudes dentro de su alcance. Solo categorías activas.
El permiso se comprueba **antes** de validar los datos (visible sin permiso: `403`, nunca `422`).
Registra el evento `category_changed` (con la categoría anterior y la nueva), que también emite la señal en tiempo
real, y vuelve a ofrecer la cola de un departamento automático (las reglas de la solicitud pudieron cambiar). No cambia
estado, versión, preparación ni `queued_at`.

**La categoría no es un atajo para saltarse la cola.** Si la solicitud está **esperando en una cola** y la nueva
categoría la dejaría tomarse antes (ya no exige confirmación, o su `minWaitSeconds()` es menor que el actual), el
cambio es una excepción a las reglas de la cola y exige `conversations.override-queue-rules`, leído de la fila del
actor bloqueada después de la conversación. Sin el permiso responde `403` («Cambiar a esta categoría adelantaría la
solicitud en la cola. Necesitas el permiso para hacer excepciones a las reglas de la cola.») y no cambia nada. Un
cambio que no relaja las reglas (mismas reglas o más estrictas), o sobre una conversación que no está en una cola,
sigue permitido como antes.

Todo cambio de categoría deja una entrada de auditoría `conversation.category_changed` con `previous_category(_id)`,
`new_category(_id)`, `queue_rules_relaxed` y `queue_eligibility_changed` (si la solicitud pasó a ser, o dejó de ser,
elegible con el cambio).

Filtros de la bandeja (estado en la URL, chips y contadores): `category=<id>` y `readiness=ready|needs_info`.

### Contrato con la interfaz

Por conversación (`ConversationResource`, documentado en `resources/js/types/conversations.ts`):

- `readiness {value, label, tracked}` (`tracked`: la regla de confirmación aplica ahora), `queued_at`,
  `category {id, name, color, is_active} | null`.
- `acceptance` (solo filas pendientes sin agente; `null` en las demás):
  `{allowed, available, code, message, available_at, blocking_conversation_id, overridable}`. Primero la razón propia
  de la solicitud (`needs_info`, `min_wait`, `queue_order`); si no hay, la de quien mira (`not_permitted`, `capacity`,
  `pending_attention`). `available` habla solo de la solicitud. `blocking_conversation_id` solo se informa a quien
  puede tomar solicitudes de esa cola o puede ver esa conversación; a cualquier otro (por ejemplo, supervisión que ve
  la fila porque pasó por sus departamentos) se le dice que hay una anterior, no cuál (`null`).
- `can.confirm_ready`, `can.override_queue_rules`, `can.categorize`.
- Props de la página: `serverTime` (parte de las props que se recargan en vivo), `categories` y
  `conversations.server_time`.

Para una página de filas no hay consultas por fila: una búsqueda de «primera de la cola» por departamento presente en
la página y una lectura de la carga de quien mira (`QueueRules::describing()`).

Interfaz: `components/conversations/queueRules.ts` (estado y cuenta regresiva), `AcceptanceNotice.vue` (motivo visible
que describe el botón «Aceptar» deshabilitado, «Ir a la solicitud que puedes tomar», «Confirmar solicitud completa»,
«Hacer una excepción»), `ReadinessBadge.vue`, `QueueOverrideDialog.vue`, `ReadinessDialog.vue` y
`categories/CategoryBadge.vue`. Ganchos para pruebas: `data-readiness`, `data-acceptance-code`, `data-queue-countdown`.

La **vista previa** (`GET /conversations/{id}/preview`) añade `first_message` (solo si no está entre los recientes) y
`conversation {stage, status, received_at, department, category, assignee, readiness, attachments, acceptance}`; sigue
siendo de solo lectura (cero escrituras, comprobado en `ConversationPreviewTest`).

### Cambios de configuración

- Encender «Solicitud completa»: solo las conversaciones **nuevas** nacen `needs_info`.
- Apagarla: las `needs_info` dejan de estar bloqueadas de inmediato; su `readiness` no se reescribe.
- Cambiar la espera mínima o el orden: aplica a la siguiente decisión (aceptar, repartir, describir la bandeja).
- Apagar la confirmación o cambiar la espera mínima (plataforma, departamento o categoría) vuelve a ofrecer las colas
  automáticas tras el commit.
- Cambiar la categoría de una solicitud: sus reglas propias aplican desde la siguiente decisión; si adelantan a una
  solicitud en cola, exige el permiso de excepciones (ver «Categoría de la solicitud»).

### Limitaciones conocidas

- La búsqueda de una anterior elegible relee bajo bloqueo como máximo 50 candidatas **elegibles** con reloj y 50 sin
  reloj; si todas fueran tomadas por otras transacciones durante esa misma lectura, no se sigue buscando.
- Eliminar o restaurar una categoría con reglas propias no reparte de inmediato: lo recoge el comando programado en su
  siguiente pasada (un minuto).
- En una transferencia de una pendiente directo a un agente de **otro** departamento, las reglas se leen del
  departamento donde la solicitud espera, no del de destino.
- El aviso de la bandeja por conversaciones pendientes de atención sigue siendo el de siempre (`acceptBlock`); el
  código `pending_attention` de `acceptance` solo deshabilita «Aceptar».

### Pruebas

`tests/Feature/Conversations/QueueRulesTest.php`, `ConversationReadinessTest.php`, `ConversationCategoryTest.php`,
`ConversationPreviewTest.php` y, con procesos reales, `tests/Feature/Concurrency/ConcurrentProcessesTest.php` (dos
agentes por la primera de la cola, uno por la primera y otro por la segunda, dos que intentan saltarla, dos pestañas
del mismo agente y reintentos, aceptar contra un cambio de preparación y contra la distribución automática).
