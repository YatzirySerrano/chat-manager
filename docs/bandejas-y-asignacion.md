# Bandejas, visibilidad y asignación de conversaciones

Este documento describe la bandeja de mensajería (incremento C), quién ve qué conversación, cómo se distribuyen entre departamentos y cómo funciona la asignación. La integración con WhatsApp Cloud API se documenta en [mensajeria-whatsapp.md](mensajeria-whatsapp.md), las señales en tiempo real en [tiempo-real.md](tiempo-real.md) y los horarios y mensajes automáticos en [notificaciones-y-horarios.md](notificaciones-y-horarios.md).

## 1. La bandeja (`/conversations`)

Una sola página con tres áreas:

| Área                         | Contenido                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        |
| ---------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Lista (izquierda)            | Bandejas a las que tienes acceso con contador (Mis conversaciones, Pendientes, Todas), pestañas por estado con contador, búsqueda por nombre o teléfono, filtros (departamento, responsable, conexión, etiqueta), orden (pendientes primero, actividad más reciente, esperando hace más tiempo) y carga incremental de 25 en 25 (máximo 250 filas cargadas). Cada fila muestra cliente, vista previa del último mensaje, hora, mensajes sin leer _para ti_, departamento, responsable (solo en bandejas de equipo y en la cola), etiquetas, estado y un punto cuando el cliente escribió al último y nadie le respondió. Las pendientes llevan un borde y un icono de reloj para distinguirlas de las atendidas. |
| Chat (centro)                | Encabezado con cliente, estado, departamento y responsable, las operaciones permitidas (aceptar, retomar, reabrir y, en el menú, asignar, transferir, poner en espera, devolver a la cola y cerrar), el historial y el compositor.                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |
| Detalles (derecha, plegable) | Nombre, teléfono, nombre de perfil de WhatsApp, correo, procedencia comprobable (referencias que envió Meta), etiquetas del contacto, estado y ventana de atención, etiquetas de la conversación (editables con permiso), conversaciones anteriores del cliente que puedes ver, últimas notas privadas del contacto (con permiso) y exportación del expediente.                                                                                                                                                                                                                                                                                                                                                  |

La conversación abierta va en la URL (`/conversations?c=ID`): seleccionar otra solo recarga el chat y los detalles; cambiar filtros solo recarga la lista. La ruta anterior `/conversations/{id}` sigue funcionando y redirige a la bandeja con la conversación abierta y los últimos filtros usados. En pantallas pequeñas se alterna entre lista y chat (botón «Volver»); los detalles se abren como panel lateral. En pantallas de 1280 px o más los detalles son una tercera columna, y su estado (abierto o cerrado) se recuerda por usuario en el navegador.

### Historial

- Se carga la página más reciente (40 mensajes) y las anteriores al desplazarse hacia arriba («Cargar mensajes anteriores»), conservando la posición de lectura. El cursor es el id del mensaje: el orden es estable y ningún mensaje se repite (el cliente combina por id).
- Las notas internas y los eventos operativos (aceptación, asignación, transferencia, espera, cierre, reapertura, liberación por cambio de acceso, envío bloqueado) se intercalan por fecha. Viajan con la página de mensajes cuyo intervalo de tiempo los contiene. Los eventos solo se muestran a quien tiene el permiso de historial.
- Separadores por día («Hoy», «Ayer» o la fecha), burbujas diferenciadas para cliente y equipo, autor de cada respuesta (o «Mensaje automático»), hora y estado de entrega.
- Estados de entrega: en cola (reloj), enviando (girando), aceptado por WhatsApp (una marca tenue), enviado (una marca), entregado (dos marcas), leído (dos marcas de color) y no se envió (alerta con el motivo). «Entregado» y «Leído» solo aparecen cuando Meta lo informó. Los mensajes del transporte simulado de desarrollo llevan la etiqueta **Simulación** (metadato `simulated: true` o `transport: "simulated"`).
- Un mensaje fallido muestra el motivo y, para el responsable, **Reintentar** (endpoint del módulo de mensajería). Si el resultado del primer intento es desconocido (`delivery_unknown`), se pide confirmar el riesgo de duplicado antes de reintentar.
- Adjuntos: imágenes en línea, reproductores de audio y video, y tarjetas para abrir o descargar documentos. Siempre se cargan por la ruta autorizada, nunca desde una URL pública. Un archivo entrante que aún no se descarga de Meta aparece como pendiente.
- Ubicaciones (enlace al mapa), contactos compartidos, respuestas interactivas, reacciones (enlazadas al mensaje original cuando está cargado), plantillas (nombre e idioma) y mensajes no compatibles tienen su propia representación. Los enlaces `http`/`https` del texto son clicables y se abren en otra pestaña sin acceso a la página.
- La vista sigue los mensajes nuevos solo si ya estabas al final; si no, aparece «N nuevos». Los mensajes nuevos del cliente se anuncian a los lectores de pantalla.

### Compositor

- Dos modos claramente separados: **Responder al cliente** y **Nota interna — no se envía al cliente**. La nota usa otro color, otra etiqueta y otro endpoint (`POST conversations/{id}/notes`); nunca pasa por Meta ni toca la ventana de atención.
- Indicador explícito del destinatario: «Para: cliente (teléfono) por WhatsApp · conexión».
- Multilínea. Por defecto Enter envía y Shift+Enter agrega un salto de línea; el interruptor «Enter para enviar» lo cambia a Ctrl/⌘+Enter. La preferencia se guarda por usuario en el navegador y la pista se muestra bajo el campo.
- Adjuntar un archivo con vista previa (imágenes) y validación inmediata de tipo y tamaño. El servidor valida de nuevo.
- Emojis con un selector local (sin dependencias externas ni red).
- Respuestas rápidas con «/» (mismo selector que en Respuestas rápidas, con las variables ya resueltas para el contacto y el departamento).
- Citar un mensaje para responderlo («Responder citando»).
- Estados de envío y errores comprensibles. Cada intento lleva una `client_reference` (UUID): si la red falla y se reintenta, el servidor devuelve el mensaje ya guardado en lugar de duplicarlo. El botón se desactiva mientras se envía.
- Desactivado con motivo cuando no puedes escribir: conversación pendiente (acéptala), en espera (retómala), cerrada (reábrela), atendida por otra persona, o ventana de 24 horas cerrada. En este último caso se ofrece **Enviar plantilla** (plantillas aprobadas del módulo de mensajería, con formulario de variables y vista previa).

### Lecturas y contadores

- Cada persona tiene su marcador de lectura por conversación (`conversation_reads`: `user_id`, `conversation_id`, `last_read_message_id`, `read_at`; único por usuario y conversación).
- Mientras el chat está visible en pantalla, el cliente llama a `POST conversations/{id}/read` con el último mensaje mostrado. El marcador solo avanza (una pestaña atrasada no vuelve a marcar como no leído) y nunca apunta más allá de los mensajes de esa conversación.
- «Sin leer» = mensajes entrantes posteriores a tu marcador. Se calcula con subconsultas sobre el índice `messages (conversation_id, direction, id)` y solo para las filas de la página.
- La vista previa del último mensaje sale de `MAX(messages.id)` por conversación (índice `messages (conversation_id, id)`), en una consulta para toda la página. No se añadió una columna desnormalizada: el flujo de entrada es de otro módulo y la subconsulta es barata.

### Tiempo real

- La lista escucha `users.{id}` y los canales de bandeja que el servidor decide para ti (`inbox.global`, `inbox.departments.{id}` o, para agentes, solo las colas `inbox.queues.{id}` que pueden aceptar), evento `.inbox.changed`, y recarga solo lista y contadores. `.availability.changed` actualiza el interruptor en tus otras pestañas y los diálogos de asignar y transferir abiertos.
- El chat abierto escucha `conversations.{id}`: `.message.recorded` pide los mensajes nuevos, `.message.status` vuelve a leer ese mensaje, `.note.saved` trae la nota y `.conversation.changed` recarga estado, ventana y detalles.
- Las señales solo llevan ids: los datos siempre se piden al servidor, que vuelve a autorizar.
- Sin Reverb (o con la conexión caída) la lista se refresca cada 20 s y el chat abierto cada 20 s (mensajes nuevos, estados pendientes y estado de la conversación). Al reconectar se pone al día una vez. Se eliminó el sondeo fijo de 15 s.

### Accesibilidad

Lista navegable con flechas, Inicio y Fin; botones con nombre accesible; región `role="log"` para el historial y anuncio `aria-live` de mensajes nuevos; foco devuelto al compositor tras enviar; modo oscuro con los tokens del tema; diseño adaptable a escritorio, tableta y móvil.

## 2. Visibilidad (requisito innegociable)

La regla vive en un solo lugar, `App\Services\Conversations\ConversationAccess` (`canView` y su equivalente en consulta `restrictToVisible`). La usan la política, la lista, los contadores, la búsqueda, las exportaciones, los adjuntos, el historial, las lecturas, las etiquetas y los canales en tiempo real. Nunca depende del nombre del rol, sino del permiso, el alcance de datos del rol, la pertenencia a departamentos y la asignación.

| Alcance del rol                            | Ve                                                                                                                                                                                                                                              |
| ------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Global (Superadministrador, Administrador) | Todas las conversaciones, con filtros administrativos (departamento, responsable, conexión, etiqueta, «Sin departamento»).                                                                                                                      |
| Departamentos (Supervisor)                 | Las de sus departamentos y las propias.                                                                                                                                                                                                         |
| Asignadas (Agente)                         | Las suyas y las **pendientes sin asignar** de los departamentos donde puede aceptar (departamento activo y distribución distinta de manual, con el permiso de aceptar). Nunca las que atiende un compañero, aunque sean del mismo departamento. |

- Las cerradas requieren además el permiso de historial.
- Los agentes tienen una bandeja personal: «Mis conversaciones» y, con permiso de aceptar, «Pendientes». No se les muestra un filtro «Solo mías» redundante ni el filtro de responsable.
- Un id de conversación ajeno se trata como inexistente: 404 en la ruta anterior, en el historial, en las lecturas, en las etiquetas, en los mensajes y en los adjuntos; en la bandeja (`?c=`) no se muestra nada y se avisa que «no existe o ya no está a tu alcance».
- Los contadores (`counts`, `viewCounts`, sin leer) se calculan sobre la misma consulta restringida: nunca cuentan conversaciones ajenas.
- Los adjuntos comprueban, además de la visibilidad, que mensaje y archivo pertenezcan a esa conversación (no se pueden mezclar ids).
- El panel de detalles solo resume conversaciones anteriores visibles para quien mira.

## 3. Distribución entre departamentos

Todos los mensajes pueden llegar al mismo número. WhatsApp no indica el departamento: lo decide `ConversationRouter` sin inventar procedencia ni intención:

1. El departamento predeterminado de la conexión, si está activo y autorizado para esa conexión.
2. Si no, el departamento predeterminado de la plataforma (Configuración → Operación), con las mismas condiciones.
3. Si no, la conversación queda **sin departamento**; solo la ven y aceptan usuarios de alcance global.

El contenido del mensaje no se usa para enrutar (es el punto de extensión para reglas, menús o bots). Al reabrirse por un mensaje entrante dentro de la ventana de reapertura de la conexión, la conversación vuelve con su agente anterior si aún puede atenderla; si no, vuelve a la cola.

## 4. Bandeja compartida, aceptación y asignación

Cada departamento tiene un modo de distribución:

| Modo                     | Comportamiento                                                                                                                        |
| ------------------------ | ------------------------------------------------------------------------------------------------------------------------------------- |
| Compartido (`shared`)    | Las pendientes se ven en la cola de los miembros elegibles; quien acepta primero se queda con ella.                                   |
| Manual (`manual`)        | Solo quien tiene el permiso de asignar las reparte; los agentes no ven ni aceptan la cola.                                            |
| Automático (`automatic`) | Se asignan solas al agente disponible elegible con menos carga; si nadie califica, esperan en la cola (que también se puede aceptar). |

Reglas del motor (`app/Actions/Conversations`):

- **Aceptar** (`AcceptConversation`): bloquea la conversación (`SELECT … FOR UPDATE`); solo una persona la obtiene y las demás reciben «Otra persona ya aceptó esta conversación». Exige estar Disponible y no superar el límite de conversaciones en atención (Configuración → Operación). Sale de la cola, aparece en «Mis conversaciones», se registra el evento y se emite la señal en tiempo real.
- **Asignación automática** (`AssignAutomatically`): solo usuarios activos, con rol activo que permita ver y responder, miembros del departamento, Disponibles y por debajo del límite. Elige la menor carga (abiertas + en espera); el empate se decide por quien lleva más tiempo sin recibir una, y luego por id. La fila del departamento se bloquea mientras se elige, así que llegadas simultáneas no caen en el mismo hueco, y la carga se relee bajo el bloqueo del agente.
- **Asignar** (`AssignConversation`, permiso de asignar): a un agente No disponible solo con confirmación explícita (advertencia ya existente). La asignación manual ignora el límite de carga.
- **Transferir** (`TransferConversation`): a otro departamento o agente, con motivo obligatorio si la configuración lo exige. Sin agente, va a la cola del destino y, si el destino es automático, se reparte excluyendo al agente anterior.
- **Poner en espera / Retomar / Cerrar / Reabrir / Devolver a la cola** respetan la matriz de estados (`pending → open|closed`, `open → pending|on_hold|closed`, `on_hold → open|pending|closed`, `closed → pending|open`) y el contador `version`: el cliente envía la versión que vio y una decisión tomada con datos viejos se rechaza.
- **Etiquetas** (`SyncConversationTags`): el responsable, o quien asigna conversaciones, con el permiso «Asignar etiquetas a contactos y conversaciones». Solo se pueden agregar etiquetas activas; las eliminadas que ya tenía pueden quedarse como historial. Se audita (`conversation.tags_changed`) y se emite la señal `tags`. No cambia el estado ni la versión.
- **Notas internas**: separadas de los mensajes; nunca cuentan como respuesta ni tocan la ventana o las fechas de actividad. Al guardarlas o editarlas se emite `ConversationNoteSaved`.
- **`first_response_at`**: se fija cuando se encola la primera respuesta humana (no cuando Meta la acepta). Los reportes calculan sus propias métricas de entrega.
- Orden de bloqueos: conversación primero, luego usuarios, luego departamentos. No hay bloqueos globales: cada operación bloquea solo la conversación que toca.

## 5. Ausencias y cambios de acceso

- «Disponible» y «No disponible» conservan su significado: estar No disponible no quita las conversaciones que ya atiendes, pero impide aceptar nuevas y recibir asignaciones automáticas. Al volver a Disponible se reparte la cola de los departamentos automáticos.
- No se redistribuye nada en silencio por estar No disponible.
- Cuando un agente **pierde el acceso** (usuario eliminado, cambio de rol que ya no permite atender, retiro de un departamento, o se quita «responder» al rol que tiene: `SaveRole` libera a cada titular en su propia transacción después de guardar el rol), `ReleaseAssignedConversations` devuelve sus conversaciones no cerradas a la cola pasando por el motor: bloquea cada conversación por separado (en orden de id), revisa de nuevo bajo bloqueo, aplica la matriz de estados y la versión, y registra «Devuelta a la cola» con el departamento y el motivo (`trigger: access_change`). Cuando la transacción del cambio de acceso se confirma, las de departamentos automáticos se ofrecen a otro agente disponible, cada una en su propia transacción. Las colas compartidas o manuales simplemente esperan.

## 6. Endpoints del módulo

| Método y ruta                                          | Uso                                                                                                                                     |
| ------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------- |
| `GET /conversations` (`?c=`, filtros, `limit`)         | Bandeja (Inertia `conversations/Index`).                                                                                                |
| `GET /conversations/{id}`                              | Redirige a la bandeja con la conversación abierta.                                                                                      |
| `GET /conversations/{id}/timeline`                     | JSON: página más reciente, `before`, `after_message`/`after_note`/`after_event`, o `messages[]`/`notes[]` para volver a leer elementos. |
| `POST /conversations/{id}/read`                        | Avanza tu marcador de lectura.                                                                                                          |
| `PUT /conversations/{id}/tags`                         | Reemplaza las etiquetas (`tag_ids`).                                                                                                    |
| `POST /conversations/{id}/messages`                    | Respuesta: `body`, `client_reference`, `attachment` (archivo) y `reply_to_message_id` opcionales.                                       |
| `GET /conversations/{id}/messages/{m}/attachments/{a}` | Archivo autorizado (en línea para imágenes, PDF, audio y video seguros; `?download=1` lo descarga).                                     |
| `POST /conversations/{id}/notes`, `PUT …/notes/{n}`    | Notas internas (JSON si se pide con `Accept: application/json`).                                                                        |

## 7. Archivos adjuntos

- Tipos y límites por tipo (alineados con Meta): imágenes JPEG/PNG 5 MB; documentos PDF, texto y Office 16 MB; audio AAC/MP4/MPEG/AMR/OGG 16 MB; video MP4/3GPP 16 MB. Se leen de `config('whatsapp.media.types')` (módulo de mensajería; el tope de documentos se ajusta con `WHATSAPP_DOCUMENT_MAX_BYTES`); si falta, se usan estos valores.
- El tipo real se detecta con `finfo` a partir del contenido; el nombre y el tipo que declara el navegador no cuentan. Un ejecutable o una página HTML renombrados se rechazan.
- El audio no admite texto (regla de WhatsApp); el texto de un archivo admite hasta 1024 caracteres.
- Se guardan en el disco privado `filesystems.media_disk` (por defecto `local`, es decir `storage/app/private`) en `media/{aaaa}/{mm}/{uuid}`, sin extensión ni nombre original en la ruta. El nombre visible se limpia de rutas y caracteres reservados. Se guarda el SHA-256.
- Si el envío se rechaza después de guardar el archivo, el archivo se borra. Un reintento con la misma `client_reference` no guarda otro archivo.
- Se sirven con `X-Content-Type-Options: nosniff`, `Content-Disposition` (en línea solo para tipos seguros; el resto se descarga como `application/octet-stream`), `Cache-Control: private` y una CSP con `sandbox` (salvo PDF, para que el visor del navegador funcione).
- Si el archivo falta en disco, la respuesta es 404 y la interfaz muestra «ya no está disponible».

## 8. Permisos relacionados

`conversations.view`, `conversations.reply`, `conversations.accept`, `conversations.assign`, `conversations.transfer`, `conversations.close`, `conversations.reopen`, `conversations.history`, `conversations.notes`, `tags.assign` (contactos y conversaciones), `quick-replies.use`, `reports.export`. El rol Agente por defecto **no** incluye `tags.assign`; un administrador puede concederlo desde Roles.

## 9. Limitaciones conocidas

- La entrega real de multimedia depende del módulo de mensajería (subida a Meta en el job de entrega). Sin credenciales de Meta, los mensajes con archivo quedan en cola o fallan con el motivo que registre ese job; el transporte simulado de desarrollo los marca como **Simulación**.
- El selector de plantillas y el botón Reintentar llaman a endpoints del módulo de mensajería (`GET/POST conversations/{id}/templates`, `POST conversations/{id}/messages/{m}/retry`); el diálogo usa la estructura y las variables que devuelve ese módulo (`structure`, `parameters`, `sendable`).
- La búsqueda usa `LIKE` con comodín inicial sobre nombre, nombre de perfil y teléfono (sin índice de texto completo).
- La lista mantiene como máximo 250 filas cargadas; para más, conviene acotar con filtros.
