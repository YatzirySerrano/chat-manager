# Guía de pruebas manuales

Guía práctica para probar Chat Manager a mano en el entorno local, ordenada por módulos. Cada prueba indica la
cuenta, el módulo, la acción exacta, los datos, el resultado esperado, qué ve el administrador y qué ve el agente,
qué queda registrado y qué indicaría un error. Las pruebas que necesitan credenciales oficiales de Meta están en
§27, las del chat web y la API de integración (fase 4), en §28, las de la fase 5, en §29, y las de la fase 5.1, en §30.

> **Todo el tráfico de WhatsApp de esta guía es simulado** (transporte `simulated` y simulador local). Nada sale a
> WhatsApp. Lo simulado lleva la etiqueta «No enviado (modo de prueba)».

## 0. Preparación

### 0.1 Cuentas disponibles en la base local `chatmanager`

Consultadas con una lectura de solo lectura el 2026-10-22. Usa **la contraseña que ya conoces** de cada cuenta (esta
guía nunca las incluye).

| Cuenta                                    | Correo                                   | Rol (alcance)                        | Departamentos   | Activa | Disponibilidad inicial |
| ----------------------------------------- | ---------------------------------------- | ------------------------------------ | --------------- | ------ | ---------------------- |
| yat serrano (**Superadministrador**)      | `serranohdez.yatziry03@gmail.com`        | Superadministrador (raíz, global)    | —               | Sí     | Disponible             |
| jesus arizmendi maya (**Administrador**)  | `jesusarizmendimaya@gmail.com`           | Administrador (toda la organización) | —               | Sí     | No disponible          |
| Demo B · Valeria Ríos (**agente Ventas**) | `valeria.ventas@demo-b.chatmanager.test` | Demo B · Agente (solo lo asignado)   | Demo B · Ventas | Sí     | No disponible          |
| Demo B · Óscar Lima (**agente Soporte**)  | `oscar.soporte@demo-b.chatmanager.test`  | Demo B · Agente (solo lo asignado)   | Soporte         | Sí     | Disponible             |

Roles sin usuarios: Supervisor (sus departamentos) y Agente (solo lo asignado). Para probar el Supervisor, crea un
usuario de prueba en el módulo 3. Los permisos de cada rol están en
[seguridad-y-auditoria.md §3.1](seguridad-y-auditoria.md#31-permisos-por-rol-tras-la-actualización-de-la-estabilización).

**Decisión pendiente:** el Administrador **no** tiene `system.view` ni `system.manage` (permisos sensibles, no
concedidos en la actualización de roles). Por eso no ve Administración → Sistema (módulo 23). Si decides dárselos:
`php artisan app:sync-permissions --upgrade-default-roles --include-sensitive` (vista previa) y después con `--apply`.

### 0.2 Departamentos y conexiones

| Departamento               | Estado                           | Distribución | Notas                                              |
| -------------------------- | -------------------------------- | ------------ | -------------------------------------------------- |
| Ventas (id 1)              | Eliminado lógicamente (inactivo) | Compartida   | Sirve para probar «Ver eliminados» y restaurar     |
| Marketing (id 2)           | Activo                           | Compartida   | Sin miembros                                       |
| Demo B · Ventas (id 3)     | Activo                           | Compartida   | Departamento por defecto de la línea demo; Valeria |
| Soporte (id 4)             | Activo                           | Compartida   | Departamento demo de Soporte renombrado; Óscar     |
| departamento prueba (id 5) | Activo                           | Compartida   | Sin miembros                                       |

| Conexión                             | Phone number ID   | Estado                                                              | Departamentos autorizados                                   |
| ------------------------------------ | ----------------- | ------------------------------------------------------------------- | ----------------------------------------------------------- |
| Demo B · WhatsApp simulado (id 1)    | `990000000000100` | **Desactivada y sin credenciales guardadas**                        | Demo B · Ventas (por defecto), Soporte, departamento prueba |
| Demo B · WhatsApp simulado (Soporte) | `990000000000101` | No existe todavía: la crea el simulador con `--create-support-line` | Soporte                                                     |

Conversaciones existentes: Laura Méndez (abierta, Demo B · Ventas, responsable el Superadministrador), Mario Ruiz
(abierta, Soporte, responsable el Superadministrador) y Sofía Torres (cerrada, Soporte, la cerró Óscar).

### 0.3 Entorno y procesos

1. **Respaldo antes de empezar** (las pruebas escriben en `chatmanager`):
   `mariadb-dump --single-transaction chatmanager | gzip > storage/app/backups/chatmanager-antes-pruebas-manuales.sql.gz`.
2. En `.env`: `WHATSAPP_TRANSPORT=simulated` (opcional `WHATSAPP_SIMULATE_STATUSES=true` y
   `WHATSAPP_SIMULATE_READ=true` para que los envíos pasen solos a Entregado/Leído), `BROADCAST_CONNECTION=reverb`,
   `QUEUE_CONNECTION=database`. Después `php artisan config:clear`.
3. **Antes del módulo 10 (y siempre que una prueba hable de «en vivo», colas, exportaciones en cola, importaciones o
   Web Push)**, arranca los procesos: `composer run dev` (servidor `php artisan serve` en `http://localhost:8000`,
   Vite, Reverb, `schedule:work` y tres procesos de colas: `queue` (`queue:work` con
   `messages,realtime,webhooks`, envíos primero), `webhooks` (`queue:work` con `webhooks,realtime,messages`, recepción
   primero) y `queue-bg` (`queue:listen` con `media,notifications,default,exports`); en Windows, un único
   `queue:listen` con todas las colas). Los dos `queue:work` corren en un bucle que los vuelve a arrancar al detenerse:
   **después de cambiar código PHP que corre en la cola (jobs, listeners, transportes), ejecuta
   `php artisan queue:restart`** para que tomen el código nuevo (el `queue:listen` de `queue-bg` lo recarga solo).
   Alternativa en terminales separadas: `php artisan serve`, `npm run dev`, `php artisan reverb:start`,
   `php artisan queue:work --queue=messages,realtime,webhooks --sleep=1 --tries=1 --timeout=0`,
   `php artisan queue:work --queue=webhooks,realtime,messages --sleep=1 --tries=1 --timeout=0`,
   `php artisan queue:listen --queue=media,notifications,default,exports --sleep=3 --tries=1 --timeout=0`
   y `php artisan schedule:work`. Sin worker, el simulador guarda los webhooks pero no se procesan; sin Reverb, la
   interfaz se actualiza por sondeo cada 20 s (no es un error).
4. **Preparar la conexión demo para poder responder** (Superadministrador, Conexiones → «Demo B · WhatsApp
   simulado» → Editar): WABA ID `990000000000200` y token de acceso desechable `DEMO-SIMULADO-TOKEN-000000000000`
   (≥ 20 caracteres, sin espacios). Guarda y pulsa **Activar**. **No pulses «Verificar credenciales»**: llamaría a
   Meta con un token falso, Meta lo rechazaría y la conexión quedaría bloqueada para enviar. La conexión quedará
   activa con la advertencia «credenciales sin verificar» (el envío se permite).
5. Ten dos navegadores (o una ventana normal y otra privada) para ver a la vez al administrador y al agente.
6. Al terminar: `php artisan chat-manager:demo-cleanup` (vista previa) y, si quieres borrar los datos ficticios,
   `--confirm`. Restaura el respaldo del paso 1 si necesitas volver al estado exacto.

### 0.4 Comandos del simulador (`php artisan chat-manager:simulate-inbound`)

Solo local, exige `--confirm`, solo líneas demo y números ficticios `5215500000100`–`5215500000199` (o `laura`,
`mario`, `sofia`). La salida es una tabla con el resultado real («Conversación nueva», «Agregado a la conversación
abierta», «Conversación reabierta», «Llegó tarde…», «Duplicado»), si el contacto es nuevo o existente, el
departamento y un enlace «Abre:». Para obtener el id de un mensaje saliente (lectura):
`php artisan tinker --execute 'echo App\Models\Message::query()->where("direction","outbound")->latest("id")->value("id");'`

| Escenario                          | Comando                                                                                                                                                              | Resultado esperado                                                                                                                                                                                                                                                                             |
| ---------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Nuevo contacto                     | `php artisan chat-manager:simulate-inbound 5215500000150 "Quiero una cotización" --name="Ana" --confirm`                                                             | «Conversación nueva», contacto «Demo B · Ana (nuevo)», en Demo B · Ventas, Pendiente                                                                                                                                                                                                           |
| Nuevo mensaje (misma conversación) | `php artisan chat-manager:simulate-inbound 5215500000150 "¿Siguen ahí?" --confirm`                                                                                   | «Agregado a la conversación abierta», contacto existente                                                                                                                                                                                                                                       |
| Contacto existente                 | `php artisan chat-manager:simulate-inbound laura "Sigo interesada" --confirm`                                                                                        | «Agregado a la conversación abierta», «(existente)»                                                                                                                                                                                                                                            |
| Dirigido a Ventas                  | `php artisan chat-manager:simulate-inbound 5215500000151 "Hola Ventas" --department=ventas --confirm`                                                                | Conversación nueva en Demo B · Ventas (`ventas` es el valor por defecto)                                                                                                                                                                                                                       |
| Dirigido a Soporte                 | `php artisan chat-manager:simulate-inbound 5215500000152 "Mi pedido llegó dañado" --department=soporte --create-support-line --confirm`                              | La primera vez crea la línea «Demo B · WhatsApp simulado (Soporte)» (auditada como `connection.created`); conversación nueva en **Soporte**. Después basta `--department=soporte`                                                                                                              |
| Multimedia con archivo             | `php artisan chat-manager:simulate-inbound mario "Recibo" --type=document --file=/ruta/a/recibo.pdf --confirm`                                                       | «Archivo de muestra adjuntado (simulación)»; el adjunto se abre desde la conversación                                                                                                                                                                                                          |
| Multimedia sin archivo             | `php artisan chat-manager:simulate-inbound mario "Foto" --type=image --confirm`                                                                                      | Adjunto «no disponible» (`simulated_media`)                                                                                                                                                                                                                                                    |
| Duplicado                          | `php artisan chat-manager:simulate-inbound laura --duplicate --confirm`                                                                                              | «Duplicado: no se guardó otra vez» (mismo `wamid`)                                                                                                                                                                                                                                             |
| Entregado                          | `php artisan chat-manager:simulate-inbound --status=delivered --message=<id saliente> --confirm`                                                                     | La burbuja pasa a dos marcas (Entregado)                                                                                                                                                                                                                                                       |
| Leído                              | `php artisan chat-manager:simulate-inbound --status=read --message=<id saliente> --confirm` (añade `--out-of-order` para mandar `read` antes que `delivered`)        | Dos marcas de color (Leído) con hora de entrega; fuera de orden queda igual de correcto                                                                                                                                                                                                        |
| Error de envío                     | `php artisan chat-manager:simulate-inbound --status=failed --message=<id saliente> --error-code=131026 --confirm`                                                    | «Fallido» con el motivo de Meta; con `--error-code=131047` el texto de ventana de 24 h vencida. Códigos: 131026, 131047, 131049, 131050, 131056, 130429, 131000, 131031                                                                                                                        |
| Ventana de 24 h vencida            | `php artisan chat-manager:simulate-inbound laura --expire-window --confirm`                                                                                          | El último mensaje del cliente pasa a hace 24 h 5 min; al recargar, «Ventana 24 h: Vencida» y solo se ofrece plantilla. Si se rechaza por mensajes recientes, usa `php artisan chat-manager:simulate-inbound 5215500000160 "Hola" --minutes-ago=1500 --confirm` (conversación nueva ya vencida) |
| Reapertura                         | Cierra una conversación desde la bandeja y, antes de 120 min: `php artisan chat-manager:simulate-inbound sofia "Una duda más" --confirm` (si la cerrada es de Sofía) | «Conversación reabierta». Pasados 120 min: «Conversación nueva»                                                                                                                                                                                                                                |
| Transferencia                      | No es un evento de Meta: se hace en la interfaz (módulo 14)                                                                                                          | Evento «Transferida» en el historial                                                                                                                                                                                                                                                           |

## 1. Login y logout

**1.1 Iniciar sesión.** Cuenta: Valeria. Acción: abrir `http://localhost:8000/login`, escribir correo y contraseña,
«Iniciar sesión». Resultado: entra al panel; el menú solo muestra lo que su rol permite (Panel, Conversaciones,
Contactos, Etiquetas, Respuestas rápidas, Chat interno, Reportes). Administrador: no ve nada distinto. Registro: no se
audita un inicio de sesión normal. Error: entrar con una contraseña incorrecta, ver módulos de administración o una
página 500.

**1.2 Bloqueo por intentos.** Cuenta: Valeria. Acción: 6 intentos seguidos con una contraseña incorrecta. Resultado: a
partir del sexto, mensaje de demasiados intentos (espera un minuto). Registro: `auth.lockout` en Auditoría (lo ve el
Superadministrador o el Administrador con `audit.view`). Error: se puede seguir probando sin límite.

**1.3 Recuperación de contraseña.** Cuenta: ninguna (sin sesión). Acción: «¿Olvidaste tu contraseña?», enviar 6 veces en
un minuto con cualquier correo. Resultado: mensaje neutral (no revela si el correo existe); la sexta responde «demasiadas
solicitudes» (429). Error: el mensaje distingue correos existentes o no hay límite.

**1.4 Cerrar sesión.** Cuenta: Valeria. Acción: menú de usuario → «Cerrar sesión» → confirmar en el diálogo; después
pulsar «Atrás» en el navegador. Resultado: vuelve al login y «Atrás» no muestra datos de la sesión anterior (historial
de Inertia cifrado y limpiado; en `localhost` funciona, por http en otra IP solo hay un aviso en la consola). Error: al
volver atrás aparecen conversaciones de la sesión cerrada.

**1.5 Sesiones al cambiar la contraseña.** Cuenta: Valeria en dos navegadores. Acción: en uno, Configuración →
Seguridad → cambiar contraseña. Resultado: el otro navegador pide iniciar sesión en la siguiente petición; el actual
sigue dentro. Registro: `auth.password_changed` con `sessions_closed`. Error: el otro navegador sigue con sesión.

## 2. Dashboard

**2.1 Panel principal.** Cuenta: Superadministrador y luego Valeria. Acción: abrir «Panel». Resultado: en vivo «En
atención», «Pendientes», «Finalizadas hoy», «Agentes activos»; del periodo, «Primera respuesta (promedio)» y las
gráficas de actividad (desde la fase 5.1, ver §30.13). El
Superadministrador ve toda la organización; Valeria solo sus cifras (alcance asignado). Registro: nada. Error: Valeria
ve cifras de otros departamentos o el panel tarda o falla.

**2.2 Actualización en vivo.** Cuenta: Superadministrador con el panel abierto; procesos de §0.3 activos. Acción:
ejecutar el comando «Nuevo contacto» de §0.4. Resultado: «Pendientes» sube en segundos sin recargar. Error: no cambia
ni siquiera tras 20 s (sondeo) o se duplica.

## 3. Usuarios

**3.1 Crear un supervisor de prueba.** Cuenta: Administrador. Acción: Administración → Usuarios → «Nuevo usuario»;
nombre «Prueba Supervisor», correo `supervisor.prueba@demo-b.chatmanager.test`, rol Supervisor, departamento Demo B ·
Ventas, contraseña temporal. Resultado: aparece en la lista; el correo se valida en vivo (disponible). Registro:
`user.created` en Auditoría. El agente no ve el módulo. Error: se puede asignar un rol superior al propio o el correo
duplicado no se detecta.

**3.2 Editar un usuario y su límite de conversaciones.** Cuenta: Administrador. Acción: Usuarios → Valeria → Editar →
«Límite de conversaciones en atención» = 1 → Guardar. Resultado: guardado; valores fuera de 1–200 dan error en
español. Registro: `user.updated` con `changes.max_open_conversations`. Valeria: no ve cambios hasta que el límite
actúe (módulo 13). Error: acepta 0 o 500, o no se audita.

**3.3 Autoedición protegida.** Cuenta: Superadministrador. Acción: Usuarios → abrir tu propia fila. Resultado: el
correo aparece de solo lectura con una pista y, en lugar de «Restablecer contraseña», un enlace a Configuración →
Seguridad; cambiar solo el nombre se guarda. Error: puedes cambiar tu contraseña o correo desde Administración.

**3.4 Restablecer la contraseña de otro.** Cuenta: Administrador; Valeria con sesión abierta en otro navegador. Acción:
Usuarios → Valeria → restablecer contraseña. Resultado: Valeria pierde la sesión en su siguiente petición. Registro:
`user.password_reset` con `sessions_closed`. Error: Valeria sigue dentro.

**3.5 Eliminar y restaurar.** Cuenta: Administrador. Acción: eliminar el usuario de prueba de 3.1; activar «Ver
eliminados» y restaurarlo. Resultado: desaparece de la lista normal y vuelve al restaurar; sus conversaciones se
habrían devuelto a la cola. Registro: `user.deleted` y `user.restored`. Error: borrado físico o sin auditoría.

## 4. Roles y permisos

**4.1 Revisar los roles actualizados.** Cuenta: Superadministrador. Acción: Administración → Roles → abrir Agente,
Supervisor, Demo B · Agente y Administrador. Resultado: coinciden con la tabla de
[seguridad §3.1](seguridad-y-auditoria.md#31-permisos-por-rol-tras-la-actualización-de-la-estabilización) (Agente con
etiquetas, reportes y chat interno; Administrador con plantillas y **sin** `system.view`/`system.manage`). Registro:
la actualización del 2026-10-22 aparece como `role.permissions_changed`. Error: falta algún permiso añadido.

**4.2 Vista previa de la actualización.** Cuenta: ninguna (terminal). Acción: `php artisan app:sync-permissions
--upgrade-default-roles --demo-b`. Resultado: «Vista previa: no se modificó nada» y, como ya se aplicó, nada que añadir
(salvo `system.*` retenidos como sensibles). Error: propone quitar permisos personalizados o escribe sin `--apply`.

**4.3 Sin escalamiento.** Cuenta: Administrador. Acción: intentar dar a un rol un permiso reservado (por ejemplo
`contacts.import`) o asignarse el rol Superadministrador. Resultado: la interfaz no lo ofrece y el servidor lo
rechaza. Error: se concede.

**4.4 Efecto inmediato.** Cuenta: Superadministrador; Valeria con sesión abierta. Acción: quitar `team-chat.use` al rol
Demo B · Agente. Resultado: en su siguiente petición Valeria ya no ve «Chat interno» (y lo pierde en vivo). Vuelve a
concederlo al terminar. Registro: `role.permissions_changed`. Error: Valeria conserva el acceso.

## 5. Departamentos

**5.1 Distribución y límite por departamento.** Cuenta: Administrador. Acción: Departamentos → Demo B · Ventas →
tarjeta «Distribución de conversaciones» → modo «Automática» y «Límite de conversaciones por agente» = 2 → Guardar.
Resultado: la tarjeta «Atención en este momento» indica «límite propio del departamento»; valores fuera de 1–200 dan
error. Registro: `department.operations_updated`. Agente: recibe conversaciones automáticas si está Disponible (módulo
13). Error: el límite no aparece o no se audita.

**5.2 Fuera de alcance = 404.** Cuenta: el supervisor de prueba (Demo B · Ventas). Acción: abrir a mano
`/departments/4` (Soporte). Resultado: la misma página 404 que un id inexistente (`/departments/999`). Error: 403 en
uno y 404 en otro (permite enumerar).

**5.3 Restaurar un departamento.** Cuenta: Administrador. Acción: Departamentos → «Ver eliminados» → Ventas →
restaurar (y vuelve a eliminarlo si no lo necesitas). Registro: `department.restored` / `department.deleted`. Error:
no aparece en eliminados.

## 6. Conexiones

**6.1 Ficha y diagnóstico.** Cuenta: Superadministrador. Acción: Conexiones → Demo B · WhatsApp simulado. Resultado:
estado (activa tras §0.3), salud (no «Operativa» porque las credenciales no están verificadas), URL del webhook,
diagnóstico (entregas no confirmadas de 7 días con enlaces) y «Modo de prueba». Sin `WHATSAPP_PILOT_RECIPIENTS` no aparece
«Piloto controlado». Error: aparece «Operativa» sin credenciales verificadas o se muestran secretos.

**6.2 Pausar con una conversación abierta.** Cuenta: Administrador; Valeria con una conversación propia abierta en la
bandeja (módulo 10). Acción: Conexiones → Desactivar. Resultado: en vivo, el compositor de Valeria cambia a «La
conexión de WhatsApp «…» está desactivada… Puedes seguir agregando notas internas.», sin adjuntos ni plantillas; la
pestaña Nota interna sigue funcionando. Sin tiempo real, al enviar recibe el motivo (409), la conversación se recarga
y el borrador se conserva. Los mensajes del cliente se siguen recibiendo. Registro: `connection.deactivated`. Error:
el agente puede enviar o se pierde un mensaje entrante.

**6.3 Reactivar.** Cuenta: Administrador. Acción: Activar. Resultado: aviso de «credenciales aún no verificadas»; el
compositor de Valeria se desbloquea en vivo con una advertencia amarilla. Registro: `connection.activated`. Error:
sigue bloqueado.

**6.4 Eliminar y restaurar** (opcional, al final). Resultado: una conexión eliminada responde 404 al webhook y el
compositor dice «fue eliminada»; al restaurarla vuelve **desactivada** y sin verificación de webhook ni suscripción.
Registro: `connection.deleted` / `connection.restored`.

## 7. Contactos

**7.1 Búsqueda.** Cuenta: Superadministrador. Acción: Contactos → buscar «laura», «Lau», «méndez» (sin acento
«mendez»), el inicio del teléfono `5215500000` y un prefijo de correo con `@` si alguno lo tiene. Resultado: encuentra
por comienzo de palabra con o sin acentos y por prefijo de teléfono; «ura» (subcadena) no encuentra nada (es una
limitación documentada). Error: «Juan 5» devuelve contactos por el dígito o el buscador falla con caracteres `+ - "`.

**7.2 Alcance.** Cuenta: Valeria. Acción: Contactos → buscar «Mario». Resultado: no aparece (no es de sus
conversaciones). Error: ve contactos ajenos.

**7.3 Crear, editar, eliminar y restaurar.** Cuenta: Administrador. Acción: «Nuevo contacto» con teléfono ficticio
`+52 55 0000 0199`; editarlo; eliminarlo; restaurarlo desde «Ver eliminados». Registro: `contact.created`,
`contact.updated`, `contact.deleted`, `contact.restored`. Error: teléfono mal normalizado o duplicado no avisado.

**7.4 Orden.** Acción: ordenar por «Más recientes». Resultado: los contactos sin interacción quedan al final.

## 8. Etiquetas

**8.1 Catálogo.** Cuenta: Administrador. Acción: Etiquetas → crear «Urgente», editar color, eliminar y restaurar.
Registro: `tag.created`, `tag.updated`, `tag.deleted`, `tag.restored`. Valeria ve la lista pero no puede administrarla.
Error: Valeria puede crear o editar etiquetas.

**8.2 Etiquetar una conversación.** Cuenta: Valeria con una conversación propia (módulo 10). Acción: panel de
detalles → etiquetas de la conversación → añadir «Urgente». Resultado: se guarda; en una conversación que no es suya
no puede (404). Registro: `conversation.tags_changed`. Error: puede etiquetar conversaciones ajenas.

## 9. Respuestas rápidas

**9.1 Crear y usar.** Cuenta: Administrador crea en Respuestas rápidas «saludo» para Demo B · Ventas con el texto
«Hola {contacto.nombre}, gracias por escribir». Valeria, en el compositor de su conversación, escribe «/» y elige
«saludo». Resultado: el texto aparece con el nombre del contacto ya resuelto. Registro: `quick_reply.created`. Error:
la variable no se resuelve o Valeria ve respuestas de otros departamentos.

**9.2 Alcance de edición.** Cuenta: el supervisor de prueba. Acción: intentar editar una respuesta global o de otro
departamento. Resultado: 403 si la ve; 404 si está fuera de su alcance. Error: puede editarla.

## 10. Bandeja de WhatsApp

Requisitos: procesos de §0.3 en marcha y la conexión demo activa con credenciales desechables.

**10.1 Llega una conversación nueva.** Cuenta: Valeria (ponla «Disponible» con el interruptor de la bandeja) y el
Superadministrador en otro navegador. Acción: comando «Nuevo contacto» de §0.4. Resultado: en segundos la fila aparece
en «Pendientes» de Valeria y en «Todas las conversaciones» del Superadministrador, sin recargar. Óscar (Soporte) no la ve. Registro:
evento «Creada» en el historial; notificación de «conversación pendiente» para los disponibles. Error: Óscar la ve o
tarda más de 20 s.

**10.2 Aceptar.** Cuenta: Valeria. Acción: abrir la fila → «Aceptar». Resultado: pasa a «Mis conversaciones» con
Valeria como responsable; si otra persona acepta a la vez, solo una gana («Otra persona ya aceptó…»). Registro: evento
«Aceptada». Error: dos responsables o aviso de éxito duplicado.

**10.3 Paginación sin tope.** Cuenta: Superadministrador. Acción: pestaña Todas → bajar con scroll o «Cargar más
conversaciones». Resultado: con pocas conversaciones el botón desaparece; nunca se repiten filas. (Con más de 300
conversaciones, en una base de pruebas, la lista sigue más allá de 250). Error: filas repetidas o advertencias de
claves duplicadas en la consola.

**10.4 Búsqueda de contacto y en mensajes.** Cuenta: Superadministrador. Acción: buscar «Ana»; después Filtros →
«Buscar en: Contenido de los mensajes» → «cotización» con la pestaña Todas; luego «the». Resultado: encuentra la
conversación por nombre y por contenido; con «the» aparece «Escribe al menos una palabra de 3 letras o números… Las
palabras muy comunes (como «the» o «for») no se buscan.». Valeria con la misma búsqueda solo encuentra lo suyo; las
cerradas solo aparecen con el permiso de historial. Error: aparecen conversaciones fuera de alcance o la búsqueda en
mensajes encuentra notas internas.

## 11. Mensajes

**11.1 Responder.** Cuenta: Valeria en su conversación. Acción: escribir «Con gusto le ayudo» y Enter. Resultado: la
burbuja pasa por Enviando → Enviado y lleva la etiqueta «No enviado (modo de prueba)». Con `WHATSAPP_SIMULATE_STATUSES` pasa
sola a Entregado/Leído; si no, usa los comandos «Entregado» y «Leído» de §0.4. El administrador lo ve en vivo.
Registro: evento en el historial; mensaje con `client_reference`. Error: el mensaje se duplica al reintentar por red o
aparece «Entregado» sin señal (simulada) de estado.

**11.2 Nota interna.** Cuenta: Valeria. Acción: pestaña «Nota interna — no se envía al cliente» → escribir → guardar.
Resultado: nota con otro color; el Superadministrador la ve en vivo; nunca sale al cliente ni toca la ventana.
Registro: evento «Nota agregada». Error: la nota aparece como mensaje al cliente.

**11.3 Error de envío y reintento.** Cuenta: Valeria. Acción: comando «Error de envío» de §0.4 con el id de su último
mensaje y `--error-code=131026`; después «Reintentar». Resultado: «Fallido» con el motivo; al reintentar vuelve a
la cola en la misma fila. Para «Entrega no confirmada» (`delivery_unknown`, tras `php artisan whatsapp:maintenance
reconcile-deliveries` con un mensaje atascado en Enviando) se ve en ámbar y el reintento pide confirmar el riesgo de
duplicado. Registro: evento «Reintentado»; con riesgo de duplicado, además
`conversation.message_retried_duplicate_risk` en Auditoría. Error: se reintenta sin confirmación o se crea otra fila.

**11.4 Ventana de 24 h.** Cuenta: Valeria. Acción: comando «Ventana de 24 h vencida» de §0.4 y recargar la
conversación. Resultado: «Ventana 24 h: Vencida», el campo libre se bloquea y se ofrece «Enviar plantilla» (no habrá
plantillas aprobadas sin Meta: el selector lo explica). Error: deja enviar texto libre.

**11.5 Duplicado.** Acción: comando «Duplicado» de §0.4. Resultado: el historial no cambia. Error: aparece el mensaje
dos veces.

**11.6 Autor que pierde el permiso.** Cuenta: Valeria y Superadministrador. Acción: detén el worker, Valeria envía un
mensaje, el Superadministrador transfiere la conversación a Soporte, vuelve a arrancar el worker. Resultado: el mensaje
queda fallido con «Quien escribió el mensaje ya no puede responder…». Error: se envía.

## 12. Multimedia

**12.1 Recibir un documento.** Acción: comando «Multimedia con archivo» de §0.4 con un PDF real de tu equipo.
Resultado: tarjeta del documento; se abre o descarga por la ruta autorizada. Sin `--file`: «no disponible». Error:
el archivo se sirve desde una URL pública.

**12.2 Enviar un archivo.** Cuenta: Valeria. Acción: adjuntar una imagen PNG pequeña y enviarla; después intentar un
`.exe` renombrado a `.png` y un archivo mayor de 16 MB. Resultado: el primero se envía (No enviado, modo de prueba); el segundo se
rechaza por contenido; el tercero por tamaño, con el límite en el mismo formato que en el chat interno. Error: se
acepta un ejecutable.

## 13. Asignaciones

**13.1 Asignación automática con capacidad.** Cuenta: Administrador; Valeria Disponible con límite 1 (3.2) y
Demo B · Ventas en modo Automático (5.1). Acción: simular dos contactos nuevos (`5215500000153` y `5215500000154`).
Resultado: Valeria recibe la primera; la segunda queda Pendiente en la cola. Al cerrar o liberar la primera, la
segunda se le asigna sola. Subir el límite a 3 le reparte lo pendiente; vaciar el campo hereda el del departamento o
de la plataforma. Registro: eventos «Asignada automáticamente». Error: recibe más de su límite o la cola no se mueve.

**13.2 Asignar a alguien al límite.** Cuenta: Administrador. Acción: en una pendiente, menú → Asignar → Valeria (al
límite). Resultado: insignia «Al límite de carga», advertencia ámbar y casilla; sin marcarla el botón queda
deshabilitado. Registro: evento «Asignada» con `assigned_over_limit` y `limit`. Valeria: la recibe. Error: asigna sin
confirmar.

**13.3 Asignar a alguien No disponible.** Igual que 13.2 con Óscar «No disponible» en un departamento que él atiende.
Resultado: confirmación obligatoria; evento con `assigned_while_unavailable`.

## 14. Transferencias

**14.1 Transferir a otro departamento eligiendo persona.** Cuenta: Valeria (responsable). Acción: menú → Transferir →
departamento Soporte → elegir a «Óscar · Disponible» → motivo «Es de Soporte» → Transferir. Resultado: la conversación
desaparece en vivo de la bandeja de Valeria (y si intenta abrirla, 404); Óscar la recibe en vivo en «Mis
conversaciones». En la lista de destinatarios también pueden aparecer personas de alcance global con permiso de ver y
responder (el Superadministrador y el Administrador), marcadas con su disponibilidad. Registro: evento «Transferida»
con motivo, de Valeria a Óscar. Error: la lista de Soporte sale vacía o Valeria sigue viendo la conversación.

**14.2 Destino no disponible o al límite sin permiso de asignar.** Cuenta: Valeria. Acción: Transferir y mirar a una
persona No disponible. Resultado: opción deshabilitada con «Transfiérela a la cola del departamento»; transferir a la
cola (sin persona) siempre funciona y la deja Pendiente. Error: Valeria puede forzar la excepción.

## 15. Disponibilidad

> **Retirado en la fase 5:** ya no existe la disponibilidad manual (ni el interruptor, ni `user.availability_changed`
> nuevos, ni las confirmaciones de §13.3 y §14.2 por «No disponible»; el límite de carga sí se sigue confirmando).
> La columna «Disponibilidad inicial» de §0.1 es histórica. Pruebas vigentes en §29.9.

**15.1 Cambiar disponibilidad.** Cuenta: Óscar en dos pestañas. Acción: interruptor «Disponible / No disponible» en la
bandeja. Resultado: la otra pestaña se actualiza en vivo; los diálogos de asignar y transferir abiertos por un
administrador muestran el cambio. Estar No disponible no le quita lo que atiende; impide aceptar nuevas y recibir
automáticas. Registro: `user.availability_changed`. Error: se le quitan conversaciones o no se audita.

## 16. Chat interno

**16.1 Chat privado y «Visto».** Cuenta: Valeria y Óscar (si comparten departamento o con permiso entre
departamentos; si no, usa el Superadministrador con Valeria). Acción: Chat interno → Nuevo → Chat privado → escribir.
Resultado: llega en vivo; «Visto» aparece cuando el otro lo lee. El Superadministrador no puede abrir chats en los que
no participa (404). Error: un tercero ve el chat.

**16.2 Grupo, menciones y notificación.** Cuenta: Superadministrador crea un grupo con Valeria y Óscar. Acción:
escribir «@Val» y elegir con Enter; enviar. Resultado: sugiere solo participantes activos (no a sí mismo); Valeria
recibe una notificación urgente de mención aunque haya silenciado el grupo; editar el mensaje no vuelve a notificar;
en un chat privado no hay sugerencias. Registro: `team_chat.group_created`, `team_chat.participants_added`. Error:
menciona a quien no participa o notifica dos veces.

**16.3 «Escribiendo…».** Acción: con el grupo abierto en dos navegadores, escribir sin enviar. Resultado: el otro ve
«X está escribiendo…», que desaparece unos 5 s después o al llegar el mensaje. Con Reverb detenido, la petición
`POST …/typing` responde 204 (pestaña Red) y no hay error. Error: respuesta 500.

**16.4 Reacciones.** Acción: pasar el cursor sobre un mensaje → icono de carita → 👍. Resultado: chip «👍 1» resaltado,
visible al instante para el otro; pulsar de nuevo lo retira; doble clic rápido no duplica; en un grupo archivado no hay
botón; un mensaje eliminado pierde sus reacciones. Error: reacciones duplicadas o en mensajes eliminados.

**16.5 En línea.** Acción: con ambos navegadores abiertos, mirar el avatar del chat privado. Resultado: anillo de color
y «En línea · Disponible/No disponible»; al cerrar la pestaña del otro desaparece en unos segundos. En DevTools → WS,
los datos del canal de presencia solo llevan `{"id":N}`. Error: se ve en línea a personas de otros departamentos que no
puedes contactar, o la carga útil incluye nombres.

**16.6 Expulsión.** Acción: el administrador del grupo retira a Óscar mientras él tiene el grupo abierto. Resultado:
Óscar pierde el grupo y su historial al instante; si intenta enviar, 404. Registro: `team_chat.participant_removed`.

## 17. Notificaciones

**17.1 Campana.** Cuenta: Valeria Disponible. Acción: simular un contacto nuevo. Resultado: la campana suma una
notificación de conversación pendiente en segundos (sin tiempo real, en ≤ 60 s); «Marcar todas como leídas» funciona.
Error: notificaciones de departamentos ajenos.

**17.2 Preferencias y aviso del navegador.** Cuenta: Valeria. Acción: Configuración → Notificaciones → activar
«Navegador» para «Conversación pendiente» → «Permitir en este navegador»; cambiar a otra pestaña y simular otro
contacto. Resultado: aparece un aviso del sistema (solo con la pestaña oculta). Error: el permiso se pide sin pulsar
el botón.

**17.3 Web Push (con la plataforma cerrada).** Requisitos: claves VAPID (`php artisan webpush:vapid`, copiar las dos
líneas a `.env` con `VAPID_SUBJECT=mailto:tu-correo`, `php artisan config:clear`), un contexto seguro (**en local
funciona en `http://localhost:8000`**; en otro host hace falta HTTPS) y el worker de la cola `notifications` activo.
Cuenta: Valeria. Acción: Configuración → Notificaciones → tarjeta «Notificaciones push» → «Activar en este navegador»
→ aceptar el permiso; activar «Navegador» para un tipo (por ejemplo «Acceso modificado») y guardar; **cerrar todas las
pestañas de la plataforma**; desde el Superadministrador cambiar los departamentos de Valeria (y devolverlos después).
Resultado: llega «Notificaciones nuevas: Tienes N notificaciones sin leer.»; al pulsarlo se abre `/dashboard`. Varias
seguidas: un solo push por minuto. Con una pestaña abierta pero oculta y la suscripción activa, solo aparece el aviso
del push (no se duplica con el de la pestaña); con la ventana enfocada no aparece nada. Tras cerrar sesión llega el
aviso genérico «Inicia sesión para verlas». La tarjeta lista el dispositivo («Chrome en macOS», «este navegador») y
permite eliminarlo. Sin claves VAPID la tarjeta explica que el servidor no está configurado; por http en una IP explica
que hace falta HTTPS. Registro: notificación in-app guardada; en el log solo avisos sin el endpoint. Error: el aviso
muestra contenido de la conversación, llegan dos avisos por el mismo hecho o se puede suscribir un endpoint que no es
de un servicio push.

## 18. Reportes

**18.1 Reporte operativo.** Cuenta: Superadministrador. Acción: Reportes → periodo «Últimos 7 días». Resultado:
recibidas, atendidas, primera respuesta humana (calendario y hábil), resolución, mapa de calor y tablas por agente,
departamento y conexión. Una conversación solo asignada automáticamente no cuenta como atendida. Valeria ve solo sus
cifras. Error: Valeria ve a otros agentes.

**18.2 Objetivos de servicio.** Cuenta: Administrador. Acción: Configuración → «Objetivos de servicio» → primera
respuesta 15 min → guardar. Resultado: en Reportes aparece «Objetivo de primera respuesta» con % y «X de Y dentro de 15
min hábiles»; con el campo vacío no hay tarjeta; 0 o texto dan error. Registro: `settings.updated`. Error: muestra
cumplimiento sin objetivo.

**18.3 Tiempo hábil.** Acción: activar un horario L-V 09:00–18:00 y ejecutar `php artisan analytics:rebuild`; después
`php artisan analytics:rebuild --business-time`. Resultado: el primero conserva los tiempos hábiles ya calculados; el
segundo los recalcula con el horario vigente. Error: el tiempo hábil cambia sin `--business-time`.

## 19. Excel y PDF

**19.1 Exportar.** Cuenta: Superadministrador. Acción: Reportes → Exportar → Excel; después PDF. Resultado: el archivo
se descarga; en la pestaña Red se ve un **POST** `/exports/analytics/xlsx` con `_token`; abrir a mano
`GET /exports/tags/xlsx` responde 405. El orden de agentes coincide con la pantalla y en el PDF la fila Total va pegada
a las últimas filas. Registro: `export.requested` y `export.generated`. Error: un GET genera el archivo o aparece «Sin
datos» en la tabla.

**19.2 Exportación en cola.** Acción: Conversaciones → Exportar con muchos filtros abiertos (o `EXPORTS_XLSX_SYNC_ROWS=0`
y `config:clear`). Resultado: vuelve a la página con «Estamos generando…»; al terminar, notificación y descarga en
Exportaciones. La descarga vuelve a comprobar el permiso del reporte. Error: el archivo de otra persona se descarga
(debe dar 404).

**19.3 Búsqueda en mensajes en el export.** Acción: en la bandeja, «Buscar en: Contenido de los mensajes» = «cotización»
y exportar. Resultado: la fila de filtros dice «Búsqueda en el contenido de los mensajes: «cotización»».

## 20. Importaciones

**20.1 Importar contactos.** Cuenta: Superadministrador (único con `contacts.import`). Acción: Contactos → Importar →
descargar la plantilla → llenar 3 filas con teléfonos ficticios `5215500000170`–`172` (una con error a propósito) →
subir. Resultado: vista previa con errores por fila; al confirmar, el lote se procesa en la cola `exports` y muestra su
reporte; se puede revertir el lote. Registro: `import.requested`, `import.completed` (restringidas: solo las ve el
Superadministrador), `import.rolled_back` al revertir. El Administrador no ve el importador. Error: el Administrador
puede importar o la reversión borra contactos modificados después.

## 21. Auditoría

**21.1 Bitácora.** Cuenta: Administrador. Acción: Administración → Auditoría → filtrar por acción «Conexión
desactivada» y por actor. Resultado: aparecen las entradas de las pruebas anteriores con actor, sujeto, IP y fecha;
las acciones del Superadministrador **no** las ve el Administrador (restringidas). Exportar la bitácora genera el
archivo. Error: se puede editar o borrar una entrada, o el Administrador ve entradas restringidas.

## 22. Configuración

**22.1 Operación.** Cuenta: Administrador. Acción: Configuración → Operación → «Máximo de conversaciones por agente»
= 2 → guardar. Resultado: subirlo reparte las colas automáticas; bajarlo no quita conversaciones. Registro:
`settings.updated`. Error: bajarlo desasigna conversaciones.

**22.2 Horario y días no laborables.** Acción: definir un horario y un día festivo; simular un mensaje fuera de
horario. Resultado: mensaje automático de fuera de horario si está activo en el departamento (con «No enviado (modo de prueba)»).
Registro: `settings.updated` / `department.hours_updated`.

**22.3 Apariencia de la plataforma.** Acción: Administración → Apariencia → cambiar nombre y color. Registro:
`appearance.updated`.

## 23. Salud del sistema

**23.1 Página de Sistema.** Cuenta: **Superadministrador** (el Administrador no tiene `system.view`: decisión
pendiente, §0.1). Acción: Administración → Sistema. Resultado: 7 colas, entre ellas «Señales de tiempo real
(realtime)», con acumulación y espera; latido del programador; trabajos fallidos con reintentar/descartar. Con el
worker detenido y un par de mensajes simulados, la cola aparece acumulada. Registro: `system.job_retried` /
`system.job_forgotten`. Error: el Administrador entra a Sistema sin el permiso.

**23.2 Preflight.** Cuenta: ninguna (terminal). Acción: `php artisan app:preflight`. Resultado: columnas CORRECTO /
ADVERTENCIA / BLOQUEANTE / NO APLICA; «Datos de demostración» en ADVERTENCIA (hay usuarios y líneas demo; en
producción sería BLOQUEANTE); Redis NO APLICA si no se usa; «Respaldos de la base» NO APLICA sin `OPS_BACKUP_DIR`;
«Notificaciones push» NO APLICA sin claves VAPID. Error: algo BLOQUEANTE fuera de producción.

## 24. Seguridad

**24.1 Aislamiento entre departamentos.** Cuenta: Óscar. Acción: abrir a mano `/conversations?c=<id de una conversación
de Valeria>` y su `…/timeline`. Resultado: 404 (no 403). Error: ve el chat o la respuesta revela que existe.

**24.2 Disponibilidad de correo fuera de alcance.** Cuenta: el supervisor de prueba. Acción: desde DevTools,
`POST /admin/users/email-availability` con el `user_id` del Administrador y luego con `999999`. Resultado: ambos 404.

**24.3 CSRF.** Acción: repetir un `POST` (por ejemplo una exportación) sin `_token`. Resultado: 419. Error: se acepta.

**24.4 Cabeceras y adjuntos.** Acción: abrir un adjunto y revisar las cabeceras en Red. Resultado:
`X-Content-Type-Options: nosniff`, `Cache-Control: private` y CSP con `sandbox` (salvo PDF). Error: un HTML adjunto se
ejecuta en el navegador.

## 25. Modo oscuro

**25.1 Apariencia personal.** Cuenta: cualquiera. Acción: Configuración → Apariencia → Oscuro; recorrer Panel,
Conversaciones (incluido el compositor bloqueado y «Entrega no confirmada»), Chat interno (reacciones, «En línea»),
Reportes y Notificaciones push. Resultado: contraste legible y colores del tema en todo; al elegir «Sistema» sigue al
sistema operativo. Error: textos invisibles, fondos blancos o iconos sin contraste.

## 26. Dispositivos móviles

**26.1 Bandeja en móvil.** Cuenta: Valeria. Acción: DevTools → modo dispositivo 390×844 (o un teléfono en la misma red
usando la IP del equipo; el tiempo real puede requerir ajustar `REVERB_*`). Recorrer: lista → abrir conversación →
«Volver»; abrir detalles como panel lateral; enviar un mensaje y un adjunto; diálogo de transferencia. Resultado: sin
desbordes horizontales, botones accesibles con el dedo y el teclado no tapa el compositor. Error: elementos cortados o
el diálogo no cabe.

**26.2 Chat interno y notificaciones en móvil.** Acción: lista de chats, grupo con menciones y reacciones, campana.
Resultado: utilizable con una mano. Web Push en móvil: Android con Chrome sobre HTTPS; iOS no (requiere instalar la
plataforma como app, no disponible).

## 27. Pruebas que requieren credenciales oficiales de Meta

No se pueden hacer en local con el transporte simulado. Siguen la activación real paso a paso de
[mensajeria-whatsapp.md §9](mensajeria-whatsapp.md#9-activación-real-paso-a-paso), en un servidor con HTTPS válido,
con una app de Meta, WABA, número de prueba y token permanente, `WHATSAPP_TRANSPORT=meta` y, para limitar el riesgo,
`WHATSAPP_PILOT_RECIPIENTS=<tu número>`:

1. **Verificar credenciales** de la conexión: debe pasar a «credenciales verificadas» y, con tráfico, «Operativa».
2. **Verificación del webhook** (GET con `hub.verify_token`) desde el panel de Meta y **firma** real
   `X-Hub-Signature-256` de cada POST.
3. **Suscripción de la app** (`subscribed_apps`) desde la ficha de la conexión.
4. **Ida y vuelta real**: escribir desde tu WhatsApp al número de prueba, responder desde la bandeja y ver
   Enviado → Entregado → Leído reales (sin «No enviado (modo de prueba)»).
5. **Piloto**: un envío a un número que no esté en `WHATSAPP_PILOT_RECIPIENTS` falla con «El destinatario no está en la
   lista del piloto…» sin llamar a Meta; la ficha muestra «Piloto controlado».
6. **Multimedia real**: recibir imagen, audio y documento (descarga desde `lookaside.fbsbx.com`, incluido el corte por
   tamaño) y enviar un archivo (subida multipart).
7. **Plantillas**: sincronizar, recibir el webhook de aprobación y enviar una plantilla con la ventana cerrada.
8. **Errores reales**: ventana vencida (131047), límites (429/130429) y textos de cURL del servidor que distinguen «no
   llegó a Meta» de «Entrega no confirmada».
9. **Credenciales rechazadas**: con un token revocado, la verificación falla y el compositor bloquea el envío con
   «Meta rechazó las credenciales…»; un reintento y una plantilla también responden 409.

Registra los resultados y cualquier diferencia de formato en
[mensajeria-whatsapp.md §10](mensajeria-whatsapp.md#10-lo-que-no-se-ha-validado-contra-meta-real).

## 28. Chat web e integraciones

**Antes de empezar.** La base local `chatmanager` **no** tiene todavía las tablas de la fase 4: hay que decidir y
ejecutar a mano `php artisan migrate` (crea `web_chat_widgets`, `web_chat_sessions` y `api_tokens`, aditivas) y
`php artisan app:sync-permissions` (crea `integrations.view` e `integrations.manage`), con respaldo previo. Construye el
widget con `npm run build` (o `npm run build:widget`; `npm run dev:widget` lo reconstruye al guardar). Para la página de
ejemplo: `WEBCHAT_DEMO_ENABLED=true` en `.env` y `php artisan config:clear`. Detalle del canal en
[canal-chat-web.md](canal-chat-web.md) y de la integración en [widget-integracion.md](widget-integracion.md).

**28.1 Crear una conexión de chat web.** Cuenta: Superadministrador. Acción: Conexiones → Nueva conexión → «Chat web»;
nombre «Chat web local», departamento Demo B · Ventas; en el widget, origen `http://localhost:8000` (el de tu
`APP_URL`), título «Hola»; guardar y activar. Resultado: la fila muestra el icono y la etiqueta «Chat web»; la salud
pasa de «Sin configurar» a «Configurada»; la tarjeta muestra el código para insertar y la vista previa real. Registro:
`web_chat.widget_updated` en Auditoría. Error: se puede activar sin orígenes, `http://tienda.com` (http no local) se
acepta o la vista previa abre una sesión.

**28.2 Widget en la página de ejemplo.** Cuenta: ninguna (visitante). Acción: abrir
`http://localhost:8000/widget/v1/demo/{clave pública}`, pulsar el botón, escribir «Quiero información». Resultado: el
mensaje queda «Enviado»; en la bandeja general de Valeria aparece en vivo una conversación pendiente «Visitante web …»
con el canal Chat web, el panel de detalles dice «Visitante web» y la ventana «No aplica». Error: el contacto se crea
al abrir el widget sin escribir, o aparece en Soporte.

**28.3 Respuesta y acuses.** Cuenta: Valeria. Acción: aceptar y responder «Con gusto». Resultado: el visitante recibe la
respuesta en segundos (con Reverb) o en ≤ 4 s (sondeo); al verla, la burbuja del agente pasa a «Entregado» y «Leído»;
la fila sigue en su sitio con «En atención». Error: la respuesta no llega sin recargar o se duplica tras una recarga del
visitante.

**28.4 Origen no permitido.** Acción: servir el mismo fragmento desde otro origen (por ejemplo
`python3 -m http.server 8099` con una página que lo contenga, en `http://127.0.0.1:8099`). Resultado: el widget no monta
(la consola muestra el bloqueo de `frame-ancestors`) y a los 20 s `[ChatManager] El chat no respondió…`; no se crea
ninguna sesión. Error: el chat funciona desde un origen no listado.

**28.5 Rotar la clave.** Cuenta: Superadministrador. Acción: «Rotar clave» y confirmar; recargar la página de ejemplo
antigua. Resultado: la sesión anterior deja de funcionar y el fragmento viejo responde 404; con la clave nueva vuelve a
funcionar. Registro: `web_chat.key_rotated`. Error: la clave vieja sigue sirviendo.

**28.6 Identidad firmada.** Cuenta: Superadministrador. Acción: modo «Identificación opcional», «Generar secreto»
(se muestra una sola vez); con el ejemplo PHP de [canal-chat-web.md §5](canal-chat-web.md#5-identidad-firmada) generar
una identidad y llamar a `ChatManager('identify', …)` desde la consola de la página de ejemplo. Resultado: la sesión
queda identificada; repetir la misma firma (mismo nonce) falla con `identity_rejected`. Error: una firma reutilizada o
con otro secreto se acepta.

**28.7 Conexión desactivada.** Acción: desactivar la conexión con el widget abierto y escribir. Resultado: el widget
muestra que el chat no está disponible (409 `widget_unavailable`) sin perder la sesión; al reactivar, vuelve a
funcionar. Error: el mensaje se guarda o la sesión se revoca.

**28.8 Token de integración.** Cuenta: Superadministrador (o quien tenga `integrations.manage`). Acción: Administración
→ Integraciones → emitir un token con «messages:send» y «conversations:read» para «Chat web local», con vencimiento en
30 días; copiarlo. Resultado: el token completo se muestra una sola vez; la lista muestra «Activo». Registro:
`api_token.issued` (sin el secreto). Después, con los ejemplos `curl` de [api-v1.md §9](api-v1.md#9-ejemplos-con-curl):
`GET /api/v1/health` responde 200; `GET /api/v1/conversations/{id}` de una conversación de WhatsApp (fuera de la
allowlist) responde 404; `POST …/messages` con `Idempotency-Key` responde 201 y repetirlo, 200; la respuesta aparece en
la bandeja con el nombre del token. Revocar con motivo: la siguiente solicitud responde 401. Error: un token revocado
sigue funcionando, se ve una conversación fuera de la allowlist o el reintento duplica el mensaje.

**28.9 Toast con X.** Cuenta: Valeria. Acción: con una notificación nueva en pantalla, pulsar la X «Cerrar
notificación». Resultado: el toast desaparece y la campana conserva su contador (cerrar no es leer). Error: la
notificación queda leída.

## 29. Fase 5

**Antes de empezar.** Las 7 migraciones de la fase y la actualización de permisos **no se han ejecutado** en
`chatmanager`. Para probar a mano hay que decidirlo: respaldo (§0.3), `php artisan migrate`,
`php artisan app:sync-permissions --upgrade-default-roles` (vista previa) y `--apply`, `npm run build`
([despliegue fase 5](infraestructura-y-despliegue.md#procedimiento-de-la-fase-5)). Crea dos agentes del mismo
departamento (A y B) y un Supervisor de ese departamento (módulo 3). Abre cada cuenta en un perfil de navegador
distinto. Si algo de esta sección contradice §13.3, §14.2 o §15, vale esta sección.

**29.1 Bandeja: indicadores y Filtros.** Cuenta: agente A. Resultado: arriba solo «Bandeja general», «Pendientes» (con
contador) y «No leídas» (con contador); todo lo demás está en el botón **Filtros** (muestra el número de filtros
aplicados; en móvil abre una hoja inferior). Elegir Estado «En atención», un departamento y dos etiquetas y pulsar
«Aplicar» (desde la fase 5.1 nada cambia antes): aparecen chips removibles bajo la búsqueda y «Limpiar filtros». Copiar la URL, abrirla en otra pestaña: mismos filtros. Quitar
un chip: la lista y los contadores cambian. «Limpiar filtros» vuelve a la bandeja general. Error: filtros que no se
reflejan en la URL o contadores distintos de lo listado.

**29.2 Etiquetas O / Y.** Con dos etiquetas aplicadas a conversaciones distintas y una conversación con ambas: modo
«Con al menos una de estas etiquetas» lista las tres; «Con todas estas etiquetas a la vez» solo la que tiene ambas (`tag_match=all` en la URL). Error: el segundo
modo muestra conversaciones con una sola etiqueta, o aparecen conversaciones fuera del alcance.

**29.3 Etiquetas desde la fila.** Menú ⋮ de una fila → «Administrar etiquetas»: el diálogo dice que se aplican a la
conversación; añadir una y guardar. Resultado: la fila muestra la etiqueta sin abrir el chat; auditoría de etiquetas.
Sin `tags.assign` la opción no aparece.

**29.4 Tipografía y diseño compacto.** Revisar la lista a 1280 px y a 1920 px: nombre, vista previa y hora legibles
sin cortes raros; filas compactas; nada se superpone. Error: texto diminuto, saltos de línea que rompen la fila.

**29.5 Alias privado.** Cuenta: agente A. En una conversación, encabezado o panel de detalles → poner alias «Cliente
VIP». Resultado: A ve «Cliente VIP» (con la marca «alias») en la lista, el encabezado y los detalles; el nombre oficial
sigue visible en la descripción. Agente B (o el Superadministrador en otra sesión) ve el nombre oficial. El contacto,
su teléfono y el destinatario de los envíos no cambian; exportaciones y notificaciones usan el nombre oficial.
Consulta: el Administrador entra a «Administración › Elementos personales» y ve el alias de A con el nombre oficial;
con `supervision.manage-personal` lo edita o elimina (auditoría `supervision.personal_item_updated/deleted`, sin el
texto del alias). Un Supervisor sin esos permisos no ve la página.

**29.6 Apariencia del chat.** Cuenta: agente A. Abrir «Apariencia del chat»: probar cada paleta y «Elige cada color»;
la vista previa cambia al instante. Elegir un texto con poco contraste (p. ej. gris claro sobre blanco) y guardar:
el servidor lo sustituye por casi negro o blanco y avisa. «Deshacer cambios» vuelve a lo guardado; «Restablecer
predeterminados» vuelve a los colores de la plataforma. Alcance: «Solo esta conversación» frente a «Todas mis
conversaciones» (la de conversación prevalece). Comprobar en modo claro y oscuro. Agente B abriendo la misma
conversación ve los colores normales; el Administrador en Elementos personales ve una muestra, pero su propio chat
no cambia. Error: los colores llegan a otro usuario o un texto ilegible se guarda tal cual.

**29.7 Contactos provisionales.** Simulador: `php artisan chat-manager:simulate-inbound` con un número nuevo. Resultado:
la conversación aparece en la bandeja normalmente; en Contactos el número **no** aparece. Activar «Incluir no
guardados» (`include_provisional=1`): aparece con la insignia de no guardado. Desde el panel de detalles →
«Guardar contacto» (con `contacts.create`), escribir el nombre y guardar: el botón desaparece y el contacto aparece
en el directorio sin el filtro. Registro: `contact.saved`. Los contactos que ya existían antes de la migración siguen
todos guardados. Error: un provisional cuenta en reportes/etiquetas de contactos por defecto, o guardar cambia el
teléfono.

**29.8 Sin «En espera».** Revisar bandeja (filtros, indicadores), acciones del chat, panel de detalles, panel principal
y reportes: no hay «En espera», ni «Poner en espera» ni «Retomar». Un enlace viejo con `status=on_hold` muestra las
activas. API v1: `GET /api/v1/conversations?status=on_hold` responde 422. Comando de retiro **solo sobre una copia o
`chatmanager_testing`, nunca sobre `chatmanager`**: con `DB_DATABASE` apuntando a la copia,
`php artisan conversations:retire-on-hold` lista cada conversación en espera con su destino («En atención» o
«Pendiente (cola)») sin escribir; repetirlo da el mismo resultado. `--apply` solo con autorización.

**29.9 Sin interruptor de disponibilidad.** No existe «Disponible / No disponible» en la bandeja ni en el menú. Agente
A acepta una pendiente: funciona. En un departamento con distribución automática, una conversación nueva se asigna
sola a un agente activo con capacidad. Desactivar la cuenta de B o quitarlo del departamento: deja de recibir.
Chat web: con la conexión activa y el horario abierto, el widget dice disponible si hay al menos un miembro activo
del departamento por defecto que pueda atender; quitando a todos los miembros, aparece como no disponible.
Panel principal: «Agentes activos».

**29.10 Supervisor tras transferir.** Cuenta: Supervisor de Ventas. Un agente transfiere una conversación de Ventas a
Soporte. Resultado: el Supervisor sigue viéndola (y su historial, también cerrada) pero sin Responder, Aceptar,
Transferir, Asignar, Cerrar ni Etiquetar; un intento por URL o petición manipulada responde 403/404. Error: el
supervisor puede escribir o la conversación desaparece.

**29.11 Insignias operativas por permiso.** Agente A: las filas no muestran departamento, responsable ni conexión.
Supervisor (con `conversations.supervise` o `conversations.assign`) y Administrador: sí los ven.

**29.12 Estado de entrega lista frente a chat.** Con `WHATSAPP_SIMULATE_STATUSES=true`, abrir la bandeja en dos
pestañas; en una, abrir el chat y enviar. Resultado: la vista previa de la fila y la burbuja pasan por Enviado →
Entregado → Leído al mismo tiempo en ambas pestañas, sin retroceder nunca. Error: la lista se queda en «Enviado».

**29.13 Respuestas rápidas personales.** Administrador: Usuarios › Editar agente A → «Puede crear respuestas rápidas
personales» (auditado `user.personal_quick_replies_granted`). Agente A: Respuestas rápidas → «Mis respuestas» → crear
una. En el chat, «/» la muestra agrupada como propia. Agente B no la ve (ni por id manipulado: 404). Revocar el
permiso: A sigue viéndola y usándola, pero ya no puede editarla, crear ni eliminar; nada se borra. Administrador con
`supervision.view-personal`: pestaña «De otros usuarios» con filtros por usuario y departamento; con
`manage-personal`, editar o eliminar (auditado, solo con el título).

**29.14 Plantillas solo administrativas.** Agente y Supervisor: el botón de plantillas no aparece en el compositor y
el envío por petición manual responde 403. Administrador (`templates.send`): puede enviarlas.

**29.15 Ventana de 24 h.** En una conversación de WhatsApp con mensaje reciente del cliente, el indicador muestra
«Quedan …; vence el …» y cuenta hacia atrás sin recargar. Fuera de la ventana (simula un último mensaje de hace más
de 24 h o espera): el compositor bloquea el texto libre y explica por qué (el Administrador puede ofrecer plantilla).
El cliente vuelve a escribir: la ventana se reabre en vivo. Chat web: «No aplica».

**29.16 Adjuntos por canal.** WhatsApp: imagen JPEG/PNG ≤ 5 MB, audio/video ≤ 16 MB, documentos PDF/TXT/Office ≤ 100 MB
(límite real según PHP/Nginx, ver [infraestructura §6](infraestructura-y-despliegue.md#6-https-y-nginx)); el
compositor muestra tipo, tamaño, vista previa y progreso, y «Cancelar» detiene la subida. Un ZIP se anuncia «Se
enviará como enlace de descarga seguro…» y la burbuja dice «Enviado como enlace de descarga seguro»; abrir el enlace
descarga; «Revocar enlace» (con confirmación) lo invalida. Errores claros con un archivo de otro tipo, uno renombrado
(`.exe` a `.pdf`: se detecta el tipo real) o uno demasiado grande. Doble clic en Enviar no duplica. Chat web: hasta
25 MB, ZIP nativo.

**29.17 Messenger e Instagram — pendiente de validación real.** Solo con una app real de Meta, sus permisos aprobados y
HTTPS público: seguir la lista de [meta-messenger-instagram.md §9](meta-messenger-instagram.md#9-validación-con-una-app-real-pendiente).
Sin esa app no hay nada que probar a mano: lo implementado se validó solo con simulaciones.

**29.18 PWA.** Requiere HTTPS o `http://localhost`. Chrome/Edge: menú de usuario → «Instalar aplicación»; se abre en
ventana propia con el icono. iOS (Safari): la opción muestra «Compartir → Agregar a inicio». Actualización: cambiar
`VERSION` en `public/sw.js`, recargar: aparece «Hay una nueva versión disponible» → «Actualizar» recarga una vez.
Sin conexión (DevTools → Offline) y navegar: página «Sin conexión» con «Reintentar»; al volver la red, recarga sola.
En DevTools → Application → Cache Storage solo hay archivos estáticos (`/build/assets`, iconos, offline), ningún HTML
ni JSON. Cerrar sesión: la caché queda vacía; otra persona inicia sesión en el mismo navegador y no ve nada del
anterior. Detalle: [infraestructura §20](infraestructura-y-despliegue.md#20-aplicación-instalable-pwa).

**29.19 Auditoría legible.** Conexiones → verificar credenciales; en Auditoría la entrada dice «Credenciales de la
conexión verificadas» con el resultado en español, sujeto con enlace y quién/cuándo. La sección técnica (clave, datos
crudos) solo aparece con `system.view` o `audit.export`.

**29.20 Errores en lenguaje claro.** Enviar un formulario vacío (usuario, contacto): mensajes en español con el nombre
del campo («correo electrónico»), nunca claves. Un error de Meta simulado muestra la explicación; el detalle técnico
solo al administrador en «Detalles técnicos». Una URL inexistente muestra la página 404 en español.

**29.21 Ayuda, tarjetas y títulos.** En Conversaciones, Contactos, Respuestas rápidas, Conexiones, Usuarios, Reportes
y Auditoría abrir el botón global de ayuda (brújula; desde la fase 5.1 sustituye a «¿Cómo funciona?», ver §30.11) →
«Ver la guía completa del módulo»: se abre el panel con objetivo, pasos, permisos y errores comunes. Panel principal
y departamentos: tarjetas de cifras compactas. Ninguna página repite su título (encabezado del layout + título de
página). Error: panel vacío, texto en inglés o título duplicado.

**29.22 Responsive y modo oscuro.** A 375, 768, 1280 y 1920 px, en modo claro y oscuro: bandeja, chat, filtros,
diálogos de alias, apariencia, guardar contacto y elementos personales sin desbordes horizontales ni textos
ilegibles. Error: contenido cortado o contraste insuficiente.

## 30. Fase 5.1

**Antes de empezar.** La fase 5.1 no tiene migraciones. `npm run build` (o `composer run dev`) y
`php artisan queue:restart` para que los workers carguen el código nuevo
([despliegue fase 5.1](infraestructura-y-despliegue.md#procedimiento-de-la-fase-51)). Cuentas: el Administrador (rol
«Administrador», global, no raíz), un Supervisor de Ventas (alcance de departamentos), agentes A y B de Ventas y C de
Soporte, cada uno en un perfil de navegador distinto, y el Superadministrador solo donde se indica. Comprueba en cada
caso el rol **real** del usuario en Usuarios (no un rol con nombre parecido). Si algo contradice §29, vale esta sección.

**30.1 El administrador ve las conversaciones de sus agentes.** Cuenta: Administrador, sin tocar filtros. Simular un
mensaje nuevo (§0.4) y que A lo acepte y responda. Resultado: la bandeja abre en «Todas las conversaciones» y la
conversación aparece en vivo, sigue ahí tras aceptarla A y su chat muestra las respuestas sin recargar. «Pendientes» y
«No leídas» cuentan lo de toda la organización. Su propio trabajo está en Filtros → Vista → «Bandeja general» o «Mis
conversaciones». Nunca aparece «Todas las visibles». Error: hay que elegir una vista para verla.

**30.2 Supervisor por departamentos.** Cuenta: Supervisor de Ventas. Resultado: la vista por omisión se llama
«Conversaciones de mis departamentos» y lista las de Ventas, atienda quien las atienda. Sin sonido ni aviso por cada
mensaje de una conversación ajena: solo se actualizan la lista y los contadores. Error: suena o aparece un _toast_ por
lo que solo observa.

**30.3 Conversaciones transferidas.** A transfiere una conversación de Ventas a la cola de Soporte; C la acepta y la
cierra. Resultado: el Administrador la sigue viendo en vivo (fila y chat, «Cerrada» al final, consultable en Filtros →
Estado «Cerradas»); el Supervisor de Ventas la sigue viendo de solo lectura (sin compositor ni acciones); A deja de
verla y abrirla por URL da 404. Error: desaparece para el Administrador o A la sigue viendo.

**30.4 Alcance del agente.** Cuenta: agente A. Resultado: la bandeja abre en «Bandeja general» (pendientes que puede
aceptar + las que atiende); no ve las que atiende B; una conversación que solo vivió en Soporte da 404 por URL; el
Supervisor de Ventas tampoco la ve. Error: un agente ve conversaciones de compañeros.

**30.5 Retirar y conceder «Transferir».** Con B con una conversación abierta en dos pestañas: el Administrador quita
«Transferir conversaciones» del rol que B tiene realmente. Resultado: el aviso al guardar dice a cuántos usuarios
activos afecta (o que no afecta a nadie si el rol no tiene titulares, con un aviso amarillo); en las dos pestañas de B
desaparece «Transferir» sin recargar y aparece «Tus permisos se actualizaron»; un POST directo de transferencia
responde 403. Volver a concederlo: la acción regresa en ambas pestañas. Si B conserva el permiso por otro rol o una
concesión directa, el editor y el aviso lo dicen, y en Usuarios se explica de dónde viene. Error: hay que recargar o
cerrar sesión para ver el cambio, o la petición directa funciona.

**30.6 Sesión activa tras cambios de rol o alcance.** Con B conectado, cambiar el alcance del rol o el rol de B.
Resultado: en segundos sus páginas abiertas muestran el menú y las acciones nuevas, sin bucles de recarga y con un solo
aviso. Volver a la pestaña tras un rato también lo pone al día. Error: menús o botones viejos que el servidor rechaza.

**30.7 Cerrar sesión sin recargar.** En Chrome con un perfil de uso real (caché grande), con la PWA/_service worker_
activo y con dos pestañas: menú → «Cerrar sesión» → confirmar. Resultado: en pocos segundos llega al inicio de sesión
sin recargar a mano; «Atrás» no muestra páginas de la sesión. Con DevTools → Offline o el servidor detenido: el diálogo
muestra un mensaje claro y «Intentar de nuevo» (nunca gira para siempre, máximo 15 s) y la sesión y los avisos push
siguen activos. Con la sesión ya vencida: va directo al inicio de sesión. En la respuesta (Network) no hay
`Clear-Site-Data`. Error: «Cerrando sesión…» indefinido.

**30.8 Fechas del chat sin encimarse.** Abrir una conversación con mensajes de varios días y desplazarse arriba y abajo
a 375 y 1280 px, claro y oscuro, y con zoom 200 % y 400 %. Resultado: un solo separador por día («Hoy», «Ayer» o la
fecha completa); el del día siguiente empuja al anterior, nunca dos superpuestos; «Ir al final» queda dentro del
historial y el compositor accesible. Error: fechas apiladas o tapando mensajes.

**30.9 Filtros sin cortes.** A 1440×900, 1024×640, 768×1024, 375×667 y 200 % de zoom, abrir «Filtros» con muchas
etiquetas (Superadministrador o Administrador). Resultado: el panel (popover; hoja inferior en móvil) cabe en la
pantalla, su cuerpo se desplaza y «Limpiar»/«Aplicar» siempre están visibles; nada cambia hasta «Aplicar»; cerrar con
cambios sin aplicar avisa o los descarta con Escape; ningún scroll horizontal. Error: el final del panel queda fuera de
la pantalla.

**30.10 Superadministrador invisible.** Cuenta: Administrador. Resultado: ni en Usuarios (lista, búsqueda, filtros,
contadores, exportación), ni en Roles (rol raíz ni permisos «Solo superadministrador»), ni en el filtro de agente,
destinos de asignar o transferir, chat interno o filtro de actor de la bitácora aparece el Superadministrador. Donde
participó (mensajes, notas, eventos, una conversación que atiende) se lee «Administración del sistema». Abrir por URL su
usuario o el rol raíz responde 404. El Superadministrador sí lo ve todo. Error: aparece su nombre o su correo.

**30.11 Recorridos guiados.** En la bandeja, pulsar el botón de ayuda (brújula, abajo a la derecha; en la barra
superior si hay un chat abierto). Resultado: popover «Ayuda de esta pantalla» con los recorridos (insignia «Nuevo» la
primera vez) y «Ver la guía completa del módulo». Iniciar uno: cada paso resalta el control real y la tarjeta queda en
pantalla; «Siguiente», «Atrás», flechas y Escape funcionan; «Terminar» o Cerrar dejan la página usable (sin capa
oscura). «Volver a mostrar los recorridos» los marca de nuevo como nuevos y se pueden reiniciar desde el paso 1. Navegar
a otra pantalla cierra el recorrido. Repetir a 375 px. Error: un paso resalta un lugar vacío o queda una capa encima.

**30.12 Permisos del panel principal.** Agente A: solo sus cifras, sin gráficas de agentes ni departamentos. Supervisor:
cifras de sus departamentos. Administrador: toda la organización. Un usuario sin `dashboard.view` no ve el panel (ni por
recarga parcial). Error: un agente ve cifras de otros.

**30.13 Datos del panel y reportes.** Con el mismo periodo y filtros, comparar el panel con Reportes y con
«Ver como tabla» de cada gráfica: actividad (por hora en «Hoy», por día en periodos largos), por canal (solo canales con
datos), estado de lo recibido, primera respuesta (un día sin respuestas es un hueco, nunca 0), agentes y departamentos
(8 + «Otros»). Exportar el panel a Excel y PDF: mismas gráficas, cifras y nombres que la pantalla. Error: totales que
no cuadran o un 0 donde no hubo mediciones.

**30.14 Respuestas rápidas en Excel y PDF.** Administrador con respuestas corporativas, propias y de otros usuarios:
«Exportar» → «Reporte general» en Excel y en PDF. Resultado: todas las que puede consultar, sin importar la pestaña, con
Atajo, Título, Contenido completo (sin cortar), Tipo, Propietario, Departamento, Estado y Fecha de creación. «Solo la
vista actual» exporta la pestaña y filtros abiertos y lo dice en el documento. Error: faltan filas o el texto sale
truncado.

**30.15 Transiciones de pestañas.** En Respuestas rápidas, alternar «Corporativas», «Mis respuestas» y «De otros
usuarios». Resultado: cambio suave sin recarga completa ni salto de scroll; con «Reducir movimiento» del sistema no hay
animación. Error: parpadeo o la barra de pestañas se va de la pantalla.

**30.16 Sin desbordes.** A 320, 375, 768, 1280 y 1440 px, claro y oscuro, y con zoom 200 %: panel, bandeja, chat,
Filtros, Roles (tarjetas y «Ver permisos»), Usuarios, Reportes, Respuestas rápidas y Sistema sin scroll horizontal,
sin textos por debajo de 13 px y con las etiquetas de las tarjetas de cifras completas. Error: contenido cortado.

**30.17 Roles y alcance.** Roles → «Ver permisos» de un rol: detalle por módulo con búsqueda y «Mostrar solo los
habilitados», sin claves internas. Editar el alcance: cada opción tiene descripción y ejemplo, se elige con flechas y,
antes de guardar, se explica a cuántas personas afecta. Error: aparecen claves como `conversations.transfer`.

**30.18 Lenguaje claro.** Recorrer bandeja, chat, notificaciones y Sistema: «Pendientes» en lugar de «cola»,
«promedio» en lugar de «prom.», «No enviado (modo de prueba)» en mensajes simulados y detalles técnicos plegados. Error:
jerga técnica como texto principal.

Automatizado: [pruebas.md §2.3 y §6.4](pruebas.md#64-escenarios-de-la-fase-51).
