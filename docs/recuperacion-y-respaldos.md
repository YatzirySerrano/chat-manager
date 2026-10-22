# Recuperación y respaldos

Qué respaldar, cómo hacerlo con los scripts de `deploy/scripts/`, cómo verificar que un respaldo sirve y cómo
restaurar. La infraestructura general está en [infraestructura-y-despliegue.md](infraestructura-y-despliegue.md).

## 1. Qué se respalda

| Elemento                    | Dónde vive                                                                                        | Cómo                                                       | Frecuencia sugerida                                           |
| --------------------------- | ------------------------------------------------------------------------------------------------- | ---------------------------------------------------------- | ------------------------------------------------------------- |
| Base de datos `chatmanager` | MariaDB                                                                                           | `backup-database.sh` (`mariadb-dump --single-transaction`) | Diario + antes de cada despliegue (automático en `deploy.sh`) |
| Archivos privados           | `shared/storage/app` (adjuntos de WhatsApp en `media/`, archivos del chat interno, importaciones) | `backup-storage.sh` (tar.gz)                               | Diario                                                        |
| `.env` y `APP_KEY`          | `shared/.env`                                                                                     | Gestor de secretos de la empresa (manual)                  | En cada cambio                                                |
| Configuración del servidor  | `/etc/nginx`, `/etc/supervisor/conf.d`, `/etc/php/8.4/fpm/pool.d`, certificados                   | Plantillas en `deploy/` + copia de `/etc`                  | En cada cambio                                                |

**`APP_KEY` es crítica**: las credenciales de las conexiones de WhatsApp (token de acceso, secreto de la app, verify
token) se guardan cifradas con ella. Una base restaurada sin la misma `APP_KEY` conserva los datos pero obliga a volver
a capturar las credenciales de cada conexión, y todas las sesiones se cierran.

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

Ejecución: `sudo /var/www/chat-manager/current/deploy/scripts/backup-database.sh`
(variables: `DB_NAME`, `BACKUP_DIR=/var/backups/chat-manager/database`, `RETENTION_DAYS=14`, `DEFAULTS_FILE`).

- `--single-transaction --quick`: copia consistente de las tablas InnoDB sin bloquear la operación.
- Incluye rutinas, triggers y eventos; `--hex-blob` y `utf8mb4` evitan alterar binarios y emojis.
- Escribe `chatmanager-AAAAMMDDTHHMMSSZ.sql.gz` (permisos 600), lo verifica con `gzip -t`, genera `.sha256` y borra
  los respaldos de más de `RETENTION_DAYS` días.
- Programación: `deploy/cron/chat-manager` (02:15 diario, como root).

## 3. Respaldo de archivos

`sudo /var/www/chat-manager/current/deploy/scripts/backup-storage.sh` genera `storage-AAAAMMDDTHHMMSSZ.tar.gz` con
`storage/app` (sin `app/private/exports`, `framework` ni `logs`), checksum y rotación (`RETENTION_DAYS`).

## 4. Copia fuera del servidor

Un respaldo en el mismo disco no protege contra la pérdida del servidor. Copia `/var/backups/chat-manager` a un
almacenamiento externo cifrado (p. ej. `rclone` a un bucket S3 privado con cifrado del lado del servidor, o `restic`).
Regla mínima: 3 copias, en 2 medios distintos, 1 fuera del sitio. Los respaldos contienen datos personales de
clientes: acceso restringido y cifrado en reposo.

## 5. Verificación periódica (mensual)

Un respaldo que nunca se restauró no está probado:

```bash
cd /var/backups/chat-manager/database
sha256sum -c chatmanager-<fecha>.sql.gz.sha256
sudo /var/www/chat-manager/current/deploy/scripts/restore-database.sh chatmanager-<fecha>.sql.gz chatmanager_restore
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
2. **Respalda el estado actual** aunque esté dañado (`backup-database.sh`): permite volver atrás.
3. **Restaura en una base nueva** y verifícala: `restore-database.sh <respaldo> chatmanager_restore`.
4. **Reemplaza**: la opción más segura es apuntar `DB_DATABASE=chatmanager_restore` en `shared/.env` (sin copiar
   datos otra vez) y ejecutar `php artisan optimize`; o bien restaurar sobre `chatmanager` con
   `ALLOW_NON_EMPTY=1 restore-database.sh <respaldo> chatmanager` después de vaciarla manualmente.
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

```bash
cd /var/backups/chat-manager/storage && sha256sum -c storage-<fecha>.tar.gz.sha256
sudo tar --extract --gzip --file storage-<fecha>.tar.gz --directory /var/www/chat-manager/shared/storage
sudo chown -R www-data:www-data /var/www/chat-manager/shared/storage/app
```

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

Los scripts se validaron por sintaxis (`bash -n`) en este repositorio; su ejecución real (permisos, rutas de la
distribución, `mariadb-dump` de la versión instalada) debe comprobarse en el servidor durante la primera verificación
mensual.
