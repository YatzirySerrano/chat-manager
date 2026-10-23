# Pruebas

Cómo se prueba Chat Manager: pruebas automatizadas con Pest sobre MariaDB, análisis estático y formato, escenarios de
navegador (E2E), pruebas de concurrencia con procesos reales y la batería de carga. Al final se separa qué es real y
qué es simulado.

> **Nunca se contacta a Meta.** Toda prueba de WhatsApp usa webhooks firmados con secretos desechables, respuestas
> HTTP falsas (`Http::fake`) o el transporte simulado (`WHATSAPP_TRANSPORT=simulated`).

## 1. Base de datos de pruebas

- Las pruebas usan **solo** `chatmanager_testing` en MariaDB (nunca SQLite, nunca la base de la aplicación
  `chatmanager`). `phpunit.xml` fija `DB_DATABASE=chatmanager_testing`, `QUEUE_CONNECTION=sync`,
  `BROADCAST_CONNECTION=null`, `CACHE_STORE=array` y `MAIL_MAILER=array`.
- Credenciales en `.env.testing` (plantilla `.env.testing.example`): un usuario de MariaDB con acceso solo a la base de
  pruebas. Ningún proceso de pruebas crea bases de datos.
- **Candado compartido** (`Tests\Support\TestDatabase`): Pest, los escenarios E2E y la batería de carga toman el mismo
  candado exclusivo de MariaDB (`GET_LOCK`) antes de reconstruir la base, así que nunca corren a la vez.
  `TEST_DB_LOCK_WAIT=<segundos>` indica cuánto esperar el turno (por defecto 0: falla de inmediato si está ocupada).
- `tests/Feature/Database/DatabaseSafetyTest.php` comprueba que nada de esto apunte a la base de la aplicación.

## 2. Pest

```bash
# Siempre acotado a lo que cambió; nunca --parallel (la base es una sola y está bajo candado)
TEST_DB_LOCK_WAIT=1800 php artisan test --compact tests/Feature/Conversations
TEST_DB_LOCK_WAIT=1800 php artisan test --compact tests/Feature/Webhooks/WhatsAppWebhookTest.php --filter=signature
# Suite completa (larga; la ejecuta quien integra)
TEST_DB_LOCK_WAIT=1800 php artisan test --compact
```

`--parallel` está prohibido: varios procesos reconstruirían la misma base.

**Última suite completa (cierre de la estabilización, 2026-10-22): 1 661 pruebas, 1 661 aprobadas, 10 562 aserciones**
(unos 18 minutos con la base bajo candado). En el mismo cierre: Pint sin cambios pendientes, PHPStan nivel 7 sin
errores, `vue-tsc` sin errores y build de producción correcto.

Organización de `tests/Feature`:

| Directorio                                                     | Cubre                                                                                                   | Documento                                                               |
| -------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------- |
| `Conversations`, `Tags`, `QuickReplies`, `Contacts`            | Bandeja, motor de estados, visibilidad, adjuntos, lecturas, etiquetas, directorio                       | [bandejas-y-asignacion.md](bandejas-y-asignacion.md)                    |
| `Webhooks`, `Messaging`, `Templates`, `Connections`            | Firma, idempotencia, tipos de mensaje, estados, errores de Graph, reintentos, plantillas, diagnóstico   | [mensajeria-whatsapp.md §11](mensajeria-whatsapp.md#11-pruebas)         |
| `Realtime`                                                     | Autorización de canales, destinatarios de señales, configuración del cliente                            | [tiempo-real.md](tiempo-real.md#pruebas)                                |
| `TeamChat`                                                     | Chat interno: acceso, mensajes, grupos, adjuntos, menciones, reacciones, «escribiendo…», presencia      | [chat-interno.md](chat-interno.md)                                      |
| `Notifications`, `Automation`                                  | Notificaciones, Web Push, horarios, mensajes automáticos                                                | [notificaciones-y-horarios.md](notificaciones-y-horarios.md)            |
| `Analytics`, `Reports`, `Exports`, `DashboardTest.php`         | Métricas, reportes, panel, exportaciones                                                                | [reportes-y-exportaciones.md §7](reportes-y-exportaciones.md#7-pruebas) |
| `Imports`, `Migration`                                         | Importación de contactos, adaptador Whaticket, reversión                                                | [migracion-whaticket.md](migracion-whaticket.md)                        |
| `Admin`, `Auth`, `Settings`, `Audit`, `Validation`, `Security` | Usuarios, roles, escalamiento de privilegios, autenticación, bitácora, barrido de rutas, endurecimiento | [seguridad-y-auditoria.md](seguridad-y-auditoria.md)                    |
| `Operations`, `Console`, `Local`                               | Salud, alertas, preflight, plantillas de despliegue, comandos, simulador local                          | [infraestructura-y-despliegue.md](infraestructura-y-despliegue.md)      |
| `Database`                                                     | Integridad del esquema, migraciones de eliminación lógica, concurrencia con dos conexiones              | §4                                                                      |
| `Concurrency`                                                  | Carreras con procesos PHP reales                                                                        | §4                                                                      |
| `Integration`                                                  | Flujos de punta a punta entre módulos, topología de colas, presupuestos de consultas                    | §5                                                                      |
| `Performance`                                                  | N+1 de la bandeja y bloqueos del departamento                                                           | [rendimiento-y-carga.md](rendimiento-y-carga.md)                        |

- **Grupo `chrome`:** las pruebas que imprimen un PDF real con Chromium se omiten si `EXPORTS_NODE_BINARY` y
  `EXPORTS_CHROME_PATH` no apuntan a ejecutables (ver `.env.testing.example`).
- **Barrido de rutas:** `tests/Feature/Security/RouteAuthorizationSweepTest.php` recorre todas las rutas de la
  aplicación con un invitado, un usuario sin permisos y un agente de otro departamento; una ruta nueva queda cubierta
  automáticamente (las de Web Push se declararon en la integración final: parámetro `subscription` y la suscripción
  como ruta de autoservicio). Es lenta: conviene correrla aparte.
- **FULLTEXT en pruebas:** InnoDB no ve filas sin confirmar de la transacción de `RefreshDatabase`. Toda prueba que
  busque por nombre (3 letras o más) o por contenido de mensajes llama a `commitForFullTextSearch()` (`tests/Pest.php`),
  que confirma los datos y obliga a reconstruir la base antes de la prueba siguiente (suma tiempo a la suite).

### 2.1 Pruebas añadidas en la estabilización

Archivos nuevos (además de casos nuevos en archivos existentes):

| Área                | Archivos nuevos                                                                                                | Casos nuevos en archivos existentes                                                                                                                                                                                                                                                                                        |
| ------------------- | -------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Mensajería          | `Realtime/ConnectionChangeSignalTest.php`                                                                      | `CustomerServiceWindowTest` (409 por conexión, idempotencia, props del compositor, `sender_not_allowed`), `MessageRetryTest`, `InboundMediaTest`, `OutboundDeliveryTest`, `ConnectionDeletionTest`, `ConnectionDiagnosticsTest`, `ConversationTemplateTest`, `TemplateSyncTest`                                            |
| Asignación          | `Conversations/TransferDestinationsTest.php`, `Conversations/AgentCapacityTest.php`                            | `AgentAvailabilityTest`, `ConversationAuthorizationTest`, `ConversationLifecycleTest` (límite con confirmación), `Database/ConcurrencyTest` (orden de bloqueos, 4 casos)                                                                                                                                                   |
| Búsqueda            | `Conversations/InboxPaginationTest.php`, `Conversations/InboxSearchTest.php`, `Contacts/ContactSearchTest.php` | `InboxTimelineTest` (`until` compuesto, mismo segundo, `until` mal formado), `InboxReadMarkerTest` (reescrita por cursor), `ModuleReportsTest` (descripción de la búsqueda en mensajes)                                                                                                                                    |
| Tiempo real y colas | `Integration/QueueRoutingTest.php`                                                                             | `QueueTopologyTest` (reescrita desde `Queues::all()`), `SignalRoutingTest`, `NotificationDeliveryTest` (Reverb caído o lento: un intento por trabajo), `SystemPageTest`                                                                                                                                                    |
| Concurrencia        | —                                                                                                              | `Concurrency/ConcurrentProcessesTest` (READ COMMITTED real, reintento registrado, 10 clientes nuevos, cliente repetido, agregado de notificaciones, notificar mientras se marca leído)                                                                                                                                     |
| Chat interno        | `TeamChat/TeamMentionsTest.php`, `TeamChat/TeamReactionsTest.php`, `TeamChat/TeamTypingTest.php`               | `TeamMessagesTest` (publicar tras expulsión o archivado, búsqueda con `has_more`, límites), `TeamChatAccessTest` (canales de presencia)                                                                                                                                                                                    |
| Notificaciones      | `Notifications/WebPushTest.php`                                                                                | —                                                                                                                                                                                                                                                                                                                          |
| Reportes            | `Analytics/MetricsConsistencyTest.php`, `Analytics/BusinessTimeTest.php`                                       | `OperationalReportsTest` (SLA, horario de verano, orden de la exportación, totales del PDF, periodo relativo en cola), `ReportExportTest`, `Admin/PlatformSettingsTest`                                                                                                                                                    |
| Seguridad           | —                                                                                                              | `UserManagementTest`, `Settings/SecurityTest`, `Auth/PasswordResetTest`, `Auth/AuthenticationTest`, `Security/RouteThrottleTest`, `ReportExportTest` (POST, CSRF, permiso al descargar), `UserEmailAvailabilityTest`, `Validation/SecurityProbeTest`, pruebas de departamentos y respuestas rápidas (404 fuera de alcance) |
| Roles y simulador   | `Local/ManualValidationSimulatorTest.php`                                                                      | `Console/SyncPermissionsCommandTest` (`--upgrade-default-roles`), `ConversationTagTest` (el Agente etiqueta lo suyo)                                                                                                                                                                                                       |
| Infraestructura     | `Operations/DeployTemplatesTest.php`                                                                           | `Operations/PreflightCommandTest` (Redis y escalado de Reverb, colas, demo, Meta sin credenciales verificadas, disco, respaldos, Web Push)                                                                                                                                                                                 |

Pruebas existentes cuyo comportamiento esperado cambió de forma legítima (y se actualizaron, sin borrarlas): el
límite de carga en `ConversationLifecycleTest`, la carga incremental en `InboxReadMarkerTest`, el teléfono parcial en
`ContactManagementTest`, la presencia en `TeamChatAccessTest`, la opción de transferencia en
`ConversationAuthorizationTest`, `ConversationChanged('message')` en `InboundMessageTypesTest`, el número de colas en
`SystemPageTest`, la señal de notificación en `NotificationDeliveryTest`, los 403 → 404 fuera de alcance y las
exportaciones de `get()` a `post()`.

## 3. Análisis estático, formato y frontend

```bash
vendor/bin/pint --format agent <archivos>          # formato PHP (o --dirty sobre los cambios propios)
vendor/bin/phpstan analyse --memory-limit=2G        # Larastan nivel 7 sobre app/, bootstrap/app.php, config/, database/, routes/
export PATH=/usr/local/bin:$PATH                    # en esta máquina, Node 24 vive en /usr/local/bin
php artisan wayfinder:generate --with-form          # siempre --with-form: el proyecto usa variantes de formulario
npx vue-tsc --noEmit                                # tipos de Vue/TypeScript (npm run types:check)
npx vp check --fix <archivos o carpetas>            # formato y lint (npm run check / check:fix), incluye docs/*.md
npm run build                                       # build de producción (vp build)
```

`composer run ci:check` encadena `npm run check`, `npm run types:check`, Pint en modo prueba, PHPStan y la suite.
`tests/` no está en las rutas de PHPStan; los archivos de apoyo (`tests/load`, `tests/Support/Concurrency`) se
analizan explícitamente cuando cambian.

## 4. Pruebas de concurrencia

Dos niveles:

1. `tests/Feature/Database/ConcurrencyTest.php`: dos conexiones a la base que se turnan dentro de un proceso (bloqueos
   de fila, versión optimista, clave única de conversación abierta).
2. `tests/Feature/Concurrency/ConcurrentProcessesTest.php` (23 casos): **procesos PHP reales** lanzados con
   `proc_open` (`tests/Support/Concurrency/worker.php`), cada uno con la aplicación completa y su propia conexión, que
   arrancan a la vez con una señal de salida. Solo corren con `APP_ENV=testing` y la base de pruebas; un vigilante
   detiene a los que se cuelgan. Los envíos van a un transporte que registra en archivo (doble de prueba, no Meta).
   Cubren: mismo `wamid` en 4 workers (1 contacto, 1 conversación, 1 mensaje), mismo cuerpo de webhook en 3 procesos,
   estados en desorden, 4 agentes aceptando a la vez (1 ganador), transferencia con mensaje entrante simultáneo,
   pérdida de acceso durante una aceptación, conexión eliminada durante entregas, ventana vencida, mismo job de
   entrega en 4 workers, misma `client_reference` en 4 procesos, permiso revocado visto por la siguiente petición y
   por un `queue:work` de larga vida, ausencia de bloqueos globales, planes de consulta con índices sobre 4 040
   conversaciones y exportación consistente mientras llegan mensajes. Desde la estabilización también: que
   `ReadCommittedTransaction` corre de verdad en READ COMMITTED (lectura no repetible observada), reintento con pausa y
   registro hasta el límite, primeros mensajes de 10 clientes nuevos a la vez, varios mensajes del mismo cliente nuevo
   junto con otros, 8 workers notificando al mismo agente con la misma clave de agregación y notificar mientras se
   marca todo como leído.

Cada prueba reconstruye la base, por lo que el archivo es lento (la última corrida conjunta con los archivos
afectados: 172 pruebas en unos 20 minutos, incluida la espera del candado).

## 5. Pruebas de integración

`tests/Feature/Integration` recorre flujos completos con colas en línea y difusión `log`: webhook firmado →
distribución automática → bienvenida o fuera de horario → entrega → webhook de estado → notificaciones, señales y
métricas; transferencia, cierre con despedida, baja de usuario y cambio de rol; importación terminada notificada una
sola vez; topología de colas (cada cola tiene un worker en las plantillas de Supervisor y systemd); y presupuestos de
consultas de las props compartidas y de la lista de notificaciones. `QueueRoutingTest` comprueba a qué cola va cada
trabajo: un mensaje del cliente encola una sola señal de tiempo real y una notificación, nada más; un mensaje saliente
nunca encola la notificación de entrada; `REALTIME_QUEUE` mueve todas las señales, chat interno incluido.

## 6. Escenarios de navegador (E2E)

Chromium real con Playwright, mouse y teclado reales, contra `chatmanager_testing`. El lanzador
`tests/e2e/run.php` toma el candado, reconstruye la base, siembra, arranca su propio servidor (y Reverb si se pide) y
lo detiene al terminar. No se agregan dependencias: Playwright se carga desde `PLAYWRIGHT_MODULE`.

| Escenario            | Contenido                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       |
| -------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `corrections.mjs`    | Correcciones de los incrementos 01, A y B: confirmación de cierre de sesión, sin passkeys, ayudas, colores, validación en vivo, eliminación lógica y restauración, exportaciones Excel/PDF e interfaz según permisos                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            |
| `increment-b.mjs`    | Bandeja de tres paneles: aceptar, notas en el compositor, cola «Pendientes», filtros persistentes, historial operativo, panel de detalles                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       |
| `finalizacion.mjs`   | Recorrido completo: permisos por rol (403), aislamiento entre departamentos, webhook firmado (firma falsa 401), envío simulado con marcas, «/», adjunto, nota, transferir, cerrar, reabrir, reintento, plantilla con ventana cerrada, reportes, exportación, plantillas, sistema, chat interno, campana, modo oscuro y móvil 390×844                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            |
| `realtime.mjs`       | Tres sesiones simultáneas con Reverb y worker reales: señales antes que la interfaz, contadores, disponibilidad, aceptación disputada, ticks, notas, transferencia, chat interno con «Visto», revocación de acceso, caída de Reverb, sondeo y reconexión sin duplicados                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| `estabilizacion.mjs` | **Nuevo.** Cinco contextos simultáneos (Superadministrador, Administrador, Ana y Beto de Ventas, Carla de Soporte) con Reverb y worker reales: webhook firmado → fila en vivo para Ventas y nunca para Soporte (0 filas y 404); Ana acepta y la fila sale en vivo de la cola de Beto; respuesta simulada con estado de entrega; nota interna vista en vivo por el Administrador; transferencia a Soporte eligiendo a «E2E Carla · Disponible» en el diálogo de destinatarios; Carla la recibe en vivo y Ana pierde la fila (chat y línea de tiempo 404); un mensaje nuevo llega solo a Carla; Carla cierra y un mensaje nuevo la reabre; el Administrador desactiva la conexión y el compositor de Carla se bloquea en vivo (la nota interna sigue permitida) y se desbloquea al reactivarla; búsqueda por contenido de mensaje (Superadministrador 1 resultado, búsqueda por contacto 0, Ana sin acceso 0); historial (Aceptada, Transferida con motivo, Cerrada, Reabierta, nota) y auditoría «Conexión desactivada/activada». Cierra con `live-before-polling` (cada trama WebSocket llega antes que el cambio de la interfaz y dentro de 8 s), sin errores de página ni respuestas 5xx. Semilla `tests/e2e/estabilizacion-seed.php` (`prepare` / `message`) |

**Utilidades compartidas** (`tests/e2e/lib.mjs`): carga de Playwright, constantes (`BASE`, `USERS`, presupuestos de
latencia), `record`/`step`/`exitWithSummary`, `php`/`inbound`, `openPage` (colector de errores, seguimiento de avisos y
neutralización del socket HMR de Vite en todos los escenarios), `login`, `shot`, `toastText`, localizadores de la
bandeja, `trackWebSocket`/`measure`, `startWorker`/`startReverb`/`stopReverb`, y desde la estabilización
`openRealtimeSession`, `openInboxView` y `signedWebhook` (POST firmado con `X-Hub-Signature-256`). Cada escenario
conserva en un envoltorio lo que le es propio (filtro de consola, viewport, prefijo de capturas).

**Comprobación previa del lanzador:** antes de tomar el candado, `run.php` verifica que `<NODE_BINARY> --version`
funciona, que `require(PLAYWRIGHT_MODULE)` carga y que existe el Chromium de ese Playwright; si algo falla termina con
código 1 y explica cómo resolverlo. En esta máquina el Node de Homebrew (`/opt/homebrew/bin/node`) está roto (le falta
`libsimdjson`): usa `/usr/local/bin/node`. Playwright 1.63.0 vive en la caché de npx (volátil; si desaparece:
`npx playwright@1.63.0 install chromium`).

```bash
export PATH=/usr/local/bin:$PATH
export NODE_BINARY=/usr/local/bin/node
export PLAYWRIGHT_MODULE=$HOME/.npm/_npx/e41f203b7505f1fb/node_modules/playwright

E2E_PORT=8030 TEST_DB_LOCK_WAIT=3600 php tests/e2e/run.php corrections.mjs
E2E_PORT=8030 TEST_DB_LOCK_WAIT=3600 php tests/e2e/run.php increment-b.mjs
WHATSAPP_TRANSPORT=simulated QUEUE_CONNECTION=sync E2E_REVERB=1 E2E_REVERB_PORT=8091 E2E_PORT=8030 \
  TEST_DB_LOCK_WAIT=3600 php tests/e2e/run.php finalizacion.mjs
E2E_REVERB=1 E2E_PORT=8040 E2E_REVERB_PORT=8101 WHATSAPP_TRANSPORT=simulated WHATSAPP_SIMULATE_READ=1 \
  TEST_DB_LOCK_WAIT=3600 php tests/e2e/run.php realtime.mjs
E2E_REVERB=1 E2E_PORT=8050 E2E_REVERB_PORT=8111 WHATSAPP_TRANSPORT=simulated \
  TEST_DB_LOCK_WAIT=3600 php tests/e2e/run.php estabilizacion.mjs
```

- Variables del lanzador: `E2E_PORT` (8020), `E2E_SERVER_WORKERS` (4), `NODE_BINARY`, `PLAYWRIGHT_MODULE`,
  `E2E_SCREENSHOTS` (capturas de pasos fallidos), `E2E_REVERB=1` y `E2E_REVERB_PORT` (8081). Usa puertos propios si
  hay otros servidores corriendo.
- Semillas auxiliares: `increment-b-seed.php`, `finalizacion-seed.php`, `realtime-seed.php` y
  `estabilizacion-seed.php` (solo aceptan la base de pruebas; `TestDatabase::guard`). Construyen los cuerpos de webhook
  con `App\Services\Meta\SimulatedWebhook`; no usan `chat-manager:simulate-inbound`, que solo actúa sobre la base de la
  aplicación. Desde la estabilización dan a la conexión E2E un `access_token` desechable, porque
  `Connection::sendingBlockReason()` bloquea el envío de una conexión sin credenciales guardadas.
- `realtime.mjs` y `estabilizacion.mjs` arrancan su propio worker (la lista `--queue` incluye `realtime`) y registran
  cada trama WebSocket para demostrar que la señal llegó antes del cambio en la interfaz.
- Los escenarios cargan los assets del servidor de Vite si existe `public/hot`.

### 6.1 Resultados de la estabilización

Ejecución del coordinador el 2026-10-22, en serie y con el candado (`TEST_DB_LOCK_WAIT=5400`), después de la suite
Pest completa y con el código final de la fase. Assets del servidor de Vite (`public/hot`); el build de producción se
generó aparte sin errores.

| Escenario            | Variables                                                                                               | Resultado | Observaciones                                                                                            |
| -------------------- | ------------------------------------------------------------------------------------------------------- | --------- | -------------------------------------------------------------------------------------------------------- |
| `corrections.mjs`    | `E2E_PORT=8030`                                                                                         | 14/14     | Exportaciones Excel/PDF por la interfaz (POST), expediente PDF, 390 px oscuro sin desbordes              |
| `increment-b.mjs`    | `E2E_PORT=8030`                                                                                         | 21/21     | Incluye la decisión obsoleta rechazada entre dos pestañas                                                |
| `finalizacion.mjs`   | `WHATSAPP_TRANSPORT=simulated QUEUE_CONNECTION=sync E2E_REVERB=1 E2E_REVERB_PORT=8091`                  | 27/27     | Campana, modo oscuro persistente, teléfono 390×844                                                       |
| `realtime.mjs`       | `E2E_REVERB=1 E2E_PORT=8040 E2E_REVERB_PORT=8101 WHATSAPP_TRANSPORT=simulated WHATSAPP_SIMULATE_READ=1` | 19/19     | Caída de Reverb: indicador degradado a 5,4 s, sondeo 16 s, reconexión 24,7 s sin duplicados              |
| `estabilizacion.mjs` | `E2E_REVERB=1 E2E_PORT=8050 E2E_REVERB_PORT=8111 WHATSAPP_TRANSPORT=simulated`                          | 17/17     | 4 roles; 14 actualizaciones en vivo, la más lenta 1 999 ms en la interfaz (presupuesto 8 s; sondeo 20 s) |

Total: 98/98 pasos, 0 errores de consola, 0 errores de página y 0 respuestas 5xx en los cinco escenarios.

## 7. Carga y resistencia

La batería `tests/load/run.php` (Apache + PHP-FPM o servidor integrado, workers con la distribución de Supervisor,
Reverb y un conjunto sintético de 10 000 conversaciones) está documentada con sus resultados y cómo repetirla en
[rendimiento-y-carga.md](rendimiento-y-carga.md). Usa el mismo candado y solo la base de pruebas.

## 8. Qué es real y qué es simulado

| Aspecto                                                 | Real en las pruebas                                                                      | Simulado                                                                              |
| ------------------------------------------------------- | ---------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------- |
| Base de datos                                           | MariaDB 11.4 (`chatmanager_testing`), bloqueos e índices reales                          | —                                                                                     |
| Concurrencia                                            | Procesos PHP separados, `queue:work` real (acotado)                                      | Peticiones web servidas por el kernel HTTP en un proceso, sin servidor web            |
| Navegador                                               | Chromium real (Playwright)                                                               | Assets del servidor de Vite, no del build de producción                               |
| Tiempo real                                             | Reverb y worker reales en `realtime.mjs` y en la batería de carga                        | En Pest, `BROADCAST_CONNECTION=null` o Reverb sin servidor (se verifica que no rompe) |
| WhatsApp entrante                                       | Firma HMAC, rutas, cola y procesamiento reales                                           | **Cuerpos de webhook generados** con el formato documentado por Meta                  |
| WhatsApp saliente y estados                             | Reclamo, bloqueo, clasificación de errores                                               | **`Http::fake`, transporte simulado o de registro**; «Entregado/Leído» simulados      |
| Exportaciones PDF                                       | Chromium real cuando está configurado (grupo `chrome`)                                   | —                                                                                     |
| Infraestructura (Nginx, Supervisor, systemd, respaldos) | Plantillas validadas por sintaxis, `QueueTopologyTest` y `DeployTemplatesTest`           | Nunca ejecutadas en un servidor real                                                  |
| Web Push                                                | Firma VAPID verificada con `openssl_verify`, encabezados, ciclo de vida de suscripciones | **Servicios push con `Http::fake`**; _service worker_ sin navegador real en Pest      |

Lo que falta validar fuera de este entorno está en [limitaciones.md](limitaciones.md).
