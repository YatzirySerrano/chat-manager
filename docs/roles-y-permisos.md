# Roles y permisos: matriz por acción

Auditoría del 2026-11-12 de qué permiso protege cada acción de cada módulo, con qué alcance de datos, qué rol por
defecto lo tiene y si la interfaz lo respeta. Está verificada contra el código (rutas, controladores, políticas y
`FormRequest`), no contra otros documentos. El catálogo vive en `app/Enums/Permission.php`; los roles por defecto, en
`app/Enums/DefaultRole.php`.

## 1. Cómo se decide un acceso

Tres cosas se combinan, siempre en el servidor (ocultar un botón nunca es la protección):

1. **Permiso** del rol (`User::holdsPermission()`).
2. **Alcance de datos** del rol (`DataScope`): «Asignado» (lo propio), «Departamentos» (lo de sus departamentos) o
   «Toda la organización» (global). Los permisos marcados _solo global_ no se ejercen con un alcance menor, aunque el
   rol los tenga guardados.
3. **Autoridad raíz**: los permisos _reservados_ solo los ejerce el Superadministrador, aunque la base diga otra cosa
   (`Permission::isReservedToRoot()`), y `PrivilegeGuard` impide que nadie otorgue más de lo que tiene o toque al rol
   raíz y a sus usuarios.

Regla uniforme de respuesta: lo que está **fuera del alcance** de quien pregunta responde `404` (no existe para esa
persona); lo que **sí ve pero no puede hacer** responde `403`.

### Roles por defecto

| Rol                | Alcance              | Permisos                                                                                                                                                                                       |
| ------------------ | -------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Superadministrador | Toda la organización | Todos, incluidos los reservados. Es la autoridad raíz.                                                                                                                                         |
| Administrador      | Toda la organización | Todos **menos** los reservados (importadores y eliminación definitiva).                                                                                                                        |
| Supervisor         | Departamentos        | Atender, asignar, transferir, cerrar, reabrir, supervisar, seguimiento de atención, reportes de sus departamentos, miembros de sus departamentos, chat interno (grupos y entre departamentos). |
| Agente             | Asignado             | Atender lo suyo, aceptar pendientes de sus departamentos, transferir, cerrar, notas, etiquetar, respuestas rápidas, sus propios indicadores, chat interno.                                     |

Abreviaturas de las tablas: **SA** Superadministrador, **AD** Administrador, **SU** Supervisor, **AG** Agente.
Alcance: **P** propio, **D** departamentos, **G** global (el que aplique según el rol).

## 2. Matriz por módulo

### Panel principal y reportes

| Acción                                  | Permiso                                     | Alcance                 | SA  | AD  | SU  | AG  | Interfaz                                 |
| --------------------------------------- | ------------------------------------------- | ----------------------- | --- | --- | --- | --- | ---------------------------------------- |
| Entrar al panel                         | `dashboard.view`                            | P/D/G                   | ✓   | ✓   | ✓   | ✓   | Menú solo con el permiso                 |
| Ver reportes                            | `reports.view`                              | P/D/G                   | ✓   | ✓   | ✓   | ✓   | Menú solo con el permiso                 |
| Exportar a Excel/PDF (cualquier módulo) | `reports.export` + permiso de ver el módulo | P/D/G                   | ✓   | ✓   | —   | —   | El menú «Exportar» lo decide el servidor |
| Ver sus archivos preparados             | `reports.export` o `audit.export`           | Propio (solo los suyos) | ✓   | ✓   | —   | —   | Coherente                                |

### Conversaciones

| Acción                                          | Permiso                                                        | Alcance | SA  | AD  | SU  | AG  | Interfaz                                                       |
| ----------------------------------------------- | -------------------------------------------------------------- | ------- | --- | --- | --- | --- | -------------------------------------------------------------- |
| Ver la bandeja y abrir conversaciones           | `conversations.view`                                           | P/D/G   | ✓   | ✓   | ✓   | ✓   | Coherente                                                      |
| Ver las que atiende otra persona (solo lectura) | `conversations.supervise`                                      | D/G     | ✓   | ✓   | ✓   | —   | Coherente                                                      |
| Consultar cerradas y su historial               | `conversations.history`                                        | P/D/G   | ✓   | ✓   | ✓   | ✓   | Coherente                                                      |
| Aceptar pendientes                              | `conversations.accept`                                         | D/G     | ✓   | ✓   | ✓   | ✓   | Botón según `can.accept`                                       |
| Aceptar aunque tenga clientes esperando         | `conversations.accept-with-pending`                            | —       | ✓   | ✓   | —   | —   | Coherente                                                      |
| Responder lo que atiende                        | `conversations.reply`                                          | P/D/G   | ✓   | ✓   | ✓   | ✓   | Compositor según `can.reply`                                   |
| Responder lo que atiende otra persona           | `conversations.intervene`                                      | D/G     | ✓   | ✓   | —   | —   | Coherente                                                      |
| Asignar a otra persona                          | `conversations.assign`                                         | D/G     | ✓   | ✓   | ✓   | —   | Menú según `can.assign`                                        |
| Transferir                                      | `conversations.transfer`                                       | P/D/G   | ✓   | ✓   | ✓   | ✓   | Menú según `can.transfer`                                      |
| Devolver a Pendientes                           | `conversations.reply` o `.assign`                              | P/D/G   | ✓   | ✓   | ✓   | ✓   | Menú según `can.release`                                       |
| Cerrar                                          | `conversations.close`                                          | P/D/G   | ✓   | ✓   | ✓   | ✓   | Menú según `can.close`                                         |
| Reabrir                                         | `conversations.reopen`                                         | D/G     | ✓   | ✓   | ✓   | —   | Botón según `can.reopen`                                       |
| Notas internas                                  | `conversations.notes`                                          | P/D/G   | ✓   | ✓   | ✓   | ✓   | Coherente                                                      |
| Etiquetar la conversación                       | `tags.assign`                                                  | P/D/G   | ✓   | ✓   | ✓   | ✓   | Coherente                                                      |
| Poner o quitar categoría                        | `conversations.reply`, `.assign` o `.confirm-ready`            | P/D/G   | ✓   | ✓   | ✓   | ✓   | Coherente                                                      |
| Confirmar «Lista para atención» (fase 2)        | `conversations.confirm-ready`                                  | D/G     | ✓   | ✓   | ✓   | —   | Solo con `FEATURE_PHASE_TWO`                                   |
| Excepción a las reglas de la cola (fase 2)      | `conversations.override-queue-rules`                           | D/G     | ✓   | ✓   | ✓   | —   | Solo con `FEATURE_PHASE_TWO`                                   |
| Enviar plantilla de WhatsApp                    | `templates.send` + poder responder                             | G       | ✓   | ✓   | —   | —   | Coherente                                                      |
| Reintentar un envío fallido                     | `conversations.reply` (el motor exige poder responderla ahora) | P/D/G   | ✓   | ✓   | ✓   | ✓   | Coherente                                                      |
| Alias y color propios del chat                  | Poder verla (son personales)                                   | Propio  | ✓   | ✓   | ✓   | ✓   | Coherente                                                      |
| **Eliminar chat definitivamente**               | `permanent-deletion.conversations` (reservado)                 | G       | ✓   | —   | —   | —   | Menú «Más acciones» y ficha del historial, solo con el permiso |

### Seguimiento de atención

| Acción                      | Permiso                              | Alcance | SA  | AD  | SU  | AG  | Interfaz  |
| --------------------------- | ------------------------------------ | ------- | --- | --- | --- | --- | --------- |
| Ver qué atiende cada agente | `attention.view`                     | D/G     | ✓   | ✓   | ✓   | —   | Coherente |
| Enviar alertas a agentes    | `attention.view` + `attention.alert` | D/G     | ✓   | ✓   | —   | —   | Coherente |
| Ver y confirmar sus alertas | Sin permiso (son propias)            | Propio  | ✓   | ✓   | ✓   | ✓   | Coherente |

### Contactos

| Acción                              | Permiso                                                     | Alcance                     | SA  | AD  | SU  | AG  | Interfaz  |
| ----------------------------------- | ----------------------------------------------------------- | --------------------------- | --- | --- | --- | --- | --------- |
| Ver directorio y fichas             | `contacts.view`                                             | P/D/G                       | ✓   | ✓   | ✓   | ✓   | Coherente |
| Crear                               | `contacts.view` + `contacts.create`                         | P/D/G                       | ✓   | ✓   | —   | —   | Coherente |
| Guardar un contacto provisional     | `contacts.create`                                           | P/D/G                       | ✓   | ✓   | —   | —   | Coherente |
| Editar                              | `contacts.update`                                           | P/D/G                       | ✓   | ✓   | —   | —   | Coherente |
| Eliminar y restaurar (lógico)       | `contacts.delete` (+ `records.view-deleted` para restaurar) | P/D/G                       | ✓   | ✓   | —   | —   | Coherente |
| Notas privadas                      | `contacts.notes`                                            | P/D/G                       | ✓   | ✓   | —   | —   | Coherente |
| Anotar cómo nos conoció             | `contacts.classify-origin` + ver                            | G                           | ✓   | ✓   | —   | —   | Coherente |
| Etiquetar                           | `tags.assign`                                               | P/D/G                       | ✓   | ✓   | ✓   | ✓   | Coherente |
| Revisión de duplicados              | `contacts.view` + `contacts.update`                         | P/D/G                       | ✓   | ✓   | —   | —   | Coherente |
| Importar desde Excel/CSV y revertir | `contacts.import` (reservado)                               | G, y solo los lotes propios | ✓   | —   | —   | —   | Coherente |

### Etiquetas, categorías y respuestas rápidas

| Acción                                        | Permiso                                                 | Alcance | SA  | AD  | SU  | AG  | Interfaz                         |
| --------------------------------------------- | ------------------------------------------------------- | ------- | --- | --- | --- | --- | -------------------------------- |
| Ver etiquetas                                 | `tags.view` o `tags.manage`                             | G       | ✓   | ✓   | ✓   | ✓   | Coherente                        |
| Crear, editar, eliminar y restaurar etiquetas | `tags.manage` (+ `records.view-deleted` para restaurar) | G       | ✓   | ✓   | —   | —   | Coherente                        |
| **Eliminar etiqueta definitivamente**         | `permanent-deletion.tags` (reservado)                   | G       | ✓   | —   | —   | —   | Solo en etiquetas ya eliminadas  |
| Ver y administrar categorías de solicitud     | `categories.manage`                                     | G       | ✓   | ✓   | —   | —   | Ver hallazgo H-2                 |
| **Eliminar categoría definitivamente**        | `permanent-deletion.categories` (reservado)             | G       | ✓   | —   | —   | —   | Solo en categorías ya eliminadas |
| Usar respuestas rápidas                       | `quick-replies.use`                                     | D/G     | ✓   | ✓   | ✓   | ✓   | Coherente                        |
| Administrar las compartidas                   | `quick-replies.manage`                                  | D/G     | ✓   | ✓   | —   | —   | Coherente                        |
| Tener respuestas personales                   | `quick-replies.personal`                                | Propio  | ✓   | ✓   | —   | —   | Coherente                        |
| **Eliminar respuesta rápida definitivamente** | `permanent-deletion.quick-replies` (reservado)          | G       | ✓   | —   | —   | —   | Solo en respuestas ya eliminadas |

### Chat interno

| Acción                                  | Permiso                      | Alcance                        | SA  | AD  | SU  | AG  | Interfaz  |
| --------------------------------------- | ---------------------------- | ------------------------------ | --- | --- | --- | --- | --------- |
| Usar el chat y sus conversaciones       | `team-chat.use`              | Solo los hilos donde participa | ✓   | ✓   | ✓   | ✓   | Coherente |
| Crear grupos                            | `team-chat.create-groups`    | —                              | ✓   | ✓   | ✓   | —   | Coherente |
| Administrar grupos en los que participa | `team-chat.manage-groups`    | Sus grupos                     | ✓   | ✓   | ✓   | —   | Coherente |
| Escribir a otros departamentos          | `team-chat.cross-department` | —                              | ✓   | ✓   | ✓   | —   | Coherente |
| Editar o borrar un mensaje              | Ser su autor                 | Propio                         | ✓   | ✓   | ✓   | ✓   | Coherente |

### Equipo: departamentos, usuarios y roles

| Acción                                          | Permiso                                          | Alcance | SA  | AD  | SU  | AG  | Interfaz                                                   |
| ----------------------------------------------- | ------------------------------------------------ | ------- | --- | --- | --- | --- | ---------------------------------------------------------- |
| Ver departamentos                               | `departments.view`                               | D/G     | ✓   | ✓   | ✓   | —   | Coherente                                                  |
| Crear departamentos                             | `departments.create`                             | G       | ✓   | ✓   | —   | —   | Coherente                                                  |
| Editar (nombre, horario, reparto, reglas)       | `departments.update`                             | D/G     | ✓   | ✓   | —   | —   | Coherente                                                  |
| Miembros                                        | `departments.manage-members`                     | D/G     | ✓   | ✓   | ✓   | —   | Coherente                                                  |
| Eliminar y restaurar departamentos              | `departments.delete`                             | G       | ✓   | ✓   | —   | —   | Coherente                                                  |
| Ver usuarios                                    | `users.view`                                     | D/G     | ✓   | ✓   | —   | —   | Coherente                                                  |
| Crear usuarios                                  | `users.create` + `users.assign-roles`            | D/G     | ✓   | ✓   | —   | —   | Coherente                                                  |
| Editar usuarios                                 | `users.update`                                   | D/G     | ✓   | ✓   | —   | —   | Coherente                                                  |
| Cambiar el rol                                  | `users.assign-roles`                             | D/G     | ✓   | ✓   | —   | —   | Coherente                                                  |
| Eliminar y restaurar usuarios                   | `users.delete`                                   | D/G     | ✓   | ✓   | —   | —   | Coherente                                                  |
| Ver roles                                       | `roles.view`                                     | G       | ✓   | ✓   | —   | —   | Coherente                                                  |
| Crear, editar, eliminar y restaurar roles       | `roles.create` / `.update` / `.delete`           | G       | ✓   | ✓   | —   | —   | Nunca se ofrecen permisos reservados ni más de los propios |
| Alias, colores y respuestas personales de otros | `supervision.view-personal` / `.manage-personal` | D/G     | ✓   | ✓   | —   | —   | Coherente                                                  |

Nadie por debajo del Superadministrador ve ni toca al rol raíz ni a sus usuarios (`PrivilegeGuard`).

### Administración

| Acción                                                                    | Permiso                                                       | Alcance                               | SA  | AD  | SU  | AG  | Interfaz                                |
| ------------------------------------------------------------------------- | ------------------------------------------------------------- | ------------------------------------- | --- | --- | --- | --- | --------------------------------------- |
| Ver conexiones                                                            | `connections.view` o `.manage`                                | G                                     | ✓   | ✓   | —   | —   | Coherente                               |
| Crear, editar, activar, verificar, registrar número, eliminar y restaurar | `connections.manage`                                          | G                                     | ✓   | ✓   | —   | —   | Coherente                               |
| Ver plantillas                                                            | `templates.view` o `.manage`                                  | G                                     | ✓   | ✓   | —   | —   | Coherente                               |
| Actualizar plantillas desde Meta                                          | `templates.manage`                                            | G                                     | ✓   | ✓   | —   | —   | Coherente                               |
| Ver claves de integraciones                                               | `integrations.view` o `.manage`                               | G                                     | ✓   | ✓   | —   | —   | Coherente                               |
| Crear y revocar claves                                                    | `integrations.manage`                                         | G                                     | ✓   | ✓   | —   | —   | Coherente                               |
| Ver auditoría                                                             | `audit.view`                                                  | G; las entradas restringidas, solo SA | ✓   | ✓   | —   | —   | Coherente                               |
| Exportar auditoría                                                        | `audit.export`                                                | G                                     | ✓   | ✓   | —   | —   | Coherente                               |
| Ver registros eliminados                                                  | `records.view-deleted`                                        | Lo que ya puede ver                   | ✓   | ✓   | —   | —   | Filtro «Eliminados» solo con el permiso |
| Ver configuración / modificarla                                           | `settings.view` / `settings.update`                           | G                                     | ✓   | ✓   | —   | —   | Coherente                               |
| Ver personalización / modificarla                                         | `appearance.view` / `appearance.update`                       | G                                     | ✓   | ✓   | —   | —   | Coherente                               |
| Ver Sistema y Almacenamiento                                              | `system.view`                                                 | G                                     | ✓   | ✓*  | —   | —   | Coherente                               |
| Reintentar o descartar tareas, «Comprobar ahora»                          | `system.view` + `system.manage`                               | G                                     | ✓   | ✓*  | —   | —   | Coherente                               |
| Importador de historial de Whaticket                                      | `history-import.view` / `.classify` / `.execute` (reservados) | G                                     | ✓   | —   | —   | —   | Coherente                               |
| Consultar historial importado                                             | `history-import.archive-view`                                 | G                                     | ✓   | ✓   | —   | —   | Coherente                               |

\* En una instalación nueva el Administrador los recibe. En una instalación ya existente **no** se le añadieron al
actualizar los roles por defecto (decisión pendiente desde la estabilización, ver `seguridad-y-auditoria.md` §3.1).

### Lo propio de cada persona (sin permiso)

Perfil, contraseña, verificación en dos pasos, apariencia personal, preferencias de avisos, notificaciones y
suscripciones push: cada quien solo ve y cambia lo suyo. No hay vista de las de otra persona.

## 3. Eliminación definitiva

Grupo **«Eliminación definitiva»** de Administración → Roles y permisos. Los cuatro permisos son irreversibles, de alto
impacto, solo globales y **reservados al Superadministrador**: se ven en su rol, no se ofrecen para ningún otro rol y
el servidor los rechaza para cualquiera que no sea la autoridad raíz, aunque estuvieran guardados en su rol.

| Permiso                            | Qué hace                                                                       | Dónde aparece                                                       |
| ---------------------------------- | ------------------------------------------------------------------------------ | ------------------------------------------------------------------- |
| `permanent-deletion.conversations` | Borra un chat con todo lo suyo.                                                | Conversaciones → «Más acciones», y la ficha de Historial importado. |
| `permanent-deletion.tags`          | Borra una etiqueta **ya eliminada** y la quita de donde seguía.                | Etiquetas, con el filtro «Eliminados».                              |
| `permanent-deletion.categories`    | Borra una categoría **ya eliminada**; sus conversaciones quedan sin categoría. | Categorías de solicitud, con el filtro «Eliminados».                |
| `permanent-deletion.quick-replies` | Borra una respuesta rápida **ya eliminada**, compartida o personal.            | Respuestas rápidas, con el filtro «Eliminados».                     |

Toda eliminación definitiva pide escribir `ELIMINAR` (el servidor lo vuelve a comprobar), tiene límite de 20 por
minuto y deja una entrada de auditoría **restringida** (solo la lee el Superadministrador), sin contenido.

### Qué borra «Eliminar chat definitivamente»

- Los mensajes del chat y sus adjuntos; los **archivos** y sus miniaturas también se borran del almacenamiento.
- Notas internas, línea de tiempo (asignaciones, transferencias, cierres), ciclos de respuesta y métricas del chat y
  de sus agentes, marcas de lectura, etiquetas del chat, alias y colores del chat y las notificaciones que apuntaban a él.
- Si era historial importado de Whaticket, sus claves de importación: volver a importar el mismo archivo lo recrea.

Se conserva el contacto con sus datos, notas privadas, identidades y procedencia. La entrada de auditoría registra
quién, cuándo, el contacto, la conexión y cuántos mensajes, adjuntos y archivos se borraron.

Orden: primero se borra todo de la base en una sola transacción (todo o nada, con la conversación bloqueada) y
**después** se borran los archivos. Si el almacenamiento no está disponible (NAS desmontado), el chat queda borrado
igualmente y los archivos que no se pudieron quitar quedan huérfanos en el almacenamiento: se cuentan en la entrada de
auditoría (`files_pending`), se escriben en el registro de la aplicación y el aviso en pantalla lo dice. Un archivo que
todavía use otro chat no se borra.

Lo que **no** se borra de inmediato: las copias crudas de los webhooks de Meta (`webhook_events`), que se depuran solas
a los 30 días (`WHATSAPP_WEBHOOK_RETENTION_DAYS`), y los respaldos de la base.

### Módulos sin eliminación definitiva (a propósito)

| Módulo                    | Por qué no                                                                                                                                                                                                        |
| ------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Usuarios                  | De ellos cuelgan mensajes, auditoría, asignaciones y métricas; borrarlos rompería ese historial.                                                                                                                  |
| Conexiones                | De ellas cuelgan conversaciones, mensajes, plantillas y webhooks.                                                                                                                                                 |
| Departamentos             | De ellos cuelgan conversaciones, miembros y métricas.                                                                                                                                                             |
| Roles                     | Ya solo se eliminan sin usuarios; la auditoría los referencia.                                                                                                                                                    |
| Contactos                 | Tienen conversaciones, identidades de WhatsApp, procedencia, notas y registros de importación (claves foráneas restrictivas). Borrar uno exige antes borrar sus chats: queda pendiente como decisión de producto. |
| Plantillas                | Son espejo de Meta: se vuelven a crear al sincronizar.                                                                                                                                                            |
| Mensajes del chat interno | El autor ya puede borrar los suyos; no hay borrado de hilos completos.                                                                                                                                            |

## 4. Hallazgos de la auditoría

### Corregidos en esta entrega

- **H-1. No existía ninguna forma de borrar un chat ni un registro.** Todo era eliminación lógica o nada. Se añadió el
  grupo «Eliminación definitiva» (§3), reservado al Superadministrador.

### Pendientes (decisión de producto, sin cambios)

- **H-2. Categorías de solicitud no tienen permiso de «ver».** La pantalla exige `categories.manage`; quien solo
  clasifica conversaciones elige la categoría desde el chat. Es coherente, pero un rol de solo consulta no puede abrir
  el catálogo. Propuesta: `categories.view`.
- **H-3. Eliminar y restaurar comparten permiso** en usuarios, departamentos, contactos, roles, etiquetas, categorías,
  respuestas rápidas y conexiones (en estas, dentro de `connections.manage`). Quien puede eliminar puede restaurar.
  Separarlos duplicaría ocho permisos; solo conviene si se quiere un rol que elimine sin poder restaurar.
- **H-4. Reintentar un envío y revocar el enlace de un archivo** no tienen permiso propio: reintentar exige poder
  responder la conversación; revocar un enlace, haberlo enviado o poder responderla.
- **H-5. El chat interno es privado también para el Superadministrador.** Solo ve los hilos en los que participa; no
  existe vista general de las conversaciones internas de otras personas. Es deliberado (privacidad del equipo). Una
  vista de supervisión sería un permiso nuevo y sensible.
- **H-6. Los archivos preparados (exportaciones) son solo de quien los generó.** El Superadministrador no descarga
  exportaciones de otra persona; sí ve en Auditoría quién exportó qué y cuándo.
- **H-7. Los lotes del importador de contactos son solo de quien los subió.** Otro Superadministrador recibe `404`. El
  importador de historial de Whaticket, en cambio, sí lo ven todos los superadministradores.
- **H-8. Notificaciones y preferencias de avisos** no tienen vista de las de otra persona.
- **H-9. `system.view` y `system.manage` del Administrador** en instalaciones existentes: sin conceder (ver nota de la
  tabla de Administración).
- **H-10. Eliminación definitiva de contactos**, pendiente (§3).
- **H-11. Las pruebas de navegador** que usen el menú de acciones de una conversación con una cuenta de
  Superadministrador ven un elemento más («Eliminar chat definitivamente»).

### Sin hallazgo

- No se encontraron permisos huérfanos: cada permiso del catálogo tiene código que lo exige.
- No se encontraron botones visibles sin permiso en las pantallas revisadas: las acciones de cada registro llegan del
  servidor (`can.*`) y el menú lateral se arma con los permisos efectivos.
- La vista general del Superadministrador sobre conversaciones, contactos, usuarios, departamentos, reportes,
  auditoría (incluidas las entradas restringidas), registros eliminados y elementos personales de otras personas ya
  existe con el alcance «Toda la organización».

## 5. Después de actualizar

```bash
php artisan migrate                 # 2026_11_12_210000_add_permanent_deletion_permissions
php artisan app:sync-permissions    # deja el catálogo completo
```

La migración crea los cuatro permisos y los concede **solo** a los roles raíz. No hace falta
`--upgrade-default-roles`: ningún otro rol por defecto los recibe.
