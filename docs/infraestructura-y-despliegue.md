# Infraestructura, despliegue y operación

Guía para preparar un VPS Linux (Debian 12 / Ubuntu 24.04) con Nginx, PHP-FPM, MariaDB, Node, Chromium, Reverb,
workers supervisados y el programador de tareas, más las herramientas de operación incluidas en la aplicación
(Administración > Sistema, `/health/ready`, alertas y `php artisan app:preflight`).

**Preparar no es desplegar**: este repositorio trae plantillas y scripts en `deploy/`; nada se conecta a un servidor
por sí mismo. Las contraseñas de este documento son marcadores (`<secreto>`); nunca uses valores conocidos.

Documentos relacionados: [tiempo-real.md](tiempo-real.md) (Reverb en detalle), [mensajeria-whatsapp.md](mensajeria-whatsapp.md)
(webhooks y activación con Meta), [reportes-y-exportaciones.md](reportes-y-exportaciones.md) (exportaciones/PDF),
[recuperacion-y-respaldos.md](recuperacion-y-respaldos.md) (respaldos y restauración).

## 1. Componentes y procesos

| Proceso                     | Qué hace                                                                                       | Plantilla                                                                               |
| --------------------------- | ---------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------- |
| Nginx                       | TLS, archivos estáticos de `public/`, proxy de WebSockets `/app` a Reverb                      | `deploy/nginx/chat-manager.conf`                                                        |
| PHP-FPM 8.4 (pool propio)   | Peticiones HTTP de Laravel                                                                     | `deploy/php-fpm/chat-manager.conf`                                                      |
| MariaDB 11.4 LTS            | Base única `chatmanager`                                                                       | —                                                                                       |
| Workers de colas (6 grupos) | Webhooks y envíos, señales de tiempo real, notificaciones, multimedia, métricas, exportaciones | `deploy/supervisor/chat-manager.conf` (o `deploy/systemd/chat-manager-worker@.service`) |
| Reverb                      | Servidor WebSocket en `127.0.0.1:8080`                                                         | Supervisor `chatmanager-reverb` (o `deploy/systemd/chat-manager-reverb.service`)        |
| Programador                 | `schedule:work` (o cron `schedule:run`)                                                        | Supervisor `chatmanager-scheduler` / `deploy/cron/chat-manager`                         |
| Redis (opcional)            | Colas/caché/escalado de Reverb                                                                 | —                                                                                       |
| Chromium + Node             | PDF de exportaciones (Browsershot + puppeteer)                                                 | —                                                                                       |

### Tareas programadas (`routes/console.php`)

| Tarea                                       | Frecuencia   | Módulo                                       |
| ------------------------------------------- | ------------ | -------------------------------------------- |
| `ops:heartbeat`                             | cada minuto  | operación: latido del programador            |
| `ops:check-alerts`                          | cada 5 min   | operación: alertas críticas                  |
| `conversations:close-inactive`              | cada hora    | conversaciones                               |
| `exports:prune`                             | cada hora    | exportaciones                                |
| `whatsapp:maintenance reconcile-deliveries` | cada 5 min   | WhatsApp: entregas atascadas                 |
| `whatsapp:maintenance recover-webhooks`     | cada 10 min  | WhatsApp: reencola webhooks atascados        |
| `whatsapp:maintenance prune-webhooks`       | diaria 03:10 | WhatsApp: retención del registro de webhooks |
| `whatsapp:maintenance sync-templates`       | cada 6 h     | WhatsApp: plantillas                         |
| `analytics:rebuild --days=2`                | diaria 03:30 | reportes                                     |
| `notifications:prune`                       | diaria 04:10 | notificaciones                               |
| `webchat:prune-sessions`                    | diaria 04:20 | chat web: sesiones vencidas o revocadas      |
| `queue:prune-failed --hours=2160`           | diaria 04:30 | operación: fallidos de más de 90 días        |
| `queue:prune-batches --hours=168`           | diaria 04:40 | operación                                    |

`php artisan schedule:list` las muestra; el preflight verifica que todos los comandos programados existan.

## 2. Dependencias del servidor

```bash
# PHP 8.4 (Debian: repositorio sury.org; Ubuntu 24.04: ppa:ondrej/php)
sudo apt install nginx mariadb-server supervisor git unzip curl \
  php8.4-fpm php8.4-cli php8.4-mysql php8.4-mbstring php8.4-intl php8.4-xml php8.4-curl \
  php8.4-zip php8.4-gd php8.4-bcmath php8.4-opcache
# Opcional: Redis (colas/caché/escalado de Reverb) con la extensión phpredis
sudo apt install redis-server php8.4-redis
# Node 20+ LTS (nodesource) y Chromium para los PDF
sudo apt install nodejs chromium   # en Ubuntu: chromium-browser o el paquete snap; usa su ruta absoluta
```

Extensiones obligatorias (las verifica el preflight): `pdo_mysql, mbstring, intl, ctype, curl, dom, fileinfo, gd,
iconv, openssl, session, simplexml, tokenizer, xml, xmlreader, xmlwriter, zip, zlib`. Recomendadas: `pcntl` (sin ella
`queue:work --timeout` no puede interrumpir un trabajo colgado; el preflight la exige en producción), OPcache en
PHP-FPM, `redis` solo si alguna configuración usa Redis con `REDIS_CLIENT=phpredis` (el paquete `predis` **no** forma
parte del proyecto). Para más de ~1 000 conexiones simultáneas de Reverb, la extensión `ev` (ver tiempo-real.md).

> El preflight se ejecuta con el PHP de línea de comandos; confirma que PHP-FPM cargue las mismas extensiones
> (`php-fpm8.4 -m`).

## 3. Estructura en el servidor

```
/var/www/chat-manager/
├── releases/20261021120000/   # una carpeta por versión (deploy.sh)
├── shared/.env                # chmod 640, propietario deploy:www-data
├── shared/storage/            # adjuntos, importaciones, logs; www-data con escritura
└── current -> releases/…      # enlace que sirve Nginx (root .../current/public)
```

Preparación inicial (una vez):

```bash
sudo mkdir -p /var/www/chat-manager/{releases,shared} /var/log/chatmanager /var/backups/chat-manager
sudo cp -r storage /var/www/chat-manager/shared/      # desde un clon del repositorio: estructura vacía
sudo chown -R deploy:www-data /var/www/chat-manager && sudo chmod -R g+w /var/www/chat-manager/shared/storage
sudo find /var/www/chat-manager/shared/storage -type d -exec chmod g+s {} +   # archivos nuevos heredan el grupo www-data
sudo install -m 640 -o deploy -g www-data .env.production /var/www/chat-manager/shared/.env
# Log de errores del pool de PHP-FPM: el pool corre como www-data y abre el archivo él mismo; el directorio
# /var/log/chatmanager queda root:root 0755 (Supervisor y los respaldos escriben como root).
sudo install -o www-data -g adm -m 640 /dev/null /var/log/chatmanager/php-fpm-errors.log
```

`.env.production` es tu archivo local con los valores reales (a partir de `.env.example`); no está en el repositorio.

Permisos mínimos: el código pertenece al usuario de despliegue (`deploy`) y solo es legible por `www-data`; únicamente
`shared/storage` y `bootstrap/cache` admiten escritura de `www-data`. `storage/app/private` (adjuntos, exportaciones,
importaciones) nunca está bajo `public/`; los archivos se sirven solo por rutas autorizadas. No ejecutes
`storage:link` hacia `storage/app` ni hacia `private`: el enlace público solo puede apuntar a `storage/app/public`.

## 4. Base de datos (MariaDB)

```sql
CREATE DATABASE chatmanager CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'chatmanager_app'@'localhost' IDENTIFIED BY '<secreto>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, DROP, LOCK TABLES
  ON chatmanager.* TO 'chatmanager_app'@'localhost';
-- Respaldo (solo lectura): ver recuperacion-y-respaldos.md
CREATE USER 'chatmanager_backup'@'localhost' IDENTIFIED BY '<secreto>';
GRANT SELECT, SHOW VIEW, TRIGGER, EVENT, LOCK TABLES ON chatmanager.* TO 'chatmanager_backup'@'localhost';
```

- Privilegios solo sobre su base: un error de configuración no puede crear otra. `DROP` se requiere para migraciones
  que reemplazan índices; los comandos destructivos de Artisan están bloqueados en producción
  (`DB::prohibitDestructiveCommands`).
- `innodb_buffer_pool_size` ≈ 50–70 % de la RAM dedicada; `max_connections` ≥ procesos PHP-FPM + workers + 20.
- Las fechas se guardan en UTC (`app.timezone=UTC`); la zona visible se elige en Configuración general.

### Migraciones en producción

- Solo aditivas (columnas nuevas, tablas nuevas, índices). Nunca `migrate:fresh`, `migrate:rollback`, `db:wipe`.
- `deploy.sh` respalda la base **antes** de `php artisan migrate --force`.
- Si una migración falla a medias: el sitio sigue en mantenimiento, la versión anterior sigue activa (el enlace no se
  cambió). Corrige y vuelve a desplegar o restaura el respaldo (recuperacion-y-respaldos.md).

## 5. Variables de entorno de producción

Base: `.env.example` (todas las claves comentadas). Valores clave:

| Variable                                                                                | Producción                                           | Notas                                                                                                                                                                                                                                            |
| --------------------------------------------------------------------------------------- | ---------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `APP_ENV` / `APP_DEBUG`                                                                 | `production` / `false`                               | Con debug, cualquier visitante ve trazas y variables                                                                                                                                                                                             |
| `APP_KEY`                                                                               | `php artisan key:generate --show`                    | Cifra sesiones y credenciales de conexiones: respáldala aparte; cambiarla las invalida                                                                                                                                                           |
| `APP_URL`                                                                               | `https://chat.empresa.com`                           | HTTPS obligatorio (Meta y cookies seguras)                                                                                                                                                                                                       |
| `TRUSTED_PROXIES`                                                                       | `127.0.0.1`                                          | Nginx en el mismo host; sin esto Laravel no reconoce HTTPS ni la IP real. **Obligatorio con un chat web activo**: los límites por IP de visitantes y la lista de IPs de los tokens usarían la IP del proxy (Preflight: bloqueante en producción) |
| `SESSION_SECURE_COOKIE`                                                                 | `true` (por defecto si `APP_URL` es https)           | HttpOnly y SameSite=lax por defecto                                                                                                                                                                                                              |
| `SESSION_DRIVER` / `CACHE_STORE`                                                        | `database` (o `redis`)                               | Nunca `array`/`cookie`                                                                                                                                                                                                                           |
| `QUEUE_CONNECTION`                                                                      | `database` (o `redis`)                               | Nunca `sync` en producción                                                                                                                                                                                                                       |
| `DB_QUEUE_RETRY_AFTER`                                                                  | `900`                                                | Mayor que el `--timeout` más largo (exportaciones 660)                                                                                                                                                                                           |
| `EXPORTS_QUEUE`                                                                         | `exports`                                            | Worker propio con timeout largo                                                                                                                                                                                                                  |
| `QUEUE_NAME_*`                                                                          | sin cambios                                          | Solo si varias apps comparten el backend de colas                                                                                                                                                                                                |
| `BROADCAST_CONNECTION`                                                                  | `reverb`                                             | Ver tiempo-real.md (`REVERB_*`, `REVERB_CLIENT_*`, `REVERB_ALLOWED_ORIGINS`)                                                                                                                                                                     |
| `WHATSAPP_TRANSPORT`                                                                    | `meta`                                               | `simulated` es solo para desarrollo (el preflight lo rechaza en producción)                                                                                                                                                                      |
| `META_GRAPH_VERSION`                                                                    | `v26.0` (o la vigente)                               | Credenciales por conexión en la interfaz, no en `.env`                                                                                                                                                                                           |
| `MAIL_*`                                                                                | SMTP real                                            | Restablecimiento de contraseña y alertas                                                                                                                                                                                                         |
| `OPS_ALERT_EMAILS` / `OPS_ALERT_NOTIFY_ADMINS`                                          | buzón de guardia / `true`                            | Destinatarios de alertas operativas                                                                                                                                                                                                              |
| `EXPORTS_NODE_BINARY`, `EXPORTS_NPM_BINARY`, `EXPORTS_CHROME_PATH`                      | rutas absolutas                                      | `/usr/bin/node`, `/usr/bin/npm`, `/usr/bin/chromium`                                                                                                                                                                                             |
| `LOG_STACK` / `LOG_LEVEL`                                                               | `daily` / `warning`                                  | `debug` llena el disco                                                                                                                                                                                                                           |
| `SECURITY_CSP_ENABLED`                                                                  | vacío (= activo en producción)                       | Ver §8                                                                                                                                                                                                                                           |
| `QUEUE_NAME_REALTIME` / `REALTIME_QUEUE`                                                | sin cambios (`realtime`)                             | Cola dedicada de señales de tiempo real (§9); `REALTIME_QUEUE` tiene prioridad                                                                                                                                                                   |
| `WHATSAPP_PILOT_RECIPIENTS`                                                             | vacío, o números del piloto                          | Con `meta`, solo envía a esos números ([mensajeria §8](mensajeria-whatsapp.md#8-transportes))                                                                                                                                                    |
| `WHATSAPP_WEBHOOK_MAX_AGE_DAYS`                                                         | `7` (opcional)                                       | Antigüedad máxima de errores y señales del webhook que se aplican                                                                                                                                                                                |
| `INERTIA_ENCRYPT_HISTORY`                                                               | `true`                                               | Historial del navegador cifrado (requiere HTTPS o localhost)                                                                                                                                                                                     |
| `VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY` / `VAPID_SUBJECT`                              | `php artisan webpush:vapid`                          | Web Push; la privada es secreta; cambiarlas invalida las suscripciones                                                                                                                                                                           |
| `WEBPUSH_TTL` / `_THROTTLE_SECONDS` / `_TIMEOUT` / `_RETENTION_DAYS` / `_ALLOWED_HOSTS` | `3600` / `60` / `5` / `120` / por defecto            | Opcionales ([notificaciones §1.8](notificaciones-y-horarios.md#18-web-push-avisos-con-la-plataforma-cerrada))                                                                                                                                    |
| `EXPORTS_XLSX_MAX_CELLS`                                                                | `800000`                                             | Tope de celdas del XLSX en memoria (≈ 650 MB)                                                                                                                                                                                                    |
| `OPS_PREFLIGHT_MIN_FREE_DISK_MB` / `OPS_BACKUP_DIR` / `OPS_BACKUP_MAX_AGE_HOURS`        | `2048` / `/var/backups/chat-manager/database` / `26` | Comprobaciones de disco y respaldos del preflight (§15)                                                                                                                                                                                          |
| `WEBCHAT_*` (límites y `WEBCHAT_SESSION_ABSOLUTE_DAYS`)                                 | por defecto                                          | Opcionales ([api-v1.md §8](api-v1.md#8-límites)); `WEBCHAT_DEMO_ENABLED` vacío (la demo solo existe en `local`)                                                                                                                                  |
| `INTEGRATIONS_READS_PER_MINUTE` / `INTEGRATIONS_WRITES_PER_MINUTE`                      | `600` / `60`                                         | Límites por token de la API de integración                                                                                                                                                                                                       |
| `SESSION_SAME_SITE`                                                                     | `lax` (no cambiar)                                   | `none` rompería la separación entre la sesión de agentes y el widget incrustado ([seguridad](seguridad-y-auditoria.md#chat-web-y-api-v1))                                                                                                        |

Secretos fuera del repositorio: `.env` vive en `shared/` (chmod 640) y en el gestor de secretos de la empresa.
`.env.example` no contiene valores reales.

## 6. HTTPS y Nginx

1. Certificado: `sudo certbot certonly --webroot -w /var/www/letsencrypt -d chat.empresa.com` (renovación automática
   del paquete certbot; `sudo systemctl reload nginx` en el hook de renovación).
2. Copia `deploy/nginx/chat-manager.conf`, ajusta dominio y rutas, `sudo nginx -t && sudo systemctl reload nginx`.
3. La plantilla: redirige 80→443; sirve solo `public/`; niega archivos ocultos (`.env`, `.git`) y extensiones
   sensibles; solo ejecuta `index.php`; `client_max_body_size 24m` (alineado con `upload_max_filesize=20M` y
   `post_max_size=24M` del pool; súbelo si aumentas `WHATSAPP_DOCUMENT_MAX_BYTES`); proxy de WebSockets en `location ^~ /app/`
   hacia Reverb (con la barra final: un prefijo `/app` capturaría también `/apple-touch-icon.png`); `/apps` (API HTTP de Reverb) no se expone; pasa `$request_id` a Laravel (`X-Request-Id`).
   **Chat web:** añade el bloque de caché corta del cargador `/widget/v1/chat-manager.js` (`max-age=300,
stale-while-revalidate`) de [widget-integracion.md §8](widget-integracion.md#8-caché-del-cargador); la plantilla aún
   no lo incluye. El frame `/widget/v1/frame/{key}` pasa por PHP (su CSP y `Cache-Control: no-store` los pone la app) y
   `/api/v1` no necesita reglas propias ni cabeceras CORS.
4. En la aplicación: con `APP_ENV=production` y `APP_URL` https se fuerzan URLs https (`URL::forceHttps`) y se envía
   HSTS en peticiones seguras.

## 7. Webhooks públicos de Meta

- URL por conexión: `https://chat.empresa.com/webhooks/whatsapp/{clave}` (la muestra la pantalla de la conexión).
  Meta exige HTTPS con certificado válido; no admite `localhost` ni certificados autofirmados.
- Las rutas de webhook están fuera del grupo `web` (sin sesión, cookies ni CSRF), autenticadas por la firma
  `X-Hub-Signature-256` (POST) o el verify token (GET) y limitadas por tasa. Se guardan y se procesan en la cola
  `webhooks`: el worker `critical` debe estar activo o los mensajes se acumulan (lo detecta la salud del sistema).
- Detalle de activación: [mensajeria-whatsapp.md §9](mensajeria-whatsapp.md).
- Si hay un firewall, permite entrada 443 desde Internet (Meta no publica un rango fijo estable).

## 8. Seguridad de la aplicación en producción

`App\Http\Middleware\SecurityHeaders` (todas las respuestas):

- `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: strict-origin-when-cross-origin`,
  `Permissions-Policy` (cámara, geolocalización, pagos y USB desactivados; micrófono solo el propio sitio),
  `Cross-Origin-Opener-Policy: same-origin`.
- `Strict-Transport-Security` solo en producción y por HTTPS.
- `Content-Security-Policy` (por defecto solo en producción; nunca mientras corre el servidor de Vite): scripts solo
  del propio origen con nonce por petición (las etiquetas de Vite y el script inline del tema lo llevan), estilos del
  propio origen (inline permitido porque la interfaz fija estilos en tiempo de ejecución), imágenes `self data: blob:`,
  `connect-src` propio más `wss://` del host de Reverb cuando está activo, `frame-ancestors 'self'`, `object-src 'none'`.
  Si un cambio de frontend necesita otro origen, agrégalo en `SecurityHeaders::policy()`.
- `App\Http\Middleware\AssignRequestId`: identificador de correlación por petición (reutiliza el de Nginx).

Lista de verificación de seguridad:

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` https, certificado válido.
- [ ] `TRUSTED_PROXIES=127.0.0.1`, `SESSION_SECURE_COOKIE=true`.
- [ ] `.env` con permisos 640 fuera del repositorio; `APP_KEY` respaldada en el gestor de secretos.
- [ ] Usuario de base con privilegios solo sobre `chatmanager`; MariaDB escuchando en 127.0.0.1.
- [ ] Puertos públicos solo 22 (restringido), 80 y 443; Reverb (8080), MariaDB (3306) y Redis (6379) solo loopback;
      Redis con contraseña.
- [ ] `public/hot` no existe (lo verifica el preflight); `public/storage` solo apunta a `storage/app/public`.
- [ ] Workers, Reverb y programador supervisados; `WHATSAPP_TRANSPORT=meta` (no simulado).
- [ ] El primer Superadministrador se crea con `php artisan app:create-administrator` (contraseña propia, nunca una
      conocida); no hay usuarios de demostración en producción (solo se siembran en `local`; el preflight los
      bloquea).
- [ ] Alertas con destinatarios (`OPS_ALERT_EMAILS`) y monitor externo de `/health/ready`.
- [ ] `php artisan app:preflight` sin fallas.

## 9. Colas y workers

Una cola por responsabilidad (`App\Support\Queues`, mapa `queue.names` en `config/queue.php`):

Topología de la estabilización: **un grupo de workers por cola**, de mayor a menor prioridad. Programas de Supervisor
`chatmanager-queue-<grupo>` (grupo `chatmanager-workers`); en systemd, instancias `chat-manager-worker@<grupo>-N`
(`critical-1…4`, `realtime-1…2`, `notifications-1…2`, `media-1…2`, `default-1`, `exports-1`). El antiguo grupo
`general` (`notifications,default`) ya no existe.

| Grupo           | Cola(s)             | Procesos | Opciones de `queue:work`                                  | `stopwaitsecs` | Uso                                                                                   |
| --------------- | ------------------- | -------- | --------------------------------------------------------- | -------------- | ------------------------------------------------------------------------------------- |
| `critical`      | `messages,webhooks` | 4        | `--sleep=1 --tries=3 --backoff=10 --timeout=150`, 256 MB  | 180            | Envíos y mensajes automáticos primero, luego el procesamiento del webhook (recepción) |
| `realtime`      | `realtime`          | 2        | `--sleep=1 --tries=3 --backoff=2 --timeout=60`, 256 MB    | 90             | Señales de tiempo real (bandejas, chat abierto, chat interno, conexiones)             |
| `notifications` | `notifications`     | 2        | `--sleep=1 --tries=3 --backoff=5 --timeout=120`, 256 MB   | 150            | Notificaciones in-app (con su señal `.notification`) y Web Push (`SendWebPush`)       |
| `media`         | `media`             | 2        | `--sleep=2 --tries=3 --backoff=30 --timeout=180`, 512 MB  | 210            | Descargas y subidas de multimedia, más lentas                                         |
| `default`       | `default`           | 1        | `--sleep=2 --tries=3 --backoff=10 --timeout=120`, 256 MB  | 150            | Métricas, sincronización de plantillas, mantenimiento                                 |
| `exports`       | `exports`           | 1        | `--sleep=3 --tries=1 --timeout=660 --max-time=7200`, 1 GB | 700            | Excel/PDF e importación de contactos (`EXPORTS_JOB_TIMEOUT=600`), baja prioridad      |

Todos usan `--max-time=3600` (salvo exportaciones) y `startretries=20`. Los comandos **no fijan conexión**
(`queue:work --queue=...`): toman `QUEUE_CONNECTION` (`database` o `redis`); antes llevaban `database` escrito y con
Redis ningún worker habría consumido. Supervisor necesita un _drop-in_ de systemd
(`/etc/systemd/system/supervisor.service.d/override.conf` con `After=mariadb.service redis-server.service` y
`Wants=mariadb.service`, documentado en la cabecera de la plantilla); las unidades `worker@` y `scheduler` declaran
`After=`/`Wants=` de MariaDB (y `After=` de Redis) y `StartLimitIntervalSec=0`, así que tras un reinicio no quedan en
`FATAL` si la base tarda en arrancar. `tests/Feature/Integration/QueueTopologyTest.php` deriva las colas de
`App\Support\Queues::all()` y comprueba ambas plantillas. La batería de carga usa el mismo reparto por omisión
(`LOAD_WORKERS=webhooks,messages:4;realtime:2;notifications:2;media:2;default:1;exports:1`; todavía con el orden anterior del grupo `critical`, ver `tests/load/run.php`).

Orden del grupo `critical`: `messages` va antes que `webhooks` para que una ráfaga entrante (miles de webhooks en
cola) no retrase la respuesta de un agente; los envíos son pocos y cortos (los de los agentes y los mensajes
automáticos que generan los propios webhooks), así que no dejan sin turno a la recepción. Mediciones en
[rendimiento y carga](rendimiento-y-carga.md#11-latencia-de-envío-en-desarrollo-y-producción).

Reglas: `timeout del job` < `--timeout del worker` < `retry_after` (900 s). `stopwaitsecs` mayor que `--timeout`
para que un despliegue no corte un trabajo. `--max-time`/`--memory` reciclan procesos. Los trabajos tienen intentos y
backoff acotados en su propia clase; nunca se reintenta automáticamente un envío que pudo haber llegado a Meta.

Ajusta el número de procesos con mediciones (cola acumulada y espera máxima en Administración > Sistema), no con
estimaciones. No se publican capacidades numéricas sin pruebas de carga.

**Desarrollo local**: `composer run dev` (procesos definidos en `OperationsServiceProvider::configureDevCommands()`,
visibles con `php artisan dev:list`) levanta Reverb, Vite, `pail`, `schedule:work` y:

| Proceso    | Comando                                                                                    | Por qué                                                             |
| ---------- | ------------------------------------------------------------------------------------------ | ------------------------------------------------------------------- |
| `queue`    | `queue:work --queue=messages,realtime,webhooks --sleep=1 --tries=1 --timeout=0` (en bucle) | Envíos y sus señales sin esperar a una ráfaga de webhooks           |
| `webhooks` | `queue:work --queue=webhooks,realtime,messages --sleep=1 --tries=1 --timeout=0` (en bucle) | Recepción y estados de Meta sin esperar a los envíos                |
| `queue-bg` | `queue:listen --queue=notifications,media,default,exports --sleep=3 --tries=1 --timeout=0` | Resto de colas; recarga el código sola; exportaciones al final      |
| `server`   | `PHP_CLI_SERVER_WORKERS=4 php artisan serve --no-reload`                                   | Webhooks del túnel, el envío y sus recargas se atienden en paralelo |

- Los dos `queue:work` **conservan el código con el que arrancaron**: tras editar PHP que corre en un trabajo (jobs,
  listeners, acciones, transportes), ejecuta `php artisan queue:restart`. Cada worker corre dentro de un bucle de
  shell que lo vuelve a levantar 1 s después (multiplex solo reinicia procesos que terminan con error, y un worker
  reiniciado termina con 0); también se relanza si supera la memoria o falla. `queue-bg` usa `queue:listen`, que
  arranca un proceso por trabajo y recarga solo.
- `PHP_CLI_SERVER_WORKERS` vale 4 por omisión; el valor de `.env` o del shell manda (por ejemplo
  `PHP_CLI_SERVER_WORKERS=8`). Con `--no-reload`, **un cambio en `.env` exige reiniciar `composer run dev`**. Sin
  `--no-reload` Laravel ignora la variable y atiende de una en una.
- Los nombres salen de `App\Support\Queues` (`QUEUE_NAME_*`, `REALTIME_QUEUE`, `EXPORTS_QUEUE`); una cola remapeada
  sobre otra no se repite.
- En Windows (`concurrently` y cmd.exe, sin bucle ni variables en línea) queda un solo `queue:listen --sleep=1` para
  todas las colas y el servidor por omisión.

**`after_commit`**: los eventos de dominio implementan `ShouldDispatchAfterCommit`. El driver `database` inserta el
trabajo dentro de la misma transacción (si se revierte, el trabajo desaparece), por eso conserva `after_commit=false`.
Redis está fuera de la transacción: su conexión usa `after_commit=true` (`REDIS_QUEUE_AFTER_COMMIT`) para que un
worker nunca lea filas aún no confirmadas.

### Opción Redis

Configuración preparada, no activa por defecto: `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis` (y opcionalmente
`SESSION_DRIVER=redis`), `REDIS_HOST/PORT/PASSWORD`, `REDIS_QUEUE_RETRY_AFTER=900`. Con un chat web público activo,
lleva al menos los límites de uso a Redis aunque la caché siga en la base: `CACHE_LIMITER=redis` (`cache.limiter`; vacío
usa `CACHE_STORE`). El almacén `database` sufre bloqueos mutuos (deadlock 1213) en ráfagas sobre la misma clave del
limitador y no reintenta; el Preflight avisa en ese caso, y la API responde esos bloqueos como `503
temporarily_unavailable` reintentable. `REDIS_QUEUE_BLOCK_FOR`
(segundos, vacío por omisión) activa la espera bloqueante en lugar de consultar cada `--sleep`; déjalo vacío con la
topología actual: Redis solo bloquea en la **primera** cola de un worker multicola, así que un worker
`messages,webhooks` ocioso tardaría hasta ese tiempo en ver un webhook. Úsalo solo si todos los workers de la
conexión leen una sola cola (0 equivale a vacío; no afecta a la conexión `database`). Los comandos `queue:work` no
necesitan cambios (usan `QUEUE_CONNECTION`); añade `redis-server.service` a `Wants=` de las unidades systemd. Para varios servidores Reverb: `REVERB_SCALING_ENABLED=true`. Requiere la
extensión `php8.4-redis` (el preflight falla si falta). Al cambiar de driver, deja vaciar la cola `database` antes de
detener sus workers.

## 10. Reverb

Ver [tiempo-real.md §Producción](tiempo-real.md): `REVERB_HOST=127.0.0.1`, `REVERB_PORT=8080`, `REVERB_SCHEME=http`
(publicación interna), `REVERB_CLIENT_HOST=<dominio>`, `REVERB_CLIENT_PORT=443`, `REVERB_CLIENT_SCHEME=https`,
`REVERB_ALLOWED_ORIGINS=<dominio>`. La salud del sistema abre una conexión TCP a `127.0.0.1:8080` (configurable con
`OPS_REVERB_PROBE_HOST/PORT`); si no responde es una advertencia: la interfaz sigue funcionando por consulta periódica.

**Cuota con el chat web.** Los visitantes del widget usan la misma app de Reverb (mismo `REVERB_APP_KEY`, sin cambiar
`REVERB_ALLOWED_ORIGINS`, porque el WebSocket sale del iframe de la propia aplicación) y comparten
`REVERB_APP_MAX_CONNECTIONS` con los agentes: cada visitante con el widget abierto es una conexión. Dimensiona ese
valor (y los descriptores de archivo del proceso) con agentes + visitantes simultáneos esperados. Si el volumen de
visitantes crece, la opción prevista es una segunda app de Reverb para el widget (variables nuevas en `.env`; pendiente
de aprobación). Sin Reverb el widget funciona por sondeo (4 s visible, 20 s oculto).

## 11. Programador

Usa **una** de estas opciones: el programa `chatmanager-scheduler` (`schedule:work`) o la línea de cron
`* * * * * cd /var/www/chat-manager/current && php artisan schedule:run` (`deploy/cron/chat-manager`). Cada minuto
`ops:heartbeat` guarda la hora en la caché; si el latido tiene más de `OPS_SCHEDULER_STALE_AFTER` (300 s) la salud
marca falla y se envía una alerta. La plantilla de cron ejecuta además `ops:check-alerts` cada 10 minutos fuera del
programador, para avisar aunque el programador esté detenido.

## 12. Browsershot y Chromium (PDF)

```bash
sudo apt install chromium fonts-noto fonts-noto-color-emoji   # fuentes para acentos y emojis en los PDF
which chromium node npm                                        # usa estas rutas absolutas en .env
```

`EXPORTS_CHROME_PATH=/usr/bin/chromium`, `EXPORTS_NODE_BINARY=/usr/bin/node`, `EXPORTS_NPM_BINARY=/usr/bin/npm`.
`npm ci` instala puppeteer (dependencia de ejecución; `deploy.sh` evita descargar su navegador con
`PUPPETEER_SKIP_DOWNLOAD=true`), por lo que `node_modules` debe permanecer en la versión desplegada.
`EXPORTS_CHROME_NO_SANDBOX=true` solo si Chromium corre como root o en un contenedor sin espacios de nombres.
El preflight comprueba Node, npm, Chromium y puppeteer; si fallan, solo se afectan los PDF (Excel sigue funcionando).

## 13. Logs y rotación

- Aplicación: `storage/logs/laravel*.log` (`LOG_STACK=daily` rota por día y conserva `LOG_DAILY_DAYS`; con `single`
  usa `deploy/logrotate/chat-manager`). Nivel recomendado `warning`.
- Alertas operativas: `storage/logs/ops-AAAA-MM-DD.log` (canal `ops`, 90 días, `LOG_OPS_DAYS`).
- Workers, Reverb y programador: `/var/log/chatmanager/*.log` (Supervisor), rotados por logrotate.
- Errores de PHP-FPM: `/var/log/chatmanager/php-fpm-errors.log`. El pool corre como `www-data`, así que el archivo
  debe existir con ese propietario (§3, `install -o www-data`); si no, PHP no puede abrirlo y los errores fatales se
  pierden. `copytruncate` conserva propietario y permisos al rotar.
- Respaldos: `/var/log/chatmanager/backup.log` (cron como root).
- Nginx: `/var/log/nginx/chat-manager.*.log` (logrotate del paquete).
- **Correlación**: cada entrada de log lleva `request_id` (petición HTTP) y, en trabajos de cola, `job`, `job_id`,
  `queue` y los identificadores públicos del trabajo (`webhook_event_id`, `message_id`, `conversation_id`,
  `attachment_id`, `connection_id`, `transfer_id`...). Estos datos viajan con los trabajos que se despachan desde ahí
  (Laravel Context), de modo que webhook → mensaje → envío → conversación se siguen con `grep webhook_event_id":123`.
  Los módulos pueden añadir identificadores con `App\Support\LogContext::add([...])` (solo claves de identificador,
  nunca contenido, teléfonos ni payloads).

## 14. Supervisión y mantenimiento (Administración > Sistema)

Permisos nuevos (alcance global obligatorio): `system.view` (ver) y `system.manage` (reintentar/descartar trabajos
fallidos). El Superadministrador los recibe al sincronizar permisos; el Administrador los tiene en instalaciones
nuevas y, en instalaciones existentes, se le asignan desde Roles y permisos.

La página `/admin/system` muestra, sin datos de clientes ni secretos:

- **Verificaciones**: base de datos, caché, almacenamiento escribible, acumulación de colas, trabajos fallidos,
  latido del programador, atraso de webhooks de Meta (registro `webhook_events`) y servidor Reverb.
- **Colas**: pendientes, programados, en proceso y espera del más antiguo por cola, con umbrales `OPS_QUEUE_*`
  (las exportaciones y la multimedia tienen umbrales de espera más amplios).
- **Trabajos fallidos**: clase, cola, fecha y la primera línea del error con tokens, correos, teléfonos y SQL ocultos.
  _Reintentar_ solo para clases idempotentes de la lista `operations.failed_jobs.retryable`
  (`ProcessWhatsAppWebhook`, `RefreshConversationMetrics`, `SyncConnectionTemplates`, listeners de tiempo real y de
  analítica); los envíos de WhatsApp se reintentan desde la conversación (la acción conoce el riesgo de duplicado) y
  las notificaciones no se reenvían tarde. _Descartar_ elimina el registro. Ambas acciones quedan en auditoría
  (`system.job_retried`, `system.job_forgotten`) y un bloqueo evita reintentos dobles.
- **Conexiones de WhatsApp**: salud registrada (sin llamar a Meta en cada visita); _Probar_ verifica credenciales con
  la acción existente (requiere `connections.manage`, limitada a 5 por minuto).
- **Errores recientes**: últimas 20 entradas de nivel error del log más reciente (lee solo los últimos 256 KB, primera
  línea, datos sensibles ocultos).
- **Entorno**: ambiente, depuración, versiones, drivers y transporte de WhatsApp (avisa si es simulado).

JSON detallado para administradores: `GET /admin/system/health` (sesión con `system.view`).

### Salud pública

- `GET /up`: vivacidad (el proceso PHP responde).
- `GET /health/ready`: preparación para monitores externos. Responde solo `{"status":"ok"}` (200) o
  `{"status":"fail"}` (503), sin decir qué falló; limitado a 60 por minuto, sin sesión. Falla con: base de datos o
  caché sin respuesta, almacenamiento no escribible, acumulación crítica de una cola o de webhooks, programador
  detenido. Las advertencias (trabajos fallidos, Reverb caído, programador sin primer latido) no lo hacen fallar.
- Configura un monitor externo (p. ej. UptimeRobot, Better Stack) contra `/health/ready`: es la única forma de enterarse
  de una caída total del servidor, que la aplicación no puede notificar.

### Alertas

`ops:check-alerts` evalúa: aumento de fallidos (`OPS_FAILED_JOBS_SPIKE` en `OPS_FAILED_JOBS_SPIKE_MINUTES`),
programador detenido, atraso crítico de webhooks o colas y base de datos caída. Cada alerta se escribe en el canal
`ops` y se envía por correo a `OPS_ALERT_EMAILS` y, con `OPS_ALERT_NOTIFY_ADMINS=true`, a los usuarios activos con
`system.view`. La misma alerta no se repite antes de `OPS_ALERT_THROTTLE_MINUTES` (60). Las alertas solo contienen
conteos y tiempos. `php artisan ops:check-alerts --dry-run` muestra las condiciones sin enviar nada.

## 15. Preflight de producción

```bash
php artisan app:preflight            # tabla; código de salida 1 si hay alguna falla
php artisan app:preflight --json     # para automatizar
php artisan app:preflight --strict   # también falla con advertencias
```

Niveles: **CORRECTO**, **ADVERTENCIA** (merece revisión, no detiene el despliegue), **BLOQUEANTE** (código de salida
1: `deploy.sh` se detiene sin tocar la versión activa) y **NO APLICA** (la verificación no corresponde a la
configuración, p. ej. Redis sin usar). En `--json` el campo `status` vale `ok`, `warning`, `fail` o `skipped`.

Es de solo lectura (no escribe en la base, la caché ni el disco). Verifica: versión y extensiones de PHP (incluidas
pcntl/OPcache/redis según la configuración), `APP_ENV`, `APP_DEBUG`, `APP_KEY`, `APP_URL` https, caché de configuración,
dependencias de desarrollo, cookies de sesión, proxies de confianza, conexión y versión de MariaDB, migraciones
pendientes, almacenamiento escribible y privado (`public/hot`, `public/storage`, discos de adjuntos/exportaciones,
permisos de `.env`), caché, configuración de colas (`retry_after` frente a exportaciones, fallidos en base), comandos
programados existentes, latido del programador, Reverb (claves, orígenes, TLS, servidor accesible), Browsershot
(Node, npm, Chromium, puppeteer), Meta (`META_GRAPH_VERSION`, `WHATSAPP_TRANSPORT`), correo y destinatarios de alertas,
nivel de logs y zona horaria. Fuera de producción, los problemas que solo bloquean producción se muestran como
advertencias.

Además:

| Clave             | Qué comprueba                                                                                                                                                                                                                                                                                | Nivel                                                                                           |
| ----------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------- |
| `redis`           | `PING` real a cada conexión de Redis que usen la cola, la caché, la sesión o el escalado de Reverb                                                                                                                                                                                           | Bloqueante en producción si no responde; advertencia fuera; no aplica si no se usa Redis        |
| `queues`          | Acumulación y espera del trabajo más antiguo por cola (indicio de workers detenidos)                                                                                                                                                                                                         | Como máximo advertencia (en la primera instalación aún no hay workers); no aplica con `sync`    |
| `webhooks`        | Entregas de Meta sin procesar más allá de `OPS_WEBHOOK_GRACE_SECONDS` y con error en 24 h                                                                                                                                                                                                    | Como máximo advertencia                                                                         |
| `failed_jobs`     | Trabajos fallidos en las últimas 24 h                                                                                                                                                                                                                                                        | Como máximo advertencia                                                                         |
| `meta`            | Con `WHATSAPP_TRANSPORT=meta`, cuántas conexiones activas tienen credenciales verificadas con Meta: con cero **no** se presenta como integrado                                                                                                                                               | Advertencia sin conexiones verificadas; `simulated` sigue siendo bloqueante en producción       |
| `demo`            | Usuarios activos `@demo-b.chatmanager.test` y conexiones no eliminadas con los Phone number ID ficticios `990000000000100` (Ventas) y `990000000000101` (Soporte), según `ManualValidation::LINES` (escenario de validación manual local, p. ej. al restaurar una copia local en producción) | Bloqueante en producción; advertencia fuera                                                     |
| `webpush`         | Con claves VAPID: que formen un par P-256 y que `APP_URL` use HTTPS                                                                                                                                                                                                                          | No aplica sin claves; advertencia si fallan                                                     |
| `storage.disk`    | Espacio libre del disco de `storage/` frente a `OPS_PREFLIGHT_MIN_FREE_DISK_MB` (2048)                                                                                                                                                                                                       | Advertencia                                                                                     |
| `storage.backups` | Antigüedad del `*.sql.gz` más reciente en `OPS_BACKUP_DIR` frente a `OPS_BACKUP_MAX_AGE_HOURS` (26 h); solo lee fechas                                                                                                                                                                       | Advertencia; en producción sin `OPS_BACKUP_DIR` también advierte; fuera de producción no aplica |

El latido del programador (`scheduler.heartbeat`) ya estaba incluido. El preflight no puede ver los procesos de
Supervisor: los workers detenidos se detectan por la cola acumulada. Para `storage.backups`, el usuario que ejecuta el
preflight (`deploy`) debe poder listar el directorio sin leer los archivos (siguen en 600):
`sudo chmod 750 /var/backups/chat-manager /var/backups/chat-manager/database && sudo chgrp deploy /var/backups/chat-manager /var/backups/chat-manager/database`
y `OPS_BACKUP_DIR=/var/backups/chat-manager/database`.

## 16. Despliegue

Lista previa:

- [ ] Respaldo reciente verificado (recuperacion-y-respaldos.md) y espacio en disco (el preflight advierte de ambos).
- [ ] Cambios revisados; migraciones nuevas solo aditivas.
- [ ] `.env` actualizado con las claves nuevas de `.env.example`.
- [ ] Con chat web activo: `TRUSTED_PROXIES` configurado y el bloque de caché del cargador en Nginx (§6).

### Scripts que se ejecutan como root (una vez, y en cada cambio de esos scripts)

Los respaldos necesitan root (credenciales en `/etc/chat-manager`, `/var/backups` privado). Root **nunca** ejecuta un
script de `/var/www/chat-manager`: esas carpetas pertenecen al usuario `deploy`, y quien controle esa cuenta o el
repositorio podría modificar el script y ejecutar cualquier cosa como root. Se instala una copia revisada, propiedad
de root, en `/usr/local/sbin`, y cron, `deploy.sh` y sudoers solo usan esas rutas fijas:

```bash
# Desde un clon revisado del repositorio (git log/diff de deploy/scripts antes de copiar)
for script in backup-database backup-storage backup-offsite restore-database restore-storage; do
  sudo install -o root -g root -m 0755 deploy/scripts/$script.sh /usr/local/sbin/chat-manager-$script
done
sudo visudo -cf deploy/sudoers/chat-manager \
  && sudo install -o root -g root -m 0440 deploy/sudoers/chat-manager /etc/sudoers.d/chat-manager
sudo install -o root -g root -m 0644 deploy/cron/chat-manager /etc/cron.d/chat-manager
```

`deploy/sudoers/chat-manager` autoriza al usuario `deploy` exactamente: `/usr/local/sbin/chat-manager-backup-database`
sin argumentos, `systemctl reload php8.4-fpm` y `supervisorctl restart chatmanager-scheduler` (ajusta el nombre del
usuario, el servicio y las rutas de `systemctl`/`supervisorctl` del servidor). Cuando un despliegue cambie algún
script de `deploy/scripts/backup-*`/`restore-*`, repite la copia manual tras revisarla: **la versión desplegada no
se instala sola**.

### Ejecución

`REPOSITORY=<url-git-del-repositorio> deploy/scripts/deploy.sh [rama-o-etiqueta]` como usuario `deploy`.
`REPOSITORY` es obligatoria (no hay URL por defecto). Variables opcionales: `APP_DIR` (`/var/www/chat-manager`),
`KEEP_RELEASES` (5), `PHP_FPM_SERVICE` (`php8.4-fpm`), `PHP`, `BACKUP_DATABASE_COMMAND`
(`/usr/local/sbin/chat-manager-backup-database`; sudoers solo permite esa ruta), `HEALTH_URL` (por defecto `APP_URL`
de `shared/.env` + `/health/ready`), `SMOKE_ATTEMPTS` (6) y `SMOKE_INTERVAL` (5 s), `AUTO_ROLLBACK` (1) y
`KEEP_FAILED_RELEASE` (0).

1. Comprueba `shared/.env`, `shared/storage` y que se pueda derivar la URL de la prueba de humo (si no, se detiene antes de
   empezar: la prueba es obligatoria).
2. Clona la versión en `releases/<fecha>` y enlaza `shared/.env` y `shared/storage`.
3. `composer install --no-dev --optimize-autoloader`, `npm ci && npm run build` (y borra `public/hot`).
4. `php artisan app:preflight` en la versión nueva: si hay un bloqueante, se detiene sin tocar la versión activa.
5. Consulta `/health/ready` en la versión activa (línea base), respaldo de la base
   (`sudo /usr/local/sbin/chat-manager-backup-database`) y `php artisan down` en la versión activa.
6. `php artisan migrate --force` (aditivas).
7. `php artisan optimize` (config, rutas, vistas y eventos en caché).
8. Cambio atómico de `current`, recarga de PHP-FPM (limpia OPcache), `queue:restart` (los workers terminan su trabajo
   actual y Supervisor los levanta con el código nuevo), `reverb:restart`, reinicio del programador.
9. `php artisan up` y **prueba de humo obligatoria** de `/health/ready` (hasta 6 intentos cada 5 s).
10. `deploy/scripts/prune-releases.sh`: conserva las últimas 5 versiones (nunca borra la activa; se ordenan por nombre;
    sin nada que borrar, como en la primera instalación, no es un error).

Si algo falla **antes** del cambio de enlace, la carpeta de la versión a medio construir se elimina (con
`KEEP_FAILED_RELEASE=1` se conserva para inspección) y la versión activa sigue sirviendo; si el fallo ocurrió con el
sitio en mantenimiento (migraciones u `optimize`), el script lo indica: corrige y vuelve a desplegar o ejecuta
`rollback.sh`, y luego `php artisan up`. Si la **prueba de humo** falla, con `AUTO_ROLLBACK=1` (por defecto) se ejecuta
`rollback.sh <versión anterior>` y el despliegue termina con error; con `AUTO_ROLLBACK=0` solo imprime el comando
exacto de reversión. En la primera instalación no hay versión anterior: el script lo dice y hay que revisar
Administración > Sistema y el preflight.

`/health/ready` falla con condiciones operativas que el preflight solo marca como advertencia (programador sin latido,
acumulación crítica de colas o webhooks, Reverb). Por eso el script consulta `/health/ready` **antes** del cambio: si ya
fallaba, avisa, y si la prueba de humo falla no revierte automáticamente (la versión nueva queda activa, el despliegue
termina con error e imprime el comando de reversión), porque el fallo no es atribuible al código nuevo. Tras `artisan up`
borra el veredicto en caché de la sonda (`cache:forget operations:readiness-verdict`) para que la prueba de humo evalúe
la versión nueva y no la respuesta de la línea base.

Una versión que se conservó con `KEEP_FAILED_RELEASE=1`, o de la que se salió con `rollback.sh` (a mano o por la prueba
de humo), queda marcada con un archivo `FAILED` y `rollback.sh` no vuelve a elegirla.

Después: revisa Administración > Sistema (colas al día, sin fallidos nuevos, latido reciente).

**Fase 4 (chat web y API v1).** `npm run build` ya construye también el widget (`vp build && vp build --config
vite.widget.config.ts`): genera `public/widget/v1/chat-manager.js`, que está en `.gitignore`, así que sin el build el
fragmento de los sitios responde 404. CI (`.github/workflows/tests.yml`) ejecuta el mismo `npm run build`. `deploy.sh`
ejecuta `php artisan app:sync-permissions` justo después de `migrate` (idempotente: crea los permisos nuevos del catálogo,
como `integrations.view` e `integrations.manage`, y no cambia roles existentes); después concede esos permisos a mano a
quien administre integraciones. Las migraciones de la fase (`web_chat_widgets`,
`web_chat_sessions`, `api_tokens`) son tablas nuevas, aditivas.

### Webhooks durante la ventana de mantenimiento

Mientras el sitio está en `artisan down` (migraciones y `optimize`, normalmente segundos), Laravel responde 503 a todo,
también a `/webhooks/whatsapp/*`, y los workers no procesan trabajos. Es deliberado: no se procesan entregas de Meta con
un esquema a medio migrar. Meta reintenta las entregas no confirmadas con espera creciente durante un tiempo limitado,
por lo que una ventana corta no pierde mensajes; tras `up` llegan con retraso. Este comportamiento depende de Meta y
**no se ha validado con tráfico real**: mide la duración de la ventana en el primer despliegue (marcas de hora del
script) y confirma en Administración > Sistema que las entregas atrasadas se procesan.

## 17. Reversión (rollback)

`deploy/scripts/rollback.sh [versión]` vuelve el enlace `current` a la versión anterior (o a la indicada), regenera
cachés, recarga PHP-FPM y reinicia workers, Reverb y programador. Sin argumento elige la versión **completa** más
reciente con nombre menor que la activa (las carpetas son marcas de tiempo; nunca se ordena por fecha de modificación,
así que una versión posterior que falló no se activa). Completa significa con `REVISION`, `vendor/autoload.php` y
`public/build/manifest.json`; una versión indicada que no lo esté, o un nombre que no sea una marca de tiempo, se
rechaza. Tampoco elige ni acepta una versión con el marcador `FAILED`; al terminar marca así la versión que abandona
(para reutilizarla a propósito, revísala y borra `releases/<versión>/FAILED`). `ROLLBACK_DRY_RUN=1 deploy/scripts/rollback.sh` solo muestra qué versión elegiría. **No revierte la base**: como las migraciones son
aditivas, el código anterior funciona con el esquema nuevo. Si el problema está en los datos, restaura el respaldo que
tomó el despliegue siguiendo [recuperacion-y-respaldos.md](recuperacion-y-respaldos.md). Nunca uses
`migrate:rollback` en producción (además está bloqueado).

## 18. Actualizaciones del sistema

- Paquetes del sistema: `apt upgrade` en ventana de mantenimiento; después `sudo systemctl restart php8.4-fpm` y
  `sudo supervisorctl restart all`.
- PHP menor (8.4.x): igual que arriba. PHP mayor o MariaDB mayor: primero en un entorno de prueba con una copia
  restaurada de la base, preflight y pruebas, luego producción.
- Dependencias de la aplicación (Composer/npm): solo mediante una versión nueva del repositorio y `deploy.sh`.

## 19. Limitaciones

- Reloj del servidor: el historial de cada conversación se ordena por hora de escritura, y mezcla la hora de Meta
  (mensajes del cliente) con la del servidor (respuestas, `created_at`), ambas con resolución de segundos. El servidor
  debe sincronizar su reloj por NTP (`timedatectl set-ntp true` o chrony; comprobar con `timedatectl status`): un
  desfase de unos segundos puede dejar una respuesta encima del mensaje que respondía. Un mensaje del cliente que llega
  muy tarde (sin conexión por horas) se coloca en su hora de escritura; el chat lo anuncia y lo cuenta como nuevo
  igualmente.

- Nada de esto se ha ejecutado contra un servidor real desde este repositorio: los scripts se validaron por sintaxis
  (`bash -n`; `shellcheck` no estaba disponible), la elección de versión de `rollback.sh` con pruebas sobre un árbol
  temporal (`tests/Feature/Operations/DeployTemplatesTest.php`), sudoers con `visudo -c`, y Nginx, PHP-FPM y
  logrotate solo por inspección (`nginx -t`, `php-fpm8.4 -t` y `logrotate -d` deben ejecutarse en el servidor).
- La ventana de mantenimiento responde 503 a los webhooks (§16); el reintento de Meta no se ha medido con tráfico
  real.
- Las alertas no usan el centro de notificaciones de la aplicación (solo log y correo); integrar un tipo
  `system.alert` en `App\Notifications` queda como mejora coordinada con ese módulo.
- No hay panel de métricas históricas (Horizon/Pulse no están instalados); la página de Sistema muestra el estado
  actual. Si se adopta Redis, Laravel Horizon sería la evolución natural para colas.
