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

| Proceso                     | Qué hace                                                                  | Plantilla                                                                               |
| --------------------------- | ------------------------------------------------------------------------- | --------------------------------------------------------------------------------------- |
| Nginx                       | TLS, archivos estáticos de `public/`, proxy de WebSockets `/app` a Reverb | `deploy/nginx/chat-manager.conf`                                                        |
| PHP-FPM 8.4 (pool propio)   | Peticiones HTTP de Laravel                                                | `deploy/php-fpm/chat-manager.conf`                                                      |
| MariaDB 11.4 LTS            | Base única `chatmanager`                                                  | —                                                                                       |
| Workers de colas (4 grupos) | Webhooks, envíos, multimedia, notificaciones, exportaciones               | `deploy/supervisor/chat-manager.conf` (o `deploy/systemd/chat-manager-worker@.service`) |
| Reverb                      | Servidor WebSocket en `127.0.0.1:8080`                                    | Supervisor `chatmanager-reverb` (o `deploy/systemd/chat-manager-reverb.service`)        |
| Programador                 | `schedule:work` (o cron `schedule:run`)                                   | Supervisor `chatmanager-scheduler` / `deploy/cron/chat-manager`                         |
| Redis (opcional)            | Colas/caché/escalado de Reverb                                            | —                                                                                       |
| Chromium + Node             | PDF de exportaciones (Browsershot + puppeteer)                            | —                                                                                       |

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
```

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

| Variable                                                           | Producción                                 | Notas                                                                                  |
| ------------------------------------------------------------------ | ------------------------------------------ | -------------------------------------------------------------------------------------- |
| `APP_ENV` / `APP_DEBUG`                                            | `production` / `false`                     | Con debug, cualquier visitante ve trazas y variables                                   |
| `APP_KEY`                                                          | `php artisan key:generate --show`          | Cifra sesiones y credenciales de conexiones: respáldala aparte; cambiarla las invalida |
| `APP_URL`                                                          | `https://chat.empresa.com`                 | HTTPS obligatorio (Meta y cookies seguras)                                             |
| `TRUSTED_PROXIES`                                                  | `127.0.0.1`                                | Nginx en el mismo host; sin esto Laravel no reconoce HTTPS ni la IP real               |
| `SESSION_SECURE_COOKIE`                                            | `true` (por defecto si `APP_URL` es https) | HttpOnly y SameSite=lax por defecto                                                    |
| `SESSION_DRIVER` / `CACHE_STORE`                                   | `database` (o `redis`)                     | Nunca `array`/`cookie`                                                                 |
| `QUEUE_CONNECTION`                                                 | `database` (o `redis`)                     | Nunca `sync` en producción                                                             |
| `DB_QUEUE_RETRY_AFTER`                                             | `900`                                      | Mayor que el `--timeout` más largo (exportaciones 660)                                 |
| `EXPORTS_QUEUE`                                                    | `exports`                                  | Worker propio con timeout largo                                                        |
| `QUEUE_NAME_*`                                                     | sin cambios                                | Solo si varias apps comparten el backend de colas                                      |
| `BROADCAST_CONNECTION`                                             | `reverb`                                   | Ver tiempo-real.md (`REVERB_*`, `REVERB_CLIENT_*`, `REVERB_ALLOWED_ORIGINS`)           |
| `WHATSAPP_TRANSPORT`                                               | `meta`                                     | `simulated` es solo para desarrollo (el preflight lo rechaza en producción)            |
| `META_GRAPH_VERSION`                                               | `v26.0` (o la vigente)                     | Credenciales por conexión en la interfaz, no en `.env`                                 |
| `MAIL_*`                                                           | SMTP real                                  | Restablecimiento de contraseña y alertas                                               |
| `OPS_ALERT_EMAILS` / `OPS_ALERT_NOTIFY_ADMINS`                     | buzón de guardia / `true`                  | Destinatarios de alertas operativas                                                    |
| `EXPORTS_NODE_BINARY`, `EXPORTS_NPM_BINARY`, `EXPORTS_CHROME_PATH` | rutas absolutas                            | `/usr/bin/node`, `/usr/bin/npm`, `/usr/bin/chromium`                                   |
| `LOG_STACK` / `LOG_LEVEL`                                          | `daily` / `warning`                        | `debug` llena el disco                                                                 |
| `SECURITY_CSP_ENABLED`                                             | vacío (= activo en producción)             | Ver §8                                                                                 |

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
      conocida); no hay usuarios de demostración en producción (solo se siembran en `local`).
- [ ] Alertas con destinatarios (`OPS_ALERT_EMAILS`) y monitor externo de `/health/ready`.
- [ ] `php artisan app:preflight` sin fallas.

## 9. Colas y workers

Una cola por responsabilidad (`App\Support\Queues`, mapa `queue.names` en `config/queue.php`):

| Grupo (Supervisor) | Colas                   | Procesos | `--timeout` | Motivo                                                           |
| ------------------ | ----------------------- | -------- | ----------- | ---------------------------------------------------------------- |
| `critical`         | `webhooks,messages`     | 4        | 150         | Entrada de Meta y envíos: no deben esperar a nada                |
| `media`            | `media`                 | 2        | 180         | Descargas/subidas de multimedia, más lentas                      |
| `general`          | `notifications,default` | 2        | 120         | Señales de tiempo real, notificaciones, métricas, plantillas     |
| `exports`          | `exports`               | 1        | 660         | Excel/PDF e importación de contactos (`EXPORTS_JOB_TIMEOUT=600`) |

Reglas: `timeout del job` < `--timeout del worker` < `retry_after` (900 s). `stopwaitsecs` mayor que `--timeout`
para que un despliegue no corte un trabajo. `--max-time`/`--memory` reciclan procesos. Los trabajos tienen intentos y
backoff acotados en su propia clase; nunca se reintenta automáticamente un envío que pudo haber llegado a Meta.

Ajusta el número de procesos con mediciones (cola acumulada y espera máxima en Administración > Sistema), no con
estimaciones. No se publican capacidades numéricas sin pruebas de carga.

**Desarrollo local**: `composer run dev` escucha todas las colas en orden de prioridad (webhooks, messages, media,
notifications, default, exports) y ejecuta `schedule:work` (configurado en `OperationsServiceProvider`).

**`after_commit`**: los eventos de dominio implementan `ShouldDispatchAfterCommit`. El driver `database` inserta el
trabajo dentro de la misma transacción (si se revierte, el trabajo desaparece), por eso conserva `after_commit=false`.
Redis está fuera de la transacción: su conexión usa `after_commit=true` (`REDIS_QUEUE_AFTER_COMMIT`) para que un
worker nunca lea filas aún no confirmadas.

### Opción Redis

Configuración preparada, no activa por defecto: `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis` (y opcionalmente
`SESSION_DRIVER=redis`), `REDIS_HOST/PORT/PASSWORD`, `REDIS_QUEUE_RETRY_AFTER=900`. Cambia `database` por `redis` en
los comandos `queue:work` de Supervisor. Para varios servidores Reverb: `REVERB_SCALING_ENABLED=true`. Requiere la
extensión `php8.4-redis` (el preflight falla si falta). Al cambiar de driver, deja vaciar la cola `database` antes de
detener sus workers.

## 10. Reverb

Ver [tiempo-real.md §Producción](tiempo-real.md): `REVERB_HOST=127.0.0.1`, `REVERB_PORT=8080`, `REVERB_SCHEME=http`
(publicación interna), `REVERB_CLIENT_HOST=<dominio>`, `REVERB_CLIENT_PORT=443`, `REVERB_CLIENT_SCHEME=https`,
`REVERB_ALLOWED_ORIGINS=<dominio>`. La salud del sistema abre una conexión TCP a `127.0.0.1:8080` (configurable con
`OPS_REVERB_PROBE_HOST/PORT`); si no responde es una advertencia: la interfaz sigue funcionando por consulta periódica.

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

Es de solo lectura (no escribe en la base, la caché ni el disco). Verifica: versión y extensiones de PHP (incluidas
pcntl/OPcache/redis según la configuración), `APP_ENV`, `APP_DEBUG`, `APP_KEY`, `APP_URL` https, caché de configuración,
dependencias de desarrollo, cookies de sesión, proxies de confianza, conexión y versión de MariaDB, migraciones
pendientes, almacenamiento escribible y privado (`public/hot`, `public/storage`, discos de adjuntos/exportaciones,
permisos de `.env`), caché, configuración de colas (`retry_after` frente a exportaciones, fallidos en base), comandos
programados existentes, latido del programador, Reverb (claves, orígenes, TLS, servidor accesible), Browsershot
(Node, npm, Chromium, puppeteer), Meta (`META_GRAPH_VERSION`, `WHATSAPP_TRANSPORT`), correo y destinatarios de alertas,
nivel de logs y zona horaria. Fuera de producción, los problemas que solo bloquean producción se muestran como
advertencias.

## 16. Despliegue

Lista previa:

- [ ] Respaldo reciente verificado (recuperacion-y-respaldos.md) y espacio en disco.
- [ ] Cambios revisados; migraciones nuevas solo aditivas.
- [ ] `.env` actualizado con las claves nuevas de `.env.example`.

Ejecución (`deploy/scripts/deploy.sh [rama-o-etiqueta]`, variables `APP_DIR`, `REPOSITORY`, `HEALTH_URL`,
`PHP_FPM_SERVICE`):

1. Clona la versión en `releases/<fecha>` y enlaza `shared/.env` y `shared/storage`.
2. `composer install --no-dev --optimize-autoloader`, `npm ci && npm run build` (y borra `public/hot`).
3. `php artisan app:preflight` en la versión nueva: si falla, se detiene sin tocar la versión activa.
4. Respaldo de la base (`sudo backup-database.sh`: el usuario `deploy` necesita sudo para ese script, además de
   `systemctl reload php8.4-fpm` y `supervisorctl`) y `php artisan down` en la versión activa.
5. `php artisan migrate --force` (aditivas).
6. `php artisan optimize` (config, rutas, vistas y eventos en caché).
7. Cambio atómico de `current`, recarga de PHP-FPM (limpia OPcache), `queue:restart` (los workers terminan su trabajo
   actual y Supervisor los levanta con el código nuevo), `reverb:restart`, reinicio del programador.
8. `php artisan up` y prueba de `/health/ready`; conserva las últimas 5 versiones.

Después: revisa Administración > Sistema (colas al día, sin fallidos nuevos, latido reciente).

## 17. Reversión (rollback)

`deploy/scripts/rollback.sh [versión]` vuelve el enlace `current` a la versión anterior (o a la indicada), regenera
cachés, recarga PHP-FPM y reinicia workers, Reverb y programador. **No revierte la base**: como las migraciones son
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

- Nada de esto se ha ejecutado contra un servidor real desde este repositorio: plantillas y scripts se validaron por
  sintaxis (`bash -n`, `nginx -t` debe ejecutarse en el servidor) y las verificaciones de la aplicación con pruebas
  automatizadas.
- Las alertas no usan el centro de notificaciones de la aplicación (solo log y correo); integrar un tipo
  `system.alert` en `App\Notifications` queda como mejora coordinada con ese módulo.
- No hay panel de métricas históricas (Horizon/Pulse no están instalados); la página de Sistema muestra el estado
  actual. Si se adopta Redis, Laravel Horizon sería la evolución natural para colas.
