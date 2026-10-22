# Registro de decisiones (ADR)

Decisiones de arquitectura importantes, una entrada por decisión con el formato `## ADR-<MÓDULO>-<n> — título`, seguida
del contexto, la decisión y sus consecuencias en pocas líneas. El registro es de solo agregar: una decisión que cambia a
otra se escribe como entrada nueva que la cita. Las entradas están agrupadas por módulo; los números son correlativos
dentro de cada prefijo.

## Índice

**Bandeja y motor de conversaciones** ([bandejas-y-asignacion.md](bandejas-y-asignacion.md)):

- [ADR-INBOX-1 — Bandeja en una sola página con la conversación en `?c=`](#adr-inbox-1--bandeja-en-una-sola-página-con-la-conversación-en-c)
- [ADR-INBOX-2 — Historial por cursor de mensajes con notas y eventos por intervalo](#adr-inbox-2--historial-por-cursor-de-mensajes-con-notas-y-eventos-por-intervalo)
- [ADR-INBOX-3 — Marcadores de lectura personales y contadores por subconsulta](#adr-inbox-3--marcadores-de-lectura-personales-y-contadores-por-subconsulta)
- [ADR-INBOX-4 — Liberación por cambio de acceso a través del motor](#adr-inbox-4--liberación-por-cambio-de-acceso-a-través-del-motor)
- [ADR-INBOX-5 — Adjuntos validados por contenido y servidos solo por ruta autorizada](#adr-inbox-5--adjuntos-validados-por-contenido-y-servidos-solo-por-ruta-autorizada)

**WhatsApp Cloud API** ([mensajeria-whatsapp.md](mensajeria-whatsapp.md)):

- [ADR-META-1 — Una URL de webhook por conexión con clave aleatoria](#adr-meta-1--una-url-de-webhook-por-conexión-con-clave-aleatoria)
- [ADR-META-2 — Webhook: guardar crudo, responder 200 y procesar en cola](#adr-meta-2--webhook-guardar-crudo-responder-200-y-procesar-en-cola)
- [ADR-META-3 — Nunca reenviar automáticamente lo que Meta pudo aceptar](#adr-meta-3--nunca-reenviar-automáticamente-lo-que-meta-pudo-aceptar)
- [ADR-META-4 — El reintento manual reutiliza la misma fila](#adr-meta-4--el-reintento-manual-reutiliza-la-misma-fila)
- [ADR-META-5 — Una conexión pausada recibe, pero no envía](#adr-meta-5--una-conexión-pausada-recibe-pero-no-envía)
- [ADR-META-6 — Plantillas sincronizadas, no creadas desde la app](#adr-meta-6--plantillas-sincronizadas-no-creadas-desde-la-app)

**Tiempo real** ([tiempo-real.md](tiempo-real.md)):

- [ADR-RT-1 — Señales de invalidación en lugar de datos por WebSocket](#adr-rt-1--señales-de-invalidación-en-lugar-de-datos-por-websocket)
- [ADR-RT-2 — Cola de departamento separada de la bandeja de equipo](#adr-rt-2--cola-de-departamento-separada-de-la-bandeja-de-equipo)
- [ADR-RT-3 — Configuración del cliente desde el servidor, no desde VITE_*](#adr-rt-3--configuración-del-cliente-desde-el-servidor-no-desde-vite_)
- [ADR-RT-4 — Guzzle 7 por compatibilidad con Reverb](#adr-rt-4--guzzle-7-por-compatibilidad-con-reverb)

**Chat interno** ([chat-interno.md](chat-interno.md)):

- [ADR-TC-1 — Chat interno en tablas propias, sin acceso por jerarquía](#adr-tc-1--chat-interno-en-tablas-propias-sin-acceso-por-jerarquía)
- [ADR-TC-2 — Quien sale o es retirado pierde también el historial](#adr-tc-2--quien-sale-o-es-retirado-pierde-también-el-historial)
- [ADR-TC-3 — Eliminar un mensaje borra su contenido y deja una marca](#adr-tc-3--eliminar-un-mensaje-borra-su-contenido-y-deja-una-marca)

**Notificaciones, horarios y mensajes automáticos** ([notificaciones-y-horarios.md](notificaciones-y-horarios.md)):

- [ADR-AUT-1 — Notificaciones con recibos de deduplicación y agrupación en base de datos](#adr-aut-1--notificaciones-con-recibos-de-deduplicación-y-agrupación-en-base-de-datos)
- [ADR-AUT-2 — El texto de una notificación se construye al leerla](#adr-aut-2--el-texto-de-una-notificación-se-construye-al-leerla)
- [ADR-AUT-3 — Prioridad de mensajes automáticos y una sola ranura de saludo por ciclo](#adr-aut-3--prioridad-de-mensajes-automáticos-y-una-sola-ranura-de-saludo-por-ciclo)

**Reportes** ([reportes-y-exportaciones.md](reportes-y-exportaciones.md)):

- [ADR-REP-1 — Métricas por conversación recalculadas desde la fuente](#adr-rep-1--métricas-por-conversación-recalculadas-desde-la-fuente)
- [ADR-REP-2 — Cohorte por fecha de recepción y primera respuesta humana aceptada](#adr-rep-2--cohorte-por-fecha-de-recepción-y-primera-respuesta-humana-aceptada)
- [ADR-REP-3 — Alcance de reportes por alcance del rol y filtros validados contra opciones](#adr-rep-3--alcance-de-reportes-por-alcance-del-rol-y-filtros-validados-contra-opciones)

**Migración desde Whaticket** ([migracion-whaticket.md](migracion-whaticket.md)):

- [ADR-MIG-1 — El lote de importación es el `DataTransfer` y su trazabilidad vive en `import_batch_records`](#adr-mig-1--el-lote-de-importación-es-el-datatransfer-y-su-trazabilidad-vive-en-import_batch_records)
- [ADR-MIG-2 — Reversión lógica conservadora](#adr-mig-2--reversión-lógica-conservadora)
- [ADR-MIG-3 — Adaptadores de origen heurísticos, sin formato oficial de Whaticket](#adr-mig-3--adaptadores-de-origen-heurísticos-sin-formato-oficial-de-whaticket)

**Operación e infraestructura** ([infraestructura-y-despliegue.md](infraestructura-y-despliegue.md)):

- [ADR-OPS-1 — Salud pública mínima y detalle solo para administradores](#adr-ops-1--salud-pública-mínima-y-detalle-solo-para-administradores)
- [ADR-OPS-2 — Reintento administrativo solo para trabajos idempotentes](#adr-ops-2--reintento-administrativo-solo-para-trabajos-idempotentes)
- [ADR-OPS-3 — Alertas pequeñas: log dedicado y correo](#adr-ops-3--alertas-pequeñas-log-dedicado-y-correo)
- [ADR-OPS-4 — Correlación con Laravel Context](#adr-ops-4--correlación-con-laravel-context)
- [ADR-OPS-5 — Base de datos como driver por defecto, Redis preparado](#adr-ops-5--base-de-datos-como-driver-por-defecto-redis-preparado)

**Seguridad** ([seguridad-y-auditoria.md](seguridad-y-auditoria.md)):

- [ADR-SEC-1 — Autorizar antes de validar en rutas con registros](#adr-sec-1--autorizar-antes-de-validar-en-rutas-con-registros)
- [ADR-SEC-2 — Archivos privados solo por controladores autorizados](#adr-sec-2--archivos-privados-solo-por-controladores-autorizados)
- [ADR-SEC-3 — Eventos de seguridad de cuenta en la bitácora](#adr-sec-3--eventos-de-seguridad-de-cuenta-en-la-bitácora)

**Rendimiento y carga** ([rendimiento-y-carga.md](rendimiento-y-carga.md)):

- [ADR-LOAD-1 — READ COMMITTED en la recepción de mensajes y orden de bloqueo del departamento automático](#adr-load-1--read-committed-en-la-recepción-de-mensajes-y-orden-de-bloqueo-del-departamento-automático)

**Integración y pruebas finales** ([pruebas.md](pruebas.md)):

- [ADR-QA-1 — Límites de frecuencia por ruta, no por usuario](#adr-qa-1--límites-de-frecuencia-por-ruta-no-por-usuario)
- [ADR-QA-2 — Permisos frescos antes de cada trabajo en cola](#adr-qa-2--permisos-frescos-antes-de-cada-trabajo-en-cola)
- [ADR-QA-3 — Exportaciones dentro de una sola instantánea](#adr-qa-3--exportaciones-dentro-de-una-sola-instantánea)
- [ADR-QA-4 — Reintentos acotados ante _deadlocks_ en operaciones idempotentes](#adr-qa-4--reintentos-acotados-ante-_deadlocks_-en-operaciones-idempotentes)
- [ADR-QA-5 — Las recargas disparadas por señales esperan la operación del usuario](#adr-qa-5--las-recargas-disparadas-por-señales-esperan-la-operación-del-usuario)

**Documentación** ([README.md](README.md)):

- [ADR-DOC-1 — Un documento por área y documentos transversales](#adr-doc-1--un-documento-por-área-y-documentos-transversales)

## ADR-INBOX-1 — Bandeja en una sola página con la conversación en `?c=`

La lista, el chat y los detalles viven en `conversations/Index`; la conversación abierta va en `?c=ID` y la ruta
`conversations/{id}` redirige ahí. Todas las props son perezosas, así que seleccionar una conversación recarga solo el
chat y filtrar recarga solo la lista. Una conversación no visible se trata como inexistente (sin 404 de toda la bandeja,
sin datos). Se eligió sobre rutas anidadas porque conserva filtros y selección en una URL compartible y no duplica
controladores.

## ADR-INBOX-2 — Historial por cursor de mensajes con notas y eventos por intervalo

Los mensajes se paginan por id (40 por página). Notas y eventos viajan con la página cuyo intervalo `[created_at del
mensaje más antiguo, created_at del cursor)` los contiene, de modo que cada elemento aparece una sola vez. Las
actualizaciones en tiempo real piden `after_*` (ids mayores) o vuelven a leer ids concretos; el cliente combina por id y
nunca duplica.

## ADR-INBOX-3 — Marcadores de lectura personales y contadores por subconsulta

`conversation_reads` guarda por usuario el último mensaje leído y solo avanza. Los no leídos y el último mensaje se
calculan con subconsultas indexadas solo para las filas de la página, en vez de una columna `last_message_id`
desnormalizada: el flujo de entrada pertenece a otro módulo y la subconsulta (`MAX(id)` sobre `(conversation_id, id)`)
es barata.

## ADR-INBOX-4 — Liberación por cambio de acceso a través del motor

`ReleaseAssignedConversations` dejó de escribir con `forceFill` sobre una consulta bloqueada en grupo: ahora bloquea
cada conversación (en orden de id), aplica la matriz de estados y la versión, registra el evento con departamento y
`trigger: access_change`, y tras el commit reparte las de departamentos automáticos. Es la única excepción documentada
al orden «conversación primero», porque quien llama ya tiene bloqueado al usuario.

## ADR-INBOX-5 — Adjuntos validados por contenido y servidos solo por ruta autorizada

El tipo de un adjunto lo decide `finfo` sobre los bytes y los límites por tipo de WhatsApp; el archivo se guarda en el
disco privado sin nombre original en la ruta y se sirve con `nosniff`, `Content-Disposition` y CSP `sandbox`. Lo no
seguro se descarga como `application/octet-stream`.

## ADR-META-1 — Una URL de webhook por conexión con clave aleatoria

Cada conexión recibe en `/webhooks/whatsapp/{webhook_key}` (48 caracteres aleatorios), así el token de verificación y
el secreto de la app (cifrados por conexión) se comprueban sin descifrar todas las conexiones, los IDs internos no se
exponen y el cuerpo nunca decide a qué conexión pertenece un mensaje. Las rutas van fuera del grupo `web` (sin sesión
ni CSRF). Ver [mensajeria-whatsapp.md](mensajeria-whatsapp.md).

## ADR-META-2 — Webhook: guardar crudo, responder 200 y procesar en cola

El cuerpo firmado se guarda en `webhook_events` (deduplicado por SHA-256 y conexión) y se procesa en la cola
`webhooks` con intentos acotados; cada paso es idempotente. Permite reprocesar eventos fallidos y nunca hace trabajo
costoso durante la petición.

## ADR-META-3 — Nunca reenviar automáticamente lo que Meta pudo aceptar

Solo se reintenta automáticamente lo que demuestra no haber llegado (DNS, conexión rechazada, tiempo de conexión) o lo que Meta
rechazó explícitamente por límite o falla temporal. Lo ambiguo queda «en proceso», se reconcilia con
`biz_opaque_callback_data` o pasa a `delivery_unknown`; el reintento manual exige confirmar el riesgo de duplicado.

## ADR-META-4 — El reintento manual reutiliza la misma fila

Un mensaje fallido se vuelve a encolar en su misma fila (misma referencia de cliente) y el intento anterior se
conserva en `metadata.delivery.history`, con el evento `message_retried`. Evita mensajes duplicados en el historial y
mantiene la idempotencia del compositor.

## ADR-META-5 — Una conexión pausada recibe, pero no envía

Desactivar una conexión no pierde mensajes de clientes: se siguen guardando; el envío queda bloqueado. Solo una
conexión eliminada deja de recibir.

## ADR-META-6 — Plantillas sincronizadas, no creadas desde la app

Las plantillas se crean y editan en WhatsApp Manager y la app las copia (sincronización paginada, webhooks de estado).
Nunca se marca aprobada una plantilla sin que Meta lo informe; las que la bandeja no puede llenar se muestran como no
enviables con su motivo.

## ADR-RT-1 — Señales de invalidación en lugar de datos por WebSocket

Los eventos de tiempo real solo llevan IDs y metadatos mínimos; el cliente vuelve a pedir los datos al servidor, que
autoriza cada lectura. Evita filtrar contenido por canales y hace que perder una señal sea recuperable (recarga al
reconectar, respaldo periódico). Ver [tiempo-real.md](tiempo-real.md).

## ADR-RT-2 — Cola de departamento separada de la bandeja de equipo

Además de `inbox.departments.{id}` (supervisores y alcance global) existe `inbox.queues.{id}` para quien puede aceptar
pendientes; la cola solo recibe señales de conversaciones en cola. Un agente ordinario no conoce ni el ID de la
actividad de conversaciones de compañeros. La prop `realtime.inbox_channels` le dice a cada usuario qué escuchar.

## ADR-RT-3 — Configuración del cliente desde el servidor, no desde VITE_*

Host, puerto, esquema y clave pública de Reverb se entregan en la prop compartida `realtime`; el mismo build funciona
en todos los entornos y el tiempo real se puede apagar sin recompilar.

## ADR-RT-4 — Guzzle 7 por compatibilidad con Reverb

`laravel/reverb` 1.x exige `guzzlehttp/psr7 ^2.6`; se bajó `guzzlehttp/guzzle` 8.2 → 7.16 (y psr7 3.1 → 2.13,
promises 3.0 → 2.6), versiones estables y soportadas por Laravel 13. Se evitó usar la rama inestable `dev-main`.
Revisar al publicarse una versión estable de Reverb compatible con psr7 3.

## ADR-TC-1 — Chat interno en tablas propias, sin acceso por jerarquía

El chat entre empleados usa `team_threads`, `team_participants`, `team_messages` y `team_attachments`, sin compartir
entidades con conversaciones de clientes. Solo los participantes actuales lo ven (404 para el resto); el
Superadministrador no tiene una habilidad "ver todo" ni hay consultas ocultas. Ver [chat-interno.md](chat-interno.md).

## ADR-TC-2 — Quien sale o es retirado pierde también el historial

Al salir o ser retirado de un grupo se pierde de inmediato el acceso al grupo, su historial y sus archivos (no se
conserva lectura hasta `left_at`): es la regla más simple de verificar y la más segura ante retiros por cambio de
puesto. Reingresar devuelve el historial completo; solo cuenta como no leído lo posterior al reingreso.

## ADR-TC-3 — Eliminar un mensaje borra su contenido y deja una marca

El autor elimina su mensaje: se borran el texto y los archivos (del disco) para todos y queda la fila con
`deleted_at` («Mensaje eliminado») para conservar orden y respuestas. Nadie puede recuperar el contenido después,
lo que evita retención oculta de lo que la persona decidió borrar.

## ADR-AUT-1 — Notificaciones con recibos de deduplicación y agrupación en base de datos

Las notificaciones se guardan en `notifications` (Laravel) con un recibo único por (usuario, evento de origen) en
`notification_receipts` y una clave de agrupación con unicidad sobre las no leídas (columna generada). Los
reintentos de webhooks y jobs no duplican, y las ráfagas (cola, mensajes de un cliente, chat interno) se pliegan en
una sola notificación con contador. Ver [notificaciones-y-horarios.md](notificaciones-y-horarios.md).

## ADR-AUT-2 — El texto de una notificación se construye al leerla

El payload solo guarda ids; el texto se arma al leer con una nueva verificación de acceso. Quien perdió acceso ve
«Ya no tienes acceso» sin detalles, y el contenido de los clientes nunca queda copiado en notificaciones.

## ADR-AUT-3 — Prioridad de mensajes automáticos y una sola ranura de saludo por ciclo

Departamento antes que conexión; fuera de horario (incluidos días no laborables) solo se envía el mensaje fuera de
horario, nunca la bienvenida; la despedida es solo de la conexión. Bienvenida y fuera de horario comparten la ranura
«saludo» con referencia determinista por (conversación, ranura, ciclo), única en `messages.client_reference`. Solo
dentro de la ventana de 24 h, sin plantillas, y no cuentan como primera respuesta.

## ADR-REP-1 — Métricas por conversación recalculadas desde la fuente

Los reportes leen `conversation_metrics` (una fila por conversación) en lugar de recorrer mensajes y eventos en cada
consulta. La fila se **recalcula completa** desde la conversación, sus eventos y sus mensajes (nunca con sumas
incrementales), mediante un job único por conversación disparado por `ConversationChanged`, `MessageRecorded` y
`MessageStatusChanged`, más `analytics:rebuild` (manual y reconciliación diaria). Así el resultado es idempotente y
coherente con la fuente aunque los eventos lleguen duplicados o desordenados. No se creó tabla de acumulados diarios:
la agregación es sobre conversaciones con índices por fecha y caché corta. Ver [reportes-y-exportaciones.md](reportes-y-exportaciones.md).

## ADR-REP-2 — Cohorte por fecha de recepción y primera respuesta humana aceptada

Las cifras de un periodo describen las conversaciones **recibidas** en él. La primera respuesta humana es el primer
mensaje saliente escrito por una persona, no automático, aceptado por el proveedor (`accepted/sent/delivered/read`),
medido desde que se escribió. `conversations.first_response_at` (se marca al encolar) ya no se usa en reportes ni en
el panel. Solo tiempo calendario.

## ADR-REP-3 — Alcance de reportes por alcance del rol y filtros validados contra opciones

Global: todo; departamentos: sus departamentos actuales más lo que atiende; asignado: solo lo propio. Un filtro de
departamento/agente/conexión fuera del alcance se descarta en el servidor (ni se aplica ni se devuelve), para evitar
IDOR y enumeración por conteos. `reports.view` pasa a estar disponible en el catálogo de permisos.

## ADR-MIG-1 — El lote de importación es el `DataTransfer` y su trazabilidad vive en `import_batch_records`

No se creó una entidad de "lote" nueva: cada importación (un `DataTransfer` de tipo `import`) es el lote. La tabla
aditiva `import_batch_records` enlaza cada contacto creado o completado con su lote, con los valores reemplazados y un
`audit_watermark` (último ID de auditoría al terminar con el registro). Permite revisar y revertir un lote sin tocar
el esquema de contactos. Ver [migracion-whaticket.md](migracion-whaticket.md).

## ADR-MIG-2 — Reversión lógica conservadora

Revertir un lote archiva (eliminación lógica existente de contactos) solo los contactos creados por el lote sin
ninguna actividad posterior, y restaura campos completados solo si conservan el valor escrito. Cualquier duda conserva
el registro y lo reporta con el motivo. Las notas nunca se eliminan (son historial). Un contacto modificado por un
lote posterior no se revierte desde el lote anterior aunque el posterior se haya revertido.

## ADR-MIG-3 — Adaptadores de origen heurísticos, sin formato oficial de Whaticket

Los orígenes (`generic`, `whaticket-contacts`) solo sugieren el mapeo, puntúan encabezados y normalizan valores; el
usuario siempre puede cambiar el mapeo y cada fila pasa por las reglas de contactos. El adaptador de Whaticket está
marcado "pendiente de validación" hasta revisar una exportación real. No se importan conversaciones ni mensajes
históricos, no se ejecuta SQL ni se restauran bases de datos.

## ADR-OPS-1 — Salud pública mínima y detalle solo para administradores

`/health/ready` responde únicamente `{"status":"ok"|"fail"}` (200/503) para monitores externos; el detalle (colas,
fallidos, programador, webhooks, Reverb) está en Administración > Sistema y `GET /admin/system/health`, protegidos por
`system.view` (alcance global). `/up` queda como prueba de vida. Solo las condiciones que detienen el servicio hacen
fallar la preparación; las demás son advertencias. Ver [infraestructura-y-despliegue.md](infraestructura-y-despliegue.md).

## ADR-OPS-2 — Reintento administrativo solo para trabajos idempotentes

Los trabajos fallidos se reintentan desde la interfaz únicamente si su clase está en
`operations.failed_jobs.retryable` (webhooks almacenados, métricas, sincronización de plantillas, señales de tiempo
real). Los envíos de WhatsApp quedan fuera (pueden haber llegado a Meta; se reintentan desde la conversación) y las
notificaciones también (se anunciarían tarde). Reintentar y descartar se auditan; se muestra solo la primera línea del
error con secretos y datos personales ocultos, nunca el payload.

## ADR-OPS-3 — Alertas pequeñas: log dedicado y correo

Sin un sistema de monitoreo propio: `ops:check-alerts` escribe en el canal `ops` y envía correo a
`OPS_ALERT_EMAILS`/usuarios con `system.view`, con limitación por alerta. Una caída total requiere un monitor externo
sobre `/health/ready`. Integrarlas al centro de notificaciones queda pendiente con el módulo de notificaciones.

## ADR-OPS-4 — Correlación con Laravel Context

`App\Support\LogContext` agrega solo identificadores (`request_id`, `webhook_event_id`, `message_id`,
`conversation_id`...) al contexto de logs; cada trabajo de cola recibe automáticamente su clase, cola y los IDs de sus
propiedades públicas (leídos del payload sin deserializar), y el contexto viaja a los trabajos que despacha.

## ADR-OPS-5 — Base de datos como driver por defecto, Redis preparado

Colas y caché siguen en `database` (sin servicio adicional); Redis queda configurado (`after_commit=true`,
`retry_after=900`) y documentado con la extensión phpredis, sin añadir `predis`. Los workers se agrupan por prioridad
(webhooks+mensajes, multimedia, notificaciones+general, exportaciones) para que una ráfaga de un tipo no retrase la
ruta crítica.

## ADR-SEC-1 — Autorizar antes de validar en rutas con registros

Un registro fuera del alcance responde 404 antes de validar la entrada, para que los errores de validación no permitan
distinguir ids existentes. Los form requests de conversación usan `App\Concerns\AuthorizesVisibleConversation`; el motor
vuelve a autorizar bajo bloqueo. La prueba de barrido `tests/Feature/Security/RouteAuthorizationSweepTest.php` recorre
todas las rutas y falla si alguna nueva responde distinto de 401/403/404 a quien no tiene acceso. Ver
[seguridad-y-auditoria.md](seguridad-y-auditoria.md).

## ADR-SEC-2 — Archivos privados solo por controladores autorizados

El disco `local` no publica la ruta firmada `storage/{path}` (`'serve' => false`): ningún archivo privado se entrega con
una URL portadora; cada descarga pasa por un controlador que autoriza al usuario actual.

## ADR-SEC-3 — Eventos de seguridad de cuenta en la bitácora

Se auditan el cambio de contraseña propio, el restablecimiento por enlace, la activación y desactivación de la
verificación en dos pasos, la regeneración de códigos de recuperación y los bloqueos por intentos fallidos (uno por correo
e IP y minuto). No se auditan los inicios de sesión correctos (se conserva `last_login_at`) para no inundar la bitácora.

## ADR-LOAD-1 — READ COMMITTED en la recepción de mensajes y orden de bloqueo del departamento automático

La batería de carga ([rendimiento y carga](rendimiento-y-carga.md)) mostró que, con REPEATABLE READ, las búsquedas
`FOR UPDATE` de la conversación abierta de contactos nuevos tomaban el mismo bloqueo de hueco al final del índice y dos
clientes distintos se bloqueaban mutuamente al insertar (446 _deadlocks_ y 36 jobs agotados en una ráfaga de 3 000
webhooks). `ReceiveInboundMessage` ejecuta su transacción en READ COMMITTED cuando es la externa, con reintentos
acotados y pausa aleatoria; la unicidad de la conversación abierta la siguen garantizando la clave única y el
reintento existente. Además, la fila de un departamento automático se bloquea antes de insertar la conversación (antes
se subía un bloqueo compartido de la clave foránea a exclusivo y producía ciclos), y los departamentos compartidos o
manuales ya no se bloquean. Resultado medido: 62 _deadlocks_ y 0 reprocesos con la misma ráfaga.

## ADR-QA-1 — Límites de frecuencia por ruta, no por usuario

`throttle:N,M` de Laravel usa un solo contador por usuario (o IP) para todas las rutas: el sondeo de la campana y las
autorizaciones de canales agotaban el límite de 10/min de importaciones y exportaciones de quien no las había usado.
`App\Http\Middleware\ThrottleRequestsPerRoute` reemplaza el alias `throttle` y agrega método y nombre de ruta a la
firma; cada ruta conserva su propio límite y los limitadores con nombre no cambian. Prueba:
`tests/Feature/Security/RouteThrottleTest.php`. Ver [seguridad-y-auditoria.md](seguridad-y-auditoria.md).

## ADR-QA-2 — Permisos frescos antes de cada trabajo en cola

El paquete de permisos guarda en memoria el mapa de roles y permisos durante toda la vida de un worker, así que un
permiso revocado seguía valiendo en distribución automática, destinatarios de notificaciones y audiencias de tiempo
real. `App\Listeners\Security\RefreshPermissionsBeforeQueuedJob` vacía ese mapa antes de cada trabajo (la caché
compartida la limpia quien revoca). Probado con un `queue:work` real y caché en base de datos; con Redis queda pendiente
de validación.

## ADR-QA-3 — Exportaciones dentro de una sola instantánea

`ReportExporter::render()` lee todas las páginas dentro de una transacción REPEATABLE READ: un mensaje nuevo ya no
mueve una conversación entre páginas (antes se duplicaban u omitían filas). No toma bloqueos, así que no detiene
escrituras; a cambio mantiene abierta la lectura durante toda la exportación (como máximo el tiempo límite del job).

## ADR-QA-4 — Reintentos acotados ante _deadlocks_ en operaciones idempotentes

Las transacciones que compiten por bloqueos de hueco y son seguras de repetir se reintentan hasta 3 veces, solo ante
errores de concurrencia: registro del contacto entrante (`RegisterInboundContact`, como ya hacía
`ReceiveInboundMessage`) y marcar notificaciones leídas (`NotificationCenter::markConversationRead` y `markAllRead`,
antes un 500 con SQLSTATE 40001). Además, `ReleaseAssignedConversations` busca las conversaciones del agente con una
lectura bloqueante para ver las aceptaciones confirmadas mientras esperaba (antes quedaban conversaciones huérfanas tras
un cambio de rol o una baja). Pruebas en `tests/Feature/Concurrency` y `NotificationCenterTest`.

## ADR-QA-5 — Las recargas disparadas por señales esperan la operación del usuario

Una señal llega mientras la propia petición del usuario todavía redirige; si en ese momento se pide otra recarga, esa
petición consume el mensaje de sesión de un solo uso que trae el aviso de éxito (aviso perdido o doble).
`afterUserVisits()` (`resources/js/lib/realtime/visits.ts`) retrasa las recargas por señal hasta que termina la visita
en curso, como máximo 10 s, y se usa en `useLiveReload`, la recarga por `.access.changed`, la disponibilidad, la campana
y el chat abierto. Prueba: `tests/e2e/realtime.mjs` (exactamente un aviso al aceptar).

## ADR-DOC-1 — Un documento por área y documentos transversales

Cada módulo mantiene un solo documento en `docs/` y enlaza a los demás en lugar de repetirlos. Los temas transversales
viven en [arquitectura.md](arquitectura.md), [pruebas.md](pruebas.md) y [limitaciones.md](limitaciones.md), y
[README.md](README.md) asigna cada uno de los 24 temas obligatorios a su sección. Este registro es de solo agregar:
una entrada nueva usa el prefijo de su módulo y el siguiente número, y una decisión que cambia otra la cita en lugar de
editarla.
