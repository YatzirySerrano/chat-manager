# Puesta en producción con un número real

Lista de verificación ordenada para salir a producción con un número real de WhatsApp y, después, Messenger,
Instagram y el chat web. Cada paso indica la variable, pantalla, comando o URL exactos. El detalle técnico vive en el
documento de cada área (enlazado); este documento solo fija **el orden** y **lo que hay que comprobar**.

Auditoría del 2026-11-12. Convenciones de esta página:

- **[código]** comprobado leyendo el código de este repositorio.
- **[ejecutado]** comprobado ejecutándolo en la máquina local (Windows, WAMP, MySQL 8.4.7, base `chatmanager`).
- **[doc]** solo documentado; no se pudo comprobar sin Meta real o sin un servidor.

> Nada de lo que sigue se ha ejecutado contra Meta real ni en un servidor de producción. La integración está
> implementada y cubierta por pruebas con respuestas simuladas; el primer mensaje auténtico será el de tu piloto
> (§6). Por eso el piloto con `WHATSAPP_PILOT_RECIPIENTS` no es opcional en la práctica.

## 0. Resumen del estado

| Área                                               | Estado                                                                                                                                                                                                                                     |
| -------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Envío real por WhatsApp Cloud API                  | **[código]** `MetaCloudWhatsAppTransport`: texto, plantilla, imagen, video, audio, documento y sticker; subida de archivos en streaming; respuesta citada; ZIP como enlace firmado. Se activa con `WHATSAPP_TRANSPORT=meta`.               |
| Webhook de WhatsApp                                | **[código]** `GET/POST /webhooks/whatsapp/{clave}` **[ejecutado: `route:list`]**: token de verificación y firma `X-Hub-Signature-256` con el App Secret de la conexión, en tiempo constante; se guarda y se procesa en la cola `webhooks`. |
| Alta, verificación y activación de una conexión    | **[código]** Conexiones → Nueva; credenciales cifradas con `APP_KEY`; «Verificar credenciales», «Revisar suscripción», «Suscribir app».                                                                                                    |
| Registro del número en Meta (`/register` con PIN)  | **Implementado [código]**: botón «Registrar número» en la conexión (§5.4); sin probar contra Meta real.                                                                                                                                    |
| Piloto por destinatarios                           | **[código]** `WHATSAPP_PILOT_RECIPIENTS`: a cualquier otro número el envío falla antes de llamar a Meta.                                                                                                                                   |
| Modo simulado en producción                        | **[código]** Imposible: con `APP_ENV=production`, `simulated` se sustituye por `unavailable` y el preflight lo bloquea.                                                                                                                    |
| Datos y cuentas de demostración                    | **[código]** Solo se siembran con `APP_ENV=local`; el preflight bloquea una base con datos «Demo B». **[ejecutado]** La base local no los tiene.                                                                                           |
| Messenger e Instagram                              | **[código]** OAuth, webhook `/webhooks/meta`, envío y callbacks. Requieren app de Meta propia, App Review y verificación del negocio (§8).                                                                                                 |
| Cabeceras de seguridad, CSP, HSTS, cookies seguras | **[código]** `SecurityHeaders`: CSP activa en producción; HSTS con HTTPS; cookie segura si `APP_URL` es https.                                                                                                                             |
| Preflight                                          | **[ejecutado]** `php artisan app:preflight`: 24 correctas, 12 advertencias, 0 bloqueantes en local (las 12 son propias del entorno local, §2.6).                                                                                           |
| Programador                                        | **[ejecutado]** `schedule:list`: 20 tareas.                                                                                                                                                                                                |
| Formato de números de México                       | **[código]** `521…` y `52…` se guardan como el mismo teléfono (Anexo B).                                                                                                                                                                   |

## 1. Lo que solo tú puedes conseguir

Sin esto no hay salida a producción, y nada de ello está en el repositorio:

1. **Servidor Linux** (Debian 12 o Ubuntu 24.04) con IP pública. Las plantillas de `deploy/` son para Linux. En
   Windows/WAMP falta `pcntl` **[ejecutado]**: los workers no respetan `--timeout` y no hay Supervisor ni systemd, así
   que Windows no sirve como producción.
2. **Dominio** (p. ej. `chat.tuempresa.com`) apuntando al servidor, con el puerto 443 abierto. Meta solo entrega
   webhooks a HTTPS con certificado válido.
3. **Portafolio comercial de Meta** (Business Manager) con el negocio verificado y un método de pago registrado.
4. **App de Meta** de tipo _Empresa_ con el producto WhatsApp (§5.1).
5. **El número**: debe poder recibir un SMS o una llamada para el código de verificación. **Un número no puede estar
   a la vez en la app WhatsApp/WhatsApp Business y en Cloud API**, ni en dos proveedores (§5.2). Mientras el número
   siga conectado en Whaticket, no puede conectarse aquí.
6. **Buzón SMTP** para restablecer contraseñas y recibir alertas.
7. **Destino de respaldos fuera del servidor** (rsync o rclone) y, si vas a guardar adjuntos fuera del VPS, el NAS
   Synology montado o un bucket S3 privado.
8. La **exportación de Whaticket** (`docs/export/`, que no se versiona porque contiene datos personales).

## 2. Servidor

Sigue [infraestructura-y-despliegue.md](infraestructura-y-despliegue.md) en este orden:

1. **Paquetes** (§2 de ese documento): Nginx, MariaDB 11.4, Supervisor, PHP 8.4 con `fpm cli mysql mbstring intl xml
curl zip gd bcmath opcache`, Node 20+ y Chromium. Confirma que `php -m` muestra `pcntl`.
2. **Estructura** (§3): `/var/www/chat-manager/{releases,shared}`, `shared/.env` con permisos `640` y
   `shared/storage` con escritura de `www-data`.
3. **Base de datos** (§4): base `chatmanager` en `utf8mb4`, usuario propio con privilegios solo sobre ella y MariaDB
   escuchando en `127.0.0.1`. La base local de desarrollo es MySQL 8.4 **[ejecutado]**; el proyecto se valida con
   MariaDB 11.4 y el preflight lo advierte. Usa MariaDB en producción.
4. **PHP-FPM y Nginx** (§6): copia `deploy/php-fpm/chat-manager.conf` y `deploy/nginx/chat-manager.conf`, cambia
   `chat.example.com` y emite el certificado con certbot. Las plantillas ya traen `upload_max_filesize=100M`,
   `post_max_size=110M` y `client_max_body_size 110m` **[código]**.
5. **Procesos** (§9 a §11): copia `deploy/supervisor/chat-manager.conf`. Define los seis grupos de workers
   (`critical` = `messages,webhooks` ×4, `realtime` ×2, `notifications` ×2, `media` ×2, `default` ×1, `exports` ×1),
   Reverb y `schedule:work` **[código]**. Si prefieres cron para el programador, usa `deploy/cron/chat-manager`.
6. **Respaldos** ([recuperacion-y-respaldos.md](recuperacion-y-respaldos.md)): instala los scripts de
   `deploy/scripts/backup-*.sh` como root, crea `/etc/chat-manager/backup.cnf` y activa las líneas de cron de
   `deploy/cron/chat-manager` (base 02:15, archivos 02:45). Restaura un respaldo de prueba antes de abrir el canal.

### 2.6 Qué marca hoy el preflight local

**[ejecutado]** En la máquina de desarrollo marca 12 advertencias, todas esperables fuera de producción: `APP_ENV=local`,
`APP_DEBUG=true`, `APP_URL` sin https, falta `pcntl`, límites de subida de PHP (2M/8M), servidor MySQL en lugar de
MariaDB, `.env` legible por todos, programador sin latido, `BROADCAST_CONNECTION=log`, Node/Chromium sin ruta
absoluta, `WHATSAPP_TRANSPORT=unavailable` y `MAIL_MAILER=log`. En producción las de depuración, HTTPS, cookies,
caché, colas, almacenamiento, Reverb sin TLS, transporte simulado y datos de demostración pasan a **bloqueantes**
**[código: `Preflight::productionFailure`]**.

> La línea «Tareas programadas: 0 tareas» que aparece en Windows es un defecto de conteo del preflight con las
> comillas de Windows; `php artisan schedule:list` muestra las 20 **[ejecutado]**. En Linux cuenta bien **[código]**.

## 3. `.env` de producción

Parte de `.env.example` y fija como mínimo estos valores en `shared/.env`. La tabla completa está en
[infraestructura §5](infraestructura-y-despliegue.md#5-variables-de-entorno-de-producción).

```dotenv
APP_NAME="Chat Manager"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://chat.tuempresa.com
APP_KEY=                      # php artisan key:generate --show   (respáldala: cifra las credenciales de Meta)
TRUSTED_PROXIES=127.0.0.1
LOG_STACK=daily
LOG_LEVEL=warning

DB_CONNECTION=mariadb
DB_DATABASE=chatmanager
DB_USERNAME=chatmanager_app
DB_PASSWORD=<secreto>

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=900

BROADCAST_CONNECTION=reverb
REVERB_APP_ID=<aleatorio>     # php -r 'echo bin2hex(random_bytes(16)), PHP_EOL;'  (uno distinto para cada valor)
REVERB_APP_KEY=<aleatorio>
REVERB_APP_SECRET=<aleatorio>
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http
REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT=8080
REVERB_CLIENT_HOST=chat.tuempresa.com
REVERB_CLIENT_PORT=443
REVERB_CLIENT_SCHEME=https
REVERB_ALLOWED_ORIGINS=chat.tuempresa.com

WHATSAPP_TRANSPORT=meta
WHATSAPP_PILOT_RECIPIENTS=5215511112222,5215533334444   # números del equipo; se vacía al terminar el piloto
META_GRAPH_VERSION=           # vacío = v26.0; confirma en el panel de Meta que la versión sigue vigente

MAIL_MAILER=smtp
MAIL_HOST=<smtp>
MAIL_PORT=587
MAIL_USERNAME=<usuario>
MAIL_PASSWORD=<secreto>
MAIL_FROM_ADDRESS=chat@tuempresa.com
OPS_ALERT_EMAILS=guardia@tuempresa.com
OPS_BACKUP_DIR=/var/backups/chat-manager/database

EXPORTS_NODE_BINARY=/usr/bin/node
EXPORTS_NPM_BINARY=/usr/bin/npm
EXPORTS_CHROME_PATH=/usr/bin/chromium

VAPID_PUBLIC_KEY=             # php artisan webpush:vapid
VAPID_PRIVATE_KEY=
VAPID_SUBJECT=mailto:chat@tuempresa.com

MEDIA_DISK=local              # o nas / s3 (ver abajo)
```

- **Credenciales de WhatsApp:** el token, el App Secret y el token de verificación **no** van en `.env`. Se capturan
  por conexión en la interfaz y se guardan cifrados con `APP_KEY` **[código]**. Si pierdes o cambias `APP_KEY`, hay
  que volver a capturarlos.
- **Adjuntos en el NAS Synology:** `MEDIA_DISK=nas`, `MEDIA_DISK_REQUIRED=true`, `MEDIA_MOUNT_PATH` y `MEDIA_MOUNT_ID`,
  con el montaje CIFS de [infraestructura §3](infraestructura-y-despliegue.md#nas-físico-montado-por-cifssmb-sobre-tailscale-media_disknas-opcional).
  Con el NAS caído los textos siguen funcionando y los archivos se rechazan; nunca se escriben en el disco del VPS
  **[código]**.
- **Redis** es opcional. Con chat web o mucho tráfico, usa al menos `CACHE_LIMITER=redis`.
- Después de cualquier cambio de `.env`: `php artisan config:cache && php artisan queue:restart`.

## 4. Primer despliegue

```bash
# Como el usuario deploy, desde un clon revisado del repositorio
REPOSITORY=git@github.com:<organización>/chat-manager.git deploy/scripts/deploy.sh main
```

`deploy.sh` **[código]** clona, instala sin dependencias de desarrollo, ejecuta `npm ci` y `npm run build`, corre el
preflight (si falla, la versión anterior sigue sirviendo), respalda la base, migra, sincroniza permisos y roles,
cachea la configuración, cambia el enlace `current`, reinicia PHP-FPM, workers, Reverb y el programador y prueba
`/health/ready`. `npm run build` descarga las tipografías de Bunny Fonts: el servidor necesita salida a Internet
durante el despliegue.

Después, una sola vez:

```bash
cd /var/www/chat-manager/current
php artisan app:create-administrator        # primer Superadministrador; pide nombre, correo y contraseña
php artisan webpush:vapid                    # copia las claves a shared/.env y repite config:cache
php artisan app:preflight                    # debe terminar sin bloqueantes
curl -fsS https://chat.tuempresa.com/health/ready     # {"status":"ok"}
sudo supervisorctl status                    # los 6 grupos, reverb y scheduler en RUNNING
```

`app:create-administrator` solo crea el primero; se niega a ejecutarse si ya existe un superadministrador
**[código]**. No ejecutes `php artisan db:seed` en producción: no hace falta (los roles los crea
`app:sync-permissions`, que `deploy.sh` ya ejecuta) y las cuentas de demostración solo existen en `local`.

Entra con el superadministrador y en Administración → Configuración general confirma el nombre de la plataforma y la
zona horaria (`America/Mexico_City` por defecto **[código]**). Crea los departamentos y los usuarios antes de
conectar el número.

## 5. Conectar el primer número de WhatsApp

### 5.1 App de Meta

1. En <https://developers.facebook.com> crea una app de tipo **Empresa** dentro de tu portafolio comercial y agrega el
   producto **WhatsApp**.
2. Anota el **App ID** y, en Configuración → Básica, el **App Secret**.

### 5.2 Liberar el número

El número que hoy atiende en Whaticket tiene que dejar de estar registrado allí:

- Si Whaticket lo usa por **código QR** (WhatsApp Web), el número vive en la app WhatsApp Business del teléfono.
  Para pasarlo a Cloud API hay que **eliminar la cuenta** en esa app (Ajustes → Cuenta → Eliminar cuenta) y esperar
  unos minutos. Se pierde el historial del teléfono: exporta antes lo que necesites (el historial de Whaticket se
  importa en §10).
- Si Whaticket lo usa por la **API oficial** con otro proveedor, pide la migración del número entre cuentas de
  WhatsApp Business (hay que desactivar la verificación en dos pasos en el origen).

Este corte deja el número sin servicio hasta terminar §5.5. Hazlo fuera del horario de atención. Para ensayar todo el
procedimiento sin riesgo, usa primero el **número de prueba** que Meta da con la app (envía solo a 5 números
autorizados) o un número nuevo.

### 5.3 Dar de alta y registrar el número en Meta

1. En WhatsApp Manager → Números de teléfono → Agregar, captura el número y verifícalo con el código por SMS o
   llamada.
2. Anota el **Phone number ID** y el **WhatsApp Business Account ID (WABA)** (app → WhatsApp → Configuración de la
   API).
3. Crea el **token permanente**: Configuración del negocio → Usuarios del sistema → nuevo usuario administrador →
   asígnale la app y la cuenta de WhatsApp con control total → genera un token **sin caducidad** con
   `whatsapp_business_messaging` y `whatsapp_business_management`. No uses el token temporal de 24 h.
4. **Registra el número en Cloud API** (paso 3 de §5.4). Ya no hace falta la terminal: la ficha de la conexión tiene
   el botón **Registrar número** **[código: `RegisterPhoneNumber`, `ConnectionRegistrationController`]**, que
   llama a `POST /{phone-number-id}/register` con las credenciales cifradas de la conexión. Elige un PIN de 6
   dígitos y guárdalo: es la verificación en dos pasos del número y Meta lo pedirá para volver a registrarlo. Si el
   número ya tiene verificación en dos pasos, usa ese PIN. La plataforma envía el PIN a Meta una sola vez y no lo
   guarda, ni en la base, ni en la auditoría, ni en los registros.

    Alternativa manual (equivalente; útil si aún no existe la conexión en Chat Manager):

    ```bash
    curl -X POST "https://graph.facebook.com/v26.0/<PHONE_NUMBER_ID>/register" \
      -H "Authorization: Bearer <TOKEN_PERMANENTE>" -H "Content-Type: application/json" \
      -d '{"messaging_product":"whatsapp","pin":"<PIN_DE_6_DIGITOS>"}'
    ```

    Debe responder `{"success":true}`. Sin este paso el número no queda `CONNECTED` y «Verificar credenciales» lo
    avisará («Meta aceptó las credenciales, pero reporta el número con estado …»).

### 5.4 Conexión en Chat Manager

1. Conexiones → Nueva conexión → WhatsApp. Captura: nombre, número visible, **Phone number ID**, **WABA ID**,
   **Meta App ID**, **token de acceso**, **App Secret** y un **token de verificación** inventado por ti (16
   caracteres o más, sin espacios) **[código: `ConnectionRequest`]**. Elige el departamento predeterminado.
2. Guarda y pulsa **Verificar credenciales**. Debe mostrar el nombre verificado.
3. Si el estado del número no es `CONNECTED`, pulsa **Registrar número** (sección «Estado» de la conexión, permiso
   de gestionar conexiones), escribe el PIN de 6 dígitos y confirma. Meta responde en el momento: si acepta, la
   plataforma vuelve a consultar el número y muestra `CONNECTED`; si rechaza, el motivo aparece junto al PIN (PIN
   incorrecto, número sin verificar por SMS, número aún activo en la app de WhatsApp, demasiados intentos). Hay un
   máximo de 3 intentos cada 10 minutos por persona y conexión, porque Meta bloquea el registro tras varios PIN
   incorrectos. El registro queda en la auditoría como «Número de WhatsApp registrado en la API de Meta», sin el PIN.
   **No se ha probado contra Meta real**: la llamada, sus errores y el secreto del PIN se comprobaron con pruebas
   automáticas (`tests/Feature/Connections/ConnectionNumberRegistrationTest.php`).
4. Pulsa **Copiar URL del webhook**. Tiene la forma `https://chat.tuempresa.com/webhooks/whatsapp/<clave>`.

### 5.5 Webhook y suscripción

1. En la app de Meta → WhatsApp → Configuración → Webhook → Editar: pega la URL y el mismo token de verificación.
   Meta hace el `GET`; la ficha de la conexión pasa a «Webhook verificado».
2. Suscribe los campos `messages` (obligatorio), `message_template_status_update`,
   `message_template_quality_update`, `phone_number_quality_update` y `account_update`.
3. En la ficha de la conexión pulsa **Revisar suscripción** y, si no aparece suscrita, **Suscribir app**. Sin esto
   Meta no envía los mensajes aunque el webhook esté verificado.
4. Crea al menos una plantilla en WhatsApp Manager, espera su aprobación y pulsa **Sincronizar plantillas**.

Con varios números en la misma app, Meta admite una sola URL por app: apunta la app a la URL de **una** conexión y
guarda **el mismo App Secret** en todas ([mensajería §2.8](mensajeria-whatsapp.md#28-varios-números-en-la-misma-app-de-meta)).

## 6. Piloto controlado

Con `WHATSAPP_PILOT_RECIPIENTS` definido (§3), la ficha de la conexión muestra «Piloto controlado» y solo esos
números reciben mensajes; los automáticos también respetan la lista **[código]**. **Activa** la conexión y prueba,
en este orden:

| #   | Prueba                                                                                | Resultado esperado                                                                                         |
| --- | ------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------- |
| 1   | Desde un teléfono del piloto, escribe «Hola» al número                                | Conversación nueva en el departamento predeterminado; la salud de la conexión pasa a «Recibiendo mensajes» |
| 2   | Acepta la conversación y responde con un texto                                        | «Enviando» → «Enviado» → «Entregado» → «Leído»                                                             |
| 3   | Envía desde el teléfono una imagen, un audio, un video y un PDF                       | Se descargan y se ven en la conversación (cola `media`)                                                    |
| 4   | Envía desde la bandeja una imagen y un PDF                                            | Llegan al teléfono con su nombre y su texto                                                                |
| 5   | Responde citando un mensaje, en ambos sentidos                                        | La cita se ve en los dos lados                                                                             |
| 6   | Envía una plantilla a una conversación con la ventana de 24 h vencida                 | Llega; el compositor bloquea el texto libre fuera de la ventana                                            |
| 7   | Desde la bandeja, escribe a un número que **no** está en el piloto                    | Falla con «El destinatario no está en la lista del piloto…»; nada sale a Meta                              |
| 8   | Con un teléfono cuyo WhatsApp ID sea `521…` y un contacto ya guardado como `+52…`     | Se enlaza al contacto existente; no se duplica (Anexo B)                                                   |
| 9   | Cierra la conversación y escribe de nuevo antes y después de la ventana de reapertura | Se reabre o crea una nueva, según la ventana configurada en la conexión                                    |

Revisa además la tarjeta de diagnóstico de la conexión (eventos procesados, sin errores) y Administración → Sistema
(colas al día, programador con latido, sin trabajos fallidos).

Si la prueba 1 no crea la conversación: revisa «Suscribir app» (§5.5), que el worker `critical` esté en ejecución y
`storage/logs` por «WhatsApp webhook refused: invalid signature» (App Secret equivocado).

## 7. Abrir a todos los clientes

1. Vacía `WHATSAPP_PILOT_RECIPIENTS` en `shared/.env`.
2. `php artisan config:cache && php artisan queue:restart`.
3. Comprueba que el aviso «Piloto controlado» ya no aparece en la ficha de la conexión.
4. Configura los mensajes automáticos (bienvenida, fuera de horario, despedida) y el horario de cada departamento
   ([notificaciones-y-horarios.md](notificaciones-y-horarios.md)).

## 8. Messenger e Instagram

Procedimiento completo y lista de comprobación en
[meta-messenger-instagram.md](meta-messenger-instagram.md#3-configuración-de-la-app-de-meta). En resumen:

1. En `shared/.env`: `META_APP_ID`, `META_APP_SECRET`, `META_WEBHOOK_VERIFY_TOKEN` (texto aleatorio propio) y, para
   Instagram, `META_INSTAGRAM_APP_ID` y `META_INSTAGRAM_APP_SECRET`. Luego `config:cache`.
2. En la app de Meta: URI de redirección `https://chat.tuempresa.com/connections/meta/messenger/callback` y
   `…/connections/meta/instagram/callback`; webhook `https://chat.tuempresa.com/webhooks/meta` con ese token;
   desautorización `…/meta/deauthorize` y eliminación de datos `…/meta/data-deletion` **[ejecutado: `route:list`]**.
3. Conexiones → Nueva conexión → Facebook Messenger o Instagram → **Autorizar acceso** → elige la página o cuenta →
   activa la conexión.
4. Antes de App Review solo escriben y reciben las personas con un rol en la app. Para atender clientes reales hacen
   falta **acceso avanzado** aprobado y la **verificación del negocio**; es un trámite de Meta que tarda días.

Las conversaciones de Messenger que hoy tienes en Whaticket no tienen número de teléfono: el historial de esos
contactos no se enlaza por teléfono al migrar (§10).

## 9. Chat web

[canal-chat-web.md](canal-chat-web.md) y [widget-integracion.md](widget-integracion.md). Conexiones → Nueva →
Chat web, registra los orígenes permitidos (el dominio exacto del sitio), copia el fragmento y pégalo en el sitio.
`TRUSTED_PROXIES` es obligatorio: sin él los límites por IP usan la IP de Nginx y el preflight bloquea **[código]**.

## 10. Historial de Whaticket

Procedimiento y formato en [migracion-whaticket.md](migracion-whaticket.md). Para planearlo:

- La exportación revisada (`whaticket_messages_2024-10-13_2025-05-28.csv`) pesa 67 MB y tiene 447 429 mensajes. Cabe
  en los límites de las plantillas del servidor (100 MB por archivo) **[código]**.
- El importador acepta hasta 100 MB por archivo desde la interfaz (Nginx y PHP-FPM cortan en 110 MB); un archivo mayor se agrega con `php artisan history-import:add-file <lote> <ruta> --kind=messages`. Para un
  archivo mayor (un ZIP con multimedia), sube temporalmente `client_max_body_size`, `upload_max_filesize` y
  `post_max_size`, o divide la exportación. Para que el mensaje de la aplicación coincida con el servidor, fija
  `HISTORY_IMPORT_MAX_KILOBYTES=102400`.
- La lectura y la ejecución corren en la cola `exports` (un worker, 1 GB, 660 s por trabajo; se reencola solo).
- Importa **antes** de abrir el canal a todos, o en horario de bajo tráfico: así los contactos ya existen cuando
  llega su primer mensaje real y se enlazan por teléfono (Anexo B).
- En desarrollo local con WAMP, PHP trae `upload_max_filesize=2M` **[ejecutado]**: el archivo de 67 MB se rechaza
  hasta subir ese valor y `post_max_size` en el `php.ini` que use el servidor web.

## 11. Las primeras 48 horas

| Qué vigilar                 | Dónde                                                                                | Señal de alarma                                                                  |
| --------------------------- | ------------------------------------------------------------------------------------ | -------------------------------------------------------------------------------- |
| Salud general               | Administración → Sistema; monitor externo sobre `https://…/health/ready` cada minuto | `{"status":"fail"}`, colas con antigüedad, programador sin latido                |
| Conexión                    | Conexiones → ficha → diagnóstico                                                     | «Credenciales inválidas», «Llegaron eventos de otro número», webhook sin tráfico |
| Mensajes que no salen       | Bandeja: «Fallido» o «Entrega no confirmada»                                         | Código 131047 (ventana vencida), 131026 (no entregable), 130429 (límite de Meta) |
| Trabajos fallidos           | Administración → Sistema → tareas con error; `php artisan queue:failed`              | Cualquier fallo repetido en `webhooks` o `messages`                              |
| Calidad y límite del número | WhatsApp Manager; la ficha muestra la calidad                                        | Calidad «Baja»: reduce plantillas; el límite diario baja                         |
| Disco y adjuntos            | Administración → Sistema; `df -h`                                                    | Menos de 2 GB libres (el preflight lo marca)                                     |
| Errores                     | `storage/logs/laravel-*.log`, `storage/logs/ops-*.log`, `/var/log/chatmanager/`      | Trazas nuevas después del despliegue                                             |
| Respaldos                   | `/var/backups/chat-manager/database`, copia externa                                  | Sin archivo nuevo en 26 h (el preflight lo marca con `OPS_BACKUP_DIR`)           |
| Costos                      | Panel de facturación de Meta                                                         | La plataforma no calcula costos ni conversaciones facturables                    |

Las alertas operativas llegan por correo a `OPS_ALERT_EMAILS` (`ops:check-alerts`, cada 5 minutos).

## 12. Reversión

- **Dejar de enviar sin tocar nada más:** `WHATSAPP_TRANSPORT=unavailable`, luego `config:cache` y `queue:restart`.
  Los mensajes entrantes se siguen recibiendo y guardando; cada envío falla de forma explícita y se puede reintentar
  después.
- **Pausar un número:** Conexiones → Desactivar. Sigue recibiendo y no envía.
- **Volver a la versión anterior del código:** `deploy/scripts/rollback.sh <versión>`. Las migraciones son aditivas y
  se quedan ([infraestructura §17](infraestructura-y-despliegue.md#17-reversión-rollback)).
- **Restaurar datos:** [recuperacion-y-respaldos.md §6](recuperacion-y-respaldos.md#6-restauración).
- **Devolver el número a Whaticket:** hay que anular su registro en Cloud API y volver a registrarlo allí; no es
  inmediato. Por eso conviene ensayar con el número de prueba de Meta o con un número nuevo.
- **Deshacer una importación de historial:** ver [migracion-whaticket.md](migracion-whaticket.md).

## 13. Bloqueadores y pendientes conocidos

| Severidad    | Hallazgo                                                                                                                   | Qué hacer                                                                           |
| ------------ | -------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------- |
| Bloqueador   | Ningún mensaje auténtico se ha intercambiado con Meta **[doc]**                                                            | Piloto de §6 con el número de prueba de Meta antes de mover el número real          |
| Bloqueador   | No hay servidor Linux, dominio ni HTTPS todavía; Windows/WAMP no sirve como producción **[ejecutado]**                     | §1 y §2                                                                             |
| Bloqueador   | El número sigue registrado en Whaticket                                                                                    | §5.2                                                                                |
| Recomendable | El registro del número (`/register`) ya está en la interfaz, sin probar con Meta real                                      | Botón «Registrar número» (§5.4) o `curl` de §5.3                                    |
| Importante   | `META_GRAPH_VERSION` por defecto `v26.0` **[código]**; no se comprobó que Meta la mantenga vigente                         | Confirmarla en el panel de la app                                                   |
| Importante   | La CSP de producción solo permite imágenes, video y fuentes del propio dominio (`img-src 'self' data: blob:`) **[código]** | Cualquier recurso externo de la interfaz se bloquea; alojarlo en el propio servidor |
| Importante   | El importador acepta 100 MB por la interfaz; archivos mayores, por `history-import:add-file` **[código]**                  | §10                                                                                 |
| Importante   | Messenger e Instagram necesitan App Review y verificación del negocio                                                      | Iniciar el trámite ya; mientras tanto solo funciona con cuentas con rol en la app   |
| Importante   | La base local `chatmanager` de esta máquina está vacía: sin usuarios, roles ni conexiones **[ejecutado]**                  | No hay datos locales que migrar; producción empieza con `app:create-administrator`  |
| Recomendable | El preflight no bloquea por `MAIL_MAILER=log` ni por `WHATSAPP_TRANSPORT=unavailable`; solo advierte **[código]**          | Leer las advertencias, no solo los bloqueantes                                      |
| Recomendable | El preflight cuenta 0 tareas programadas en Windows **[ejecutado]**                                                        | Solo afecta al desarrollo en Windows                                                |
| Recomendable | Colas, caché y límites en `database`                                                                                       | Redis al crecer el tráfico o al activar el chat web                                 |

Lo que sigue sin validar por depender de terceros está en [limitaciones.md §4](limitaciones.md#4-pendiente-de-validación-externa).

## Anexo A. Ensayo desde la máquina local con un túnel HTTPS

Sirve para comprobar el flujo con el **número de prueba de Meta** antes de tener servidor. No se ha ejecutado
**[doc]**; el código no lo impide: el transporte `meta` funciona con cualquier `APP_ENV` **[código]**.

1. Abre un túnel HTTPS hacia el puerto local (Cloudflare Tunnel, ngrok o similar) y pon su URL en `APP_URL`.
2. `WHATSAPP_TRANSPORT=meta` y `WHATSAPP_PILOT_RECIPIENTS` con tu teléfono.
3. `composer run dev` (servidor, colas, programador y Reverb).
4. Sigue §5.4 a §6 con el número de prueba.

No uses esto con el número real ni con clientes: sin `pcntl`, sin supervisión de procesos y con `APP_DEBUG=true`.

## Anexo B. Números de México: `521…` y `52…`

Meta informa el WhatsApp ID de un móvil mexicano con el «1» heredado (`521` + 10 dígitos). El plan de numeración ya
no lo usa y Whaticket exporta los números de las dos formas (en la exportación revisada hay números de 12 y de 13
dígitos).

- `PhoneNumber::normalize` convierte `521XXXXXXXXXX` en `+52XXXXXXXXXX` y rechaza números `+52` que no tengan 10
  dígitos nacionales **[código]**.
- **Entrante:** el contacto se busca por ese teléfono normalizado. Si hay exactamente un contacto con ese teléfono y
  sin identidad de WhatsApp, se enlaza; el WhatsApp ID original (`521…`) se guarda aparte como identidad
  **[código: `RegisterInboundContact`]**. Si hay varios contactos con el mismo teléfono, se crea uno provisional y se
  abre un caso de revisión en Contactos → Revisiones.
- **Saliente:** el campo `to` se envía siempre como `52` + 10 dígitos **[código: `MetaCloudWhatsAppTransport::recipientNumber`]**.
- **Importadores:** contactos e historial usan la misma normalización **[código]**, así que un contacto importado como
  `5215512345678` o `525512345678` queda como `+525512345678` y coincide con el primer mensaje real de ese cliente.
- **Piloto:** la lista admite cualquiera de las dos formas.
- Los números de 10 dígitos sin lada internacional se toman como mexicanos. Los que no se pueden normalizar (9
  dígitos, por ejemplo) quedan sin teléfono y no se enlazan solos.
