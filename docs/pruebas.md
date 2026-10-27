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

### 2.2 Pruebas añadidas en la fase 4

| Área                   | Archivos                                                                                                                                                                                                                                                                                     |
| ---------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Esquema y modelos      | `tests/Feature/Database/WebChatSchemaTest.php` (up/down solo en `chatmanager_testing`), `tests/Feature/WebChat/WebChatModelsTest.php`, `tests/Feature/Api/ApiTokenModelTest.php`                                                                                                             |
| Visitante del chat web | `tests/Feature/WebChat/*`: frame y CSP, sesiones, identidad firmada, mensajes, acuses, límites (también aislamiento por sesión), tiempo real y adjuntos                                                                                                                                      |
| Conexiones web         | `tests/Feature/Connections/WebChatWidgetManagementTest.php`, `WebChatConnectionStateTest.php`                                                                                                                                                                                                |
| Transporte por canal   | `tests/Feature/Messaging/ChannelTransportsTest.php`                                                                                                                                                                                                                                          |
| Bandeja general        | `tests/Feature/Conversations/GeneralInboxTest.php` y las pruebas de bandeja actualizadas                                                                                                                                                                                                     |
| API de integración     | `tests/Feature/Api/V1/*` (autenticación, conversaciones, límites por token, envío idempotente), `ApiErrorFormatTest.php`, `tests/Feature/Admin/ApiTokenManagementTest.php`                                                                                                                   |
| Documento OpenAPI      | `tests/Feature/Api/OpenApiDocumentTest.php`: cada ruta `api/v1` de `route:list` (método y ruta) está en `docs/openapi/v1.json` con su nombre como `operationId`, y viceversa                                                                                                                 |
| Seguridad              | `tests/Feature/Security/CorsTest.php` y `RouteAuthorizationSweepTest.php` (rutas `api.token` y `webchat.session` esperan 401 también con sesión de agente)                                                                                                                                   |
| Concurrencia           | `tests/Feature/Concurrency/WebWidgetConcurrencyTest.php` con procesos PHP reales: dos conexiones web a la vez, webhooks y widget en el mismo departamento, aceptación disputada, pestañas del mismo visitante, `client_message_id` repetido y el límite por sesión compartido entre procesos |

```bash
TEST_DB_LOCK_WAIT=3600 php artisan test --compact tests/Feature/WebChat tests/Feature/Api tests/Feature/Concurrency/WebWidgetConcurrencyTest.php
```

### 2.3 Pruebas añadidas en la fase 5.1

| Área                       | Archivos                                                                                                                                                                                                |
| -------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Supervisión                | `tests/Feature/Conversations/SupervisionInboxTest.php` (vista por omisión y nombres por alcance), `SupervisionRealtimeTest.php` (canales), `SupervisionNotificationTest.php` (ver no es recibir avisos) |
| Permisos efectivos         | `tests/Feature/Admin/EffectivePermissionsTest.php`, `PermissionCacheFreshnessTest.php`, `RoleCatalogPresentationTest.php`, `tests/Unit/Enums/DataScopeTest.php`                                         |
| Cuentas protegidas         | `tests/Feature/Admin/ProtectedAccountVisibilityTest.php`, `tests/Feature/Security/ProtectedAccountOperationalVisibilityTest.php`                                                                        |
| Cierre de sesión           | `tests/Feature/Auth/LogoutTest.php` (sin `Clear-Site-Data`, push solo de este navegador, sesión cerrada no reutilizable)                                                                                |
| Autorización de canales    | `tests/Feature/Realtime/ChannelAuthorizationThrottleTest.php`                                                                                                                                           |
| Panel, reportes y gráficas | `tests/Feature/Analytics/AnalyticsActivityTest.php`, `AnalyticsChannelTest.php`, `tests/Feature/Dashboard/DashboardChartsTest.php`, `DashboardConsistencyTest.php`                                      |
| Respuestas rápidas         | `tests/Feature/QuickReplies/QuickRepliesExportTest.php` (reporte general y vista actual, columnas completas)                                                                                            |
| Rendimiento                | `tests/Feature/Performance/RealtimeSignalQueriesTest.php`, `SupervisorPassagesVisibilityTest.php`                                                                                                       |

```bash
TEST_DB_LOCK_WAIT=3600 php artisan test --compact tests/Feature/Conversations/Supervision* tests/Feature/Admin \
  tests/Feature/Auth/LogoutTest.php tests/Feature/Realtime tests/Feature/Analytics tests/Feature/Dashboard \
  tests/Feature/QuickReplies tests/Feature/Performance tests/Feature/Security
```

### 2.4 Pruebas añadidas en la fase 5.2

| Área                       | Archivos                                                                                                                                                                                                                                                                                                                                                                                                                            |
| -------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Errores 403/404/409        | `tests/Feature/Conversations/ConversationConflictTest.php` (códigos y textos exactos por operación, responsable que perdió la conversación frente a quien nunca la tuvo, versión vieja), cambios en `ConversationLifecycleTest.php` y `ConcurrencyTest.php`                                                                                                                                                                         |
| Historial importado        | `tests/Feature/Conversations/ImportedConversationAccessTest.php` (403 de solo consulta, 404 del archivado en rutas operativas), `CustomerServiceWindowTest.php` (sin ventana), `InboxListRowsTest.php`, `ConversationInboxTest.php`, `DashboardConsistencyTest.php`                                                                                                                                                                 |
| Importador de historial    | `tests/Feature/HistoryImport/*`: acceso y permisos, flujo completo, clasificación y reglas, exclusiones (bandeja, búsqueda, métricas, API, exportaciones), ejecución e idempotencia, seguridad (ZIP, fórmulas, contenido fuera de logs), formatos (`WhaticketFormatsTest`, con `tests/fixtures/whaticket`) y archivo histórico                                                                                                      |
| Almacenamiento de adjuntos | `tests/Feature/Storage/MediaStorageTest.php` (disco listo o no, `MEDIA_DISK_REQUIRED`, escritura verificada, descargas con el disco caído, `attachments:recover-stale`), `StorageConsumersTest.php` (importación de contactos en disco remoto, avatar del widget tras cambiar de disco, alerta operativa por cuota, 503 en el enlace seguro, en la descarga de Meta para Messenger y en el chat web) con el doble `FailingDisk.php` |
| Adjuntos no disponibles    | `tests/Feature/Conversations/AttachmentAvailabilityTest.php`                                                                                                                                                                                                                                                                                                                                                                        |
| Exportaciones              | `tests/Feature/Exports/ExportDeliveryTest.php` (descarga desde la notificación, archivos recientes por reporte, `/exports` conservado), `ModuleReportsTest.php`                                                                                                                                                                                                                                                                     |
| Grupos de WhatsApp         | `tests/Feature/Webhooks/WhatsAppGroupPayloadTest.php`                                                                                                                                                                                                                                                                                                                                                                               |
| Limitador de canales       | `tests/Feature/Realtime/ChannelAuthorizationThrottleTest.php` (lecturas del contador, paso sin contar con aviso y contador diario, otros fallos cerrados)                                                                                                                                                                                                                                                                           |
| Autorización de rutas      | `tests/Feature/Security/RouteAuthorizationSweepTest.php` (rutas `admin.history-import.*` y `admin.history.*`)                                                                                                                                                                                                                                                                                                                       |

```bash
TEST_DB_LOCK_WAIT=3600 php artisan test --compact tests/Feature/HistoryImport tests/Feature/Storage \
  tests/Feature/Conversations/ConversationConflictTest.php tests/Feature/Conversations/ImportedConversationAccessTest.php \
  tests/Feature/Conversations/AttachmentAvailabilityTest.php tests/Feature/Exports/ExportDeliveryTest.php \
  tests/Feature/Webhooks/WhatsAppGroupPayloadTest.php tests/Feature/Realtime/ChannelAuthorizationThrottleTest.php
```

Estas pruebas usan solo `chatmanager_testing` y archivos sintéticos; nunca la base principal ni exportaciones reales.

### 2.5 Pruebas añadidas en la fase 5.2.1

| Área                         | Archivos                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    |
| ---------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Cadena de subida             | `tests/Feature/Conversations/AttachmentUploadFailuresTest.php`: archivo sin texto en WhatsApp y chat web, texto solo y archivo con pie, nada que enviar, cada error de PHP (`UPLOAD_ERR_*`) con su `code` y nunca «Escribe un mensaje…», 413 por `post_max_size` (con sesión de página y sin estado para webhooks/`up`/`broadcasting`/API), límite del canal por categoría, archivos justo bajo los límites decimales, disco caído (503), subida rechazada por Meta, límite del servidor en el compositor y en la salud, presupuesto de tiempo de la subida dentro de un intento, y el chat interno con un archivo solo o rechazado por PHP. Cambios en `OutboundAttachmentPolicyTest.php`, `InboxAttachmentTest.php`, `MultiAgentDeliveryTest.php`, `MediaStorageTest.php` y `SystemPageTest.php` (unidades decimales y chequeo `uploads`) |
| Personalización chat interno | `tests/Feature/Personalization/TeamChatAppearanceTest.php`: tema y predeterminado, hilo que prevalece solo para su dueño, predeterminado compartido y restablecer por niveles, texto ilegible ajustado e inválidos rechazados, solo participantes vigentes (404), supervisión con `view-personal` (solo consulta, sin el hilo) y `manage-personal` (edita y restablece, auditado), y el CHECK que impide conversación e hilo a la vez                                                                                                                                                                                                                                                                                                                                                                                                       |
| Avisos del chat interno      | `tests/Feature/Notifications/TeamChatNotificationTest.php` (ampliado): remitente, grupo y vista previa para participantes, texto largo recortado, mensajes solo con archivos, agrupadas con el autor del último, sin acceso (genérico), nadie fuera del hilo, mensaje ya leído no se notifica, leer el hilo lee sus avisos (salvo la agrupada con un mensaje más nuevo), sonido por omisión frente a la preferencia guardada y la lectura que llega mientras se guarda (BAJO-5)                                                                                                                                                                                                                                                                                                                                                             |

```bash
TEST_DB_LOCK_WAIT=3600 php artisan test --compact tests/Feature/Conversations/AttachmentUploadFailuresTest.php \
  tests/Feature/Personalization/TeamChatAppearanceTest.php tests/Feature/Notifications/TeamChatNotificationTest.php
```

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
E2E_REVERB=1 E2E_PORT=8060 E2E_REVERB_PORT=8121 E2E_SERVER_WORKERS=8 WHATSAPP_TRANSPORT=simulated \
  TEST_DB_LOCK_WAIT=3600 php tests/e2e/run.php bandeja.mjs
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
- `bandeja.mjs` (semilla `bandeja-seed.php`, con dos conexiones de WhatsApp) cubre la bandeja con dos sesiones que
  aceptan la misma fila a la vez, los filtros rápidos combinados, las no leídas, el toast y el sonido de los avisos
  (espía de Web Audio: `trackAlerts`, `alertDelta`; registro de toasts sin quitar duplicados: `toastsSince`) y una
  matriz responsive de 320 a 1440 px en modo claro y oscuro (`layoutReport`: desborde del documento, desbordes
  internos, texto recortado, fuente mínima y objetivos táctiles).
- `overflow()` de `lib.mjs` también mide los desbordes internos (paneles con `overflow-hidden` u `overflow-x-auto`), no
  solo el ancho del documento. Con `E2E_SCREENSHOTS` el lanzador guarda ahí la salida del servidor web y de Reverb, y
  falla con un mensaje claro si alguno no arranca.

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

### 6.2 Escenarios de la fase 4

| Escenario             | Contenido                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       |
| --------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `chat-movil.mjs`      | El chat en teléfonos (320–430 px), claro y oscuro: carga, scroll al primer mensaje, cita de una palabra de 90 caracteres, adjunto con nombre muy largo, menú de acciones, mensaje en vivo con palabra larga y panel de detalles (Sheet), sin scroll horizontal. Semilla `chat-movil-seed.php`                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| `bandeja-general.mjs` | Bandeja general con WhatsApp y Chat web: solo lo que espera a Ana, cada número de chip igual a lo que lista, filtro «Canal», aceptar sin que la fila salte («En atención»), un visitante nuevo llega en vivo a dos agentes que aceptan a la vez (uno gana, un evento), y la X «Cerrar notificación» a 375 y 1280 px sin marcar leída. Semilla `bandeja-general-seed.php`                                                                                                                                                                                                                                                                                                                                                        |
| `widget.mjs`          | El widget en páginas host de **otro sitio** (`tests/e2e/hosts/`, servidas en `E2E_HOST_PORT` como `http://tienda.test:{puerto}`; Chromium resuelve `tienda.test` e `intruso.test` a 127.0.0.1): cola del fragmento, `init` idempotente, abrir/cerrar/Escape, `postMessage` falsificado ignorado, token ilegible para el sitio y nunca en una URL, `intruso.test` no monta (frame-ancestors) ni obtiene sesiones, CSS hostil, anchos sin desborde (también dentro del iframe), ida y vuelta con un agente por Reverb o por sondeo, recarga y reconexión con la misma sesión, `destroy` sin restos, adaptador jQuery con `jquery-stub.js` (la URL del CDN se responde en local) y 0 errores sin jQuery. Semilla `widget-seed.php` |

```bash
E2E_REVERB=1 E2E_PORT=8070 E2E_REVERB_PORT=8131 WHATSAPP_TRANSPORT=simulated \
  TEST_DB_LOCK_WAIT=3600 php tests/e2e/run.php chat-movil.mjs
E2E_REVERB=1 E2E_PORT=8085 E2E_REVERB_PORT=8141 WHATSAPP_TRANSPORT=simulated \
  TEST_DB_LOCK_WAIT=3600 php tests/e2e/run.php bandeja-general.mjs
E2E_REVERB=1 E2E_PORT=8090 E2E_REVERB_PORT=8151 E2E_HOST_PORT=8092 \
  WHATSAPP_TRANSPORT=simulated TEST_DB_LOCK_WAIT=3600 php tests/e2e/run.php widget.mjs
```

- Sin `E2E_REVERB` el mensaje en vivo y la ida y vuelta del widget se comprueban por el sondeo de respaldo.
- `bandeja.mjs`, `increment-b.mjs`, `finalizacion.mjs`, `realtime.mjs` y `estabilizacion.mjs` se adaptaron a la bandeja
  general (chips con los mismos textos). `openInboxView` (`lib.mjs`) espera la respuesta Inertia de la lista antes de
  leerla, y el detector de desbordes se reforzó.
- Defectos que encontraron estos escenarios y se corrigieron: una recarga en vivo pisaba el filtro elegido
  (`useLiveReload.ts`), un nombre largo desbordaba el panel de detalles a 1280/1440 px, el panel principal daba 500 con
  un chat web activo y el límite «por sesión» de mensajes del visitante se contaba por IP.
- **Carga:** escenario `widget` de `tests/load/run.php` (frame → sesión → WebSocket opcional → mensajes con reenvío
  idempotente → sondeo final), por ejemplo `php tests/load/run.php widget:40,agents:5`. Perillas
  `LOAD_WIDGET_VISITORS`, `LOAD_WIDGET_MESSAGES`, `LOAD_WIDGET_CONCURRENCY`, `LOAD_WIDGET_SOCKET_SHARE`
  ([rendimiento-y-carga.md](rendimiento-y-carga.md)). Medido solo con 40 visitantes y 5 agentes sobre 1 000
  conversaciones.
- No se ejecutaron al cerrar la fase la suite Pest completa ni todos los E2E en serie: quedan para quien integra.

### 6.3 Escenario de la fase 5

| Escenario   | Contenido                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| ----------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `fase5.mjs` | Sesiones reales separadas (Administrador global, Supervisora de Ventas, Ana y Beto de Ventas): alias personal de Ana (lo ve Ana en lista y cabecera; Beto y el Administrador ven el nombre oficial; el Administrador lo consulta en «Elementos personales»), apariencia del chat de Ana sin efecto en Beto, menú «Filtros» con dos etiquetas O/Y, chips y «Limpiar filtros», «Administrar etiquetas» desde el menú de la fila, estados de entrega por webhooks firmados (enviado → entregado → leído) en el chat y en la vista previa de dos pestañas sin recargar, supervisión de solo lectura tras transferir a Soporte (sin acciones operativas ni compositor), 404 por URL a otro departamento, «Guardar contacto» de un contacto provisional, respuesta rápida personal que Beto no ve, sin botón de plantilla para el agente, ZIP como enlace y `.exe` renombrado a `.pdf` rechazado, matriz 320–1440 px claro/oscuro (bandeja, conversación, Filtros, apariencia, ayuda, Usuarios, Conexiones con 4 canales, detalle de Auditoría) sin desborde horizontal, y PWA (manifiesto, service worker, página sin conexión, el cierre de sesión vacía la caché; desde la fase 5.1 sin `Clear-Site-Data`). Semilla `fase5-seed.php` (`prepare` / `status`) |

```bash
E2E_REVERB=1 E2E_PORT=8100 E2E_REVERB_PORT=8161 WHATSAPP_TRANSPORT=simulated \
  WHATSAPP_SIMULATE_STATUSES=0 QUEUE_CONNECTION=sync TEST_DB_LOCK_WAIT=3600 php tests/e2e/run.php fase5.mjs
```

- `WHATSAPP_SIMULATE_STATUSES=0`: los estados de entrega solo llegan por los webhooks firmados del escenario.
- Capturas de cada estado de la matriz en `E2E_SCREENSHOTS` (`{ancho}-{esquema}-{estado}.png`).

### 6.4 Escenarios de la fase 5.1

| Escenario           | Contenido                                                                                                                                                                                                                                                                                                                                                                                                                                      |
| ------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `supervision.mjs`   | Sesiones simultáneas (Administrador global, supervisor de Ventas, Ana, Beto y Carla): una conversación nueva aparece en vivo en la vista por omisión de todos con el nombre por alcance; aceptar, responder, transferir a Soporte y cerrar siguen visibles para el Administrador; 404 fuera de alcance; retirar y volver a conceder «Transferir conversaciones» al rol real de Beto con dos pestañas abiertas, sin recargar (POST directo 403) |
| `logout.mjs`        | Perfil persistente con caché HTTP grande (15 000 respuestas por defecto, `E2E_LOGOUT_CACHE_ENTRIES`): cierre normal, «Atrás» tras cerrar, con _service worker_, varias sesiones, fallo de red y error del servidor (mensaje y reintento), 419 con la sesión reabierta y sesión ya vencida                                                                                                                                                      |
| `fechas-chat.mjs`   | Un separador por día («Hoy», «Ayer», fecha completa) que nunca se encima con otro al desplazarse, a 320–1280 px, claro y oscuro, 200 % y 400 % de zoom, tras cargar páginas anteriores, con un mensaje en vivo y al pasar la medianoche. Semilla `fechas-chat-seed.php`                                                                                                                                                                        |
| `filtros.mjs`       | Panel «Filtros» de 320 a 1440 px × 568 a 900 px: «Aplicar» siempre visible, cuerpo con su propio scroll, sin desborde; foco y Escape; borrador (nada cambia hasta «Aplicar», «Limpiar» restablece y aplica); zoom 200 %. Semilla `filtros-seed.php`                                                                                                                                                                                            |
| `quick-replies.mjs` | Cambio de pestañas sin recarga completa, sin salto de scroll y con `prefers-reduced-motion`; menú «Exportar» con «Reporte general» y «Solo la vista actual». Semilla `quick-replies-seed.php`                                                                                                                                                                                                                                                  |
| `roles.mjs`         | Tarjetas de rol (alcance, usuarios, permisos y áreas), «Ver permisos» por módulo sin claves internas, hoja móvil, alcance con flechas y consecuencias, aviso de 0 titulares y el Administrador sin rol raíz. Semilla `roles-seed.php`                                                                                                                                                                                                          |
| `tours.mjs`         | Botón global de ayuda con indicador de nuevos, cada paso resalta su control real, controles y teclado, reinicio, paso ausente saltado, navegación que cierra, sin overlay residual, movimiento reducido y botón que no tapa controles                                                                                                                                                                                                          |
| `diseno.mjs`        | Todas las páginas a 320–1440 px, claro y oscuro y 200 % de zoom: sin scroll horizontal, nada por debajo de 13 px, sin errores de consola. Solo lectura                                                                                                                                                                                                                                                                                         |
| `graficas.mjs`      | Panel y reportes dibujan exactamente las cifras del servidor (tabla, teclado y puntero), día sin respuestas como hueco, canales, estados, equipo, estado vacío, agente sin gráficas de equipo, modo oscuro, movimiento reducido y responsive. Semilla `graficas-seed.php`                                                                                                                                                                      |

Además se ampliaron `bandeja.mjs` (un solo aviso por mensaje entrante, sin contar las resuscripciones tras `.access.changed`), `realtime.mjs`
(autorización de canal que reintenta tras un 500) y `lib.mjs`. Ejecución, siempre en serie y con el candado:

```bash
B="E2E_REVERB=1 E2E_REVERB_PORT=8143 WHATSAPP_TRANSPORT=simulated QUEUE_CONNECTION=sync TEST_DB_LOCK_WAIT=3600"
env $B E2E_PORT=8100 php tests/e2e/run.php supervision.mjs
env $B E2E_PORT=8086 php tests/e2e/run.php logout.mjs
env $B E2E_PORT=8087 php tests/e2e/run.php fechas-chat.mjs
env $B E2E_PORT=8089 php tests/e2e/run.php filtros.mjs
env $B E2E_PORT=8095 php tests/e2e/run.php quick-replies.mjs
env $B E2E_PORT=8090 php tests/e2e/run.php roles.mjs
env $B E2E_PORT=8091 php tests/e2e/run.php tours.mjs
env $B E2E_PORT=8088 php tests/e2e/run.php diseno.mjs
env $B E2E_PORT=8092 php tests/e2e/run.php graficas.mjs
```

- `QUEUE_CONNECTION=sync`: las señales y avisos se procesan en la misma petición, sin workers, para que cada paso del
  escenario sea determinista.
- `realtime.mjs` no debe ejecutarse con `QUEUE_CONNECTION=sync`: con cola síncrona el estado simulado se procesa antes
  de que exista el identificador del proveedor y los ticks se quedan en «Enviado». Usa la cola asíncrona con su worker.
- Resultado al cerrar la fase (2026-10-25, ronda final en serie, 19 escenarios, 431/431 pasos): `supervision` 12/12,
  `logout` 11/11, `fechas-chat` 17/17, `filtros` 44/44, `quick-replies` 6/6, `roles` 20/20, `tours` 32/32, `diseno`
  24/24 (9 anchos × claro/oscuro + 200 %), `graficas` 39/39, `realtime` 19/19 (×4), `bandeja` 33/33, `fase5` 35/35.
  Capturas en `E2E_SCREENSHOTS`.

### 6.5 Escenarios de la fase 5.2

| Escenario          | Contenido                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |
| ------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `toast-oscuro.mjs` | Menú lateral sin «Exportaciones»; el _toast_ «Preparando PDF…» con su descripción a 390 y 1280 px en claro y oscuro (sigue el tema; título y descripción ≥ 4,5:1, icono y botón de cerrar ≥ 3:1, también con el puntero; dentro de la pantalla); anillo de foco del botón de cerrar; Excel grande preparado en cola con «Descargar» en la notificación; «Archivos preparados recientemente» en el menú; `/exports` sigue abriendo. Semilla `toast-oscuro-seed.php`                                                                                                                                                                                                                      |
| `adjuntos.mjs`     | Menú del clip con mouse y teclado (Fotos e imágenes, Videos, Documentos, Audio; Escape y clic fuera; foco al clip); `accept` por categoría; vista previa de imagen, video y documento; rechazos por tamaño, tipo y categoría equivocada; ZIP en WhatsApp solo con «Enviar como enlace privado temporal»; ZIP nativo en el chat web; duplicado; progreso real con un segundo envío ignorado; conversación cerrada mientras tanto (409); «Archivo histórico no disponible» y «Archivo no disponible»; 320–1280 px claro y oscuro. Semilla `adjuntos-seed.php`                                                                                                                             |
| `historial.mjs`    | Importador con archivos sintéticos: agente sin permiso (403 en importador e historial); crear lote; subir reporte de chats, mensajes, contactos, ZIP y `Chat.txt` con «Formato no verificado»; archivo de tipo equivocado rechazado; conexiones a «Conexión histórica sin envío»; inventario y filtros iguales al servidor; selección, regla con vista previa, participantes de la empresa, acción sobre un filtro y cambio manual; resumen igual al servidor; 409 `summary_changed`; confirmación, progreso y resultado; reporte CSV; historial archivado buscable y de solo lectura sin palomitas; importada en «Cerradas»; 320/768/1280 claro y oscuro. Semilla `historial-seed.php` |

```bash
env E2E_REVERB=1 E2E_PORT=8110 E2E_REVERB_PORT=8171 WHATSAPP_TRANSPORT=simulated QUEUE_CONNECTION=sync \
  EXPORTS_XLSX_SYNC_ROWS=0 TEST_DB_LOCK_WAIT=3600 php tests/e2e/run.php toast-oscuro.mjs
env E2E_REVERB=1 E2E_PORT=8130 E2E_REVERB_PORT=8191 WHATSAPP_TRANSPORT=simulated QUEUE_CONNECTION=sync \
  TEST_DB_LOCK_WAIT=3600 php tests/e2e/run.php adjuntos.mjs
env E2E_REVERB=1 E2E_PORT=8140 E2E_REVERB_PORT=8197 WHATSAPP_TRANSPORT=simulated QUEUE_CONNECTION=sync \
  TEST_DB_LOCK_WAIT=3600 php tests/e2e/run.php historial.mjs
```

- `realtime.mjs` se amplió con dos carreras deterministas del chat interno (señal durante una carga y respuesta de
  lectura vieja; [chat-interno §Correcciones](chat-interno.md#correcciones-de-la-fase-52)). **No usa
  `QUEUE_CONNECTION=sync`**: necesita la cola asíncrona con su worker (§6.4). Se ejecuta como en §6.
- `EXPORTS_XLSX_SYNC_ROWS=0` obliga a `toast-oscuro.mjs` a preparar el Excel en cola.
- Resultados (2026-10-25, en serie con el candado): `toast-oscuro` 12/12, `adjuntos` 27/27, `historial` 36/36 (incluye «Quitar archivo») y
  `realtime` 21/21 en cinco corridas seguidas. Capturas en `E2E_SCREENSHOTS`.
- Cierre de la fase 5.2 (2026-10-26): Pest completo 2750/2750 (18 159 aserciones, 32,5 min, en serie); ronda E2E
  final con los 22 escenarios en verde (`fase5` 35/35 dos veces tras actualizar la aserción del ZIP como enlace
  privado elegido); batería de carga A (`profile,webhooks:3000`) y D (`webhooks:600`, `reconnect`, `transfers:200`,
  `export:30`) sin mensajes perdidos ni duplicados y sin jobs fallidos. Los 429 del limitador de webhooks son
  esperados; el escenario `export` los cuenta como «perdidos» si dura más de ~40 s a 30/s.

### 6.6 Escenarios de la fase 5.2.1

`subidas.mjs` (fase 5.2.1): la cadena de subida por HTTP real con archivos generados en un directorio temporal
(imágenes 1–5,2 MB, videos 3,7–16,5 MB, documentos de 4 KB a 101 MB, con y sin texto, extensión cambiada, cancelación,
doble clic, 390 y 1280 px). El transporte real de WhatsApp sube cada archivo a `tests/e2e/fake-graph.php` (sustituto
local de Graph API, registra tamaño y SHA-256); se comprueba en la base que el mensaje y su archivo existen con el
tamaño exacto. Dos pasadas: con límites de producción y con los del `php.ini` (2M/8M: error de configuración del
servidor antes de subir, y con la página sin conocer el límite, `UPLOAD_ERR_INI_SIZE` y 413 en la tarjeta). Semilla
`subidas-seed.php`, que al final borra los archivos guardados.

```bash
env E2E_PORT=8140 WHATSAPP_TRANSPORT=meta META_GRAPH_BASE_URL=http://127.0.0.1:8141 QUEUE_CONNECTION=sync \
  E2E_UPLOAD_MAX_FILESIZE=100M E2E_POST_MAX_SIZE=110M TEST_DB_LOCK_WAIT=3600 php tests/e2e/run.php subidas.mjs
env E2E_PORT=8140 WHATSAPP_TRANSPORT=meta META_GRAPH_BASE_URL=http://127.0.0.1:8141 QUEUE_CONNECTION=sync \
  TEST_DB_LOCK_WAIT=3600 php tests/e2e/run.php subidas.mjs
```

`chat-interno-avisos.mjs`: avisos del chat interno en navegadores reales con Reverb real y un worker de cola que
arranca el propio escenario (no usa `QUEUE_CONNECTION=sync`). Pasos: aviso en otro módulo (_toast_ con remitente,
vista previa, «Abrir» y cerrar; campana; badge; un sonido; ninguno para el autor), «Abrir» lleva al hilo y lee la
notificación, hilo abierto y visible sin aviso, otro hilo con aviso, mensaje propio sin sonido, sonido desactivado
(_toast_ sin sonido) y reactivado, dos pestañas con un solo sonido, hilo abierto en otra pestaña sin aviso en ninguna,
reconexión con un solo sonido y el badge actualizado, _toast_ a 390 y 1280 px en claro y oscuro (dentro de la
pantalla, botones con área suficiente) y el worker vivo al final. El sonido se cuenta espiando los osciladores de Web
Audio (`installAlertSpy` de `lib.mjs`; un tono = osciladores creados en 100 ms), sin salida de audio real. Semilla
`chat-interno-avisos-seed.php`.

`chat-interno-apariencia.mjs`: colores propios en el chat interno (Ana y Beto de `increment-b-seed.php` en el grupo
«E2E Apariencia»; Ana también tiene un privado con Carla). Guardar «Océano» solo para el hilo sin recargar, texto
≥ 4,5:1 medido en la página, persiste al recargar y al cerrar e iniciar sesión, el otro hilo conserva el tema, Beto no
ve los colores de Ana, modo oscuro, matriz 360/390/430/768/1024/1440 px en claro y oscuro (sin desplazamiento
horizontal, botón «Apariencia» alcanzable, iconos de reacción y acciones ≥ 3:1 respecto de lo que tienen detrás),
texto ilegible ajustado con aviso, fondo negro en modo claro con iconos ≥ 3:1 y «Restablecer predeterminados».
Semilla `chat-interno-apariencia-seed.php`.

```bash
env E2E_REVERB=1 WHATSAPP_TRANSPORT=simulated TEST_DB_LOCK_WAIT=3600 php tests/e2e/run.php chat-interno-avisos.mjs
env E2E_PORT=8130 TEST_DB_LOCK_WAIT=3600 php tests/e2e/run.php chat-interno-apariencia.mjs
```

- Resultados (2026-10-26, en serie con el candado): `subidas` 24/24 con límites de producción y 12/12 con los del
  `php.ini` (2M/8M), `chat-interno-avisos` 14/14 y `chat-interno-apariencia` 25/25.
- Solo Chromium (Playwright): Safari, Firefox y móviles reales no se verificaron.

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
