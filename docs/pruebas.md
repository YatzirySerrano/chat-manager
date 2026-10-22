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

`--parallel` está prohibido: varios procesos reconstruirían la misma base. Organización de `tests/Feature`:

| Directorio                                                     | Cubre                                                                                                   | Documento                                                               |
| -------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------- |
| `Conversations`, `Tags`, `QuickReplies`, `Contacts`            | Bandeja, motor de estados, visibilidad, adjuntos, lecturas, etiquetas, directorio                       | [bandejas-y-asignacion.md](bandejas-y-asignacion.md)                    |
| `Webhooks`, `Messaging`, `Templates`, `Connections`            | Firma, idempotencia, tipos de mensaje, estados, errores de Graph, reintentos, plantillas, diagnóstico   | [mensajeria-whatsapp.md §11](mensajeria-whatsapp.md#11-pruebas)         |
| `Realtime`                                                     | Autorización de canales, destinatarios de señales, configuración del cliente                            | [tiempo-real.md](tiempo-real.md#pruebas)                                |
| `TeamChat`                                                     | Chat interno: acceso, mensajes, grupos, adjuntos                                                        | [chat-interno.md](chat-interno.md)                                      |
| `Notifications`, `Automation`                                  | Notificaciones, horarios, mensajes automáticos                                                          | [notificaciones-y-horarios.md](notificaciones-y-horarios.md)            |
| `Analytics`, `Reports`, `Exports`, `DashboardTest.php`         | Métricas, reportes, panel, exportaciones                                                                | [reportes-y-exportaciones.md §7](reportes-y-exportaciones.md#7-pruebas) |
| `Imports`, `Migration`                                         | Importación de contactos, adaptador Whaticket, reversión                                                | [migracion-whaticket.md](migracion-whaticket.md)                        |
| `Admin`, `Auth`, `Settings`, `Audit`, `Validation`, `Security` | Usuarios, roles, escalamiento de privilegios, autenticación, bitácora, barrido de rutas, endurecimiento | [seguridad-y-auditoria.md](seguridad-y-auditoria.md)                    |
| `Operations`, `Console`, `Local`                               | Salud, alertas, preflight, comandos                                                                     | [infraestructura-y-despliegue.md](infraestructura-y-despliegue.md)      |
| `Database`                                                     | Integridad del esquema, migraciones de eliminación lógica, concurrencia con dos conexiones              | §4                                                                      |
| `Concurrency`                                                  | Carreras con procesos PHP reales                                                                        | §4                                                                      |
| `Integration`                                                  | Flujos de punta a punta entre módulos, topología de colas, presupuestos de consultas                    | §5                                                                      |
| `Performance`                                                  | N+1 de la bandeja y bloqueos del departamento                                                           | [rendimiento-y-carga.md](rendimiento-y-carga.md)                        |

- **Grupo `chrome`:** las pruebas que imprimen un PDF real con Chromium se omiten si `EXPORTS_NODE_BINARY` y
  `EXPORTS_CHROME_PATH` no apuntan a ejecutables (ver `.env.testing.example`).
- **Barrido de rutas:** `tests/Feature/Security/RouteAuthorizationSweepTest.php` recorre las ~140 rutas de la
  aplicación con un invitado, un usuario sin permisos y un agente de otro departamento; una ruta nueva queda cubierta
  automáticamente. Es lenta: conviene correrla aparte.

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
2. `tests/Feature/Concurrency/ConcurrentProcessesTest.php` (15 escenarios): **procesos PHP reales** lanzados con
   `proc_open` (`tests/Support/Concurrency/worker.php`), cada uno con la aplicación completa y su propia conexión, que
   arrancan a la vez con una señal de salida. Solo corren con `APP_ENV=testing` y la base de pruebas; un vigilante
   detiene a los que se cuelgan. Los envíos van a un transporte que registra en archivo (doble de prueba, no Meta).
   Cubren: mismo `wamid` en 4 workers (1 contacto, 1 conversación, 1 mensaje), mismo cuerpo de webhook en 3 procesos,
   estados en desorden, 4 agentes aceptando a la vez (1 ganador), transferencia con mensaje entrante simultáneo,
   pérdida de acceso durante una aceptación, conexión eliminada durante entregas, ventana vencida, mismo job de
   entrega en 4 workers, misma `client_reference` en 4 procesos, permiso revocado visto por la siguiente petición y
   por un `queue:work` de larga vida, ausencia de bloqueos globales, planes de consulta con índices sobre 4 040
   conversaciones y exportación consistente mientras llegan mensajes.

Cada prueba reconstruye la base, por lo que el archivo es lento (la última corrida conjunta con los archivos
afectados: 172 pruebas en unos 20 minutos, incluida la espera del candado).

## 5. Pruebas de integración

`tests/Feature/Integration` recorre flujos completos con colas en línea y difusión `log`: webhook firmado →
distribución automática → bienvenida o fuera de horario → entrega → webhook de estado → notificaciones, señales y
métricas; transferencia, cierre con despedida, baja de usuario y cambio de rol; importación terminada notificada una
sola vez; topología de colas (cada cola tiene un worker en las plantillas de Supervisor y systemd); y presupuestos de
consultas de las props compartidas y de la lista de notificaciones.

## 6. Escenarios de navegador (E2E)

Chromium real con Playwright, mouse y teclado reales, contra `chatmanager_testing`. El lanzador
`tests/e2e/run.php` toma el candado, reconstruye la base, siembra, arranca su propio servidor (y Reverb si se pide) y
lo detiene al terminar. No se agregan dependencias: Playwright se carga desde `PLAYWRIGHT_MODULE`.

| Escenario          | Contenido                                                                                                                                                                                                                                                                                                                            | Pasos |
| ------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ----- |
| `corrections.mjs`  | Correcciones de los incrementos 01, A y B: confirmación de cierre de sesión, sin passkeys, ayudas, colores, validación en vivo, eliminación lógica y restauración, exportaciones Excel/PDF e interfaz según permisos                                                                                                                 | 14    |
| `increment-b.mjs`  | Bandeja de tres paneles: aceptar, notas en el compositor, cola «Pendientes», filtros persistentes, historial operativo, panel de detalles                                                                                                                                                                                            | 21    |
| `finalizacion.mjs` | Recorrido completo: permisos por rol (403), aislamiento entre departamentos, webhook firmado (firma falsa 401), envío simulado con marcas, «/», adjunto, nota, transferir, cerrar, reabrir, reintento, plantilla con ventana cerrada, reportes, exportación, plantillas, sistema, chat interno, campana, modo oscuro y móvil 390×844 | 27    |
| `realtime.mjs`     | Tres sesiones simultáneas con Reverb y worker reales: señales antes que la interfaz (< 8 s, más rápido que el sondeo de 20 s), contadores, disponibilidad, aceptación disputada, ticks, notas, transferencia, chat interno con «Visto», revocación de acceso, caída de Reverb, sondeo y reconexión sin duplicados                    | 19    |

```bash
export PATH=/usr/local/bin:$PATH
PLAYWRIGHT_MODULE=<ruta a node_modules/playwright> E2E_PORT=8030 TEST_DB_LOCK_WAIT=1800 \
  php tests/e2e/run.php corrections.mjs
WHATSAPP_TRANSPORT=simulated QUEUE_CONNECTION=sync E2E_REVERB=1 E2E_REVERB_PORT=8091 E2E_PORT=8030 \
  TEST_DB_LOCK_WAIT=1800 php tests/e2e/run.php finalizacion.mjs
E2E_REVERB=1 E2E_PORT=8040 E2E_REVERB_PORT=8101 WHATSAPP_TRANSPORT=simulated WHATSAPP_SIMULATE_READ=1 \
  TEST_DB_LOCK_WAIT=1800 php tests/e2e/run.php realtime.mjs
```

- Variables del lanzador: `E2E_PORT` (8020), `E2E_SERVER_WORKERS` (4), `NODE_BINARY`, `PLAYWRIGHT_MODULE`,
  `E2E_SCREENSHOTS` (capturas de pasos fallidos), `E2E_REVERB=1` y `E2E_REVERB_PORT` (8081). Usa puertos propios si
  hay otros servidores corriendo.
- Semillas auxiliares: `increment-b-seed.php`, `finalizacion-seed.php` y `realtime-seed.php` (solo aceptan la base de
  pruebas). Construyen los cuerpos de webhook con `App\Services\Meta\SimulatedWebhook`; no usan
  `chat-manager:simulate-inbound`, que solo actúa sobre la base de la aplicación.
- `realtime.mjs` arranca su propio worker y reinicia Reverb para el paso de caída; registra cada trama WebSocket para
  demostrar que la señal llegó antes del cambio en la interfaz.
- Los escenarios cargan los assets del servidor de Vite si existe `public/hot`; ediciones simultáneas pueden recargar
  la página a mitad de un paso. `realtime.mjs` neutraliza esa conexión; los demás no.

Últimos resultados reportados por la ola de QA (no repetidos al escribir este documento): corrections 14/14,
increment-b 21/21, finalizacion 27/27 (con Reverb) y realtime 19/19, sin errores de consola ni respuestas 5xx.

## 7. Carga y resistencia

La batería `tests/load/run.php` (Apache + PHP-FPM o servidor integrado, workers con la distribución de Supervisor,
Reverb y un conjunto sintético de 10 000 conversaciones) está documentada con sus resultados y cómo repetirla en
[rendimiento-y-carga.md](rendimiento-y-carga.md). Usa el mismo candado y solo la base de pruebas.

## 8. Qué es real y qué es simulado

| Aspecto                                                 | Real en las pruebas                                               | Simulado                                                                              |
| ------------------------------------------------------- | ----------------------------------------------------------------- | ------------------------------------------------------------------------------------- |
| Base de datos                                           | MariaDB 11.4 (`chatmanager_testing`), bloqueos e índices reales   | —                                                                                     |
| Concurrencia                                            | Procesos PHP separados, `queue:work` real (acotado)               | Peticiones web servidas por el kernel HTTP en un proceso, sin servidor web            |
| Navegador                                               | Chromium real (Playwright)                                        | Assets del servidor de Vite, no del build de producción                               |
| Tiempo real                                             | Reverb y worker reales en `realtime.mjs` y en la batería de carga | En Pest, `BROADCAST_CONNECTION=null` o Reverb sin servidor (se verifica que no rompe) |
| WhatsApp entrante                                       | Firma HMAC, rutas, cola y procesamiento reales                    | **Cuerpos de webhook generados** con el formato documentado por Meta                  |
| WhatsApp saliente y estados                             | Reclamo, bloqueo, clasificación de errores                        | **`Http::fake`, transporte simulado o de registro**; «Entregado/Leído» simulados      |
| Exportaciones PDF                                       | Chromium real cuando está configurado (grupo `chrome`)            | —                                                                                     |
| Infraestructura (Nginx, Supervisor, systemd, respaldos) | Plantillas validadas por sintaxis y por `QueueTopologyTest`       | Nunca ejecutadas en un servidor real                                                  |

Lo que falta validar fuera de este entorno está en [limitaciones.md](limitaciones.md).
