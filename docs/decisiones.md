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
- [ADR-INBOX-6 — Búsqueda con FULLTEXT de MariaDB, sin motor externo](#adr-inbox-6--búsqueda-con-fulltext-de-mariadb-sin-motor-externo)
- [ADR-INBOX-7 — Paginación _keyset_ de la bandeja y cursor compuesto del historial](#adr-inbox-7--paginación-_keyset_-de-la-bandeja-y-cursor-compuesto-del-historial)
- [ADR-INBOX-8 — Capacidad: agente > departamento > plataforma, con redistribución acotada tras el commit](#adr-inbox-8--capacidad-agente--departamento--plataforma-con-redistribución-acotada-tras-el-commit)
- [ADR-INBOX-9 — Destinos de transferencia por elegibilidad, no por visibilidad](#adr-inbox-9--destinos-de-transferencia-por-elegibilidad-no-por-visibilidad)
- [ADR-INBOX-10 — Orden único de bloqueos: conversación → departamento → agente](#adr-inbox-10--orden-único-de-bloqueos-conversación--departamento--agente)

**WhatsApp Cloud API** ([mensajeria-whatsapp.md](mensajeria-whatsapp.md)):

- [ADR-META-1 — Una URL de webhook por conexión con clave aleatoria](#adr-meta-1--una-url-de-webhook-por-conexión-con-clave-aleatoria)
- [ADR-META-2 — Webhook: guardar crudo, responder 200 y procesar en cola](#adr-meta-2--webhook-guardar-crudo-responder-200-y-procesar-en-cola)
- [ADR-META-3 — Nunca reenviar automáticamente lo que Meta pudo aceptar](#adr-meta-3--nunca-reenviar-automáticamente-lo-que-meta-pudo-aceptar)
- [ADR-META-4 — El reintento manual reutiliza la misma fila](#adr-meta-4--el-reintento-manual-reutiliza-la-misma-fila)
- [ADR-META-5 — Una conexión pausada recibe, pero no envía](#adr-meta-5--una-conexión-pausada-recibe-pero-no-envía)
- [ADR-META-6 — Plantillas sincronizadas, no creadas desde la app](#adr-meta-6--plantillas-sincronizadas-no-creadas-desde-la-app)
- [ADR-META-7 — Una regla de bloqueo de envío por conexión en todos los caminos](#adr-meta-7--una-regla-de-bloqueo-de-envío-por-conexión-en-todos-los-caminos)
- [ADR-META-8 — El job revalida que el autor aún pueda responder](#adr-meta-8--el-job-revalida-que-el-autor-aún-pueda-responder)
- [ADR-META-9 — Piloto controlado por lista de destinatarios](#adr-meta-9--piloto-controlado-por-lista-de-destinatarios)

**Tiempo real** ([tiempo-real.md](tiempo-real.md)):

- [ADR-RT-1 — Señales de invalidación en lugar de datos por WebSocket](#adr-rt-1--señales-de-invalidación-en-lugar-de-datos-por-websocket)
- [ADR-RT-2 — Cola de departamento separada de la bandeja de equipo](#adr-rt-2--cola-de-departamento-separada-de-la-bandeja-de-equipo)
- [ADR-RT-3 — Configuración del cliente desde el servidor, no desde VITE_*](#adr-rt-3--configuración-del-cliente-desde-el-servidor-no-desde-vite_)
- [ADR-RT-4 — Guzzle 7 por compatibilidad con Reverb](#adr-rt-4--guzzle-7-por-compatibilidad-con-reverb)
- [ADR-RT-5 — Cola `realtime` dedicada y un solo evento por mensaje](#adr-rt-5--cola-realtime-dedicada-y-un-solo-evento-por-mensaje)
- [ADR-RT-6 — La señal de notificación se publica en el acto, con corte tras el primer fallo](#adr-rt-6--la-señal-de-notificación-se-publica-en-el-acto-con-corte-tras-el-primer-fallo)

**Chat interno** ([chat-interno.md](chat-interno.md)):

- [ADR-TC-1 — Chat interno en tablas propias, sin acceso por jerarquía](#adr-tc-1--chat-interno-en-tablas-propias-sin-acceso-por-jerarquía)
- [ADR-TC-2 — Quien sale o es retirado pierde también el historial](#adr-tc-2--quien-sale-o-es-retirado-pierde-también-el-historial)
- [ADR-TC-3 — Eliminar un mensaje borra su contenido y deja una marca](#adr-tc-3--eliminar-un-mensaje-borra-su-contenido-y-deja-una-marca)
- [ADR-TC-4 — «Escribiendo…» por señal efímera del servidor en lugar de whisper](#adr-tc-4--escribiendo-por-señal-efímera-del-servidor-en-lugar-de-whisper)
- [ADR-TC-5 — Presencia por departamento con carga útil `{id}`](#adr-tc-5--presencia-por-departamento-con-carga-útil-id)

**Notificaciones, horarios y mensajes automáticos** ([notificaciones-y-horarios.md](notificaciones-y-horarios.md)):

- [ADR-AUT-1 — Notificaciones con recibos de deduplicación y agrupación en base de datos](#adr-aut-1--notificaciones-con-recibos-de-deduplicación-y-agrupación-en-base-de-datos)
- [ADR-AUT-2 — El texto de una notificación se construye al leerla](#adr-aut-2--el-texto-de-una-notificación-se-construye-al-leerla)
- [ADR-AUT-3 — Prioridad de mensajes automáticos y una sola ranura de saludo por ciclo](#adr-aut-3--prioridad-de-mensajes-automáticos-y-una-sola-ranura-de-saludo-por-ciclo)
- [ADR-AUT-4 — Web Push sin carga útil y sin dependencias](#adr-aut-4--web-push-sin-carga-útil-y-sin-dependencias)
- [ADR-AUT-5 — Sin aviso de pestaña si el navegador ya tiene push](#adr-aut-5--sin-aviso-de-pestaña-si-el-navegador-ya-tiene-push)

**Reportes** ([reportes-y-exportaciones.md](reportes-y-exportaciones.md)):

- [ADR-REP-1 — Métricas por conversación recalculadas desde la fuente](#adr-rep-1--métricas-por-conversación-recalculadas-desde-la-fuente)
- [ADR-REP-2 — Cohorte por fecha de recepción y primera respuesta humana aceptada](#adr-rep-2--cohorte-por-fecha-de-recepción-y-primera-respuesta-humana-aceptada)
- [ADR-REP-3 — Alcance de reportes por alcance del rol y filtros validados contra opciones](#adr-rep-3--alcance-de-reportes-por-alcance-del-rol-y-filtros-validados-contra-opciones)
- [ADR-REP-4 — La asignación automática no es atención; tiempo hábil congelado](#adr-rep-4--la-asignación-automática-no-es-atención-tiempo-hábil-congelado)

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
- [ADR-OPS-6 — Un grupo de workers por cola y sin conexión fija](#adr-ops-6--un-grupo-de-workers-por-cola-y-sin-conexión-fija)
- [ADR-OPS-7 — Scripts de root fuera del árbol de la aplicación y prueba de humo obligatoria](#adr-ops-7--scripts-de-root-fuera-del-árbol-de-la-aplicación-y-prueba-de-humo-obligatoria)

**Seguridad** ([seguridad-y-auditoria.md](seguridad-y-auditoria.md)):

- [ADR-SEC-1 — Autorizar antes de validar en rutas con registros](#adr-sec-1--autorizar-antes-de-validar-en-rutas-con-registros)
- [ADR-SEC-2 — Archivos privados solo por controladores autorizados](#adr-sec-2--archivos-privados-solo-por-controladores-autorizados)
- [ADR-SEC-3 — Eventos de seguridad de cuenta en la bitácora](#adr-sec-3--eventos-de-seguridad-de-cuenta-en-la-bitácora)
- [ADR-SEC-4 — 404 para lo que está fuera de alcance, 403 para lo visible no permitido](#adr-sec-4--404-para-lo-que-está-fuera-de-alcance-403-para-lo-visible-no-permitido)
- [ADR-SEC-5 — Exportaciones por POST con formulario nativo](#adr-sec-5--exportaciones-por-post-con-formulario-nativo)
- [ADR-SEC-6 — Cambiar o restablecer una contraseña cierra las sesiones](#adr-sec-6--cambiar-o-restablecer-una-contraseña-cierra-las-sesiones)
- [ADR-SEC-7 — Los permisos sensibles no se conceden de forma automática](#adr-sec-7--los-permisos-sensibles-no-se-conceden-de-forma-automática)
- [ADR-SEC-8 — Eliminación lógica heterogénea con la auditoría como fuente](#adr-sec-8--eliminación-lógica-heterogénea-con-la-auditoría-como-fuente)
- [ADR-SEC-9 — Dependencias: estabilidad antes que actualización, y nada se quita sin aprobación](#adr-sec-9--dependencias-estabilidad-antes-que-actualización-y-nada-se-quita-sin-aprobación)

**Rendimiento y carga** ([rendimiento-y-carga.md](rendimiento-y-carga.md)):

- [ADR-LOAD-1 — READ COMMITTED en la recepción de mensajes y orden de bloqueo del departamento automático](#adr-load-1--read-committed-en-la-recepción-de-mensajes-y-orden-de-bloqueo-del-departamento-automático)
- [ADR-LOAD-2 — Transacción READ COMMITTED compartida y upsert de notificaciones agregadas](#adr-load-2--transacción-read-committed-compartida-y-upsert-de-notificaciones-agregadas)

**Integración y pruebas finales** ([pruebas.md](pruebas.md)):

- [ADR-QA-1 — Límites de frecuencia por ruta, no por usuario](#adr-qa-1--límites-de-frecuencia-por-ruta-no-por-usuario)
- [ADR-QA-2 — Permisos frescos antes de cada trabajo en cola](#adr-qa-2--permisos-frescos-antes-de-cada-trabajo-en-cola)
- [ADR-QA-3 — Exportaciones dentro de una sola instantánea](#adr-qa-3--exportaciones-dentro-de-una-sola-instantánea)
- [ADR-QA-4 — Reintentos acotados ante _deadlocks_ en operaciones idempotentes](#adr-qa-4--reintentos-acotados-ante-_deadlocks_-en-operaciones-idempotentes)
- [ADR-QA-5 — Las recargas disparadas por señales esperan la operación del usuario](#adr-qa-5--las-recargas-disparadas-por-señales-esperan-la-operación-del-usuario)
- [ADR-QA-6 — Escenario E2E multisesión y utilidades compartidas](#adr-qa-6--escenario-e2e-multisesión-y-utilidades-compartidas)

**Chat web y API de integración (fase 4)** ([canal-chat-web.md](canal-chat-web.md), [api-v1.md](api-v1.md)):

- [ADR-WEB-1 — Widget en iframe servido por Chat Manager](#adr-web-1--widget-en-iframe-servido-por-chat-manager)
- [ADR-WEB-2 — Sin Sanctum: tokens propios con hash y middlewares propios](#adr-web-2--sin-sanctum-tokens-propios-con-hash-y-middlewares-propios)
- [ADR-WEB-3 — Mismo Reverb para los visitantes, canal autorizado fuera de `routes/channels`](#adr-web-3--mismo-reverb-para-los-visitantes-canal-autorizado-fuera-de-routeschannels)
- [ADR-WEB-4 — Contacto y conversación con el primer mensaje, por el mismo pipeline](#adr-web-4--contacto-y-conversación-con-el-primer-mensaje-por-el-mismo-pipeline)
- [ADR-API-1 — El token de integración es el principal, con su propia allowlist](#adr-api-1--el-token-de-integración-es-el-principal-con-su-propia-allowlist)
- [ADR-API-2 — Idempotencia sin tabla y errores con código estable](#adr-api-2--idempotencia-sin-tabla-y-errores-con-código-estable)
- [ADR-INBOX-11 — Bandeja general como primera vista, con contadores en una agregación](#adr-inbox-11--bandeja-general-como-primera-vista-con-contadores-en-una-agregación)
- [ADR-UX-1 — Terminología de estados solo de presentación](#adr-ux-1--terminología-de-estados-solo-de-presentación)

**Documentación** ([README.md](README.md)):

- [ADR-DOC-1 — Un documento por área y documentos transversales](#adr-doc-1--un-documento-por-área-y-documentos-transversales)
- [ADR-DOC-2 — Registro de la fase de estabilización y guía de pruebas manuales](#adr-doc-2--registro-de-la-fase-de-estabilización-y-guía-de-pruebas-manuales)

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

## ADR-INBOX-6 — Búsqueda con FULLTEXT de MariaDB, sin motor externo

La búsqueda con `LIKE '%término%'` no podía usar índices y la de la bandeja buscaba dígitos sueltos en el teléfono.
Se usa el índice FULLTEXT de InnoDB (modo booleano, `+palabra*` por palabra de 3 o más caracteres, sin operadores del
usuario ni _stopwords_) sobre `contacts(name, profile_name)` y `messages(body)`, prefijos E.164 y de `wa_id` para
teléfonos y prefijo para correos, en una sola acción (`ContactSearch`) para Contactos y bandeja. No se añadió
Meilisearch, Elasticsearch ni Scout: más infraestructura y dependencias para un volumen que MariaDB atiende en
milisegundos (medido con 8 000 conversaciones y 40 000 mensajes). Consecuencias: no hay búsqueda por subcadena dentro
de una palabra ni por sufijo de teléfono; el primer FULLTEXT reconstruye la tabla (ventana de bajo tráfico); las
pruebas que buscan deben confirmar sus datos (`commitForFullTextSearch()`). Ver
[bandejas-y-asignacion.md](bandejas-y-asignacion.md#búsqueda-y-paginación).

## ADR-INBOX-7 — Paginación _keyset_ de la bandeja y cursor compuesto del historial

La ventana creciente (`limit`, tope 250) no avanzaba y volvía a serializar todas las filas. La bandeja pagina con un
cursor opaco sobre su propio orden (prioridad de estado, última actividad, id) y un endpoint JSON
(`conversations.page`) que vuelve a autorizar en cada página. El historial acota notas y eventos a 300 por página y
devuelve `until` (segundo + id por tipo) para no perder ni repetir elementos aunque compartan el mismo segundo.
Completa [ADR-INBOX-2](#adr-inbox-2--historial-por-cursor-de-mensajes-con-notas-y-eventos-por-intervalo).

## ADR-INBOX-8 — Capacidad: agente > departamento > plataforma, con redistribución acotada tras el commit

El límite de conversaciones en atención se resuelve con la precedencia límite propio del agente > límite por agente
del departamento > límite de la plataforma (`null` = heredar). La carga cuenta todas las conversaciones del agente.
Aceptar y la distribución automática nunca lo superan; superarlo a mano exige `conversations.assign`, confirmación y
queda en el evento. Al liberarse capacidad la cola se redistribuye **después del commit**, como máximo 100
conversaciones por departamento y una transacción corta por conversación, sin bloqueos globales: un fallo nunca deshace
la operación que liberó el hueco.

## ADR-INBOX-9 — Destinos de transferencia por elegibilidad, no por visibilidad

El diálogo ofrecía solo a quien el actor ya podía ver, así que un agente no podía transferir a un compañero de otro
departamento. Los destinos salen de `TransferDestinations` con la regla `canAttend` del motor (cuenta activa, rol que
permite ver y responder, miembro del departamento o alcance global) y solo exponen id, nombre, disponibilidad y
`at_capacity`. Elegir a alguien no requiere ver sus conversaciones; el motor revalida todo bajo bloqueo.

## ADR-INBOX-10 — Orden único de bloqueos: conversación → departamento → agente

La clave foránea del evento al departamento toma un bloqueo compartido sobre su fila; tomado después del agente
invertía el orden de la asignación automática y formaba ciclos. Todas las operaciones bloquean conversación,
departamento(s) en orden de id (exclusivo si pueden repartir la cola), agente (`freshUser`: usuario, rol y
`department_user`, nunca `departments`) y, por último, leen su carga. Los ciclos residuales documentados los resuelve
MariaDB revirtiendo una transacción completa. Completa
[ADR-LOAD-1](#adr-load-1--read-committed-en-la-recepción-de-mensajes-y-orden-de-bloqueo-del-departamento-automático).

## ADR-META-7 — Una regla de bloqueo de envío por conexión en todos los caminos

`Connection::sendingBlockReason()` (eliminada, desactivada, sin credenciales guardadas o con credenciales rechazadas
por Meta) decide si se puede enviar. La aplican el compositor (vía `ConversationResource`), `QueueOutboundMessage`,
`QueueTemplateMessage` y `RetryOutboundMessage` (409 con el mismo motivo, sin guardar nada) y el job en el momento del
envío. Las credenciales sin verificar no bloquean: solo advierten. Completa
[ADR-META-5](#adr-meta-5--una-conexión-pausada-recibe-pero-no-envía): la conexión pausada sigue recibiendo.

## ADR-META-8 — El job revalida que el autor aún pueda responder

La autorización al encolar no basta si la conversación se transfiere, se cierra o la cuenta se desactiva antes de que
corra el worker. `DeliverOutboundMessage` evalúa bajo bloqueo `ConversationPolicy::reply` con el autor efectivo (el
último que reintentó o quien escribió) y bloquea con `sender_not_allowed`. Los mensajes automáticos, sin autor humano,
están exentos.

## ADR-META-9 — Piloto controlado por lista de destinatarios

Activar `WHATSAPP_TRANSPORT=meta` es global. Para un piloto, `WHATSAPP_PILOT_RECIPIENTS` limita los envíos reales a los
números listados (comparación por dígitos, como `wa_id`); a cualquier otro el mensaje falla con
`transport_unavailable` antes de subir archivos o llamar a Meta. Vacío = sin restricción. Se eligió una lista en
configuración en lugar de un piloto por conexión para no tocar el esquema.

## ADR-RT-5 — Cola `realtime` dedicada y un solo evento por mensaje

Las señales compartían cola y workers con las notificaciones in-app y llegaban con 15–35 s de retraso en ráfagas. Ahora
tienen su cola `realtime` con 2 workers propios; un mensaje produce un único evento (`MessageRecorded`) y un único
trabajo de tiempo real, y los listeners que no harían nada se descartan en `shouldQueue()`. Los objetivos de latencia
(p95 ≤ 2 s para mensajes y bandejas, ≤ 5 s para la campana) son metas de diseño pendientes de medir.

## ADR-RT-6 — La señal de notificación se publica en el acto, con corte tras el primer fallo

`NotificationSignal` dejó de ser una difusión en cola: `BroadcastNotificationSignal` la publica con `RealtimeSignals`
en el mismo worker que guardó la notificación (_best effort_, `warning` en el log). Con `#[Scoped]`, tras el primer
fallo de un trabajo se omiten las demás señales de ese trabajo: un Reverb colgado cuesta como mucho un _timeout_ por
trabajo y nunca hace fallar ni reintentar el reparto. La campana se recupera con su actualización periódica.

## ADR-TC-4 — «Escribiendo…» por señal efímera del servidor en lugar de whisper

Un _whisper_ (evento de cliente) en un canal privado no lleva una identidad verificada, habilitarlo
(`accept_client_events_from`) lo abriría en todos los canales privados y un expulsado aún suscrito podría seguir
emitiendo. Se usa `POST team-chat/threads/{thread}/typing`: el servidor autoriza `post` en cada llamada y emite
`.typing {user_id}` con el autor que él fija; no se guarda nada. Es la única señal síncrona durante la petición y está
protegida con `rescue`. Coste: una petición HTTP cada 3 s como máximo mientras se escribe.

## ADR-TC-5 — Presencia por departamento con carga útil `{id}`

Un único `presence-team` mostraba a todos los usuarios del chat interno, saltándose el alcance por departamento. Hay
`presence-team` solo para quien llega a todos y `presence-team.departments.{id}` para los miembros de cada
departamento, con carga `{id}`. Consecuencia aceptada: quien llega a todos no ve en línea a usuarios departamentales
de otros departamentos.

## ADR-AUT-4 — Web Push sin carga útil y sin dependencias

Se envía un `POST` vacío firmado con VAPID (ES256 con `ext-openssl`), sin cifrado `aes128gcm` porque no viaja
contenido; el _service worker_ pide el resumen al servidor con la sesión del usuario y muestra un texto genérico. No
se añadió `minishlink/web-push` ni un servicio de pago. Límite de un push por usuario y minuto, endpoints solo de
servicios push conocidos (anti-SSRF) y limpieza de suscripciones al desactivar usuarios. Ver
[notificaciones-y-horarios.md §1.8](notificaciones-y-horarios.md#18-web-push-avisos-con-la-plataforma-cerrada).

## ADR-AUT-5 — Sin aviso de pestaña si el navegador ya tiene push

Con la pestaña abierta pero oculta aparecían dos avisos (el de la API `Notification` y el del push). Si el navegador
tiene una suscripción push activa, la página no muestra su aviso del sistema y deja el aviso al _service worker_; sin
suscripción, se conserva el aviso de la pestaña.

## ADR-REP-4 — La asignación automática no es atención; tiempo hábil congelado

«Atendidas» y «Primera atención» contaban la asignación automática (≈ 0 s). Ahora cuentan aceptación, asignación
manual, transferencia a un agente o primera respuesta humana. El tiempo hábil (primera respuesta y resolución) se
congela al calcularse y solo `analytics:rebuild --business-time` lo recalcula con el horario vigente, para que editar
un horario no reescriba el pasado sin querer. La agrupación por hora usa el desfase vigente en cada fecha (horario de
verano) calculado en PHP, sin tablas de zonas de MariaDB.

## ADR-OPS-6 — Un grupo de workers por cola y sin conexión fija

Cada cola tiene su programa de Supervisor (y sus instancias systemd), en orden de prioridad: `critical`, `realtime`,
`notifications`, `media`, `default`, `exports`. Los comandos no nombran conexión (`queue:work --queue=...`) y toman
`QUEUE_CONNECTION`, así el cambio a Redis no deja colas sin consumir. `startretries=20` y dependencias de MariaDB en
systemd evitan el estado `FATAL` tras un reinicio. Sustituye la parte de grupos de
[ADR-OPS-5](#adr-ops-5--base-de-datos-como-driver-por-defecto-redis-preparado).

## ADR-OPS-7 — Scripts de root fuera del árbol de la aplicación y prueba de humo obligatoria

Cron y `sudo` ejecutaban como root scripts de `/var/www/...`, que controla el usuario `deploy`. Ahora se instalan una
vez, revisados, en `/usr/local/sbin/chat-manager-*` (root:root) y `sudoers` solo permite comandos exactos. `deploy.sh`
exige `REPOSITORY`, siempre hace la prueba de humo contra `/health/ready` y revierte automáticamente solo si la sonda
estaba sana antes del cambio; `rollback.sh` elige por nombre la release completa anterior y nunca una marcada `FAILED`.

## ADR-SEC-4 — 404 para lo que está fuera de alcance, 403 para lo visible no permitido

Un 403 a un registro existente fuera de alcance y un 404 a un id inexistente permitían enumerar ids. Regla uniforme:
sin permiso del módulo, 403; con permiso, un registro fuera del alcance de datos responde 404 igual que uno
inexistente; uno visible sobre el que no se puede actuar, 403. Las políticas devuelven `Response` y los form requests
usan `Gate::inspect()`. Amplía [ADR-SEC-1](#adr-sec-1--autorizar-antes-de-validar-en-rutas-con-registros).

## ADR-SEC-5 — Exportaciones por POST con formulario nativo

Generar una exportación tiene efectos (archivo, auditoría, trabajo en cola), así que pasa de GET a POST con CSRF. Para
que el navegador siga descargando el archivo se envía un formulario nativo efímero con `_token` (prop compartida
`csrfToken`) en lugar de una petición XHR. La descarga de un archivo ya generado sigue por GET y vuelve a comprobar el
permiso del reporte concreto.

## ADR-SEC-6 — Cambiar o restablecer una contraseña cierra las sesiones

Restablecer la contraseña (administración o enlace) cierra todas las sesiones de la cuenta; cambiarla uno mismo
conserva solo la sesión actual (`User::closeSessions()`, auditado con `sessions_closed`). Con un driver de sesión
distinto de `database` no hay filas que borrar: las sesiones sin «recordarme» siguen abiertas hasta caducar (riesgo
documentado). La propia contraseña y el propio correo no se cambian desde Administración.

## ADR-SEC-7 — Los permisos sensibles no se conceden de forma automática

`app:sync-permissions --upgrade-default-roles` pone al día los roles por defecto de forma aditiva y auditada, pero
retiene los permisos `Permission::isSensitive()` (`system.view`, `system.manage`) salvo con `--include-sensitive`. Por
eso el Administrador no tiene acceso a Sistema hasta que el usuario lo decida
([seguridad-y-auditoria.md §3.1](seguridad-y-auditoria.md#31-permisos-por-rol-tras-la-actualización-de-la-estabilización)).

## ADR-SEC-8 — Eliminación lógica heterogénea con la auditoría como fuente

Cada entidad marca la eliminación a su manera (`is_active` en usuarios, roles, departamentos, etiquetas y respuestas
rápidas; `deleted_at` en conexiones y plantillas; estado `archived` en contactos). Unificar con `deleted_at` exigiría
migraciones y cambios en todas las acciones de eliminar y restaurar para mostrar una fecha que hoy no se pide en la
interfaz. Se mantiene el modelo y la auditoría (`*.deleted` / `*.restored`, con actor y fecha) es la fuente de quién y
cuándo. Si algún día se requiere la fecha en listas o exportaciones, se añade `deleted_at` aditivo.

## ADR-SEC-9 — Dependencias: estabilidad antes que actualización, y nada se quita sin aprobación

Guzzle se mantiene en 7.16 (rama soportada) porque Reverb exige `guzzlehttp/psr7 ^2.6`
([ADR-RT-4](#adr-rt-4--guzzle-7-por-compatibilidad-con-reverb)). En npm solo se aplicó el `override` no rompedor de
`tinypool` 2.1.2; las alertas restantes son de herramientas de desarrollo (`npm audit --omit=dev` limpio) y su
corrección exige versiones recién publicadas o un salto mayor de `vite-plus`. Los paquetes sin uso (`laravel/chisel`,
`laravel/sail`, `@laravel/passkeys`) se documentan para eliminarlos con aprobación del usuario.

## ADR-LOAD-2 — Transacción READ COMMITTED compartida y upsert de notificaciones agregadas

Los interbloqueos residuales venían de bloqueos de hueco en REPEATABLE READ (contactos nuevos y claves de agregación de
notificaciones inexistentes). `App\Support\ReadCommittedTransaction::run()` aplica READ COMMITTED a la transacción
externa, reintenta 3 veces con pausa aleatoria y registra cada reintento por operación (sin datos del cliente);
`InAppChannel` usa `INSERT … ON DUPLICATE KEY UPDATE occurrences = occurrences + 1`. No se cambió el aislamiento
global (las exportaciones necesitan REPEATABLE READ) ni se usan bloqueos globales. Contrapartida aceptada: un contacto
manual creado con el mismo teléfono en el mismo instante puede quedar como posible duplicado.

## ADR-QA-6 — Escenario E2E multisesión y utilidades compartidas

El flujo completo entre departamentos se verifica con cinco sesiones simultáneas de Chromium, Reverb y un worker
reales (`tests/e2e/estabilizacion.mjs`), y los escenarios comparten utilidades en `tests/e2e/lib.mjs`. `run.php`
comprueba Node, Playwright y Chromium antes de tomar el candado de la base. Playwright sigue fuera de las
dependencias del proyecto (`PLAYWRIGHT_MODULE`).

## ADR-DOC-2 — Registro de la fase de estabilización y guía de pruebas manuales

La fase se registra en un documento propio ([estabilizacion-final.md](estabilizacion-final.md)) con la tabla de
problemas del informe anterior, su estado y las funciones existentes intervenidas; las pruebas manuales por módulo
viven en [guia-pruebas-manuales.md](guia-pruebas-manuales.md). Los demás documentos se actualizaron en su lugar, sin
duplicarlos ([ADR-DOC-1](#adr-doc-1--un-documento-por-área-y-documentos-transversales)).

## ADR-WEB-1 — Widget en iframe servido por Chat Manager

El sitio solo carga un cargador IIFE sin dependencias (`public/widget/v1/chat-manager.js`) que dibuja el botón en un
Shadow DOM cerrado y crea un iframe de `GET /widget/v1/frame/{publicKey}`. El token del visitante vive en el
`localStorage` del iframe y la API y el WebSocket salen del origen de la app: el JavaScript del sitio (o un XSS del
sitio) no puede leerlos, no hace falta CORS, `REVERB_ALLOWED_ORIGINS` no cambia, el CSS queda aislado y la CSP de la app
se conserva. Solo el frame levanta `X-Frame-Options` (atributo `security.allow_framing`) y envía su propia
`frame-ancestors` con los orígenes permitidos. Se descartó montar toda la UI en un Shadow DOM del sitio: el token y el
socket quedarían en el contexto del sitio y habría que abrir CORS y Reverb por cliente.

## ADR-WEB-2 — Sin Sanctum: tokens propios con hash y middlewares propios

No se instala ninguna dependencia. `api_tokens` y `web_chat_sessions` guardan `sha256` del secreto, se comparan con
`hash_equals` y los autentican dos middlewares (`api.token`, `webchat.session`) que dejan el principal en atributos del
request. No se crean guards: `$request->user()` sigue siendo un `User` o `null`, nunca un token que confunda a Gate y a
las políticas. Sanctum no modela la allowlist de conexiones y departamentos, las IPs, el motivo y autor de la
revocación ni la expiración deslizante de los visitantes; habría que extenderlo igual
([ADR-SEC-9](#adr-sec-9--dependencias-estabilidad-antes-que-actualización-y-nada-se-quita-sin-aprobación)).

## ADR-WEB-3 — Mismo Reverb para los visitantes, canal autorizado fuera de `routes/channels`

Los visitantes usan la misma app de Reverb con un canal privado por sesión, `webchat.sessions.{public_id}`, autorizado
solo por `POST /api/v1/widget/broadcasting/auth` con el Bearer del visitante y la firma del protocolo Pusher. El canal no
se registra en `routes/channels*.php`, así que el `/broadcasting/auth` de agentes lo rechaza. Las señales
(`webchat.signal`) son invalidaciones sin contenido, como en
[ADR-RT-1](#adr-rt-1--señales-de-invalidación-en-lugar-de-datos-por-websocket), con sondeo con ETag de respaldo. Una
segunda app de Reverb exigiría variables nuevas y despliegue; queda como endurecimiento si la cuota `max_connections`
compartida se queda corta. Long-poll o SSE retendrían workers de PHP-FPM.

## ADR-WEB-4 — Contacto y conversación con el primer mensaje, por el mismo pipeline

La sesión del visitante se crea al cargar el widget, pero el contacto y la conversación nacen solo con el primer mensaje,
por `ReceiveInboundMessage` (el mismo de WhatsApp): sin contactos basura y con la misma distribución, horario,
automatizaciones y reportes. El id de proveedor `web.in.{sesión}.{client_message_id}` da la idempotencia. Las
identidades firmadas derivan `visitor_id` de la conexión y el `external_id` (prefijo `wi:`) y nunca se fusionan con
WhatsApp ni con otra conexión; identificar una sesión que ya escribió la reemplaza, para no adjuntar el historial
anónimo a la persona. Las salidas usan `ChannelTransports` → `WebChatTransport`, sin tocar el transporte de Meta.

## ADR-API-1 — El token de integración es el principal, con su propia allowlist

Cada token lleva `connection_ids` (obligatorio), `department_ids` opcional, capacidades, IPs y expiración obligatoria.
La visibilidad sale de `ApiToken::scopeConversations()` y lo que queda fuera es el mismo 404 que lo inexistente
([ADR-SEC-4](#adr-sec-4--404-para-lo-que-está-fuera-de-alcance-403-para-lo-visible-no-permitido)). Los mensajes de la
integración llevan `sender_user_id` nulo y `metadata.integration`, y `DeliverOutboundMessage` los acepta porque no hay
autor que revalidar. Una cuenta de servicio `User` exigiría excluirla de distribución, disponibilidad, capacidad,
reportes y transferencias; ligar el token a una persona rompería la regla de que solo el asignado responde.

## ADR-API-2 — Idempotencia sin tabla y errores con código estable

`Idempotency-Key` es obligatoria y se guarda como `client_reference = UUIDv5(namespace, "{token}:{llave}")` con la
huella sha256 del texto: el mismo intento responde 200 con el mismo mensaje y otro uso de la llave, 409
`idempotency_key_reused`. Cada token tiene su espacio de llaves, sin oráculo entre conversaciones ni migración. Todas
las respuestas de `/api/v1` son `{message, code}` (`ApiResponseHeaders::errorResponse()`), con `Cache-Control: no-store`
y sin CORS (`config/cors.php` con `paths => []`).

## ADR-INBOX-11 — Bandeja general como primera vista, con contadores en una agregación

Quien atiende conversaciones entra a `view=general`: pendientes que puede aceptar, sus abiertas y las en espera de su
alcance, de todos los canales, ordenadas por actividad reciente. `queue` se normaliza a general con `status=pending`.
Los chips usan un único prop `counts` calculado con `SUM(CASE …)` en una consulta y cada número es lo que lista su
chip con los mismos filtros. Aceptar no toca `last_message_at`, así que la fila no salta. No hizo falta migración.

## ADR-UX-1 — Terminología de estados solo de presentación

Los valores de `MessageStatus` no cambian; las etiquetas sí: «Enviando» (`queued`, `sending`), «Enviado» (`accepted`,
`sent`), «Entregado», «Leído», «Fallido» y «Entrega no confirmada» (`failed` + `delivery_unknown`), con la acción
«Reintentar». Se aplica en el enum, `messageFormat.ts`, el expediente, la API v1 y los avisos. Cerrar un toast con la
X no lo marca como leído.

## ADR-CONTACTS-1 — Contactos provisionales: escribir no guarda en el directorio

Quien escribe por primera vez (WhatsApp, Chat web, Messenger, Instagram) se registra con `RegisterInboundContact` como
**contacto provisional** (`contacts.saved_at` nulo): conserva su identidad técnica, sus conversaciones, las respuestas y
la resolución del destinatario exactamente igual que antes, pero no forma parte del directorio comercial. Entra al
directorio con `saved_at`/`saved_by_user_id` cuando: alguien lo crea a mano (`CreateContact`), una importación lo crea,
una fila de importación con «completar campos vacíos» coincide con él (se guarda aunque no haya campos que completar;
revertir el lote no lo vuelve provisional), o alguien con `contacts.create` pulsa «Guardar contacto» en la conversación o
en su ficha (`SaveContactToDirectory`: bloquea la fila, reautoriza, actualiza nombre/correo oficiales con la auditoría de
`UpdateContact`, nota privada opcional con permiso de notas, audita `contact.saved` y emite `ConversationChanged`
«contact» para sus conversaciones abiertas). El teléfono y las identidades nunca cambian al guardar.

- Todos los contactos que existían antes de la migración quedaron guardados (`saved_at = created_at`).
- Solo cuentan contactos guardados: el directorio (`ContactListQuery`, con el filtro «Incluir no guardados»), el reporte y
  la exportación de contactos (`ContactsReport`, el mismo filtro) y el número de contactos por etiqueta (`TagCatalogQuery`,
  pantalla y reporte de etiquetas).
- Siguen viendo los provisionales: la búsqueda de la bandeja, las políticas (su ficha es accesible con un aviso y la
  acción de guardar), la detección de duplicados (marca `is_saved = false` y el formulario ofrece «Guardar este
  contacto» en vez de duplicarlo) y la revisión de duplicados.
- `is_saved` se expone en `ContactResource`, en el `contact` de `ConversationResource` y en la API v1.
- Se distinguen cuatro cosas: datos técnicos de la conversación (identidad del canal), contacto corporativo del
  directorio (compartido), alias personal (solo lo ve su autor, nunca cambia la ficha) y etiquetas corporativas.
