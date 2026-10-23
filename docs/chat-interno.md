# Chat interno entre empleados (Incremento D)

Mensajería privada del equipo: chats uno a uno y grupos, con archivos, contadores de no leídos, búsqueda, notificaciones
y actualización en tiempo real. Es **independiente de las conversaciones con clientes**: tiene sus propias tablas, sus
mensajes nunca se envían a Meta/WhatsApp y nunca aparecen en el historial de un cliente, en reportes de atención ni en
exportaciones.

Página: **Chat interno** (`/team-chat`), en el menú lateral, sección _Equipo_, visible con `team-chat.use`. El menú
muestra el total de mensajes sin leer (los chats silenciados no suman).

## Modelo de datos

| Tabla                    | Contenido                                                                                                                                                        | Notas                                                                                                                                                      |
| ------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `team_threads`           | `type` (`direct`/`group`), `name`, `description`, `direct_key`, `created_by_user_id`, `archived_at`, `archived_by_user_id`, `last_message_id`, `last_message_at` | `direct_key` = `"{idMenor}:{idMayor}"`, único: **un solo chat privado por pareja**, también si ambos lo abren a la vez. Los hilos nunca se borran.         |
| `team_participants`      | `team_thread_id`, `user_id`, `role` (`member`/`admin`), `joined_at`, `left_at`, `added_by_user_id`, `removed_by_user_id`, `last_read_message_id`, `muted`        | Único por hilo y persona. Salir o ser retirado pone `left_at` (la fila queda como historial); volver a entrar la reutiliza.                                |
| `team_messages`          | `team_thread_id`, `user_id`, `kind` (`message`/`system`), `body`, `metadata`, `reply_to_message_id`, `edited_at`, `deleted_at`                                   | Nunca se borran físicamente. `system` registra cambios del grupo visibles para todos.                                                                      |
| `team_attachments`       | `team_message_id`, `disk`, `path`, `original_name`, `mime_type`, `kind`, `size_bytes`, `sha256`, `purged_at`                                                     | El archivo vive en disco privado; la base solo guarda metadatos.                                                                                           |
| `team_message_reactions` | `team_message_id`, `user_id`, `emoji`                                                                                                                            | Único por mensaje, persona y emoji (reaccionar dos veces no duplica). `emoji` con intercalación binaria (`utf8mb4_bin`). Se borran al eliminar el mensaje. |

Todas las llaves foráneas son `restrictOnDelete`/`nullOnDelete`: no hay borrados en cascada. Migraciones:
`2026_10_22_025230..025233_create_team_*_table.php` y `2026_10_22_162857_create_team_message_reactions_table.php`
(aditivas). Las menciones se guardan en `team_messages.metadata.mentions` (ids), sin tabla propia.

Código: `app/Models/TeamChat`, `app/Actions/TeamChat`, `app/Services/TeamChat`, `app/Policies/TeamChat`,
`app/Http/Controllers/TeamChat`, `app/Http/Requests/TeamChat`, `app/Events/TeamChat`, `app/Listeners/TeamChat`,
`app/Broadcasting/TeamChat`, `routes/team-chat.php`, `routes/channels/team-chat.php`, `config/team-chat.php`,
`resources/js/pages/team-chat`, `resources/js/components/team-chat`, `resources/js/types/team-chat.ts`.

## Permisos

| Permiso                      | Significado                                                                                                                                                                              |
| ---------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `team-chat.use`              | Usar el chat interno: ver sus propios chats, escribir, iniciar chats privados. Sin él no se ve el módulo ni se recibe nada (aunque siga figurando como participante).                    |
| `team-chat.create-groups`    | Crear grupos (quien lo crea queda como administrador del grupo).                                                                                                                         |
| `team-chat.manage-groups`    | Administrar **los grupos en los que participa** como si fuera administrador del grupo (renombrar, participantes, administradores, archivar/reactivar). **No** da acceso a grupos ajenos. |
| `team-chat.cross-department` | Iniciar chats y agregar a grupos a personas de otros departamentos.                                                                                                                      |

**Alcance de contacto** (`TeamChatDirectory`): solo cuentas activas con `team-chat.use`. Con alcance global o con
`team-chat.cross-department` se llega a cualquiera; si no, solo a quien comparte al menos un departamento. Esto
gobierna iniciar chats y agregar personas; un hilo existente sigue disponible para sus participantes actuales.

**Roles predeterminados** (solo instalaciones nuevas, vía `DefaultRole`; `SyncRolesAndPermissions` nunca modifica roles
existentes): Superadministrador y Administrador tienen todos; Supervisor tiene los cuatro; Agente tiene
`team-chat.use`. En una instalación existente, los roles Supervisor/Agente deben recibir estos permisos desde
**Roles y permisos** (los permisos ahora aparecen disponibles en la matriz).

## Seguridad y privacidad

- **Solo los participantes actuales** acceden a un hilo, sus mensajes, sus archivos y su canal de tiempo real
  (`TeamThreadPolicy`). Para cualquier otra persona la respuesta es **404**, idéntica a la de un hilo inexistente (no se
  confirma que exista). Mensajes y adjuntos se resuelven con _scoped bindings_: un mensaje de otro hilo no se alcanza
  aunque se participe en el hilo de la URL.
- **El Superadministrador no tiene acceso automático** a chats ajenos: no existe una habilidad "ver todo", ni consulta
  oculta, ni exportación de chats. No hay vigilancia invisible. Si en el futuro se requiere un acceso excepcional
  (p. ej. requerimiento legal), deberá diseñarse como flujo explícito con finalidad, autorización específica,
  aviso y auditoría; hoy no existe.
- **Retirados del grupo / quien sale**: pierden el acceso de inmediato, también al historial y a los archivos (decisión
  ADR-TC-2). Si vuelven a ser agregados recuperan el historial completo del grupo y solo cuentan como no leídos los
  mensajes posteriores a su reingreso.
- **Cuentas eliminadas** (eliminación lógica): se muestran como «Usuario eliminado», no se ofrecen en los selectores,
  no pueden agregarse a grupos ni nombrarse administradores, y un chat privado con una cuenta eliminada queda en solo
  lectura.
- **Búsqueda**: solo en hilos donde la persona participa hoy; nunca devuelve ni cuenta coincidencias de otros hilos.
  Mensajes eliminados y líneas de sistema no se buscan. Los comodines `%`/`_` se escapan. Devuelve las 20 coincidencias
  más recientes y `has_more` cuando hay más (la interfaz pide acotar la búsqueda).
- **Envíos tras una expulsión o un archivado**: `PostTeamMessage` (y añadir una reacción) vuelve a evaluar la política `post` dentro de su
  transacción, con la fila del hilo bloqueada (`lockForUpdate`, el mismo bloqueo que toman retirar, salir y archivar).
  Si la persona dejó de participar entre la autorización de la petición y el guardado responde 404; si el grupo se
  archivó, 403. No queda mensaje ni archivo.
- **Menciones y reacciones**: solo para participantes actuales de hilos que aceptan mensajes. El servidor descarta
  menciones a quien no participa (o al propio autor); nunca se notifica a terceros ni se revela el chat.
- **Presencia**: cada canal de presencia solo reúne a personas que pueden chatear entre sí según `TeamChatDirectory`
  y solo publica el id de cada miembro (sin nombre ni disponibilidad). Ver «Tiempo real».
- **Selector de personas**: expone solo nombre, departamentos y disponibilidad (sin correo).
- Texto plano: los mensajes se muestran como texto (saltos de línea preservados), nunca como HTML.

## Funciones

- **Chats privados**: «Nuevo → Chat privado». Si ya existía, se abre el mismo.
- **Grupos**: nombre (2–80), descripción opcional (≤500), participantes (máximo `TEAM_CHAT_GROUP_MAX_PARTICIPANTS`,
  100 por omisión). Administradores del grupo (o quien tenga `manage-groups` y participe): renombrar, agregar, retirar,
  nombrar/quitar administradores, archivar y reactivar. Un grupo siempre conserva un administrador: si el último sale o
  es retirado, el miembro más antiguo activo pasa a serlo (línea de sistema visible). Si todos salen, el grupo se archiva.
- **Archivar ≠ eliminar**: un grupo archivado conserva el historial para sus participantes, no acepta mensajes y puede
  reactivarse. Los hilos nunca se eliminan.
- **Mensajes**: texto multilínea (≤ `TEAM_CHAT_MESSAGE_MAX_LENGTH`, 4000), Enter envía y Shift+Enter agrega línea,
  emojis rápidos, responder a un mensaje, editar los propios (marca «Editado») y eliminarlos.
- **Menciones (@)**, solo en grupos: al escribir `@` el compositor sugiere a los demás participantes activos (flechas,
  Enter/Tab para elegir, Esc para cerrar). Se envían los **ids** elegidos (`mentions[]`, máximo `max_mentions` = 20),
  no se interpreta el texto por nombre porque los nombres no son únicos; antes de enviar se descartan las menciones
  cuyo `@Nombre` ya no está en el texto. El servidor conserva solo participantes activos distintos del autor; en un
  chat privado responde 422. El mencionado recibe una notificación urgente (`TeamChatMention`) en lugar de la normal,
  aunque haya silenciado el grupo; la notificación se deduplica por mensaje, así que un reintento del aviso no la
  repite. **Editar un mensaje no cambia sus menciones ni vuelve a notificar.** La burbuja resalta los `@Nombre` como
  texto (nunca HTML).
- **«Escribiendo…»**: mientras se escribe, el compositor envía como máximo una señal cada 3 s
  (`POST threads/{thread}/typing`, 30/min). El servidor autoriza cada llamada con la política `post` y emite
  `.typing` {user_id} en el canal del hilo, con el autor que pone el servidor (no el cliente). No se guarda nada; el
  aviso desaparece a los 5 s sin señales o al llegar el mensaje de esa persona. Se eligió una señal del servidor en
  lugar de un _whisper_ (evento de cliente) porque en un canal privado Reverb no garantiza la identidad del emisor y
  habilitar eventos de cliente los abriría en todos los canales privados; `config/reverb.php` sigue sin aceptar
  eventos de cliente. Es la única señal que se publica de forma síncrona durante la petición: si Reverb falla, la
  petición sigue respondiendo 204 (el envío ocurre dentro de `rescue`).
- **Publicar bajo bloqueo**: `PostTeamMessage` y añadir una reacción bloquean la fila del hilo (y, en la reacción,
  también la del mensaje) y vuelven a evaluar la política dentro de la transacción. Un mensaje enviado justo después
  de una expulsión responde 404 y uno enviado tras un archivado 403, sin dejar mensaje ni archivo; no se puede
  reaccionar a un mensaje que se eliminó a la vez.
- **Reacciones**: lista blanca `team-chat.reactions` (👍 ❤️ 😂 😮 😢 🙏 ✅ 👀). Botón «Reaccionar» en cada mensaje y
  chips con el contador (la propia resaltada, nombres al pasar el cursor); pulsar un chip propio la retira. Añadir y
  retirar son idempotentes (llave única + `insertOrIgnore`), no notifican ni se auditan, y emiten
  `thread.changed` {reason: message.updated, message_id} para refrescar el mensaje. No se puede reaccionar a mensajes
  eliminados o de sistema ni en grupos archivados.
- **Eliminar un mensaje** (solo su autor): borra su texto y sus archivos para todos, de forma irrecuperable; queda la
  marca «Mensaje eliminado» para no romper el orden ni las respuestas (ADR-TC-3).
- **Historial**: paginación por cursor (`before`/`after`, 30 por página) estable aunque lleguen mensajes nuevos;
  `?around={id}` abre el hilo centrado en un mensaje (resultados de búsqueda, saltar a la respuesta).
- **No leídos**: por hilo y total (badge del menú y encabezado). El marcador de lectura solo avanza; se actualiza al
  ver el final del chat con la pestaña visible. Los mensajes propios, los de sistema y los eliminados no cuentan.
  Silenciar un chat lo excluye del badge global (sigue mostrando su contador en la lista).
- **«Visto»**: en chats privados, bajo tu último mensaje cuando la otra persona ya lo leyó.
- **Disponibilidad**: punto de estado con la disponibilidad declarada por la persona (Disponible/No disponible, la misma
  de la atención de conversaciones). Es un estado operativo, no de conexión.
- **En línea**: anillo alrededor del avatar (y «En línea» en el encabezado del chat privado y en la información del
  grupo) cuando la persona tiene la aplicación abierta en cualquier página. Es independiente de la disponibilidad: se
  puede estar en línea y «No disponible». Sin conexión en tiempo real no se muestra a nadie en línea.
- **Filtros** de la lista: Todos, No leídos, Privados, Grupos, Archivados (se recuerda el último en el navegador).
- **Notificaciones**: cada mensaje emite `TeamMessagePosted`; el módulo de notificaciones
  (`App\Listeners\Notifications\NotifyTeamChatMessage`) avisa a los demás participantes activos que no silenciaron el
  hilo. Ver [notificaciones-y-horarios.md](notificaciones-y-horarios.md).

## Adjuntos

- Disco privado `TEAM_CHAT_DISK` (por omisión `local` = `storage/app/private`), ruta `team-chat/{aaaa}/{mm}/{uuid}`
  sin extensión ni nombre original. Compatible con cualquier disco privado tipo S3.
- Validación del **contenido real** con `finfo` (nunca el tipo que declara el navegador) y coherencia con la extensión.
  Permitidos: JPEG, PNG, GIF, WebP, PDF, TXT/LOG, CSV, Word/Excel/PowerPoint (97 y OOXML), OpenDocument texto/hoja,
  MP3, OGG, M4A y MP4. Nunca HTML, SVG, scripts ni ejecutables. Los OOXML/ODF que `finfo` reporta como ZIP se aceptan solo
  si contienen el manifiesto de su formato.
- Límites: `TEAM_CHAT_MAX_KILOBYTES` (10 240 KB) por archivo y `TEAM_CHAT_MAX_FILES` (5) por mensaje.
- Descarga solo por `GET team-chat/threads/{thread}/messages/{message}/attachments/{attachment}`, que vuelve a autorizar.
  `Content-Disposition: attachment` salvo `?inline=1` para imágenes seguras y PDF; siempre
  `X-Content-Type-Options: nosniff`, `Cache-Control: private, no-store`, `Referrer-Policy: no-referrer` y CSP con `sandbox` (salvo la vista previa de PDF, porque los navegadores no muestran un PDF dentro de un documento aislado). No hay URLs públicas ni
  permanentes. Nombre mostrado saneado (sin rutas, comillas ni caracteres de control). SHA-256 guardado.
- Si el archivo falta en disco, la descarga responde 404 y se registra una advertencia (sin datos del archivo).
- Al eliminar el mensaje el archivo se borra del disco (después del commit) y el adjunto queda con `purged_at`.
- Respaldo: incluir `storage/app/private/team-chat` (o el bucket configurado) junto con la base de datos.

## Rutas (todas bajo `auth` + `verified`)

| Método          | Ruta                                                                      | Uso                                                             |
| --------------- | ------------------------------------------------------------------------- | --------------------------------------------------------------- |
| GET             | `/team-chat`                                                              | Página con la lista (`search`, `filter`, `limit`)               |
| GET             | `/team-chat/threads/{thread}`                                             | Página con el hilo abierto (`around` opcional)                  |
| GET             | `/team-chat/people`                                                       | JSON: personas contactables (`search`, `exclude_thread`)        |
| GET             | `/team-chat/search?q=`                                                    | JSON: mensajes en hilos propios (60/min)                        |
| POST            | `/team-chat/direct`                                                       | Abrir/crear chat privado (`user_id`)                            |
| POST            | `/team-chat/groups`                                                       | Crear grupo                                                     |
| PUT             | `/team-chat/threads/{thread}`                                             | Renombrar / descripción                                         |
| POST            | `/team-chat/threads/{thread}/archive` · `/unarchive` · `/leave`           | Archivar, reactivar, salir                                      |
| PUT             | `/team-chat/threads/{thread}/mute`                                        | Silenciar (`muted`)                                             |
| POST            | `/team-chat/threads/{thread}/read`                                        | Avanzar marcador (`message_id`), responde el total sin leer     |
| POST/PUT/DELETE | `/team-chat/threads/{thread}/participants[/{user}]`                       | Agregar / rol / retirar                                         |
| GET/POST        | `/team-chat/threads/{thread}/messages`                                    | JSON: historial (`before`, `after`, `ids[]`) / enviar (120/min) |
| PUT/DELETE      | `/team-chat/threads/{thread}/messages/{message}`                          | Editar / eliminar propio                                        |
| PUT/DELETE      | `/team-chat/threads/{thread}/messages/{message}/reactions`                | JSON: añadir / retirar la reacción propia (`emoji`, 120/min)    |
| POST            | `/team-chat/threads/{thread}/typing`                                      | Señal «escribiendo…» (204, 30/min, no se guarda)                |
| GET             | `/team-chat/threads/{thread}/messages/{message}/attachments/{attachment}` | Descarga autorizada                                             |

## Tiempo real

Canales (`routes/channels/team-chat.php`, autorizados en cada suscripción contra datos frescos):

- `team-chat.threads.{id}` — participantes actuales con `team-chat.use` (`TeamThreadChannel`). Eventos:
  `.message.created` {message_id}, `.thread.changed` {reason, message_id?}, `.read` {user_id, message_id},
  `.typing` {user_id} (efímero, emitido al momento por el servidor).
- `users.{id}` — `.team-chat.changed` {thread_id, reason}: refresca lista y badge.
- Presencia (`TeamPresenceChannel`, `TeamDepartmentPresenceChannel`), siempre con `team-chat.use` y cuenta activa:
  `presence-team` para quien llega a todos (alcance global o `team-chat.cross-department`) y
  `presence-team.departments.{id}` para los miembros de cada departamento. Así cada canal solo reúne a personas que
  pueden chatear entre sí y nadie conoce a usuarios fuera de su alcance. La carga útil de cada miembro es solo
  `{id}`. El servidor publica la lista de canales del usuario en la prop compartida `realtime.presence_channels`;
  `useTeamPresenceConnection()` (montado en `AppLayout`) se une a ellos en todas las páginas con
  `joinPresence()` de `resources/js/lib/realtime`, y `useTeamPresence().isOnline(id)` lo consulta.

Las cargas útiles son solo IDs (nunca texto ni nombres de archivo). Flujo: acción → evento de dominio
(`TeamMessagePosted`, `TeamThreadChanged`, `TeamThreadRead`, despachados después del commit) →
`BroadcastTeamChatActivity` → `TeamThreadSignal` / `TeamChatUserSignal` (cola `realtime`) → el cliente vuelve a
pedir datos a endpoints que autorizan otra vez. Los cambios de membresía emiten además `UserAccessChanged`
(`team-chat.membership`) para que el cliente re-autorice sus canales. Sin Reverb, la lista se refresca cada 20 s y el
chat abierto consulta mensajes nuevos cada 15 s; al reconectar se recupera lo perdido. Ver [tiempo-real.md](tiempo-real.md).

## Auditoría

Se auditan las operaciones de administración de grupos: `team_chat.group_created`, `group_updated` (cambios de nombre
y descripción), `participants_added`, `participant_removed`, `participant_role_changed`, `group_archived`,
`group_unarchived`. Nunca se audita el contenido de mensajes. Los chats privados, enviar, editar, eliminar mensajes,
leer y silenciar no se auditan (son actividad privada, no administración). Las acciones del Superadministrador quedan
restringidas como en el resto del sistema.

## Limitaciones y pendientes

- **Presencia parcial para quien llega a todos**: una persona con alcance global o `team-chat.cross-department` ve en
  línea a quienes también llegan a todos y a los miembros de sus propios departamentos, pero no a personas de otros
  departamentos que no llegan a todos (unirse a esos canales la expondría ante quien no puede contactarla). Para
  ellas no se muestra el indicador, lo que no significa que estén desconectadas. Tampoco se ve en línea a un
  participante de un grupo que no comparte canal de presencia con quien mira.
- La presencia se conoce por pestaña conectada: con varias pestañas abiertas, la persona sigue en línea hasta cerrar
  la última; Reverb tarda unos segundos en notar una desconexión abrupta.
- La búsqueda de mensajes usa `LIKE` acotado a los hilos propios (sin índice de texto completo) y muestra como máximo
  20 resultados; si hay más, lo avisa (`has_more`) y pide acotar la búsqueda.
- Un Reverb lento puede retrasar la petición de «escribiendo…» hasta el _timeout_ del cliente HTTP del broadcaster
  (un Reverb caído no la hace fallar).
- No hay pruebas de navegador del autocompletado de menciones ni del indicador en línea (el proyecto no instala
  `pest-plugin-browser`); los cubren pruebas Pest del servidor y la prueba manual.
- La lista carga 50 hilos y amplía de 50 en 50 hasta un máximo de 500 (`limits.maxThreads`); al llegar al máximo se
  oculta «Ver más chats» y se pide usar la búsqueda o los filtros.
- Sin vista previa de video/audio en línea (se descargan).
- No se ofrece exportación de chats internos (decisión de privacidad).
- No hay retención automática por antigüedad: los mensajes se conservan hasta que su autor los elimina.
