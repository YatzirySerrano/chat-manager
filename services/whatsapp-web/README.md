# Servicio WhatsApp Web (QR)

Proceso Node.js privado que mantiene las sesiones de WhatsApp Web de Chat Manager: genera el QR de vinculación, guarda
las credenciales cifradas, envía y recibe mensajes y avisa a Laravel de cada evento. Es una pieza de la modalidad
**WhatsApp Web (QR)**; el contrato con Laravel y con la interfaz está en
[`docs/whatsapp-web-qr-contrato.md`](../../docs/whatsapp-web-qr-contrato.md).

> **Integración no oficial.** Usa el protocolo de WhatsApp Web a través de una biblioteca de terceros. WhatsApp puede
> cambiar el protocolo sin aviso (y dejar el servicio sin funcionar hasta actualizar la biblioteca) y puede suspender
> números que usen clientes no oficiales. No sustituye a WhatsApp Cloud API, que sigue disponible por conexión. El
> servicio no hace envíos masivos ni campañas. Al vincular un número entrega a Laravel el historial de sus chats
> individuales (`WAWEB_SYNC_HISTORY`, activo por defecto), que Laravel guarda como historial de solo lectura.

## Biblioteca y versión

| Dato               | Valor                                                                           |
| ------------------ | ------------------------------------------------------------------------------- |
| Biblioteca         | [Baileys](https://github.com/WhiskeySockets/Baileys), paquete npm `baileys`     |
| Versión fijada     | `7.0.0-rc14` (exacta, con `package-lock.json`)                                  |
| Node.js            | 20 o superior (lo exige la biblioteca; el servicio se verificó con Node 24.11)  |
| Módulos            | ESM (`"type": "module"`); la biblioteca dejó de publicar CommonJS en la línea 7 |
| Otras dependencias | `pino` (registros). Sin Chromium, sin base de datos propia                      |

**Por qué Baileys y por qué esta versión** (consultado en el registro de npm el 2026-11-12):

- `baileys` y `@whiskeysockets/baileys` publican lo mismo; la etiqueta `latest` de ambos es `7.0.0-rc14` y la línea
  6.x (`6.7.24`) está etiquetada `legacy`. Los mantenedores dirigen las instalaciones nuevas a la línea 7 aunque siga
  en candidata a versión final; por eso se fija la versión exacta y no un rango.
- La línea 7 es la que entiende los identificadores de privacidad de WhatsApp (`@lid`): un chat puede llegar con un
  LID en lugar del número, con el número en `remoteJidAlt` o resoluble con `signalRepository.lidMapping`. El
  almacén de credenciales del servicio guarda los tipos que esa línea añade (`lid-mapping`, `device-list`,
  `tctoken`, `identity-key`).
- Frente a `whatsapp-web.js`: esa biblioteca automatiza un Chromium por sesión (cientos de MB de memoria por número y
  un navegador que mantener); Baileys habla el protocolo por WebSocket, sin navegador, y varias sesiones caben en un
  solo proceso pequeño. Para un servicio persistente bajo Supervisor en un VPS compartido es la opción adecuada.
- El código que depende de la biblioteca está aislado en `src/baileys.ts`. Las firmas usadas (`makeWASocket`,
  `makeCacheableSignalKeyStore`, `downloadContentFromMessage`, `fetchLatestWaWebVersion`, eventos
  `connection.update`, `creds.update`, `messages.upsert`, `messages.update`) se contrastaron con los tipos de la
  versión instalada, no con ejemplos de versiones anteriores.

Para actualizar la biblioteca: cambia la versión exacta en `package.json`, `npm install`, `npm run typecheck`,
`npm test`, y repite la verificación del QR real (abajo) antes de desplegar.

## Arquitectura

```
Laravel ──(HTTP firmado, 127.0.0.1)──▶ API de control ──▶ SessionManager ──▶ Session (una por conexión)
   ▲                                                                            │
   │                                                                            ▼
   └──(HTTP firmado)── Outbox (cola en disco, cifrada) ◀── eventos ◀── socket de WhatsApp Web (Baileys)
```

| Archivo              | Qué hace                                                                                                     |
| -------------------- | ------------------------------------------------------------------------------------------------------------ |
| `src/index.ts`       | Arranque: configuración, bloqueo de instancia única, señales de apagado.                                     |
| `src/app.ts`         | Ensambla el servicio; la parte de WhatsApp se inyecta (las pruebas usan un doble).                           |
| `src/server.ts`      | API de control (§4 del contrato). Verifica la firma de todo salvo `GET /health`.                             |
| `src/signing.ts`     | Firma HMAC-SHA256, ventana de 60 s, caché de nonces de 5 min, comparación en tiempo constante.               |
| `src/manager.ts`     | Una sesión por conexión; una operación a la vez por sesión; restauración al arrancar.                        |
| `src/session.ts`     | Máquina de estados (§3), QR, reconexión con backoff y jitter, envío idempotente, filtro de mensajes.         |
| `src/baileys.ts`     | Único módulo que usa Baileys. Abre el socket y lo adapta a la interfaz de `src/wa.ts`.                       |
| `src/normalize.ts`   | Convierte un mensaje de Baileys en el `InboundMessage` del contrato. Sin red.                                |
| `src/auth-state.ts`  | Credenciales de la sesión cifradas en disco (reemplaza a `useMultiFileAuthState`, que las escribe en claro). |
| `src/vault.ts`       | AES-256-GCM con escritura atómica; cada archivo queda ligado a su nombre lógico.                             |
| `src/outbox.ts`      | Cola de eventos hacia Laravel: al menos una vez, en orden por conexión, con reintentos.                      |
| `src/sent-store.ts`  | Claves de idempotencia (48 h) e identificador estable de cada mensaje enviado.                               |
| `src/media-fetch.ts` | Descarga del archivo de un mensaje saliente, siempre desde el Laravel configurado.                           |
| `src/lock.ts`        | Bloqueo de instancia única (`service.lock` con el PID).                                                      |
| `src/cli.ts`         | Cliente de diagnóstico que firma peticiones a la API de control.                                             |

### Decisiones que conviene conocer

- **Estados reales.** Solo `connected` permite enviar. Una sesión revocada (cierre desde el teléfono, número
  rechazado, credenciales inválidas) queda en `logged_out` y **no** genera un QR por su cuenta. Una sesión reemplazada
  por otra instancia queda en `error` sin pelear por la conexión.
- **Reconexión.** 2 s, 4 s, 8 s… hasta 60 s, con ±25 % de variación, y un máximo de intentos
  (`WAWEB_RECONNECT_MAX_ATTEMPTS`). Agotados, la sesión queda en `error`; «Reconectar» o un reinicio del servicio la
  retoman, porque las credenciales se conservan.
- **Restauración.** Al arrancar se reabren las sesiones que estaban en marcha (también las que se quedaron en `error`
  por red). No se reabren las detenidas a propósito, las revocadas ni las reemplazadas.
- **Idempotencia.** El identificador de WhatsApp de cada mensaje se deriva de la clave de idempotencia. Repetir una
  clave ya enviada devuelve el resultado anterior sin enviar. Si un envío quedó sin confirmar (504 o caída del
  proceso), el reintento usa el mismo identificador y WhatsApp lo trata como el mismo mensaje.
- **Destinatario.** Para un número (`to` con dígitos) el servicio pregunta a WhatsApp qué JID tiene registrado y
  escribe a ese; así un número mexicano guardado como `52…` llega a la cuenta registrada como `521…`. Un número sin
  WhatsApp responde `422 invalid_recipient`. Un JID completo (`…@s.whatsapp.net`, `…@lid`) se usa tal cual.
- **Qué se notifica.** Mensajes de chats individuales posteriores a la vinculación, también los que WhatsApp retuvo
  mientras el servicio estaba desconectado. Nunca grupos, estados, difusiones ni canales. Lo escrito desde el propio
  teléfono llega como `message.outbound_echo`; lo que envió el servicio no se repite. Una edición llega como
  `message.edited` y un «eliminar para todos» como `message.revoked`, con el identificador del mensaje original.
- **Historial.** Una sesión vinculada con `WAWEB_SYNC_HISTORY=true` se registra como cliente de escritorio y recibe de
  WhatsApp el historial del teléfono; cada tramo se normaliza y se encola al momento como `history.batch` (máximo
  `WAWEB_HISTORY_BATCH_SIZE` mensajes y 200 KB por evento, del más antiguo al más reciente). La decisión se toma al
  vincular y se guarda con la sesión: cambiar la variable no afecta a los números ya vinculados.
- **Eventos.** Cada evento se sella en disco antes de enviarse y se borra cuando Laravel responde `2xx`. De los
  estados de sesión pendientes solo se conserva el último, de modo que un QR viejo nunca se entrega tarde. Un `404`
  descarta los eventos de esa conexión y detiene su sesión sin cerrar la sesión del dispositivo.
- **Archivos salientes.** De la URL recibida solo se usan la ruta y la consulta: la petición va siempre a
  `WAWEB_LARAVEL_URL`, así una URL manipulada no puede hacer que el servicio descargue otra cosa.
- **Privacidad.** El dispositivo no se marca «en línea» (el teléfono sigue recibiendo sus notificaciones), no se
  envían confirmaciones de lectura y la biblioteca no registra nada (`WAWEB_LIBRARY_LOG_LEVEL=silent`).

### Datos en disco (`WAWEB_DATA_DIR`)

```
sessions/{id}/meta.bin     intención y datos de la sesión (número, fecha de vinculación)
sessions/{id}/auth/*.bin   credenciales y claves de Baileys, un archivo por clave
outbox/*.evt               eventos pendientes de entregar a Laravel
sent/*.bin                 claves de idempotencia de las últimas 48 h
tmp/                       archivos salientes mientras se envían (se borran al terminar)
service.lock               PID de la instancia en ejecución
```

Todo salvo `service.lock` está cifrado con `WAWEB_SESSION_KEY`. Sin esa clave los archivos no abren: si se pierde,
hay que vincular cada número de nuevo. Respalda la clave por separado de los datos.

## Requisitos

- Node.js 20 o superior y npm.
- Salida HTTPS/WSS hacia los servidores de WhatsApp (`web.whatsapp.com`, `*.whatsapp.net`).
- Acceso HTTP a Laravel (`WAWEB_LARAVEL_URL`).
- Un directorio privado y persistente para `WAWEB_DATA_DIR`.

## Variables

Descritas en [`.env.example`](.env.example). Las obligatorias son `WAWEB_SECRET` (el mismo valor que
`WHATSAPP_WEB_SECRET` de Laravel), `WAWEB_LARAVEL_URL`, `WAWEB_DATA_DIR` y `WAWEB_SESSION_KEY`. Sin alguna de ellas el
proceso termina al arrancar con el motivo (código de salida 78).

## Uso en local

```bash
cd services/whatsapp-web
npm ci
cp .env.example .env          # completa WAWEB_SECRET, WAWEB_SESSION_KEY, WAWEB_DATA_DIR=./data, WAWEB_LARAVEL_URL
npm run dev                   # arranca con tsx, sin compilar
npm test                      # 73 pruebas con un doble de WhatsApp; no usan la red
npm run typecheck
npm run build && npm start    # como en producción
```

## Producción (Ubuntu, Supervisor)

Supervisor es el gestor que ya usa el proyecto para los workers y Reverb; este servicio es un programa más
(`deploy/supervisor/chat-manager-whatsapp-web.conf`). Los comandos suponen las rutas de las plantillas
(`/var/www/chat-manager`); ajústalas a las del servidor.

```bash
# 1. Inspección (no cambia nada)
node -v && command -v node                      # 20 o superior; anota la ruta
sudo supervisorctl status | grep chatmanager
sudo ss -ltnp | grep -E ':(3410|8091)\b' || echo "puertos 3410 y 8091 libres"

# 2. Usuario sin privilegios y directorio de sesiones (fuera de las versiones)
sudo install -d -m 755 /var/lib/chatmanager
sudo adduser --system --group --no-create-home --home /var/lib/chatmanager/whatsapp-web --shell /usr/sbin/nologin chatmanager-wa
sudo install -d -o chatmanager-wa -g chatmanager-wa -m 700 /var/lib/chatmanager/whatsapp-web

# 3. Entorno del servicio (secreto y clave de cifrado)
sudo install -o root -g chatmanager-wa -m 640 \
    /var/www/chat-manager/current/services/whatsapp-web/.env.example /var/www/chat-manager/shared/whatsapp-web.env
openssl rand -hex 32        # -> WAWEB_SECRET (y WHATSAPP_WEB_SECRET en el .env de Laravel)
openssl rand -base64 32     # -> WAWEB_SESSION_KEY (guárdala también en tu gestor de secretos)
sudoedit /var/www/chat-manager/shared/whatsapp-web.env

# 4. Dependencias y compilación (deploy.sh lo hace en cada despliegue; a mano la primera vez)
cd /var/www/chat-manager/current/services/whatsapp-web
npm ci --no-audit --no-fund && npm run build && npm prune --omit=dev --no-audit --no-fund

# 5. Servicios
sudo cp /var/www/chat-manager/current/deploy/supervisor/chat-manager-whatsapp-web.conf /etc/supervisor/conf.d/
sudo supervisorctl reread && sudo supervisorctl update
sudo cp /var/www/chat-manager/current/deploy/nginx/chat-manager-internal.conf /etc/nginx/sites-available/
sudo ln -s /etc/nginx/sites-available/chat-manager-internal.conf /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx     # reload, no restart: no corta a los demás sitios

# 6. Verificaciones
sudo supervisorctl status chatmanager-whatsapp-web
curl -s http://127.0.0.1:3410/health             # {"status":"ok"}
sudo -u chatmanager-wa env WAWEB_ENV_FILE=/var/www/chat-manager/shared/whatsapp-web.env \
    node dist/cli.js GET /v1/status              # versión, biblioteca y sesiones
sudo tail -n 50 /var/log/chatmanager/whatsapp-web.log
```

- **Registros:** `/var/log/chatmanager/whatsapp-web.log`. La regla `/var/log/chatmanager/*.log` de
  `deploy/logrotate/chat-manager` ya lo rota (diario, 14 copias, `copytruncate`); no hay que añadir nada.
- **Arranque tras un reinicio:** `autostart=true`. Las sesiones en marcha se restauran solas.
- **Despliegues:** `deploy/scripts/deploy.sh` compila el servicio dentro de la versión nueva y reinicia el programa
  de Supervisor al final. Nunca toca `WAWEB_DATA_DIR`. `rollback.sh` también lo reinicia.
- **sudoers:** `deploy/sudoers/chat-manager` permite al usuario de despliegue
  `supervisorctl restart chatmanager-whatsapp-web`; vuelve a instalarlo si usas esa plantilla.
- **Rutas internas:** con `chat-manager-internal.conf`, Laravel atiende `/internal/whatsapp-web/` solo en
  `127.0.0.1:8091`. Cierra esas rutas en el servidor público añadiendo `location ^~ /internal/ { return 404; }` al
  bloque 443. Sin la plantilla interna el servicio puede usar la URL pública (`WAWEB_LARAVEL_URL=https://dominio`):
  las peticiones siguen firmadas, pero viajan por la interfaz pública.

### Reversión

```bash
sudo supervisorctl stop chatmanager-whatsapp-web
sudo rm /etc/supervisor/conf.d/chat-manager-whatsapp-web.conf && sudo supervisorctl reread && sudo supervisorctl update
sudo rm /etc/nginx/sites-enabled/chat-manager-internal.conf && sudo nginx -t && sudo systemctl reload nginx
```

Detener el servicio no cierra la sesión de ningún teléfono ni borra nada: las conexiones QR quedan sin servicio y
WhatsApp Cloud API sigue igual. Para desvincular de verdad usa «Cerrar sesión del dispositivo» en Chat Manager antes
de detenerlo, o quita el dispositivo desde WhatsApp en el teléfono. `/var/lib/chatmanager/whatsapp-web` solo se borra
a mano y cuando ya no se quiera recuperar ninguna sesión.

## Diagnóstico

```bash
cd /var/www/chat-manager/current/services/whatsapp-web
export WAWEB_ENV_FILE=/var/www/chat-manager/shared/whatsapp-web.env
sudo -u chatmanager-wa -E node dist/cli.js GET /v1/status          # servicio, eventos pendientes y sesiones
sudo -u chatmanager-wa -E node dist/cli.js GET /v1/sessions/12     # una sesión (el QR sale oculto)
sudo -u chatmanager-wa -E node dist/cli.js POST /v1/sessions/12/stop
```

| Síntoma                                     | Causa probable y qué hacer                                                                                                                         |
| ------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- |
| El proceso sale con código 78               | Falta o es inválida una variable; el motivo está en la primera línea del registro.                                                                 |
| El proceso sale con código 75               | Ya hay otra instancia sobre el mismo `WAWEB_DATA_DIR` (`numprocs` debe ser 1).                                                                     |
| `401` en todas las peticiones de Laravel    | `WHATSAPP_WEB_SECRET` y `WAWEB_SECRET` no coinciden, o los relojes difieren más de 60 s.                                                           |
| `events.pending` crece en `/v1/status`      | Laravel no acepta los eventos: revisa `WAWEB_LARAVEL_URL`, el secreto y el registro de Laravel. No se pierden: se entregan al volver.              |
| La sesión no pasa de `starting`             | Sin salida hacia WhatsApp (cortafuegos, DNS) o la versión de WhatsApp Web anunciada fue rechazada; sube `WAWEB_LIBRARY_LOG_LEVEL=info` un momento. |
| `qr_timeout`                                | Nadie escaneó a tiempo. Pide un QR nuevo desde Chat Manager.                                                                                       |
| `logged_out` sin haberlo pedido             | Se quitó el dispositivo desde el teléfono, o WhatsApp revocó la sesión (`last_error.code`). Hay que vincular de nuevo.                             |
| `error` con `replaced`                      | Otra copia del servicio abrió la misma sesión (dos servidores con los mismos datos). Deja una sola y pulsa «Reconectar».                           |
| Las sesiones no abren tras cambiar la clave | `WAWEB_SESSION_KEY` no es la que cifró los datos. Restaura la clave anterior o vincula de nuevo.                                                   |
| `422 invalid_recipient` al enviar           | El número no tiene WhatsApp, o no es un número/JID de persona.                                                                                     |
| `504 send_timeout`                          | WhatsApp no confirmó a tiempo. El mensaje pudo salir o no; reintentar con la misma clave es seguro.                                                |

## Pruebas

`npm test` ejecuta 73 pruebas con el runner nativo de Node y un doble del socket de WhatsApp (ninguna usa la red):
firma y protección contra reproducción, máquina de estados (vinculación, QR agotado, reconexión, sesión revocada,
reemplazada), reinicio del servicio, recepción y duplicados, envío e idempotencia, cola de eventos con Laravel caído,
cifrado en disco, instancia única, normalización de mensajes y archivos.

**Verificación contra WhatsApp real (2026-11-12, sin teléfono):** el servicio compilado obtuvo un QR auténtico de los
servidores de WhatsApp en un segundo, lo renovó a los 60 s y a los 20 s, y al agotar las renovaciones volvió a
`unconfigured` con `qr_timeout`. **No se ha vinculado ningún teléfono**: el escaneo, la recepción, el envío de cada
tipo de mensaje, los estados de entrega, la descarga de archivos y la reconexión con una sesión real están pendientes
de la prueba con un número autorizado.
