# Recuperación y respaldos

Qué respaldar, cómo hacerlo con los scripts de `deploy/scripts/`, cómo verificar que un respaldo sirve y cómo
restaurar.

Los scripts de respaldo y restauración se ejecutan como root, siempre desde la copia revisada instalada en
`/usr/local/sbin/chat-manager-*` (propietario root, 0755), nunca desde `/var/www/chat-manager/...`, que pertenece al
usuario `deploy`. Instalación y actualización: infraestructura-y-despliegue.md §16 («Scripts que se ejecutan como
root»). La infraestructura general está en [infraestructura-y-despliegue.md](infraestructura-y-despliegue.md).

## 1. Qué se respalda

| Elemento                    | Dónde vive                                                                                        | Cómo                                                                 | Frecuencia sugerida                                           |
| --------------------------- | ------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------- | ------------------------------------------------------------- |
| Base de datos `chatmanager` | MariaDB                                                                                           | `chat-manager-backup-database` (`mariadb-dump --single-transaction`) | Diario + antes de cada despliegue (automático en `deploy.sh`) |
| Archivos privados           | `shared/storage/app` (adjuntos de WhatsApp en `media/`, archivos del chat interno, importaciones) | `chat-manager-backup-storage` (tar.gz)                               | Diario                                                        |
| Copia fuera del servidor    | `/var/backups/chat-manager` completo                                                              | `chat-manager-backup-offsite` (rsync o rclone)                       | Diario, después de los anteriores                             |
| `.env` y `APP_KEY`          | `shared/.env`                                                                                     | Gestor de secretos de la empresa (manual)                            | En cada cambio                                                |
| Configuración del servidor  | `/etc/nginx`, `/etc/supervisor/conf.d`, `/etc/php/8.4/fpm/pool.d`, certificados                   | Plantillas en `deploy/` + copia de `/etc`                            | En cada cambio                                                |

**`APP_KEY` es crítica**: las credenciales de las conexiones de WhatsApp (token de acceso, secreto de la app, verify
token) se guardan cifradas con ella. Una base restaurada sin la misma `APP_KEY` conserva los datos pero obliga a volver
a capturar las credenciales de cada conexión, y todas las sesiones se cierran.

**Claves VAPID** (`VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`): respáldalas con el `.env`. La tabla `push_subscriptions`
viaja en el respaldo de la base, pero si al restaurar se generan claves nuevas todas las suscripciones dejan de valer y
cada usuario debe volver a pulsar «Activar en este navegador».

No se respaldan: exportaciones generadas (caducan en `EXPORTS_RETENTION_HOURS` y se pueden regenerar), cachés de
`storage/framework` ni logs (tienen su propia rotación).

## 2. Respaldo de la base de datos

Preparación (una vez, como root):

```bash
sudo install -d -m 700 /etc/chat-manager
sudo tee /etc/chat-manager/backup.cnf >/dev/null <<'CNF'
[client]
user=chatmanager_backup
password=<secreto>
host=127.0.0.1
CNF
sudo chmod 600 /etc/chat-manager/backup.cnf
```

El usuario `chatmanager_backup` solo tiene `SELECT, SHOW VIEW, TRIGGER, EVENT, LOCK TABLES` sobre `chatmanager`
(ver infraestructura-y-despliegue.md §4). La contraseña nunca va en la línea de comandos ni en el crontab.

Ejecución: `sudo /usr/local/sbin/chat-manager-backup-database`
(variables: `DB_NAME`, `BACKUP_DIR=/var/backups/chat-manager/database`, `RETENTION_DAYS=14`, `DEFAULTS_FILE`; `sudo`
descarta las variables del usuario, así que para cambiarlas edita la línea de cron).

- `--single-transaction --quick`: copia consistente de las tablas InnoDB sin bloquear la operación.
- Incluye rutinas, triggers y eventos; `--hex-blob` y `utf8mb4` evitan alterar binarios y emojis.
- Escribe `chatmanager-AAAAMMDDTHHMMSSZ.sql.gz` (permisos 600), lo verifica con `gzip -t`, genera `.sha256` y borra
  los respaldos de más de `RETENTION_DAYS` días.
- Programación: `deploy/cron/chat-manager` (02:15 diario, como root, con la ruta de `/usr/local/sbin`).
- Para que `php artisan app:preflight` compruebe la antigüedad del último respaldo (`storage.backups`), el usuario
  `deploy` debe poder **listar** el directorio, no leer los respaldos (los archivos siguen en 600):
  `sudo chgrp deploy /var/backups/chat-manager /var/backups/chat-manager/database && sudo chmod 750 /var/backups/chat-manager /var/backups/chat-manager/database`
  y `OPS_BACKUP_DIR=/var/backups/chat-manager/database` en `shared/.env` (límite `OPS_BACKUP_MAX_AGE_HOURS`, 26 h).

## 3. Respaldo de archivos

`sudo /usr/local/sbin/chat-manager-backup-storage` genera `storage-AAAAMMDDTHHMMSSZ.tar.gz` con
`storage/app` (sin `app/private/exports`, `framework` ni `logs`), checksum y rotación (`RETENTION_DAYS`).

## 4. Copia fuera del servidor

Un respaldo en el mismo disco no protege contra la pérdida del servidor: sin copia externa, perder el VPS es perder
también los respaldos. `chat-manager-backup-offsite` (plantilla `deploy/scripts/backup-offsite.sh`) copia
`/var/backups/chat-manager` (respaldos y `.sha256`, sin `.partial`) a `OFFSITE_TARGET`:

- `OFFSITE_TOOL=rsync` (por defecto): `OFFSITE_TARGET=<usuario>@<servidor-respaldo>:/srv/backups/chat-manager`, con una
  clave SSH de root autorizada en el destino.
- `OFFSITE_TOOL=rclone`: `OFFSITE_TARGET=<remoto>:<bucket>/chat-manager`, con el remoto configurado en el
  `rclone.conf` de root (p. ej. un bucket S3 privado con cifrado del lado del servidor).

Ninguna credencial va en el script ni en cron. Activa la línea comentada de `deploy/cron/chat-manager` (03:30)
reemplazando el marcador. El script solo añade archivos: aplica la retención en el destino (regla de ciclo de vida del
bucket o tarea en el servidor de respaldo). Alternativa equivalente: `restic`.
Regla mínima: 3 copias, en 2 medios distintos, 1 fuera del sitio. Los respaldos contienen datos personales de
clientes: acceso restringido y cifrado en reposo.

## 5. Verificación periódica (mensual)

Un respaldo que nunca se restauró no está probado:

```bash
cd /var/backups/chat-manager/database
sha256sum -c chatmanager-<fecha>.sql.gz.sha256
sudo /usr/local/sbin/chat-manager-restore-database chatmanager-<fecha>.sql.gz chatmanager_restore
# Comprueba conteos básicos y elimina la base de prueba al terminar:
mariadb --defaults-extra-file=/etc/chat-manager/restore.cnf -e "SELECT COUNT(*) FROM chatmanager_restore.conversations; DROP DATABASE chatmanager_restore;"
```

`/etc/chat-manager/restore.cnf` usa un usuario con `CREATE, INSERT, ALTER, INDEX, DROP` sobre `chatmanager_restore`
(y sobre `chatmanager` solo durante una restauración real).

## 6. Restauración

### 6.1 Base de datos

1. **Anuncia la ventana** y detén la entrada de datos: `php artisan down` y
   `sudo supervisorctl stop chatmanager-workers:* chatmanager-scheduler`. Los webhooks de Meta recibirán 503 y Meta
   los reintentará durante horas (no se pierden si la ventana es corta).
2. **Respalda el estado actual** aunque esté dañado (`sudo /usr/local/sbin/chat-manager-backup-database`): permite
   volver atrás.
3. **Restaura en una base nueva** y verifícala: `sudo /usr/local/sbin/chat-manager-restore-database <respaldo> chatmanager_restore`.
4. **Reemplaza**: la opción más segura es apuntar `DB_DATABASE=chatmanager_restore` en `shared/.env` (sin copiar
   datos otra vez) y ejecutar `php artisan optimize`; o bien restaurar sobre `chatmanager` con
   `sudo ALLOW_NON_EMPTY=1 /usr/local/sbin/chat-manager-restore-database <respaldo> chatmanager` después de vaciarla
   manualmente.
5. **Migraciones**: si el respaldo es anterior a la versión de código activa, `php artisan migrate --force` aplica las
   migraciones aditivas faltantes. Si el código es anterior al respaldo, despliega la versión correspondiente.
6. **Reanuda**: `sudo supervisorctl start chatmanager-workers:* chatmanager-scheduler`, `php artisan up`,
   `php artisan app:preflight` y revisa Administración > Sistema.

Consecuencias a revisar tras restaurar un respaldo antiguo:

- Mensajes y webhooks recibidos después del respaldo no existen en la base. Meta reintenta los webhooks no confirmados
  por un tiempo limitado; lo anterior a esa ventana se pierde (el historial sigue en los teléfonos de los clientes).
- Mensajes salientes aceptados por Meta después del respaldo pueden reaparecer como pendientes o fallidos: **no** los
  reintentes en bloque; revisa su estado (los webhooks de estado recibidos después corrigen los que conocen).
- Las exportaciones listadas pueden apuntar a archivos ya borrados (aparecen como expiradas).

### 6.2 Archivos

Con el sitio en mantenimiento y los workers detenidos (pasos 1 y 6 de §6.1):

```bash
sudo /usr/local/sbin/chat-manager-restore-storage /var/backups/chat-manager/storage/storage-<fecha>.tar.gz
```

El script (`deploy/scripts/restore-storage.sh`):

1. Exige el `.sha256` junto al respaldo y lo verifica (sin él se detiene, salvo `ALLOW_NO_CHECKSUM=1`), y comprueba el
   archivo con `gzip -t`.
2. Extrae en un directorio temporal dentro de `shared/storage` (mismo disco), sin conservar propietarios del archivo.
3. Muestra cuántos archivos trae el respaldo y cuántos hay ahora, y pide escribir `restaurar`.
4. Conserva las exportaciones actuales (no forman parte del respaldo), ajusta propietario (`RESTORE_OWNER`,
   `www-data:www-data`) y permisos (grupo con escritura y setgid en directorios) y sustituye `storage/app` con un
   renombrado. El `storage/app` anterior queda como `app.before-restore-<fecha>`: bórralo cuando hayas verificado la
   restauración.

Variables: `STORAGE_DIR` (`/var/www/chat-manager/shared/storage`) y `RESTORE_OWNER`.

Los adjuntos se identifican por ruta aleatoria (`media/AAAA/MM/uuid`) guardada en `message_attachments`; restaura
archivos y base del mismo momento para que coincidan. Un adjunto sin archivo se muestra como no disponible, sin romper
la conversación.

### 6.3 Servidor completo

1. Nuevo VPS con las dependencias de infraestructura-y-despliegue.md §2 y la estructura §3.
2. Restaura `shared/.env` desde el gestor de secretos (misma `APP_KEY`).
3. Restaura base (§6.1) y archivos (§6.2).
4. `deploy.sh` con la etiqueta de la versión que estaba en producción.
5. Certificado TLS (certbot), DNS al nuevo servidor; las URLs de webhook no cambian si el dominio es el mismo.
6. `php artisan app:preflight`, prueba de `/health/ready`, verificación de credenciales de cada conexión
   (Conexiones > Verificar) y un mensaje de prueba de ida y vuelta.

## 7. Objetivos de recuperación

Con respaldos diarios, la pérdida máxima de datos (RPO) es de hasta 24 horas, menos lo que Meta reintente; el tiempo
de recuperación (RTO) depende del tamaño de la base y debe medirse en la verificación mensual (§5). Para un RPO menor
se requiere replicación de MariaDB o respaldos binarios incrementales (`mariabackup` + binlogs), no incluidos aquí.

## 8. Lo que no se ha probado

Los scripts se validaron por sintaxis (`bash -n`, en la prueba `tests/Feature/Operations/DeployTemplatesTest.php`;
`shellcheck` no estaba disponible) en este repositorio; su ejecución real (permisos, rutas de la distribución,
`mariadb-dump` de la versión instalada, `rsync`/`rclone` hacia el destino externo y `restore-storage.sh` completo)
debe comprobarse en el servidor durante la primera verificación mensual. Mientras no haya copia externa activa, la
pérdida del servidor implica perder los respaldos.

## 9. Respaldos locales de la estabilización (2026-10-22)

Antes de tocar la base local `chatmanager` en la fase de estabilización, el coordinador guardó dos volcados en
`storage/app/backups/` (fuera de Git; contienen datos de la base local, trátalos como privados):

| Archivo                                                      | Momento                                       | SHA-256                                                            |
| ------------------------------------------------------------ | --------------------------------------------- | ------------------------------------------------------------------ |
| `chatmanager-pre-estabilizacion-20261022.sql.gz`             | Al empezar la fase, antes de cualquier cambio | `1a5cb3a01d63583d46768bf38c8357a2acdc21ee6e9c86b11eb7234fea97043d` |
| `chatmanager-pre-migraciones-estabilizacion-20261022.sql.gz` | Justo antes de aplicar las 6 migraciones      | `42ca73f4697fe31f02dbcea7b0b180fd840dcb57820d3f29883459336d1d11b5` |

Después se aplicaron 6 migraciones **aditivas** (`team_message_reactions`; capacidad en `users` y `departments`;
índices de búsqueda y FULLTEXT; tiempo hábil en `conversation_metrics`; índice `messages.updated_at`;
`push_subscriptions`). Los conteos de filas de las tablas principales fueron idénticos antes y después (contactos 5,
conversaciones 3, mensajes 6, usuarios 4, roles 5, departamentos 5, mensajes internos 0, auditoría 64) y `CHECK TABLE`
respondió OK. Luego se ejecutaron `app:sync-permissions`, `app:sync-permissions --upgrade-default-roles --demo-b --apply`
(ver [seguridad-y-auditoria.md §3.1](seguridad-y-auditoria.md#31-permisos-por-rol-tras-la-actualización-de-la-estabilización))
y `analytics:rebuild` (3 métricas).

Para volver al estado anterior en local (solo si hiciera falta; borra lo hecho después):

```bash
shasum -a 256 storage/app/backups/chatmanager-pre-migraciones-estabilizacion-20261022.sql.gz   # debe coincidir con la tabla
gzip -t storage/app/backups/chatmanager-pre-migraciones-estabilizacion-20261022.sql.gz
# Restaura en una base de prueba primero (§5) y compara conteos; solo después sobre chatmanager, con la app detenida:
gunzip -c storage/app/backups/chatmanager-pre-migraciones-estabilizacion-20261022.sql.gz | mariadb chatmanager
```

Tras restaurar ese volcado el código actual espera las 6 migraciones: vuelve a ejecutar `php artisan migrate`,
`php artisan app:sync-permissions` y `php artisan analytics:rebuild`.
