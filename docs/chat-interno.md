# Chat interno entre empleados (Incremento D)

Mensajería privada del equipo: chats uno a uno y grupos, con archivos, contadores de no leídos, búsqueda, notificaciones
y actualización en tiempo real. Es **independiente de las conversaciones con clientes**: tiene sus propias tablas, sus
mensajes nunca se envían a Meta/WhatsApp y nunca aparecen en el historial de un cliente, en reportes de atención ni en
exportaciones.

Página: **Chat interno** (`/team-chat`), en el menú lateral, sección _Equipo_, visible con `team-chat.use`. El menú
muestra el total de mensajes sin leer (los chats silenciados no suman).

## Modelo de datos

| Tabla               | Contenido                                                                                                                                                        | Notas                                                                                                                                              |
| ------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- |
| `team_threads`      | `type` (`direct`/`group`), `name`, `description`, `direct_key`, `created_by_user_id`, `archived_at`, `archived_by_user_id`, `last_message_id`, `last_message_at` | `direct_key` = `"{idMenor}:{idMayor}"`, único: **un solo chat privado por pareja**, también si ambos lo abren a la vez. Los hilos nunca se borran. |
| `team_participants` | `team_thread_id`, `user_id`, `role` (`member`/`admin`), `joined_at`, `left_at`, `added_by_user_id`, `removed_by_user_id`, `last_read_message_id`, `muted`        | Único por hilo y persona. Salir o ser retirado pone `left_at` (la fila queda como historial); volver a entrar la reutiliza.                        |
| `team_messages`     | `team_thread_id`, `user_id`, `kind` (`message`/`system`), `body`, `metadata`, `reply_to_message_id`, `edited_at`, `deleted_at`                                   | Nunca se borran físicamente. `system` registra cambios del grupo visibles para todos.                                                              |
| `team_attachments`  | `team_message_id`, `disk`, `path`, `original_name`, `mime_type`, `kind`, `size_bytes`, `sha256`, `purged_at`                                                     | El archivo vive en disco privado; la base solo guarda metadatos.                                                                                   |

Todas las llaves foráneas son `restrictOnDelete`/`nullOnDelete`: no hay borrados en cascada. Migraciones:
`2026_10_22_025230..025233_create_team_*_table.php` (aditivas).

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
  Mensajes eliminados y líneas de sistema no se buscan. Los comodines `%`/`_` se escapan.
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
- **Eliminar un mensaje** (solo su autor): borra su texto y sus archivos para todos, de forma irrecuperable; queda la
  marca «Mensaje eliminado» para no romper el orden ni las respuestas (ADR-TC-3).
- **Historial**: paginación por cursor (`before`/`after`, 30 por página) estable aunque lleguen mensajes nuevos;
  `?around={id}` abre el hilo centrado en un mensaje (resultados de búsqueda, saltar a la respuesta).
- **No leídos**: por hilo y total (badge del menú y encabezado). El marcador de lectura solo avanza; se actualiza al
  ver el final del chat con la pestaña visible. Los mensajes propios, los de sistema y los eliminados no cuentan.
  Silenciar un chat lo excluye del badge global (sigue mostrando su contador en la lista).
- **«Visto»**: en chats privados, bajo tu último mensaje cuando la otra persona ya lo leyó.
- **Disponibilidad**: punto de estado con la disponibilidad declarada por la persona (Disponible/No disponible, la misma
  de la atención de conversaciones). No es un indicador de "en línea".
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
| GET             | `/team-chat/threads/{thread}/messages/{message}/attachments/{attachment}` | Descarga autorizada                                             |

## Tiempo real

Canales (`routes/channels/team-chat.php`, autorizados en cada suscripción contra datos frescos):

- `team-chat.threads.{id}` — participantes actuales con `team-chat.use` (`TeamThreadChannel`). Eventos:
  `.message.created` {message_id}, `.thread.changed` {reason, message_id?}, `.read` {user_id, message_id}.
- `users.{id}` — `.team-chat.changed` {thread_id, reason}: refresca lista y badge.
- `presence-team` (`TeamPresenceChannel`) — autorizado para usuarios con `team-chat.use`; solo expone id, nombre y
  disponibilidad. **El cliente aún no se une** porque la librería de tiempo real no expone presencia (ver limitaciones).

Las cargas útiles son solo IDs (nunca texto ni nombres de archivo). Flujo: acción → evento de dominio
(`TeamMessagePosted`, `TeamThreadChanged`, `TeamThreadRead`, despachados después del commit) →
`BroadcastTeamChatActivity` → `TeamThreadSignal` / `TeamChatUserSignal` (cola `notifications`) → el cliente vuelve a
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

- **Presencia en línea**: el canal `presence-team` está autorizado en el servidor, pero `resources/js/lib/realtime` no
  ofrece `join()` de presencia; el indicador muestra la disponibilidad declarada. Pendiente del módulo de tiempo real.
- Sin indicador "escribiendo…", reacciones ni menciones con `@` en la interfaz (las notificaciones ya admiten
  `metadata.mentions`, pero el compositor no las genera).
- La lista carga 50 hilos y amplía de 50 en 50 (máximo 500 por vista).
- Sin vista previa de video/audio en línea (se descargan).
- No se ofrece exportación de chats internos (decisión de privacidad).
- No hay retención automática por antigüedad: los mensajes se conservan hasta que su autor los elimina.
