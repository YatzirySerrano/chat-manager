# Bandejas, visibilidad y asignación de conversaciones

Este documento describe la bandeja de mensajería (incremento C), quién ve qué conversación, cómo se distribuyen entre departamentos y cómo funciona la asignación. La integración con WhatsApp Cloud API se documenta en [mensajeria-whatsapp.md](mensajeria-whatsapp.md), las señales en tiempo real en [tiempo-real.md](tiempo-real.md) y los horarios y mensajes automáticos en [notificaciones-y-horarios.md](notificaciones-y-horarios.md).

## 1. La bandeja (`/conversations`)

Una sola página con tres áreas:

| Área                         | Contenido                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| ---------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Lista (izquierda)            | Indicadores operativos siempre visibles: la vista por omisión (**Bandeja general** para quien atiende; **Todas las conversaciones** o **Conversaciones de mis departamentos** para quien supervisa o no atiende, ver [Vista general](#vista-general-chips-y-contadores)), **Pendientes** con su contador (resaltado mientras haya alguna) y **No leídas** con su contador. Todo lo demás vive en un único botón **Filtros** (icono de controles + texto + número de filtros aplicados), que abre un panel con cabecera y pie fijos (popover en escritorio, hoja inferior en pantallas angostas o muy bajas; ver [Panel de filtros](#panel-de-filtros-fase-51)) por secciones: «Qué conversaciones» (Vista, Estado: activas, Pendientes, En atención y, con historial o supervisión, Cerradas; Solo no leídas), «Quién y dónde» (Departamento, Agente responsable solo para quien supervisa o asigna en bandejas de equipo, Conexión si hay más de una, Canal si hay más de uno), «Etiquetas» (varias, con búsqueda y modo de coincidencia, ver [Filtro por etiquetas](#filtro-por-etiquetas)) y «Orden y búsqueda» (Orden, Buscar en). Los filtros aplicados aparecen como chips removibles bajo la búsqueda, junto a «Limpiar filtros». El estado vive en la URL. Cada fila muestra a todos: contacto (o tu alias personal, con la marca «alias» y el nombre oficial en la descripción), vista previa con su estado de entrega, hora, no leídos _para ti_ y etiquetas de la conversación; las pendientes llevan borde, reloj, insignia «Pendiente» y, si puedes, **Aceptar**. Estado, departamento, responsable y conexión solo se muestran a quien supervisa (`conversations.supervise`), asigna (`conversations.assign`) o tiene alcance global (`viewer.can_supervise`). Cada fila tiene un menú (⋮, visible al pasar o enfocar en escritorio y siempre en pantallas táctiles) con «Abrir», «Marcar como leída» (si hay no leídos) y «Administrar etiquetas» (con `can.tag`). |
| Chat (centro)                | Encabezado con cliente, estado, departamento y responsable, las operaciones permitidas (aceptar, reabrir y, en el menú, asignar, transferir, devolver a la cola y cerrar), el historial y el compositor.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| Detalles (derecha, plegable) | Nombre, teléfono, nombre de perfil de WhatsApp, correo, procedencia comprobable (referencias que envió Meta), etiquetas del contacto, estado y ventana de atención, etiquetas de la conversación (editables con permiso), conversaciones anteriores del cliente que puedes ver, últimas notas privadas del contacto (con permiso) y exportación del expediente.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   |

La conversación abierta va en la URL (`/conversations?c=ID`): seleccionar otra solo recarga el chat y los detalles; cambiar filtros solo recarga la lista. La ruta anterior `/conversations/{id}` sigue funcionando y redirige a la bandeja con la conversación abierta y los últimos filtros usados. En pantallas pequeñas se alterna entre lista y chat (botón «Volver»); los detalles se abren como panel lateral. En pantallas de 1280 px o más los detalles son una tercera columna, y su estado (abierto o cerrado) se recuerda por usuario en el navegador.

### Historial

- Se carga la página más reciente (40 mensajes) y las anteriores al desplazarse hacia arriba («Cargar mensajes anteriores»), conservando la posición de lectura. El cursor es el id del mensaje: el orden es estable y ningún mensaje se repite (el cliente combina por id).
- Las notas internas y los eventos operativos (aceptación, asignación, transferencia, espera, cierre, reapertura, liberación por cambio de acceso, envío bloqueado) se intercalan por fecha. Viajan con la página de mensajes cuyo intervalo de tiempo los contiene (como máximo 300 notas y 300 eventos por página; si hay más, la página se acorta y la siguiente continúa desde el cursor `until`, sin pérdidas). Los eventos solo se muestran a quien tiene el permiso de historial.
- Separadores por día («Hoy», «Ayer» o la fecha). Desde la fase 5.1 cada día del calendario (en la zona horaria de la plataforma, `components/conversations/dayGroups.ts`) es una sección propia y su separador solo se queda fijo dentro de ella: el del día siguiente lo empuja, así que dos fechas nunca se encimen al desplazarse. Un chat abierto pasada la medianoche vuelve a rotular sus días. Burbujas diferenciadas para cliente y equipo, autor de cada respuesta (o «Mensaje automático»), hora y estado de entrega.
- Estados de entrega: en cola (reloj), enviando (girando), aceptado por WhatsApp (una marca tenue), enviado (una marca), entregado (dos marcas), leído (dos marcas de color) y no se envió (alerta con el motivo). «Entregado» y «Leído» solo aparecen cuando Meta lo informó. Los mensajes del transporte simulado de desarrollo llevan la etiqueta **No enviado (modo de prueba)** (metadato `simulated: true` o `transport: "simulated"`).
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
- Desactivado con motivo cuando no puedes escribir: conversación pendiente (acéptala), cerrada (reábrela), atendida por otra persona, o ventana de 24 horas cerrada. En este último caso se ofrece **Enviar plantilla** (plantillas aprobadas del módulo de mensajería, con formulario de variables y vista previa) solo a quien tiene `templates.send` (`can.send_templates`); los demás ven que podrán responder cuando el cliente vuelva a escribir. La cuenta regresiva de la ventana corre en el navegador a partir de `remaining_seconds` y pasa a «Ventana vencida» justo al vencer, sin recargar (`useLiveCustomerWindow`).
- Bloqueado por la conexión: si la conexión de WhatsApp está desactivada, eliminada, sin credenciales guardadas o con credenciales rechazadas por Meta, el compositor muestra el motivo en lugar del campo de respuesta (sin adjuntos, plantillas ni reintentos) y deja agregar notas internas. Con credenciales sin verificar el envío se permite con una advertencia. El servidor aplica la misma regla y responde `409` con el mismo motivo (ver [mensajeria-whatsapp.md §2.5](mensajeria-whatsapp.md#25-política-de-conexión-inactiva-o-eliminada)).

### Lecturas y contadores

- Cada persona tiene su marcador de lectura por conversación (`conversation_reads`: `user_id`, `conversation_id`, `last_read_message_id`, `read_at`; único por usuario y conversación).
- Mientras el chat está visible en pantalla, el cliente llama a `POST conversations/{id}/read` con el último mensaje mostrado. El marcador solo avanza (una pestaña atrasada no vuelve a marcar como no leído) y nunca apunta más allá de los mensajes de esa conversación.
- «Sin leer» = mensajes entrantes posteriores a tu marcador. Se calcula con subconsultas sobre el índice `messages (conversation_id, direction, id)` y solo para las filas de la página (`ConversationInboxQuery::unreadMessages()`). Listar nunca mueve el marcador: solo leer el chat lo hace.
- **Quien nunca abrió la conversación** (sin marcador) no hereda todo el historial como no leído: solo cuentan los mensajes del cliente posteriores a la última respuesta enviada por una persona (`sender_user_id` no nulo; los mensajes automáticos no cuentan como respuesta). Si nadie respondió, cuentan todos. En cuanto abres el chat, manda tu marcador personal. No requirió migración.
- **Las conversaciones cerradas no cuentan** como no leídas: el contador señala trabajo pendiente.
- Un webhook duplicado no suma dos veces: `messages.whatsapp_message_id` es único y el duplicado no se vuelve a registrar.
- Filtro **No leídas** (`?unread=1`): la misma regla como `EXISTS` dentro de los filtros de la bandeja, así que se combina con vista, estado, departamento, responsable, conexión, etiqueta y búsqueda, y los contadores por estado y por bandeja lo respetan. El contador del filtro (`unreadCount`) cuenta las conversaciones con mensajes sin leer en la vista, filtros y pestaña de estado actuales, es decir, las filas que mostraría al activarlo. En «Mis conversaciones» no hay pestaña «Pendiente» (aceptar asigna y devolver a la cola desasigna); si llega `status=pending` por URL o sesión, se normaliza a las activas. La exportación no aplica este filtro, porque depende de los marcadores de cada persona y el reporte no lo describe.
- Canal: hay dos canales (`ConnectionChannel`: WhatsApp y Chat web). El filtro **Canal** (`?channel=whatsapp|web_chat`) aparece cuando `filterOptions.channels` trae más de uno (solo canales con conexiones visibles) y se aplica como `connection_id IN (conexiones de ese canal, incluidas las eliminadas)`, sin correlación. La fila muestra el icono y el nombre del canal (`connection.channel`, `connection.channel_label`).
- La vista previa del último mensaje sale de `MAX(messages.id)` por conversación (índice `messages (conversation_id, id)`), en una consulta para toda la página. No se añadió una columna desnormalizada: el flujo de entrada es de otro módulo y la subconsulta es barata.

### Vista general, chips y contadores

Desde la fase 4 la primera vista de quien atiende conversaciones (`attendsConversations()`) es la **Bandeja general**
(`view=general`), que mezcla WhatsApp y Chat web. Quien no atiende abre `view=all`.

**Supervisión (fase 5.1).** Quien supervisa su alcance (`ConversationAccess::supervisesReach()`: permiso
`conversations.supervise` con alcance global o de departamentos) abre por omisión `view=all`, aunque también atienda:
ve todas las conversaciones de su alcance, sin importar quién las atienda, incluidas las transferidas que pasaron por sus
departamentos. Su propio trabajo («Bandeja general», «Mis conversaciones») queda a un clic en «Filtros». Antes, un
administrador que también atendía abría la bandeja general (pendientes que puede aceptar + abiertas asignadas a él) y
no veía las conversaciones de sus agentes salvo eligiendo «Todas las visibles». La visibilidad no cambió
(`ConversationAccess::restrictToVisible`); solo la vista por omisión y los nombres.

El nombre de `view=all` dice el alcance real, nunca «visibles»: **Todas las conversaciones** (global),
**Conversaciones de mis departamentos** (departamentos) o **Mis conversaciones** (asignado). Las vistas por omisión
de cada perfil son:

| Perfil                                                        | Vistas (la primera es la de omisión) |
| ------------------------------------------------------------- | ------------------------------------ |
| Quien no atiende conversaciones                               | `all`                                |
| Agente con alcance asignado                                   | `general`, `mine`                    |
| Atiende, alcance de departamentos o global, sin supervisión   | `general`, `mine`, `all`             |
| Supervisa (`conversations.supervise`, departamentos o global) | `all`, `general`, `mine`             |

Ver también lo nuevo de las alertas: ver una conversación nunca hace sonar un aviso
([notificaciones §Visibilidad y avisos](notificaciones-y-horarios.md#visibilidad-frente-a-avisos-fase-51)).

- **Predicado** (una sola consulta dentro de `Conversation::visibleTo()`): `status IN (pending, open)` y
  (pendientes sin responsable de los departamentos que puedes aceptar —o sin departamento si tu alcance es global—, o
  abiertas asignadas a ti). «En espera» está retirado (fase 5): ya no hay chip, contador ni filtro; las filas
  heredadas en ese estado salen de la bandeja general y siguen visibles en «Mis conversaciones» y en la vista `all` hasta que el
  comando de retiro las mueva. Un enlace viejo con `status=on_hold` se normaliza a las activas.
- **Vistas:** según la tabla anterior (`ConversationInboxQuery::views()`). `view=queue` se normaliza a `general` con
  `status=pending`.
- **Indicadores** (el `nav` se llama «Bandejas»): la vista por omisión con sus activas («Bandeja general» o el
  nombre de `all` según el alcance), «Pendientes» (vista por omisión + `pending`; en supervisión, las pendientes de todo
  su alcance) y «No leídas» (`unread=1`). Las demás vistas, «En atención» (`open`) y «Cerradas» (`closed`, solo con
  `conversations.history` o `conversations.supervise`) se eligen en «Filtros». Con
  `status=all` (solo por URL) la vista general lista su predicado más las cerradas visibles.
- **Contadores:** un único prop `counts {general, mine, pending, attending}` calculado con una agregación
  condicional (`SUM(CASE …)`) en una consulta. Cada número es exactamente lo que lista su indicador con los mismos
  filtros (búsqueda, departamento, agente, conexión, canal, etiquetas y «No leídas»). `mine` incluye las filas
  heredadas en espera, igual que su lista. Para quien no atiende (`views = ['all']`) se calculan sobre `all`.
  `unreadCount` sigue siendo una consulta aparte porque depende de la vista y el estado actuales (no se puede
  derivar de `counts`, que los ignora).

### Filtro por etiquetas

- `tags[]` (hasta 10 ids de etiquetas de **conversación**) y `tag_match`: `any` (por defecto, «Con al menos una de
  estas etiquetas») o `all` («Con todas estas etiquetas a la vez»). El
  parámetro antiguo `tag` (una etiqueta) sigue funcionando y se suma a la lista.
- Consulta: `EXISTS` correlacionado sobre `conversation_tag` (índice único `conversation_id, tag_id`), sin unir la
  tabla `tags`; `any` es una búsqueda con `IN`, `all` una por etiqueta (máximo 10), sin agrupar ni contar. Siempre
  dentro de `visibleTo()`: una etiqueta nunca muestra conversaciones fuera de tu alcance. Validación: arreglo de
  máximo 10 enteros distintos; `tag_match` solo `any|all`.
- El catálogo del filtro y del menú «Administrar etiquetas» es el mismo prop `tags` (etiquetas activas), leído una sola
  vez por petición junto con `details.availableTags`.
- Las etiquetas del **contacto** son otra cosa: se administran desde la ficha del contacto y no filtran la bandeja.
- **Administrar etiquetas** desde la fila abre el mismo editor que el panel de detalles (`TagPickerBody`) en un
  diálogo que aclara «Estas etiquetas se aplican a esta conversación» y guarda con `PUT conversations/{id}/tags`
  (`SyncConversationTags`: bloqueo, permiso, auditoría y señal en tiempo real); la página se recarga y la fila, los
  contadores y el filtro se actualizan.
- **Orden:** «Actividad más reciente» (`last_message_at DESC, id DESC`, cursor _keyset_) por defecto en todas las
  vistas; aceptar no toca `last_message_at`, así que la fila se queda en su sitio y solo cambia su insignia a «En
  atención». Tras aceptar desde una vista con `status=pending`, se redirige a la bandeja general con `?c=id`.
- **Accesibilidad:** el cambio de chip y el número de resultados se anuncian con `aria-live`.
- **E2E:** `tests/e2e/bandeja-general.mjs` ([pruebas.md §6](pruebas.md#6-escenarios-de-navegador-e2e)).

### Panel de filtros (fase 5.1)

- `components/conversations/InboxFilterSurface.vue` elige el contenedor: popover alineado al botón (ancho 22 rem,
  alto limitado al espacio disponible de la ventana, con margen de 12 px) desde 640 px de ancho y 500 px de alto; hoja
  inferior hasta 90 % del alto en pantallas menores (también con zoom de 200 % o más). Cabecera («Filtros», «N aplicados») y pie («Limpiar», «Aplicar») quedan fijos; solo el
  cuerpo se desplaza. Antes el panel se recortaba porque su alto se medía desde el botón y todo el bloque, incluido
  «Limpiar filtros», se desplazaba junto.
- **Borrador:** el panel trabaja sobre una copia de los filtros; la lista no cambia hasta «Aplicar» (o Enter en un
  campo). «Limpiar» restablece y aplica. Un clic fuera o un giro de pantalla conserva el borrador y avisa «Tienes cambios sin
  aplicar»; Escape o «Cerrar» lo descartan. Los indicadores rápidos y los chips
  aplicados siguen aplicando al instante. El número aplicado se anuncia en la región `aria-live` de la lista.
- Las opciones siguen llegando del servidor (departamentos, agentes, vistas…), la URL sigue siendo el estado y el
  servidor valida. E2E: `tests/e2e/filtros.mjs`.

### Tiempo real

- La lista escucha `users.{id}` y los canales de bandeja que el servidor decide para ti (`inbox.global`, `inbox.departments.{id}`, `inbox.supervision.{id}` para quien supervisa un departamento con alcance de departamentos, o, para agentes, solo las colas `inbox.queues.{id}` que pueden aceptar), evento `.inbox.changed`, y recarga solo lista y contadores. Así quien supervisa ve llegar conversaciones nuevas, mensajes, asignaciones, transferencias y cierres de su alcance sin recargar; esas señales nunca suenan ni generan avisos ([tiempo-real.md](tiempo-real.md#supervisión-por-departamentos-fase-51)).
- En los mismos canales escucha `.inbox.message_status` (estado de entrega del **último** mensaje de una conversación) y solo corrige el icono de la fila, sin recargar: se aplica únicamente si avanza el estado mostrado (misma regla que `MessageStatus::canAdvanceTo()`: nunca retrocede, un fallo solo sustituye estados anteriores a «Entregado», y `delivery_unknown` cede ante la evidencia posterior de Meta) y se descarta cuando una recarga trae ese estado o uno más reciente (`last_message.updated_at`). El `.message.status` del chat abierto alimenta lo mismo, así que fila y chat coinciden. Ver [tiempo-real.md](tiempo-real.md).
- El chat abierto escucha `conversations.{id}`: `.message.recorded` pide los mensajes nuevos, `.message.status` vuelve a leer ese mensaje, `.note.saved` trae la nota y `.conversation.changed` recarga estado, ventana y detalles.
- Las señales solo llevan ids: los datos siempre se piden al servidor, que vuelve a autorizar.
- Una señal que llega mientras se carga un cambio de filtro no se pierde: al terminar esa carga, la lista se recarga una vez más (la respuesta del filtro pudo leerse antes de que el cambio se confirmara). La recarga en vivo incluye filas, contadores por estado, por bandeja y de no leídas.
- Sin Reverb (o con la conexión caída) la lista se refresca cada 20 s y el chat abierto cada 20 s (mensajes nuevos, estados pendientes y estado de la conversación). Al reconectar se pone al día una vez. Se eliminó el sondeo fijo de 15 s.

### Accesibilidad

Lista navegable con flechas, Inicio y Fin; botones con nombre accesible; región `role="log"` para el historial y anuncio `aria-live` de mensajes nuevos; foco devuelto al compositor tras enviar; modo oscuro con los tokens del tema; diseño adaptable a escritorio, tableta y móvil.

### Búsqueda y paginación

Implementado en la estabilización (`ConversationInboxQuery`, `App\Actions\Contacts\ContactSearch`, `App\Support\FullTextTerms`, `ConversationTimeline`). Índices en la migración aditiva `2026_10_22_163328_add_search_and_listing_indexes`.

**Paginación de la bandeja**

- La lista se pagina con un **cursor _keyset_** sobre su propio orden: prioridad de estado (pendiente, abierta, cerrada; una fila heredada «en espera» ordena como abierta), última actividad e id como desempate. «Cargar más conversaciones» (botón o scroll) avanza de verdad y **no tiene tope**: se puede recorrer todo el historial autorizado. Ya no existen el límite de 250 filas ni el parámetro `limit` en la URL.
- La primera página llega con la página de Inertia (`conversations` = `{data, has_more, next_cursor}`). Las siguientes, por `GET /conversations/page?after=<cursor>&limit=<1..500>&<filtros>` (ruta `conversations.page`, JSON, 120 peticiones por minuto). Autoriza igual que la bandeja y aplica siempre `Conversation::visibleTo()`, en cada petición: una pestaña abierta deja de ver lo que se le retiró. Un cursor manipulado o de otro orden responde `422` («La lista cambió. Vuelve a cargarla.»).
- Recarga en vivo: solo se recarga la primera página; las filas cargadas después se releen desde el nuevo final de la primera página (hasta 500 por petición) y se deduplican por id. Si se habían cargado más de 500 filas adicionales, tras una recarga en vivo se conservan las 500 primeras y «Cargar más» sigue disponible. Si se pide «Cargar más» mientras se releen las filas, la petición espera a que termine la relectura y continúa desde ahí, así que no se saltan ni se repiten filas durante la actividad en vivo.
- Las conversaciones sin actividad (`last_message_at` nulo) van al final en «Pendientes primero» y «Actividad más reciente», y al principio en «Esperando hace más tiempo». Cambiar un filtro vuelve a empezar desde la primera página.

**Búsqueda de contactos** (pantalla de Contactos y bandeja; una sola lógica en `ContactSearch`)

- **Teléfono**: si el término solo tiene dígitos y signos telefónicos (espacios, paréntesis, guiones, puntos, `+` o `00`) y al menos 3 dígitos, se busca el número exacto normalizado a E.164, el comienzo del número tal como se escribió y precedido del código de país por defecto (+52; «55 1234» encuentra +52551234…) y el comienzo del identificador de WhatsApp (`wa_id`).
- Un término con letras **nunca** busca sus dígitos en el teléfono («Juan 5» busca «Juan»).
- **Correo**: un término de una sola palabra con `@` busca el comienzo de la dirección.
- **Nombre**: índice FULLTEXT de InnoDB (MariaDB) sobre nombre y nombre de perfil, en modo booleano. Cada palabra de 3 caracteres o más debe aparecer como palabra o comienzo de palabra («gonz ana» encuentra «Ana González»). No distingue mayúsculas ni acentos (`utf8mb4_unicode_ci`). Se ignoran las palabras de menos de 3 caracteres y las _stopwords_ de InnoDB (the, for, with, com…), y se usan como máximo 8 palabras. Los operadores que escriba la persona (`+ - < > ( ) ~ * " @`) no se interpretan. Si no queda ninguna palabra de 3 caracteres o más («Al»), se busca el comienzo del nombre.
- En la bandeja la búsqueda es un `IN` sin correlación sobre `contacts`, siempre dentro de las conversaciones visibles: un contacto de un compañero no aparece ni cambia los contadores.
- En la bandeja también encuentra los **alias personales de quien busca** (texto contenido en el alias, con `%` y `_` tomados literalmente); los alias de otros usuarios nunca coinciden.
- No se encuentra una subcadena dentro de una palabra («zález» no encuentra «González») ni los últimos dígitos de un teléfono («5678»): ambos impedirían usar índices.

**Búsqueda en el contenido de los mensajes** (bandeja)

- En el panel de filtros: **Buscar en** → «Nombre, teléfono o correo del contacto» (por defecto) o «Contenido de los mensajes» (`search_in=messages`). Cuenta como filtro activo y cambia el texto de ayuda del campo.
- Usa el índice FULLTEXT de `messages.body` con las mismas reglas de palabras que los nombres. Si el término no tiene ninguna palabra buscable (menos de 3 letras o números, o solo palabras muy comunes), el servidor lo indica (`filters.search_ignored`) y la bandeja muestra «Escribe al menos una palabra de 3 letras o números para buscar en los mensajes. Las palabras muy comunes (como «the» o «for») no se buscan.».
- Parte siempre de las conversaciones visibles para el usuario. Las cerradas solo aparecen con el permiso «Consultar conversaciones cerradas y su historial» y respetan la pestaña elegida (para encontrarlas, elige en Estado «Cerradas» o «Todos los estados»). Los resultados se paginan con el mismo cursor. No busca en notas internas ni en el chat interno y no muestra el fragmento que coincide.
- El export de conversaciones hereda el filtro y su descripción dice «Búsqueda en el contenido de los mensajes: «…»».

**Historial de la conversación**

- Cada página trae como máximo 300 notas y 300 eventos (`ConversationTimeline::SIDE_LIMIT`). Si su intervalo tiene más, la página se acorta a los más recientes y devuelve `until`, un cursor compuesto opaco (segundo + id de nota + id de evento): aunque cientos de notas o eventos compartan el mismo segundo, cada página avanza y ninguno se pierde ni se repite. Un `until` manipulado responde `422` («El historial cambió. Vuelve a abrir la conversación.»).
- Al ponerse al día tras una desconexión (`after_*`), si llega el máximo de 200 elementos por tipo, el chat vuelve a pedir lo que falta.

**Orden estable en Contactos**: «Más recientes» = última interacción desc, id desc (sin interacción al final); «Nombre» = nombre, id; «Más nuevos» = fecha de alta desc, id desc.

**Índices añadidos**: FULLTEXT `contacts(name, profile_name)`, `contacts(last_interaction_at, id)`, `conversations(last_message_at, id)` y FULLTEXT `messages(body)`. InnoDB actualiza el índice de mensajes en cada mensaje (coste de escritura moderado). El primer FULLTEXT de cada tabla reconstruye la tabla: en producción, aplicar la migración en una ventana de bajo tráfico.

**Medición** (EXPLAIN en `chatmanager_testing` con 8 000 contactos, 8 000 conversaciones y 40 000 mensajes; no es una medición de producción):

| Consulta                                         | Plan                                                                | Tiempo |
| ------------------------------------------------ | ------------------------------------------------------------------- | ------ |
| Bandeja `status=all` «recent», primera página    | `conversations_last_message_id_index`, 26 filas                     | 0,9 ms |
| Bandeja `status=all` «recent», página por cursor | rango sobre el mismo índice                                         | 0,9 ms |
| Bandeja activa con prioridad                     | `conversations_status_last_message_at_index` + filesort             | 1,6 ms |
| Prioridad con `status=all`                       | recorrido completo + filesort (`FIELD` no es indexable)             | 3,3 ms |
| Búsqueda por nombre en la bandeja                | fulltext `contacts_name_fulltext`                                   | 6,3 ms |
| Búsqueda por teléfono en la bandeja              | recorrido de `conversations` con `eq_ref` a contactos e identidades | 7,1 ms |
| Búsqueda en mensajes                             | fulltext `messages_body_fulltext` + semijoin                        | 15 ms  |
| Contactos «recent»                               | `contacts_last_interaction_id_index`, 15 filas                      | 0,5 ms |
| Contactos, búsqueda por nombre                   | fulltext                                                            | 1,7 ms |

Pendiente si el volumen crece: la prioridad con `status=all` (o la pestaña Cerradas en la vista global) sigue sin índice; requeriría una columna generada `status_rank` indexada. La búsqueda por teléfono en la bandeja crece de forma lineal con las conversaciones visibles.

**Pruebas**: `InboxPaginationTest`, `InboxSearchTest`, `ContactSearchTest` e `InboxTimelineTest`. InnoDB FULLTEXT no ve filas sin confirmar de la transacción de `RefreshDatabase`: toda prueba que busque por nombre (3 letras o más) o por contenido de mensajes llama a `commitForFullTextSearch()` (`tests/Pest.php`), que confirma los datos y obliga a reconstruir `chatmanager_testing` antes de la prueba siguiente.

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

| Modo                     | Comportamiento                                                                                                             |
| ------------------------ | -------------------------------------------------------------------------------------------------------------------------- |
| Compartido (`shared`)    | Las pendientes se ven en la cola de los miembros elegibles; quien acepta primero se queda con ella.                        |
| Manual (`manual`)        | Solo quien tiene el permiso de asignar las reparte; los agentes no ven ni aceptan la cola.                                 |
| Automático (`automatic`) | Se asignan solas al agente elegible con menos carga; si nadie califica, esperan en la cola (que también se puede aceptar). |

Reglas del motor (`app/Actions/Conversations`):

- **Aceptar** (`AcceptConversation`): bloquea la conversación (`SELECT … FOR UPDATE`); solo una persona la obtiene y las demás reciben «Otra persona ya aceptó esta conversación». Exige cuenta y rol activos, permiso, pertenecer al departamento (activo) y no superar su límite de conversaciones en atención (ver «Capacidad»). Sale de la cola, aparece en «Mis conversaciones», se registra el evento y se emite la señal en tiempo real.
- **Aceptar desde la lista**: las filas pendientes muestran **Aceptar** solo cuando `can.accept` (la misma `ConversationPolicy::accept` que usa el chat) lo permite. El botón llama a la misma ruta `POST conversations/{id}/accept` y a la misma acción transaccional; no hay endpoint propio. Mientras se procesa muestra un indicador y no admite otro clic (una aceptación a la vez). Al aceptar, la conversación se abre y la fila pasa a «En atención» y a «Mis conversaciones» en la misma respuesta. Si otra persona la tomó antes, se avisa «Otra persona ya aceptó esta conversación.» y la lista vuelve sin esa fila (`409` para clientes JSON). Fuera de tu departamento responde `404`; si la ves pero tu rol no permite aceptar, `403`. Los demás agentes reciben el cambio por las señales existentes.
- **Asignación automática** (`AssignAutomatically`): solo usuarios activos, con rol activo que permita ver y responder, miembros del departamento y por debajo de su límite (no existe disponibilidad manual). Elige la menor carga (abiertas, más filas heredadas en espera aún no migradas); el empate se decide por quien lleva más tiempo sin recibir una, y luego por id. La fila del departamento se bloquea mientras se elige, así que llegadas simultáneas no caen en el mismo hueco, y la carga se relee bajo el bloqueo del agente. Si nadie tiene capacidad, la conversación queda **Pendiente** en la cola, visible para quien la gestiona; nunca se rechaza ni se pierde un mensaje del cliente.
- **Asignar** (`AssignConversation`, permiso de asignar): a un agente que ya atiende su máximo, solo con confirmación explícita (`confirm_over_capacity`). La excepción queda en el evento (`metadata.assigned_over_limit` y `metadata.limit`); no cambia el límite del agente. `confirm_unavailable` ya no existe (los eventos antiguos pueden conservar `assigned_while_unavailable`).
- **Transferir** (`TransferConversation`): a otro departamento (su cola), a un agente elegible del mismo departamento, a un agente de otro departamento (la conversación pasa también a ese departamento) o a la cola de un departamento sin persona; con motivo obligatorio si la configuración lo exige. Sin agente, va a la cola del destino y, si el destino es automático, se reparte excluyendo al agente anterior. Entregarla a alguien al límite es la misma excepción que al asignar: solo quien tiene el permiso de asignar, con confirmación, y queda registrada; quien no lo tiene recibe «Transfiérela a la cola del departamento…». Transferir a una cola nunca se bloquea por capacidad.
- **Destinos elegibles** (`TransferDestinations`, `GET /conversations/{id}/destinations`): JSON que solo responde a quien puede asignar o transferir **esa** conversación (404 si no la ve, 403 si la ve pero no puede). Devuelve los departamentos activos autorizados por la conexión y, por departamento, a las personas que podrían ser responsables ahí (cuenta activa —las eliminadas lógicamente quedan fuera—, rol activo que permite ver y responder, miembro del departamento o alcance global), con solo `id`, `name`, `availability` y `at_capacity`, más `allows_exceptions` (si quien consulta puede hacer las excepciones anteriores). Nunca correo, rol, cifras de carga ni conversaciones: un agente no necesita ver las conversaciones de un compañero para elegirlo. El diálogo deshabilita a quien no se puede elegir y muestra la advertencia y la casilla de confirmación cuando corresponde; el servidor vuelve a validar todo bajo bloqueo.
- **Cerrar / Reabrir / Devolver a la cola** respetan la matriz de estados (`pending → open|closed`, `open → pending|closed`, `closed → pending|open`; `on_hold` está retirado: nada entra en él y las filas heredadas solo salen a `open|pending|closed`) y el contador `version`: el cliente envía la versión que vio y una decisión tomada con datos viejos se rechaza.
- **Etiquetas** (`SyncConversationTags`): el responsable, o quien asigna conversaciones, con el permiso «Asignar etiquetas a contactos y conversaciones». Solo se pueden agregar etiquetas activas; las eliminadas que ya tenía pueden quedarse como historial. Se audita (`conversation.tags_changed`) y se emite la señal `tags`. No cambia el estado ni la versión.
- **Notas internas**: separadas de los mensajes; nunca cuentan como respuesta ni tocan la ventana o las fechas de actividad. Al guardarlas o editarlas se emite `ConversationNoteSaved`.
- **`first_response_at`**: se fija cuando se encola la primera respuesta humana (no cuando Meta la acepta). Los reportes calculan sus propias métricas de entrega.
- **Orden de bloqueos** (el mismo en asignación automática, aceptación, asignación, transferencia y devolución a la cola; `ConversationLifecycle`): 1) la conversación; 2) su departamento y, en una transferencia, también el de destino, en orden de id (exclusivo cuando la operación puede repartir esa cola, compartido en los demás casos); 3) el agente (`freshUser`: fila del usuario exclusiva, y lecturas bloqueantes compartidas de su rol y de sus filas de `department_user`, nunca de las filas de `departments`); 4) lecturas compartidas de sus otras conversaciones para contar la carga. El departamento va antes que el usuario porque el evento referencia al departamento y esa clave foránea toma de todos modos un bloqueo compartido sobre su fila: tomado después del agente invertía el orden de la entrada (departamento → agente) y podía bloquear en ciclo una conversación nueva con una aceptación, o dos departamentos automáticos que comparten agente. No hay bloqueos globales: cada operación bloquea solo lo que toca.
- Excepciones documentadas: `ReleaseAssignedConversations` bloquea conversaciones mientras quien la llama tiene la fila del agente o su membresía; y el paso 4 lee conversaciones después de la fila del agente, de modo que mover a otro departamento una conversación del propio agente mientras se le asigna otra puede formar un ciclo. En ambos casos MariaDB detecta el interbloqueo, revierte por completo una de las dos transacciones y la persona recibe una respuesta controlada para reintentar.

### Capacidad

- El máximo de conversaciones en atención (abiertas, más heredadas en espera) de un agente es, por orden de precedencia: **su propio límite** (ficha del usuario en Administración → Usuarios, campo opcional), **el límite por agente del departamento** de la conversación (tarjeta «Distribución de conversaciones» del departamento, campo opcional) y **el límite de la plataforma** (Configuración → Operación). Vacío significa «heredar el siguiente nivel»; sin ninguno, no hay límite.
- La carga se cuenta sobre **todas** sus conversaciones en atención, de cualquier departamento; el límite que se compara es el del departamento de la conversación que recibiría.
- Aceptar y la distribución automática nunca lo superan. Superarlo a mano es la excepción descrita en Asignar y Transferir.
- **Cuando se libera capacidad** la cola se vuelve a ofrecer: al cerrar (a mano o por inactividad), al asignar, transferir o liberar una conversación (para el responsable anterior; al liberar, la conversación liberada no vuelve a esa persona en esa misma pasada), al subir o quitar el límite de la plataforma y al fijar, subir o quitar el límite propio del agente o del departamento (fijar uno donde se heredaba puede subir el límite efectivo). Se hace después de confirmar la operación, solo para departamentos activos y automáticos y agentes elegibles, de forma acotada: como máximo 100 conversaciones por departamento, cada una en su propia transacción corta, y se detiene en la primera que nadie puede tomar. Un fallo se reporta y deja la cola esperando la siguiente ocasión; nunca deshace la operación que liberó el hueco.
- Bajar un límite nunca quita conversaciones ya asignadas; los cambios se aplican en caliente a las siguientes decisiones.

### Errores de las acciones: 403, 404 y 409 (fase 5.2)

Cada operación (aceptar, asignar, transferir, devolver a la cola, cerrar, reabrir, etiquetar, notas, responder,
enviar plantilla) se decide en dos mitades: **permiso y alcance** (`ConversationPolicy::permits()`, sin mirar el
estado) y **estado actual** (`App\Services\Conversations\ConversationStateGuard`, evaluado otra vez bajo bloqueo por
el motor). El orden es fijo:

| Caso                                                                                                                   | Respuesta | Texto (`message`)                                                                                                          |
| ---------------------------------------------------------------------------------------------------------------------- | --------- | -------------------------------------------------------------------------------------------------------------------------- |
| La conversación no es visible (fuera de alcance, inexistente o historial archivado en una ruta operativa)              | **404**   | Sin detalles; la interfaz dice «Esta conversación ya no está disponible para ti. Actualiza la información para continuar.» |
| Visible, pero sin el permiso o el alcance operativo                                                                    | **403**   | «No tienes autorización para realizar esta acción.»                                                                        |
| Visible y es historial **importado** de Whaticket (cualquier operación)                                                | **403**   | «Esta conversación es un historial importado y es de solo consulta.»                                                       |
| Con permiso, pero el estado cambió: `code = state_changed`                                                             | **409**   | «Esta conversación cambió de estado. Actualiza la información para continuar.»                                             |
| Con permiso, pero otro agente la atiende ahora: `code = assigned_to_other`                                             | **409**   | «Otro agente ya está atendiendo esta conversación.»                                                                        |
| Con permiso, pero se cerró: `code = closed`                                                                            | **409**   | «Esta conversación está cerrada. Revisa las opciones disponibles.»                                                         |
| La conversación dejó de ser visible **durante** la petición (se transfirió o reasignó entre la vista y el bloqueo)     | **409**   | `state_changed`, sin detalles de adónde fue                                                                                |
| Otro conflicto que no es de estado (límite alcanzado, otra conversación activa, cambio concurrente): `code = conflict` | **409**   | Su propia explicación (`App\Exceptions\ConversationConflict`, convención de `/api/v1`)                                     |

- **Ser responsable es estado:** a un agente que actúa sobre una conversación que **fue suya** (se la asignaron a
  otro, la transfirieron o la devolvieron mientras la tenía abierta) se le responde 409 con lo que pasó, no 403. A
  quien nunca la atendió (no hay ningún evento que se la quitara) y no la administra, 403.
- Una decisión tomada sobre una versión vieja de la conversación (`version`) responde 409 con la explicación más
  específica posible.
- Orden dentro del motor (`ConversationLifecycle::authorize()`, bajo bloqueo): (1) sigue siendo visible (si no, 409
  `state_changed`; aceptar tiene su propia regla de visibilidad); (2) permiso y alcance (403); (3) versión (409);
  (4) estado (409, o 403 si nunca fue suya).
- **Responsable de su propia conversación:** responder, cerrar y devolver a Pendientes solo exigen ser el responsable
  (más el permiso), como antes de la fase 5.2; los demás necesitan el alcance operativo. Si sigue abierta y a su
  cargo lo decide la guarda de estado.
- JSON: `{code, message}` (`App\Exceptions\ConversationStateConflict`, códigos de `App\Enums\ConversationConflict`).
  Inertia: el mensaje en `errors.conversation` y el código en `errors.conversation_conflict`; un 403 en una visita
  Inertia muestra el texto en un aviso y recarga la vista con los permisos vigentes.
- Interfaz: `resources/js/lib/conversations/actionErrors.ts` (`conversationActionErrorMessage(status, body)`,
  `isConversationConflict(status)`) lo usan `ConversationActions`, la aceptación, los diálogos de motivo y de destino
  y el compositor. Ante un 409 se muestra el mensaje y se recarga la conversación para que aparezca su estado real.
- Pruebas: `tests/Feature/Conversations/ConversationConflictTest.php`, `ImportedConversationAccessTest.php`.
  Decisión: [ADR-INBOX-13](decisiones.md#adr-inbox-13--permiso-y-estado-separados-403-para-permisos-409-para-estado).

### Conversaciones importadas de Whaticket (fase 5.2)

- Destino «Importar»: conversación cerrada visible en el filtro «Cerradas», la búsqueda, las exportaciones y la ficha
  del contacto (con la etiqueta de origen). Sus mensajes muestran «Importado de Whaticket» y el remitente original,
  sin palomitas ni «Enviado». No tiene compositor ni acciones (403 con el texto de historial importado), no entra en
  métricas, no abre la ventana de 24 h y nunca recibe mensajes nuevos: el siguiente mensaje del cliente abre o
  continúa una conversación en vivo.
- Destino «Archivar» (`history_only_at`): fuera de la bandeja, la búsqueda, las exportaciones, los contadores y la API;
  cualquier ruta operativa responde 404. Se consulta en Administración → «Historial importado» con
  `history-import.archive-view`; la ficha del contacto y el panel de detalles de una conversación en vivo del mismo
  contacto enlazan a ese historial («Ver en el historial importado») solo para quien tiene el permiso.
- Detalle completo: [migracion-whaticket.md §Destinos](migracion-whaticket.md#destinos).

## 5. Ausencias y cambios de acceso

- **No hay disponibilidad manual** (fase 5): se retiraron el interruptor «Disponible / No disponible», su ruta y su señal. Aceptar, asignar, transferir, la distribución automática y los avisos de cola dependen solo de cuenta activa, rol activo, permiso, pertenencia al departamento, departamento activo y límite de carga (más el horario donde ya aplicaba). Las columnas `users.availability*` se conservan sin uso operativo. Panel y reportes muestran «Agentes activos».
- **«En espera» está retirado** (fase 5): sin rutas `hold`/`resume`, sin acciones ni filtros. El caso `on_hold` sigue en el enum solo para filas antiguas, que cuentan como «en atención» para carga y métricas hasta migrarlas con `php artisan conversations:retire-on-hold` (simulación por defecto; `--apply` escribe): si su agente aún puede atenderla vuelve a «En atención» (evento «Retomada», `metadata.reason = on_hold_retired`); si no, a la cola por el camino normal (distribución automática incluida). El motivo de espera se conserva en el evento y en una nota interna sin autor. Después conviene `php artisan analytics:rebuild`.
- Cuando un agente **pierde el acceso** (usuario eliminado, cambio de rol que ya no permite atender, retiro de un departamento, o se quita «responder» al rol que tiene: `SaveRole` libera a cada titular en su propia transacción después de guardar el rol), `ReleaseAssignedConversations` devuelve sus conversaciones no cerradas a la cola pasando por el motor: bloquea cada conversación por separado (en orden de id), revisa de nuevo bajo bloqueo, aplica la matriz de estados y la versión, y registra «Devuelta a la cola» con el departamento y el motivo (`trigger: access_change`). Cuando la transacción del cambio de acceso se confirma, las de departamentos automáticos se ofrecen a otro agente elegible, cada una en su propia transacción. Las colas compartidas o manuales simplemente esperan.

## 6. Endpoints del módulo

| Método y ruta                                          | Uso                                                                                                                                |
| ------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------- |
| `GET /conversations` (`?c=`, filtros, `search_in`)     | Bandeja (Inertia `conversations/Index`); la primera página llega con `next_cursor`.                                                |
| `GET /conversations/page` (`after`, `limit` 1–500)     | JSON: siguientes páginas de la lista por cursor, con los mismos filtros (120/min).                                                 |
| `GET /conversations/{id}`                              | Redirige a la bandeja con la conversación abierta.                                                                                 |
| `GET /conversations/{id}/destinations`                 | JSON de destinos elegibles para asignar o transferir (ver sección 4).                                                              |
| `GET /conversations/{id}/timeline`                     | JSON: página más reciente, `before` (+ `until`), `after_message`/`after_note`/`after_event`, o `messages[]`/`notes[]` para releer. |
| `POST /conversations/{id}/read`                        | Avanza tu marcador de lectura.                                                                                                     |
| `PUT /conversations/{id}/tags`                         | Reemplaza las etiquetas (`tag_ids`).                                                                                               |
| `POST /conversations/{id}/messages`                    | Respuesta: `body`, `client_reference`, `attachment` (archivo) y `reply_to_message_id` opcionales.                                  |
| `GET /conversations/{id}/messages/{m}/attachments/{a}` | Archivo autorizado (en línea para imágenes, PDF, audio y video seguros; `?download=1` lo descarga).                                |
| `POST /conversations/{id}/notes`, `PUT …/notes/{n}`    | Notas internas (JSON si se pide con `Accept: application/json`).                                                                   |

## 7. Archivos adjuntos

- **Tipos y límites por canal:** la matriz vigente está en
  [mensajeria-whatsapp.md §4.2](mensajeria-whatsapp.md#42-saliente) (`ChannelAttachmentPolicy`). En WhatsApp: imágenes
  JPEG/PNG 5 MB; audio AAC, AMR, MP3, M4A y OGG (solo Opus mono) 16 MB, sin texto; video MP4/3GPP 16 MB; documentos
  PDF, TXT y Office 100 MB (`WHATSAPP_DOCUMENT_MAX_BYTES`); ZIP solo como enlace privado.
- **Menú del clip (fase 5.2).** El clip abre un menú con las categorías que el canal admite, en el orden de WhatsApp:
  **Fotos e imágenes, Videos, Documentos y Audio**. Cada una abre el selector de archivos con su propio filtro
  (`accept`) y su máximo; se cierra con un clic fuera o con Escape, se recorre con flechas y el foco vuelve al clip.
  Un archivo elegido en otra categoría (una foto en «Videos») se rechaza con un texto propio de la categoría, nunca se
  envía como otro tipo.
- **Vista previa** antes de enviar: miniatura (imagen), primer fotograma (video) o icono, con nombre, tamaño, tipo y
  «Se envía como archivo»; se puede quitar. Durante la subida se ve el progreso real y un segundo envío se ignora.
- **Duplicados:** elegir otra vez el mismo archivo no lo agrega de nuevo; si ese mismo archivo ya se envió en la
  conversación, se avisa («Ya enviaste este mismo archivo en esta conversación. Revisa que no sea un duplicado antes de
  enviarlo.»).
- **ZIP:** en WhatsApp, Messenger e Instagram solo se envía con la acción explícita **«Enviar como enlace privado
  temporal»** (texto con un enlace firmado que vence; ver [mensajeria §4.2](mensajeria-whatsapp.md#42-saliente)); el
  chat web lo lleva como archivo.
- **Errores claros:** demasiado grande, tipo no admitido, categoría equivocada, almacenamiento no disponible (503; el
  clip se desactiva con «Por ahora no se pueden adjuntar archivos…» y el texto sigue funcionando) y conversación que
  cambió mientras tanto (409, §4 «Errores de las acciones»).
- **Adjuntos no disponibles:** «Archivo no disponible» (expirado, rechazado o fallido) y «Archivo histórico no
  disponible» (importado sin archivo en el ZIP), sin enlace de descarga.
- El tipo real se detecta con `finfo` a partir del contenido; el nombre y el tipo que declara el navegador no cuentan. Un ejecutable o una página HTML renombrados se rechazan.
- El audio de WhatsApp no admite texto; el texto de un archivo admite hasta 1024 caracteres.
- Se guardan en el disco privado `filesystems.media_disk` (`MEDIA_DISK`: `local`, es decir `storage/app/private`, o un
  bucket S3 privado; [infraestructura §Almacenamiento de adjuntos](infraestructura-y-despliegue.md#almacenamiento-de-adjuntos-fase-52))
  en `media/{aaaa}/{mm}/{uuid}`, sin extensión ni nombre original en la ruta. El nombre visible se limpia de rutas y caracteres reservados. Se guarda el SHA-256. Cada escritura se verifica antes de registrar el archivo.
- Si el envío se rechaza después de guardar el archivo, el archivo se borra. Un reintento con la misma `client_reference` no guarda otro archivo.
- Se sirven **siempre a través de la aplicación** (también desde S3: se transmiten por la ruta autorizada, nunca con una URL prefirmada) con `X-Content-Type-Options: nosniff`, `Content-Disposition` (en línea solo para tipos seguros; el resto se descarga como `application/octet-stream`), `Cache-Control: private` y una CSP con `sandbox` (salvo PDF, para que el visor del navegador funcione).
- Si el archivo falta en disco, la respuesta es 404 y la interfaz muestra «ya no está disponible».

## 8. Permisos relacionados

`conversations.view`, `conversations.reply`, `conversations.accept`, `conversations.assign`, `conversations.transfer`, `conversations.close`, `conversations.reopen`, `conversations.history`, `conversations.notes`, `tags.assign` (contactos y conversaciones), `quick-replies.use`, `reports.export`. Desde la estabilización, la definición del rol Agente incluye `tags.view` y `tags.assign` (solo en las conversaciones de las que es responsable; `ConversationPolicy::tag`) y la del Supervisor `tags.assign`; los roles ya existentes se ponen al día con `php artisan app:sync-permissions --upgrade-default-roles --apply` (ver [seguridad-y-auditoria.md §3](seguridad-y-auditoria.md#3-modelo-de-permisos)). La excepción de asignar o transferir a alguien al límite exige `conversations.assign`; el límite propio del agente se edita con `users.update` y el del departamento con `departments.update`.

## 9. Limitaciones conocidas

- La entrega real de multimedia depende del módulo de mensajería (subida a Meta en el job de entrega). Sin credenciales de Meta, los mensajes con archivo quedan en cola o fallan con el motivo que registre ese job; el transporte simulado de desarrollo los marca como **No enviado (modo de prueba)**.
- El selector de plantillas y el botón Reintentar llaman a endpoints del módulo de mensajería (`GET/POST conversations/{id}/templates`, `POST conversations/{id}/messages/{m}/retry`); el diálogo usa la estructura y las variables que devuelve ese módulo (`structure`, `parameters`, `sendable`).
- Búsqueda: sin subcadenas dentro de una palabra ni sufijos de teléfono; la búsqueda en mensajes no muestra el fragmento que coincide ni busca en notas (ver [Búsqueda y paginación](#búsqueda-y-paginación)).
- La ordenación por prioridad con `status=all` no usa índice (filesort); medido en ~3 ms con 8 000 conversaciones.
- Interbloqueos residuales documentados en el orden de bloqueos (§4): MariaDB los detecta, revierte una transacción completa y la persona recibe una respuesta controlada.
- El diálogo de transferencia no tiene pruebas automáticas de componente (el proyecto no tiene pruebas de componentes Vue); lo cubren el backend, `vue-tsc` y el escenario E2E `estabilizacion.mjs`.

## Supervisión (solo lectura)

`conversations.supervise` («Supervisar conversaciones de su alcance (solo lectura)», `ConversationAccess`):

- Dentro del alcance del rol muestra todas las conversaciones sin importar responsable ni estado, incluidas las
  cerradas y su historial (sin necesitar `conversations.history`).
- Con alcance por departamentos, además, las que **pasaron** por alguno de sus departamentos (cualquier evento con
  `from_department_id` o `to_department_id` suyo), aunque luego se transfirieran fuera: ya no se pierden de vista tras
  una transferencia. En la consulta se resuelve como semi-join no correlacionado
  (`conversations.id IN (… from_department_id IN … UNION … to_department_id IN …)`, cada rama por su índice) y, en la
  lista, el paso de las filas de la página se carga con una sola consulta (`ConversationAccess::rememberPassages`), sin
  migración nueva. Con alcance global no cambia nada (ya ve todo); con alcance «asignadas» no amplía nada.
- Nunca concede operar. `ConversationAccess` separa dos alcances: el **de lectura** (`canView`/`restrictToVisible`:
  `view`, `viewHistory`, `supervise`, línea de tiempo, mensajes, adjuntos y la lista), que incluye la ampliación de
  supervisión, y el **operativo** (`canOperate`), que conserva las reglas sin supervisión (sus departamentos; cerradas
  solo con `conversations.history`) y usan todas las habilidades que modifican: asignar, transferir, cerrar, reabrir,
  notas, etiquetas, devolver a la cola, responder y plantillas (también al reautorizar bajo bloqueo, que repite las
  mismas habilidades). Así un Supervisor ve la conversación transferida fuera de su departamento, o una cerrada sin
  permiso de historial, pero no puede operar en ella. El alias y la apariencia del chat solo exigen `view`: son
  privados del usuario y no tocan la conversación. El recurso expone `can.supervise`.
- Las insignias operativas de la lista (departamento, responsable, conexión; `viewer.can_supervise`) se muestran con
  `conversations.supervise`, con alcance global y también con `conversations.assign`: quien reparte trabajo dentro de
  sus departamentos necesita ver a qué departamento y a quién está asignada cada fila para asignar o transferir. Las
  filas llevan esos datos para todos (el panel de detalles los usa); la decisión de mostrarlos es la del visor.
- La aplicación es de una sola organización (no hay concepto de tenant): «global» es toda la instalación.
