# WhatsApp Web (QR): guía de instalación y operación

Modalidad para conectar un **WhatsApp normal** escaneando un código QR, sin Meta Business ni WhatsApp Business. Convive
con WhatsApp Cloud API: cada conexión elige su modalidad y las conexiones oficiales no cambian.

- Contrato técnico entre componentes: [whatsapp-web-qr-contrato.md](whatsapp-web-qr-contrato.md).
- Detalle del servicio Node: [`services/whatsapp-web/README.md`](../services/whatsapp-web/README.md).

> **No es una integración oficial.** Usa el protocolo de «Dispositivos vinculados» mediante la biblioteca Baileys.
> WhatsApp puede cerrar la sesión o **suspender el número** sin aviso, también sin envíos masivos, y un cambio de su
> protocolo puede dejar la modalidad sin funcionar hasta actualizar la biblioteca. Úsala con un número de prueba
> autorizado y de forma temporal mientras se prepara Cloud API. Nada aquí evita una suspensión.

## 1. Qué está probado y qué no

| Comprobación                                                                                        | Estado                                       |
| --------------------------------------------------------------------------------------------------- | -------------------------------------------- |
| El servicio obtiene un QR auténtico de WhatsApp, lo renueva y lo deja caducar                       | Probado real (sin teléfono)                  |
| Laravel y el servicio se autentican en ambos sentidos (firma HMAC) y el QR aparece en el asistente  | Probado real en local, en navegador          |
| Crear una conexión QR desde el formulario, cancelar un QR pendiente, tarjeta en Sistema             | Probado real en local, en navegador          |
| Estados, reconexión, sesión revocada, envío idempotente, recepción, duplicados, medios, permisos    | Pruebas automáticas con dobles (73 + 87)     |
| **Escanear con un teléfono, enviar y recibir mensajes y archivos reales, cierre desde el teléfono** | **Pendiente: requiere un número autorizado** |
| Plantillas de despliegue en un servidor                                                             | Pendiente: nunca se han ejecutado            |

Hasta hacer la prueba real del §6, la modalidad no debe darse por lista para producción.

## 2. Qué hace y qué no

Hace: vincular por QR, recibir y enviar texto, emojis, imágenes, documentos, audio, video y respuestas citadas; guardar
los archivos con `MediaDisk` (Synology); usar las mismas conversaciones, departamentos, asignaciones, etiquetas,
respuestas rápidas, auditoría, notificaciones y tiempo real que el resto de la plataforma; mostrar el estado real de la
sesión en Conexiones y en Sistema.

No hace, a propósito: respuestas automáticas (bienvenida, fuera de horario, despedida), menús, mensajes programados,
plantillas, campañas, envíos masivos, extracción de contactos, importación del historial del teléfono, grupos ni
estados. Solo envía lo que escribe una persona con permiso en Conversaciones.

Comportamientos a conocer:

- Al terminar la primera vinculación la conexión **se activa sola** a nombre de quien la pidió (queda en auditoría).
- Un mensaje escrito desde el propio teléfono vinculado se guarda en la conversación solo si ese contacto ya tiene una
  conversación en esa conexión.
- Límites de archivos: los mismos de WhatsApp en la plataforma (video 16 MB, imagen 5 MB). Uno mayor queda como «no
  disponible».
- Si el NAS es obligatorio y no responde, el archivo entrante queda pendiente de reintento; nunca se guarda en el VPS.
- Cuando un envío termina sin respuesta clara, el mensaje queda como «entrega sin confirmar» y **no** se reenvía solo.

## 3. Arquitectura

```
Navegador ── HTTPS ──> Laravel (mensajes.mr-lana.com)
                          │  ▲
       API de control     │  │  eventos y medios
       127.0.0.1:3410     ▼  │  127.0.0.1:8091 (vhost interno)
                     Servicio Node (Baileys) ── WebSocket ──> WhatsApp
                          │
                 /var/lib/chatmanager/whatsapp-web   (sesiones cifradas)
```

- **Biblioteca:** `baileys` `7.0.0-rc14` (la que npm publica como vigente; la serie 6 está marcada como antigua), fijada
  con `package-lock.json`. Funciona por WebSocket: no instala Chromium. Requiere **Node 20 o superior**.
- **Servicio Node** (`services/whatsapp-web`): proceso único bajo Supervisor, usuario propio sin privilegios, escucha
  solo en `127.0.0.1`. Guarda las credenciales de cada sesión cifradas (AES-256-GCM) fuera del árbol desplegado.
- **Laravel:** fuente de verdad de conexiones, mensajes, permisos y archivos. Habla con el servicio por una API interna
  firmada (HMAC, ventana de 60 s, protección contra reproducción). El QR nunca se guarda en la base.
- **Supervisor** y no systemd: es lo que ya usan los workers y Reverb del proyecto, así que arranque, reinicio y logs se
  operan igual.

## 4. Instalación en producción (Ubuntu 24.04, `/var/www/mensajes`)

Ejecuta los bloques en orden. No reinician el VPS ni tocan otros proyectos: solo agregan un programa de Supervisor y un
vhost interno de Nginx propios de ChatManager. Ajusta `www-data` si PHP-FPM corre con otra cuenta.

### 4.1 Inspección (no cambia nada)

```bash
cd /var/www/mensajes && git status --short && git log --oneline -1
node -v && command -v node                       # debe ser v20 o superior
sudo supervisorctl status
sudo ss -ltnp | grep -E ':(3410|8091)\b' || echo "puertos 3410 y 8091 libres"
grep -RhE "fastcgi_pass|server_name|root " /etc/nginx/sites-enabled/ | grep -iB2 -A2 mensajes   # socket de PHP-FPM
ls -ld /var/lib/chatmanager /var/log/chatmanager 2>/dev/null
```

Si Node es menor a 20, instala una versión LTS vigente antes de seguir (por ejemplo con NodeSource) y confirma que el
resto del proyecto sigue compilando con ella.

### 4.2 Respaldo y código

```bash
cd /var/www/mensajes
mysqldump --single-transaction --routines chatmanager | gzip > ~/chatmanager-antes-qr-$(date +%F).sql.gz   # ajusta el nombre de la base
git pull origin main
composer install --no-dev --optimize-autoloader
```

### 4.3 Usuario, directorios y secretos

```bash
sudo install -d -m 755 /var/lib/chatmanager /var/log/chatmanager /etc/chatmanager
sudo adduser --system --group --no-create-home --home /var/lib/chatmanager/whatsapp-web \
     --shell /usr/sbin/nologin chatmanager-wa
sudo install -d -o chatmanager-wa -g chatmanager-wa -m 700 /var/lib/chatmanager/whatsapp-web

sudo install -o root -g chatmanager-wa -m 640 \
     /var/www/mensajes/services/whatsapp-web/.env.example /etc/chatmanager/whatsapp-web.env
openssl rand -hex 32        # → WAWEB_SECRET (y WHATSAPP_WEB_SECRET en Laravel: el mismo valor)
openssl rand -base64 32     # → WAWEB_SESSION_KEY (guárdala también en tu gestor de contraseñas)
sudoedit /etc/chatmanager/whatsapp-web.env
```

En `/etc/chatmanager/whatsapp-web.env`:

```dotenv
WAWEB_HOST=127.0.0.1
WAWEB_PORT=3410
WAWEB_SECRET=<el hex de 64 caracteres>
WAWEB_LARAVEL_URL=http://127.0.0.1:8091
WAWEB_DATA_DIR=/var/lib/chatmanager/whatsapp-web
WAWEB_SESSION_KEY=<la clave base64>
```

> Si se pierde `WAWEB_SESSION_KEY` hay que vincular de nuevo cada número. No la guardes en Git.

En `/var/www/mensajes/.env` de Laravel:

```dotenv
WHATSAPP_WEB_ENABLED=true
WHATSAPP_WEB_URL=http://127.0.0.1:3410
WHATSAPP_WEB_SECRET=<el mismo hex de WAWEB_SECRET>
WHATSAPP_WEB_CALLBACK_URL=http://127.0.0.1:8091
WHATSAPP_WEB_ALLOWED_IPS=127.0.0.1,::1
```

`WHATSAPP_TRANSPORT` **no se toca**: sigue gobernando solo las conexiones de Cloud API.

### 4.4 Migraciones

```bash
cd /var/www/mensajes
php artisan migrate --force          # solo agrega columnas y permisos; no borra nada
php artisan app:sync-permissions
```

### 4.5 Compilación

```bash
cd /var/www/mensajes
npm ci --no-audit --no-fund && npm run build

cd /var/www/mensajes/services/whatsapp-web
npm ci --no-audit --no-fund && npm run build && npm prune --omit=dev --no-audit --no-fund

cd /var/www/mensajes
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
```

### 4.6 Servicios

Vhost interno (para que el servicio entregue eventos y medios sin salir a Internet). Sustituye la ruta y el socket de
PHP-FPM por los que mostró la inspección:

```bash
sudo cp /var/www/mensajes/deploy/nginx/chat-manager-internal.conf /etc/nginx/sites-available/
sudo sed -i 's#/var/www/chat-manager/current/public#/var/www/mensajes/public#' \
     /etc/nginx/sites-available/chat-manager-internal.conf
sudoedit /etc/nginx/sites-available/chat-manager-internal.conf     # fastcgi_pass: el socket real de PHP-FPM
sudo ln -s /etc/nginx/sites-available/chat-manager-internal.conf /etc/nginx/sites-enabled/
```

En el vhost **público** de `mensajes.mr-lana.com` agrega, dentro del bloque `server` de 443, para que esas rutas no sean
alcanzables desde Internet:

```nginx
location ^~ /internal/ { return 404; }
```

```bash
sudo nginx -t && sudo systemctl reload nginx
```

Programa de Supervisor:

```bash
sudo cp /var/www/mensajes/deploy/supervisor/chat-manager-whatsapp-web.conf /etc/supervisor/conf.d/
sudo sed -i -e 's#/var/www/chat-manager/current/services#/var/www/mensajes/services#' \
            -e 's#/var/www/chat-manager/shared/whatsapp-web.env#/etc/chatmanager/whatsapp-web.env#' \
            -e "s#command=/usr/bin/node#command=$(command -v node)#" \
     /etc/supervisor/conf.d/chat-manager-whatsapp-web.conf
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl status chatmanager-whatsapp-web
```

La rotación de logs existente (`/var/log/chatmanager/*.log`) ya cubre `whatsapp-web.log`. El programador de Laravel
ejecuta `whatsapp-web:sync` cada minuto: comprueba que `php artisan schedule:list | grep whatsapp-web` lo muestre.

### 4.7 Verificaciones

```bash
curl -s http://127.0.0.1:3410/health                                   # {"status":"ok"}
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8091/         # 404 (solo existen las rutas internas)
curl -s -o /dev/null -w '%{http_code}\n' -X POST http://127.0.0.1:8091/internal/whatsapp-web/events   # 401 (sin firma)
curl -s -o /dev/null -w '%{http_code}\n' -X POST https://mensajes.mr-lana.com/internal/whatsapp-web/events   # 404
cd /var/www/mensajes/services/whatsapp-web && \
  sudo -u chatmanager-wa env WAWEB_ENV_FILE=/etc/chatmanager/whatsapp-web.env node dist/cli.js GET /v1/status
sudo tail -n 20 /var/log/chatmanager/whatsapp-web.log
```

En la plataforma: Administración → Sistema debe mostrar «WhatsApp Web (QR)» con el servicio respondiendo.

## 5. Vincular el primer número

1. Administración → Conexiones → **Nueva conexión** → **WhatsApp Web (QR)**.
2. Nombre, departamentos y departamento predeterminado → **Crear conexión**.
3. En la ficha, **Vincular teléfono**. Aparece el QR con su cuenta regresiva.
4. En el teléfono: WhatsApp → Dispositivos vinculados → Vincular un dispositivo → escanear.
5. La ventana pasa por «Vinculando» y «Conectada» y muestra el número reconocido. La conexión queda activa.

El QR caduca (60 s el primero, 20 s los siguientes) y se renueva solo hasta 5 veces; después hay que pulsar «Generar QR».
Para un segundo número, repite los pasos con otra conexión: cada una tiene su sesión independiente.

## 6. Prueba real (pendiente, con un número autorizado)

Con un número de prueba vinculado y un segundo teléfono:

1. Enviar desde el segundo teléfono un texto con emoji → aparece en Conversaciones, en el departamento correcto, una
   sola vez.
2. Responder desde ChatManager → llega al teléfono; el estado avanza a enviado, entregado y leído.
3. Recibir y enviar imagen, documento, nota de voz y video; abrirlos en ChatManager y comprobar en Administración →
   Almacenamiento que se guardaron en el Synology.
4. Responder citando un mensaje, en ambos sentidos.
5. Escribir desde el propio teléfono vinculado a un contacto con conversación abierta → aparece como saliente.
6. `sudo supervisorctl restart chatmanager-whatsapp-web` → la sesión vuelve sola a «Conectada», sin QR y sin duplicar
   mensajes.
7. En el teléfono, Dispositivos vinculados → cerrar la sesión → la conexión pasa a «Sesión cerrada» y pide vincular de
   nuevo; no se regenera sola.
8. Desmontar el NAS de prueba (o simularlo) y recibir un archivo → queda pendiente, no en el VPS.

Anota lo que falle con la hora: el log del servicio no contiene mensajes ni números completos, solo estados y errores.

## 7. Operación

| Acción en la ficha            | Efecto                                                                         |
| ----------------------------- | ------------------------------------------------------------------------------ |
| Desconectar temporalmente     | Cierra el socket y conserva la sesión. «Reconectar» la retoma sin QR.          |
| Cerrar sesión del dispositivo | Desvincula el teléfono y borra las credenciales. Habrá que escanear de nuevo.  |
| Eliminar conexión             | Quita la conexión y su sesión. Las conversaciones y el historial se conservan. |

Diagnóstico:

```bash
sudo supervisorctl status chatmanager-whatsapp-web
sudo tail -f /var/log/chatmanager/whatsapp-web.log
cd /var/www/mensajes && php artisan whatsapp-web:sync           # reconcilia estados con el servicio
tail -n 50 /var/www/mensajes/storage/logs/laravel.log | grep -i whatsapp
```

| Síntoma                                | Causa habitual y arreglo                                                                                 |
| -------------------------------------- | -------------------------------------------------------------------------------------------------------- |
| «El servicio no responde» en la ficha  | El programa de Supervisor está caído o `WHATSAPP_WEB_URL` no coincide con `WAWEB_HOST`/`WAWEB_PORT`.     |
| El QR no aparece y el estado no cambia | Los eventos no llegan a Laravel: revisa el vhost `127.0.0.1:8091` y que los dos secretos sean idénticos. |
| `401` en el log del servicio           | Secretos distintos o reloj del servidor desfasado más de 60 s.                                           |
| «Sesión cerrada» sin haberla cerrado   | WhatsApp revocó la sesión (otro dispositivo, inactividad del teléfono, restricción). Vincula de nuevo.   |
| «Error» tras varios minutos sin red    | Se agotaron los reintentos. Pulsa «Reconectar».                                                          |
| Archivos «no disponibles»              | Superan el límite, el NAS no respondía o WhatsApp ya caducó la descarga.                                 |

Recuperar una sesión: «Reconectar» si las credenciales siguen vigentes; si WhatsApp la revocó, «Vincular teléfono».
Las credenciales no se pueden copiar a otro servidor sin `WAWEB_SESSION_KEY` y el directorio de datos completo.

Detener el servicio (las conexiones QR quedan sin enviar ni recibir; Cloud API no se afecta):

```bash
sudo supervisorctl stop chatmanager-whatsapp-web
```

Actualizar tras un `git pull`: repetir §4.5 y `sudo supervisorctl restart chatmanager-whatsapp-web`. El directorio
`/var/lib/chatmanager/whatsapp-web` no se toca en ningún despliegue.

## 8. Reversión

```bash
sudo supervisorctl stop chatmanager-whatsapp-web
sudo rm /etc/supervisor/conf.d/chat-manager-whatsapp-web.conf && sudo supervisorctl reread && sudo supervisorctl update
sudo rm /etc/nginx/sites-enabled/chat-manager-internal.conf && sudo nginx -t && sudo systemctl reload nginx
# en /var/www/mensajes/.env: WHATSAPP_WEB_ENABLED=false
cd /var/www/mensajes && php artisan config:cache
```

Las columnas y permisos agregados por las migraciones son inofensivos y pueden quedarse. Las conversaciones recibidas por
QR se conservan. Para borrar también las sesiones: `sudo rm -rf /var/lib/chatmanager/whatsapp-web` (irreversible).

## 9. Paso posterior a Cloud API

La modalidad QR es temporal. Cuando el número oficial esté listo:

1. Crea la conexión de **WhatsApp Cloud API** siguiendo [puesta-en-produccion.md](puesta-en-produccion.md). Tiene su
   propio registro y sus propias credenciales: las de la sesión QR **no** se migran.
2. Un mismo número no puede estar a la vez en WhatsApp normal y en Cloud API: antes del alta oficial, cierra la sesión
   del dispositivo en la conexión QR y da de baja el número en la app de WhatsApp.
3. Asigna a la conexión oficial los mismos departamentos. Los contactos se reconocen por teléfono, así que el historial
   de cada persona continúa en su ficha; las conversaciones anteriores quedan ligadas a la conexión QR y no se alteran.
4. Desactiva la conexión QR (o elimínala: el historial se conserva).
5. Cuando ya no quede ninguna conexión QR en uso, aplica la reversión del §8 para apagar el servicio.
