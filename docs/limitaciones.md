# Limitaciones y dependencias externas

Inventario consolidado de lo que está probado, lo que está simulado, lo que depende de una validación externa y lo
que no está implementado. Cada módulo conserva en su documento el detalle técnico; aquí se reúne para decidir qué
falta antes de producción.

Convenciones:

- **Implementado y probado:** existe en el código y lo cubren pruebas automatizadas sobre MariaDB o escenarios de
  navegador en este repositorio.
- **Simulado:** se probó con dobles explícitamente etiquetados (webhooks generados, `Http::fake`, transporte
  `simulated`); el comportamiento real del proveedor no se observó.
- **Pendiente de validación externa:** necesita Meta real, una exportación real de Whaticket o un servidor real.
- **No implementado / trabajo futuro:** decisión consciente de no hacerlo en esta versión.

## 1. Dependencias externas

| Dependencia                                  | Para qué                                                       | Sin ella                                                                                     |
| -------------------------------------------- | -------------------------------------------------------------- | -------------------------------------------------------------------------------------------- |
| Meta: WhatsApp Cloud API (Graph)             | Recibir y enviar mensajes, multimedia, plantillas              | No hay mensajería real; `WHATSAPP_TRANSPORT=unavailable` falla cada envío de forma explícita |
| App de Meta, WABA, número y token permanente | Credenciales de cada conexión                                  | La conexión no se verifica ni recibe webhooks                                                |
| Dominio con HTTPS válido                     | Webhooks de Meta, cookies seguras, WSS                         | Meta no acepta `localhost` ni certificados autofirmados                                      |
| MariaDB 11.4                                 | Base única y colas/caché/sesiones por defecto                  | —                                                                                            |
| Reverb (proceso supervisado)                 | Tiempo real                                                    | La interfaz sigue funcionando con sondeo cada 20 s                                           |
| Workers de colas y programador               | Webhooks, envíos, multimedia, señales, métricas, exportaciones | Los mensajes se acumulan; la salud del sistema lo marca                                      |
| Node, npm, Chromium y puppeteer              | PDF de exportaciones                                           | Solo fallan los PDF; Excel sigue funcionando                                                 |
| SMTP                                         | Restablecer contraseña y alertas                               | No llegan correos                                                                            |
| Monitor externo de `/health/ready`           | Detectar una caída total                                       | La aplicación no puede avisar de su propia caída                                             |
| Redis (opcional)                             | Escalar colas, caché y varias instancias de Reverb             | Todo corre en `database`                                                                     |

## 2. Implementado y probado

Resumen; los conteos y comandos están en [pruebas.md](pruebas.md).

- Bandeja de tres paneles, visibilidad por alcance, cola compartida, asignación manual y automática, transferencias,
  espera, cierre y reapertura con matriz de estados y versión; un solo ganador en aceptaciones disputadas (procesos
  reales y hasta 30 agentes en la batería de carga).
- Webhooks: firma, verificación, tamaño, tasa, idempotencia por cuerpo y por `wamid`, procesamiento en cola con
  recuperación; estados fuera de orden y repetidos; entrega con reclamo bajo bloqueo; reintento manual.
- Tiempo real con Reverb real: señales a dos y tres sesiones en Chromium, revocación de acceso, caída de Reverb,
  sondeo y reconexión sin duplicados (`realtime.mjs`, 19/19).
- Chat interno, notificaciones, horarios y mensajes automáticos, reportes y exportaciones, importación con
  trazabilidad y reversión, salud del sistema y preflight.
- Seguridad: barrido de las ~140 rutas (401/403/404 para quien no tiene acceso), autorizar antes de validar, secretos
  fuera de props, eventos de cuenta auditados, límites por ruta.
- Integridad bajo carga en esta máquina: 0 mensajes perdidos o duplicados, 0 conversaciones o contactos duplicados en
  ráfagas de 3 000 webhooks ([rendimiento-y-carga.md](rendimiento-y-carga.md)).

## 3. Simulado

- **Todo el tráfico de WhatsApp.** Mensajes y estados entrantes son cuerpos generados con el formato documentado por
  Meta y firmados con secretos desechables; los envíos usan `Http::fake`, el transporte `simulated` (IDs `sim.`,
  `metadata.simulated = true`, etiqueta «Simulación») o un transporte de registro en las pruebas de concurrencia. Con el
  transporte simulado, «Enviado», «Entregado» y «Leído» también son simulados.
- **Multimedia simulada** sin `--file`: queda «no disponible» (`simulated_media`).
- **Agentes de la batería de carga:** clientes HTTP sin navegador; los WebSockets de carga son clientes Node, no
  navegadores.
- **Concurrencia:** las peticiones web de las pruebas con procesos se sirven por el kernel HTTP con el usuario fijado,
  no por un servidor web; los workers de larga vida se representan con `queue:work` acotado.
- **Escenarios E2E** sin worker usan `QUEUE_CONNECTION=sync`; los assets vienen del servidor de Vite, no del build de
  producción.

## 4. Pendiente de validación externa

### 4.1 Meta real

Nunca se ha intercambiado un mensaje auténtico con Meta ([mensajeria-whatsapp.md §10](mensajeria-whatsapp.md#10-lo-que-no-se-ha-validado-contra-meta-real)):

- firma real de `X-Hub-Signature-256` y verificación GET con la app real;
- descarga de multimedia desde `lookaside.fbsbx.com` y carga multipart a `/{phone-number-id}/media`;
- formato exacto de `subscribed_apps`, sincronización y webhooks de plantillas, aprobación real de plantillas;
- límites reales (429/130429), latencia y reintentos de Meta, y los textos de error de cURL del servidor de
  producción que distinguen «no llegó a Meta» de «ambiguo»;
- estados reales de entrega y lectura; los IDs de multimedia subida caducan a los 30 días (un reintento muy tardío
  falla con error de Meta);
- el procedimiento de activación paso a paso ([§9](mensajeria-whatsapp.md#9-activación-real-paso-a-paso)) está
  escrito pero no ejecutado.

### 4.2 Whaticket real

- El adaptador `whaticket-contacts` es heurístico y está marcado **pendiente de validación** hasta revisar una
  exportación auténtica ([migracion-whaticket.md](migracion-whaticket.md#validar-el-adaptador-con-una-exportación-real)).
- La importación de conversaciones y mensajes históricos requiere muestras reales anonimizadas, archivos multimedia y
  los mapeos de usuarios, colas y conexiones que defina la empresa.

### 4.3 Servidor real

- Nginx, PHP-FPM, Supervisor, systemd, logrotate, cron y los scripts `deploy.sh`, `rollback.sh`, `backup-*.sh` y
  `restore-database.sh` solo se validaron por sintaxis y por `QueueTopologyTest`; nunca se ejecutaron en un VPS.
- WSS a través de Nginx hacia Reverb, CSP en modo producción, cookies `Secure` sobre https real, HSTS y proxies de
  confianza.
- Redis para colas, caché y escalado de Reverb (el arreglo de permisos en workers se probó solo con caché en base de
  datos); varias instancias web o de Reverb.
- MariaDB con _buffer pool_ dimensionado e historiales de cientos de miles de conversaciones; los planes de consulta
  se verificaron con unas 4 000 y 10 000 conversaciones.
- Capacidad: ninguna cifra de la batería de carga es una promesa de producción; se midió hasta 100 agentes simulados
  y 200 WebSockets en un portátil compartido.
- Restauración de respaldos y objetivos RPO/RTO: deben medirse en la primera verificación mensual.
- Build de producción (`npm run build`) revisado con la interfaz final; lectores de pantalla y anchos de tableta.

## 5. No implementado / trabajo futuro

| Área           | Pendiente                                                                                                                                                                                                                                                                    |
| -------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Mensajería     | Crear o editar plantillas desde la app (se hace en WhatsApp Manager); cálculo de costos; comparar el `sha256` de Meta en descargas; plantillas OTP, Flow, catálogo, carrusel y ofertas por tiempo limitado; encabezados multimedia de plantilla sin URL pública              |
| Bandeja        | Búsqueda de texto completo (hoy `LIKE` con comodín inicial); más de 250 filas cargadas en la lista; más de 300 notas y 300 eventos por página del historial; límite de carga por departamento (solo hay límite global)                                                       |
| Asignación     | Un agente de alcance «asignado» no puede transferir a un compañero concreto (regla de visibilidad); reglas de enrutamiento por contenido, menús o bots                                                                                                                       |
| Chat interno   | Presencia en línea (el canal `presence-team` está autorizado en el servidor, pero el cliente no se une), «escribiendo…», reacciones, menciones con `@` en el compositor, exportación (decisión de privacidad) y retención automática; vista previa en línea de audio y video |
| Notificaciones | Web Push / _service worker_ (sin pestaña abierta no hay avisos); alertas operativas en el centro de notificaciones (hoy log y correo)                                                                                                                                        |
| Reportes       | Tiempo en horario laboral y SLA; historial de quién tuvo cada conversación; atribución de mensajes por su propia fecha; desfase horario fijo por periodo en zonas con horario de verano; tabla de acumulados diarios                                                         |
| Operación      | Panel de métricas históricas (Horizon/Pulse no están instalados); replicación o respaldos incrementales para RPO menor a 24 h                                                                                                                                                |
| Migración      | Importador de tickets y mensajes de Whaticket; revertir desde un lote anterior un contacto que modificó un lote posterior; eliminar notas agregadas a contactos existentes                                                                                                   |
| Seguridad      | Cifrado del historial de Inertia (`INERTIA_ENCRYPT_HISTORY` + `clearHistory` al salir); límite por IP en «¿Olvidaste tu contraseña?»; cerrar otras sesiones al cambiar la contraseña; exportaciones por GET con efectos                                                      |

## 6. Riesgos residuales y comportamientos observados

Detalle y mitigaciones en [seguridad-y-auditoria.md §7](seguridad-y-auditoria.md#7-riesgos-residuales-y-recomendaciones)
y [rendimiento-y-carga.md §8](rendimiento-y-carga.md#8-identificado-y-no-corregido-con-motivo).

- **Cola `notifications` en ráfagas:** ~5 trabajos por webhook; con 2 workers las señales llegan con 15–35 s de retraso
  (p95/p99) durante ráfagas grandes, y el grupo `notifications,default` retrasa las métricas. Se recomienda workers
  dedicados para `notifications` y `default` y escalar por medición.
- **_Deadlocks_ residuales** en `InAppChannel` (2–6 por ráfaga): la cola los reintenta sin pérdidas.
- **Conteos por estado de la bandeja** recorren toda la tabla `conversations`; crecerán con el historial.
- **Lista de la superadministración:** ~120 ms de PHP para 25 filas.
- **Limitador del webhook** (1 200/min por conexión) responde 429 en ráfagas; Meta reintenta, pero retrasa la atención.
- **Reconexión de Reverb:** pusher-js tarda ~25 s en reconectar tras un reinicio; mientras tanto las páginas sondean
  cada 20 s sin perder ni duplicar nada.
- **Señales del chat interno y de notificaciones durante una caída de Reverb:** son difusiones en cola; fallan como
  trabajos (log de nivel ERROR con la URL de publicación y la clave pública, nunca el secreto) y pueden disparar la
  alerta de fallidos. Las señales de bandejas se publican en modo _best effort_ con un `warning`.
- **Revocación en tiempo real:** el protocolo no permite expulsar a un suscriptor; entre el cambio y la
  re-autorización (normalmente < 1 s) solo pueden llegar IDs, nunca datos. Las señales de bandejas de equipo pueden
  anunciar IDs de conversaciones cerradas a quien no tiene permiso de historial.
- **Conexión pausada:** el compositor permite escribir; el job bloquea el envío y el chat muestra «Envío bloqueado».
- **Exportación consistente:** `ReportExporter::render()` mantiene una transacción de lectura abierta durante toda la
  exportación (hasta el tiempo límite del job); no bloquea escrituras, pero retrasa la purga del historial de deshacer
  de MariaDB.
- **Check-then-send:** un mensaje cuyo envío ya se decidió puede salir aunque la conexión se elimine durante la llamada
  de red.
- **PDF en vista previa sin `sandbox`**, descarga de multimedia sin tope en _streaming_ cuando Meta no informa el
  tamaño, diagnóstico de conexiones visible para `connections.view` (último error saneado).
- **Inertia DevTools** con `APP_ENV=local` expone las props registradas sin autenticación; nunca usar `local` con
  datos reales.
- **Prueba de preparación:** su limitador usa la caché; con la caché caída responde 5xx en lugar del JSON `fail` (los
  monitores lo ven igualmente como caído).
- **Pruebas de navegador:** fallas aisladas atribuidas a recargas del servidor de Vite compartido durante corridas
  simultáneas (no reproducidas); un retraso de ~3,5 s entre el clic en «Enviar» y la petición observado en una
  corrida de `realtime.mjs`, sin causa confirmada.

## 7. Antes de producción

1. En el servidor, aplicar las migraciones (aditivas) con respaldo previo y ejecutar
   `php artisan app:sync-permissions` y `php artisan analytics:rebuild` una vez. En la base local `chatmanager`, al
   escribir este documento, `migrate:status` no mostraba migraciones pendientes.
2. Ejecutar la suite completa y `npm run build`.
3. Seguir la activación real de Meta ([mensajeria-whatsapp.md §9](mensajeria-whatsapp.md#9-activación-real-paso-a-paso))
   con un número de prueba y una prueba de ida y vuelta.
4. Validar el adaptador de Whaticket con una exportación real antes de importar.
5. Desplegar en el servidor con [infraestructura-y-despliegue.md](infraestructura-y-despliegue.md), pasar
   `php artisan app:preflight` sin fallas y restaurar un respaldo de prueba
   ([recuperacion-y-respaldos.md](recuperacion-y-respaldos.md)).
