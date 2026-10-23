# Rendimiento y pruebas de carga

Batería reproducible de carga y resistencia (SPEC §20 y §21): qué se midió, cómo repetirlo, qué cuellos de botella
aparecieron y cuáles se corrigieron.

> **Medido en esta máquina de desarrollo, con WhatsApp simulado. No es extrapolable a producción.** Ningún número de
> este documento es una capacidad garantizada: "100 agentes" es el nombre de un escenario medido aquí, no una promesa.
> Nunca se envió ni recibió nada de Meta ni de usuarios reales; todo corre contra la base de pruebas
> `chatmanager_testing`.

Documentos relacionados: [infraestructura y despliegue](infraestructura-y-despliegue.md) (workers, Redis, nginx),
[tiempo real](tiempo-real.md), [mensajería WhatsApp](mensajeria-whatsapp.md),
[bandejas y asignación](bandejas-y-asignacion.md), [reportes y exportaciones](reportes-y-exportaciones.md).

## 1. Resumen

- **Integridad bajo carga (verificado aquí):** en todas las corridas, 0 mensajes perdidos, 0 mensajes duplicados, 0
  conversaciones abiertas duplicadas por contacto, 0 contactos duplicados por teléfono, estados de entrega siempre al
  final correcto (`read`) aunque llegaran en orden inverso, y exactamente un ganador en cada aceptación disputada (hasta
  30 agentes aceptando la misma conversación a la vez). Ningún job terminó en `failed_jobs`.
- **Cuatro cuellos de botella reales corregidos** (sección 7): N+1 en la bandeja (284 → 16 consultas por petición),
  bloqueo de la fila del departamento en cada conversación nueva aunque el departamento no distribuya automáticamente,
  _deadlocks_ por bloqueos de hueco (gap locks) al crear conversaciones de clientes nuevos, y _deadlock_ por subir un
  bloqueo compartido a exclusivo en departamentos automáticos. En la ráfaga de 3 000 webhooks los _deadlocks_ de InnoDB
  bajaron de 446 a 62, los eventos de webhook reprocesados por error de 150 a 0, y el tiempo de procesamiento p99 de
  17 s a 10 s (máximo de 48 s a 10 s).
- **Cuellos de botella identificados y no corregidos en código** (sección 8): la cola `notifications` acumula ~5 jobs
  por webhook y con 2 workers retrasa las señales de tiempo real hasta 15–35 s en ráfagas; el pool
  `notifications,default` deja sin atender la cola `default` (métricas) mientras haya notificaciones; el limitador de
  1 200 webhooks/min por conexión responde 429 en ráfagas; _deadlocks_ restantes en `notifications` (módulo de
  notificaciones); conteos por estado que recorren toda la tabla `conversations`.

- **Repetición tras la fase de estabilización (2026-10-22, sección 10):** con la topología de colas separada
  (`realtime` propia), sin listeners vacíos y con las correcciones de interbloqueos, la ráfaga de 3 000 webhooks pasó
  de 55–62 _deadlocks_ a **0**, la cola `notifications` de ~13 000 a 1 602 trabajos como máximo y la señal
  `.message.recorded` de p95 7,0 s a 2,5 s (p99 22 s → 8,5 s). Sin pérdidas ni duplicados en ningún escenario,
  incluidos los nuevos de transferencias concurrentes y reinicio de Reverb.

## 2. Entorno medido

| Elemento                | Valor                                                                                                                                                                                                                                                                                                                                                     |
| ----------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Máquina                 | Apple M2, 8 núcleos (4 de rendimiento + 4 de eficiencia), 24 GB RAM, macOS (Darwin 25.0.0)                                                                                                                                                                                                                                                                |
| Compartida              | Sí: otros procesos de desarrollo (pruebas E2E y Pest de otros módulos) corrían a la vez; el _load average_ llegó a 4–7 en algunas corridas. Los números tienen ese ruido.                                                                                                                                                                                 |
| PHP                     | 8.4.24 (OPcache activo en FPM)                                                                                                                                                                                                                                                                                                                            |
| Base de datos           | MariaDB 11.4.13 local, `innodb_buffer_pool_size` = 128 MB (valor por defecto de Homebrew), aislamiento por defecto REPEATABLE READ                                                                                                                                                                                                                        |
| Servidor web            | Apache httpd 2.4.62 de macOS (MPM event) + PHP-FPM `pm=static` con **8 procesos** (`LOAD_SERVER=fpm`, por defecto). Comparación: servidor integrado de PHP con `PHP_CLI_SERVER_WORKERS=8` (`LOAD_SERVER=builtin`)                                                                                                                                         |
| Workers                 | Sección 6 (topología de entonces): 4 × `webhooks,messages`, 1 × `media`, 2 × `notifications,default`, 1 × `exports`. Sección 10 (topología actual de `deploy/supervisor`): 4 × `webhooks,messages`, 2 × `realtime`, 2 × `notifications`, 2 × `media`, 1 × `default`, 1 × `exports`. En ambas la batería usa `--sleep=1 --tries=3 --backoff=10` para todos |
| Cola / caché / sesiones | `database` (tabla `jobs`), `database`, `database`. **Sin Redis** (no está instalado en esta máquina)                                                                                                                                                                                                                                                      |
| Tiempo real             | Reverb 1 proceso, protocolo pusher, autorización real por `/broadcasting/auth`                                                                                                                                                                                                                                                                            |
| WhatsApp                | **SIMULACIÓN**: `WHATSAPP_TRANSPORT=simulated`; webhooks firmados (`X-Hub-Signature-256`) con secretos desechables de conexiones de prueba                                                                                                                                                                                                                |
| Cliente de carga        | Node.js 24.16 (fetch y WebSocket nativos), un solo proceso                                                                                                                                                                                                                                                                                                |
| Entorno de la app       | `APP_ENV=testing`, `APP_DEBUG=false`, `BCRYPT_ROUNDS=4` (solo abarata el inicio de sesión de la preparación)                                                                                                                                                                                                                                              |

Efecto del servidor: con 8 procesos PHP, como mucho 8 peticiones se ejecutan a la vez; las demás esperan en la cola de
FPM. Con `LOAD_SERVER=builtin` (8 workers) el escenario de 50 agentes dio latencias equivalentes (sección 6.3), así que
en esta máquina el límite no es el servidor web sino la CPU compartida y MariaDB.

## 3. Conjunto de datos sintético

Generado por `tests/load/seed.php` (determinista, semilla fija) en cada corrida, sobre `chatmanager_testing` recién
reconstruida:

| Dato                             | Cantidad                                                                                                                        |
| -------------------------------- | ------------------------------------------------------------------------------------------------------------------------------- |
| Conversaciones históricas        | 10 000 (8 500 cerradas en los últimos 90 días, 800 abiertas con ventana de 24 h vigente, 500 pendientes en cola, 200 en espera) |
| Mensajes                         | 72 189 (3–12 por conversación; salientes con estados `accepted`/`read`)                                                         |
| Eventos de conversación          | 28 000                                                                                                                          |
| Contactos / identidades WhatsApp | 7 500 / 7 500                                                                                                                   |
| Usuarios                         | 106: 100 agentes, 4 supervisores (uno por departamento), 1 administrador, 1 superadministrador                                  |
| Departamentos                    | 4: Ventas, Cobranza, Postventa (compartidos) y Soporte (automático)                                                             |
| Conexiones                       | 2 (simuladas): Ventas (70 % del tráfico) y Soporte                                                                              |
| Métricas de reportes             | 10 000 filas calculadas con el comando real `analytics:rebuild`                                                                 |

Tiempos de preparación medidos: `migrate:fresh --seed` 12,5 s, datos sintéticos 4,8 s, `analytics:rebuild` de 10 000
conversaciones 16,9 s.

## 4. Cómo reproducir

Requisitos: MariaDB con `chatmanager_testing` (la de `.env.testing`), Node 22 o superior en el `PATH` (aquí
`/usr/local/bin/node`), puertos libres. No se instala nada.

```bash
export PATH=/usr/local/bin:$PATH
TEST_DB_LOCK_WAIT=1800 php tests/load/run.php profile,webhooks:3000
TEST_DB_LOCK_WAIT=1800 LOAD_DURATION=45 php tests/load/run.php agents:10,agents:25,agents:50
TEST_DB_LOCK_WAIT=1800 LOAD_DURATION=45 php tests/load/run.php agents:100,websocket:200,export
TEST_DB_LOCK_WAIT=1800 LOAD_WEBHOOK_RATE=10 php tests/load/run.php webhooks:600,export:30,reconnect,transfers:200
```

- El lanzador **solo acepta `chatmanager_testing`** y toma el mismo candado exclusivo que Pest y los escenarios E2E
  (`TEST_DB_LOCK_WAIT` segundos de espera), así que nunca corre a la vez que las pruebas. Reconstruye esa base en cada
  invocación; nunca toca `chatmanager`.
- Arranca sus propios procesos en sus propios puertos (HTTP 8050, FPM 8052, Reverb 8111; configurables con
  `LOAD_PORT`/`E2E_PORT`, `LOAD_FPM_PORT`, `LOAD_REVERB_PORT`/`E2E_REVERB_PORT`) y los detiene al terminar, también ante
  errores o Ctrl+C.
- Cada proceso hijo usa un directorio de `storage` propio dentro de la carpeta de salida (logs, exportaciones,
  sesiones en archivo), de modo que el `storage/` del proyecto no se toca. Las credenciales de Reverb, los secretos de
  webhook y la contraseña de los usuarios sintéticos se generan al azar por corrida y solo viven en el `manifest.json`
  de la carpeta de salida (permisos 0600); no aparecen en reportes.
- Salida (`LOAD_OUTPUT`, por defecto el directorio temporal del sistema): `summary.json` y un JSON por escenario con
  latencias, muestras por segundo (CPU y RSS por proceso, cola por nombre, mensajes nuevos), contadores de InnoDB y las
  verificaciones de integridad.
- Variables útiles: `LOAD_SERVER` (`fpm`|`builtin`), `LOAD_PHP_WORKERS` (8), `LOAD_WORKERS`
  (`"messages,webhooks:4;realtime:2;notifications:2;media:2;default:1;exports:1"`, la topología actual de
  `deploy/supervisor`), `LOAD_CONVERSATIONS` (10000),
  `LOAD_SEED_AGENTS` (100), `LOAD_DURATION` (60 s por escenario de agentes), `LOAD_THINK_MIN_MS`/`LOAD_THINK_MAX_MS`
  (1000/3000), `LOAD_WEBHOOK_CONCURRENCY` (20), `LOAD_WEBHOOK_RATE` (sin límite), `LOAD_WS_STORMS` (3),
  `LOAD_EXPORT_WEBHOOKS_PER_S` (10), `LOAD_SCENARIO_TIMEOUT` (300 s), `LOAD_DRAIN_TIMEOUT` (180 s),
  `WHATSAPP_WEBHOOK_RATE_LIMIT` (se pasa a los procesos). Cada escenario documenta las suyas en su cabecera.
- Duración: cada invocación de arriba tarda 3–4 minutos (≈35 s de preparación). Para no saturar la máquina no se
  corren todas juntas.

Métricas de bloqueo: el usuario de pruebas no tiene privilegio `PROCESS`, así que no se usa
`SHOW ENGINE INNODB STATUS`; se registran los deltas globales de `Innodb_deadlocks`, `Innodb_row_lock_waits` y
`Innodb_row_lock_time` (son de todo el servidor MariaDB: incluyen actividad ajena, que fue mínima mientras se tenía el
candado de pruebas) y los _deadlocks_ que llegaron al log de la aplicación con su SQL.

## 5. Escenarios

| Escenario       | Qué hace                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| --------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `profile`       | En proceso: cada página/endpoint principal 3 veces por rol (agente, supervisión, superadministración); mediana, número de consultas, tiempo SQL, consultas repetidas (candidatas a N+1) y `EXPLAIN` de las más lentas.                                                                                                                                                                                                                                                                                                       |
| `webhooks[:n]`  | `n` webhooks firmados en 20 carriles concurrentes, sin pausa: clientes nuevos (algunos con 3 mensajes simultáneos), clientes históricos, mensajes a conversaciones abiertas, duplicados exactos (también dos copias a la vez), reenvíos del mismo `wamid` con otro sobre, y estados en orden inverso (`read`, `delivered`, `sent`). Como Meta, reintenta con espera los 429/5xx. 30 sondas miden de punta a punta el tiempo del POST a la señal `.message.recorded` en un socket de Reverb.                                  |
| `agents[:n]`    | `n` agentes (más 4 supervisores y el administrador viendo reportes) durante `LOAD_DURATION` s con pausa de 1–3 s entre acciones: bandeja, cola, abrir conversación, historial, marcar leído, responder (transporte simulado), aceptar de la cola, Dashboard y reportes con filtros. Antes, 5 rondas de aceptación disputada: todos los agentes del departamento aceptan la misma conversación al mismo instante. Tráfico de fondo de 2 webhooks/s.                                                                           |
| `websocket[:n]` | `n` conexiones pusher (varias pestañas por agente) suscritas a `users.{id}`, `inbox.queues.{dep}` y una conversación propia; difusión de 10 conversaciones nuevas a la cola de Ventas; 20 suscripciones a conversaciones ajenas (deben rechazarse); 3 tormentas de reconexión (todas cierran y reconectan a la vez).                                                                                                                                                                                                         |
| `export`        | 10 s de línea base y luego exportación XLSX de conversaciones (historial completo) y contactos mientras entran 10 webhooks/s y 5 agentes usan la bandeja.                                                                                                                                                                                                                                                                                                                                                                    |
| `transfers[:n]` | `n` transferencias (`LOAD_TRANSFER_CONCURRENCY` carriles, 10) alternando a otro agente del departamento y a la cola de otro departamento de la conexión, mientras los clientes escriben en esas mismas conversaciones (`LOAD_TRANSFER_WEBHOOKS_PER_S`, 5). El destino escucha su canal (`users.{id}` o `inbox.queues.{dep}`) y se mide POST → `.inbox.changed`. 10 rondas disputadas (`LOAD_TRANSFER_CONTENDED`): responsable y supervisión transfieren la misma conversación con la misma versión a la vez; debe ganar una. |
| `reconnect[:n]` | `n` sockets (uno por agente con conversación abierta, máx. 96 en el conjunto) y un mensaje de cliente cada 500 ms; a los 10 s `run.php` reinicia Reverb (SIGTERM y arranque). Mide la caída, el tiempo hasta que cada socket vuelve a estar listo (reintento cada ~1 s), las señales perdidas y que ningún mensaje se pierda.                                                                                                                                                                                                |

## 6. Resultados medidos

Latencias en milisegundos como p50 / p95 / p99, medidas desde el cliente (incluyen la espera en la cola de FPM).
"Antes" = código al empezar esta tarea; "después" = con las correcciones de la sección 7. Corridas de 45 s por nivel de
agentes.

### 6.1 Perfil por endpoint (en proceso, 10 000 conversaciones, mediana de 3)

| Rol                 | Página                                | Antes: ms / consultas | Después: ms / consultas |
| ------------------- | ------------------------------------- | --------------------- | ----------------------- |
| Agente              | Bandeja, vista cola                   | 262,8 / 284           | 147,5 / 16              |
| Agente              | Bandeja, mis conversaciones           | 66,7 / 31             | 59,8 / 17               |
| Agente              | Búsqueda en bandeja                   | 26,1 / 25             | 21,4 / 11               |
| Supervisión         | Bandeja, todas                        | 222,1 / 227           | 136,4 / 18              |
| Supervisión         | Bandeja, cola                         | 230,2 / 227           | 135,7 / 18              |
| Superadministración | Bandeja, todas                        | 150,6 / 43            | 152,4 / 43              |
| Todos               | Abrir conversación                    | 22–29 / 23–37         | 22–25 / 21–23           |
| Todos               | Historial (JSON)                      | 13–15 / 17–19         | 14–16 / 17–19           |
| Supervisión         | Dashboard (resumen diferido)          | 27,4 / 27             | 20,4 / 12               |
| Supervisión         | Reportes 90 días (analítica diferida) | 17,9 / 19             | 18,0 / 12               |

La lista de la superadministración no tenía N+1 de departamentos (alcance global), pero aún consulta 25 veces los
departamentos aceptables y pasa ~120 ms en PHP armando 25 filas con 13 permisos cada una (sección 8).

### 6.2 Ráfaga de 3 000 webhooks (SIMULACIÓN)

| Métrica                                                                | Antes                            | Después                         | Después + workers separados¹    |
| ---------------------------------------------------------------------- | -------------------------------- | ------------------------------- | ------------------------------- |
| Peticiones HTTP (incl. reintentos) / rendimiento                       | 3 420 / 52,7 por s               | 3 440 / 51,6 por s              | 3 406 / 52,6 por s              |
| Latencia webhook entrante p50/p95/p99                                  | 86 / 115 / 128                   | 102 / 145 / 181²                | 85 / 120 / 136                  |
| Respuestas 429 del limitador (luego reentregadas)                      | 280                              | 290                             | 280                             |
| Mensajes únicos esperados / guardados / perdidos / duplicados          | 1 954 / 1 954 / 0 / 0            | 1 953 / 1 953 / 0 / 0           | 1 988 / 1 988 / 0 / 0           |
| Estados en orden inverso con final correcto                            | 255 de 255                       | 244 de 244                      | 247 de 247                      |
| Eventos de webhook procesados / reprocesados por error                 | 2 820 / 150                      | 2 799 / 0                       | 2 819 / 0                       |
| Procesamiento (recibido → procesado, resolución 1 s) p50/p95/p99/máx   | 5 / 13 / 17 / 48 s               | 6 / 9 / 10 / 10 s               | 7 / 11 / 11 / 12 s              |
| _Deadlocks_ InnoDB (delta global)                                      | 446                              | 62                              | 55                              |
| _Deadlocks_ que agotaron reintentos (log, job reintentado por la cola) | 36 (`insert into conversations`) | 2 (`insert into notifications`) | 3 (`insert into notifications`) |
| Esperas de bloqueo de fila / tiempo total                              | 11 549 / 20,0 s                  | 14 962 / 24,8 s                 | 10 778 / 26,7 s                 |
| Cola máxima: webhooks / messages / notifications / default             | 1 245 / 1 758 / 14 369 / 1 548   | 1 234 / 2 012 / 13 204 / 1 508  | 1 371 / 2 019 / 13 299 / 83     |
| Señal de tiempo real (POST → `.message.recorded`) p50/p95/p99          | 837 / 15 991 / 32 543            | 854 / 15 607 / 35 452           | 834 / 7 000 / 22 121            |
| CPU media / pico (100 % = 1 núcleo): web · workers · MariaDB           | 32/462 · 128/323 · 59/208        | 31/431 · 130/324 · 57/194       | 36/476 · 161/401 · 70/201       |
| RSS pico: web (8 FPM) · workers (9) · MariaDB · Reverb                 | 383 · 530 · 204 · 63 MB          | 379 · 526 · 225 · 63 MB         | 378 · 658 · 225 · 63 MB         |

¹ `LOAD_WORKERS="webhooks,messages:4;media:1;notifications:3;default:1;exports:1"`.
² La corrida "después" coincidió con el _load average_ más alto (7,0) por pruebas de otros módulos en la misma máquina;
la variación de la latencia HTTP de recepción no se debe a los cambios (que no tocan la petición HTTP del webhook).

Lectura: la recepción HTTP se mantiene en ~0,1 s porque solo guarda el cuerpo y encola. La entrada de 3 000 webhooks
en ~20 s (más la segunda ola tras los 429) se procesa en ~2 minutos con 4 workers; la señal de tiempo real llega en
<1 s cuando no hay acumulación, y hasta 15–35 s cuando la cola `notifications` tiene más de 10 000 jobs.

### 6.3 Agentes simultáneos

Sin errores HTTP en ningún nivel (0 de 439 … 2 671 peticiones). Rendimiento = peticiones por segundo del escenario
completo.

| Agentes | Código                          | Peticiones / por s | Bandeja (lista) | Bandeja (cola)  | Abrir conversación | Enviar respuesta | Aceptar de cola | Dashboard (resumen) | Reportes (analítica) | CPU web media/pico | CPU MariaDB media/pico |
| ------- | ------------------------------- | ------------------ | --------------- | --------------- | ------------------ | ---------------- | --------------- | ------------------- | -------------------- | ------------------ | ---------------------- |
| 10      | antes                           | 439 / 8,8          | 96 / 242 / 255  | 263 / 349 / 381 | 46 / 77 / 106      | 38 / 64 / 78     | 38 / 60 / 60    | 32 / 48 / 50        | 73 / 115 / 118       | 38 / 168           | 22 / 46                |
| 10      | después                         | 449 / 9,0          | 81 / 172 / 256  | 156 / 203 / 320 | 46 / 70 / 75       | 36 / 191 / 305³  | 34 / 56 / 56    | 32 / 49 / 125       | 67 / 110 / 124       | 34 / 177           | 16 / 49                |
| 25      | antes                           | 801 / 15,7         | 77 / 216 / 260  | 245 / 286 / 343 | 39 / 54 / 63       | 33 / 43 / 69     | 34 / 49 / 54    | 36 / 77 / 80        | 68 / 121 / 127       | 65 / 274           | 30 / 47                |
| 25      | después                         | 820 / 16,5         | 71 / 129 / 155  | 146 / 172 / 187 | 38 / 58 / 104      | 31 / 45 / 50     | 32 / 46 / 46    | 34 / 75 / 79        | 61 / 99 / 120        | 62 / 258           | 22 / 37                |
| 50      | antes                           | 1 428 / 28,8       | 73 / 131 / 220  | 235 / 283 / 303 | 37 / 46 / 51       | 29 / 38 / 43     | 30 / 34 / 36    | 33 / 68 / 76        | 67 / 103 / 111       | 121 / 470          | 39 / 58                |
| 50      | después                         | 1 439 / 29,5       | 73 / 122 / 144  | 149 / 163 / 168 | 37 / 43 / 50       | 32 / 38 / 48     | 29 / 35 / 39    | 34 / 69 / 74        | 64 / 106 / 129       | 115 / 495          | 34 / 58                |
| 50      | después, servidor integrado PHP | 1 445 / 30,2       | 69 / 121 / 152  | 149 / 163 / 176 | 38 / 47 / 58       | 32 / 38 / 44     | 31 / 39 / 42    | 27 / 41 / 61        | 68 / 103 / 132       | 107 / 397          | 33 / 45                |
| 100     | antes                           | 2 671 / 54,0       | 77 / 136 / 204  | 237 / 346 / 414 | 37 / 56 / 82       | 29 / 46 / 54     | 29 / 45 / 58    | 26 / 45 / 67        | 63 / 135 / 137       | 259 / 581          | 48 / 79                |
| 100     | después                         | 2 665 / 54,0       | 72 / 137 / 184  | 154 / 223 / 252 | 37 / 56 / 82       | 30 / 46 / 63     | 29 / 52 / 82    | 27 / 47 / 66        | 73 / 113 / 115       | 233 / 591          | 40 / 54                |

³ Valores aislados en una corrida de 31 envíos con la máquina ocupada por otros procesos; en 25, 50 y 100 agentes el
envío quedó en 30–32 ms p50 y ≤63 ms p99.

Aceptación disputada (5 rondas por nivel, verificado en la base): con 3, 8, 15 y 30 contendientes, siempre 1 ganador
(HTTP 302), el resto 409 controlado, 1 solo evento de aceptación y el responsable es el ganador. Latencia con 30
contendientes: p50 66 ms, p99 116 ms. Respuestas: todas las aceptadas por la API (hasta 308 en 45 s) quedaron
guardadas y entregadas por el transporte simulado, sin errores.

Con 100 agentes (≈54 peticiones/s) los 8 procesos PHP llegan a picos de ~590 % de CPU (casi toda la máquina) y la
latencia sigue estable; el límite de esta máquina está cerca. No se probó más allá de 100 agentes.

### 6.4 WebSocket (Reverb)

200 conexiones de 100 agentes, 600 suscripciones privadas.

| Métrica                                                                    | Antes⁴            | Después         |
| -------------------------------------------------------------------------- | ----------------- | --------------- |
| Conexión (abrir socket → `connection_established`) p50/p95/p99             | 1,6 / 29 / 31     | 1,6 / 21 / 23   |
| Autorización `/broadcasting/auth` p50/p95/p99 (600 en ráfagas de 50)       | 191 / 398 / 445   | 180 / 201 / 208 |
| Las 200 conexiones listas (3 canales cada una)                             | 2,7 s             | 2,2 s           |
| Difusión a la cola de Ventas: sockets que recibieron / esperados           | 500 / 500         | 500 / 500       |
| Difusión: POST del webhook → `.inbox.changed` (p50 por ronda)              | 0,77–1,74 s       | 0,13–1,80 s     |
| Suscripciones a conversaciones ajenas rechazadas (403)                     | 20 de 20          | 20 de 20        |
| Tormenta de reconexión (200 a la vez, 600 autorizaciones): todas listas en | 1,8–2,0 s         | 2,1–2,2 s       |
| Conexiones que no quedaron listas por tormenta                             | 57, 91, 96 de 200 | 2, 11, 5 de 200 |
| CPU pico Reverb / RSS pico Reverb                                          | 16 % / 71 MB      | 15 % / 72 MB    |

⁴ "Antes" tenía el límite del arnés: Apache con `MaxRequestWorkers` 128 rechazaba conexiones cuando llegaban 600
autorizaciones a la vez. Se subió a 800 hilos (las peticiones esperan a FPM en vez de ser rechazadas). Las fallas
restantes (27 de 1 800 autorizaciones en la última corrida) son `UND_ERR_SOCKET`: Apache cierra conexiones
_keep-alive_ bajo presión y el cliente de prueba no reintenta; `pusher-js` en un navegador reintenta la suscripción al
reconectar. No es un error de la aplicación, pero muestra que una tormenta de 600 autorizaciones simultáneas satura 8
procesos PHP durante ~2 s.

La latencia de difusión depende de los workers de la cola `notifications` (`--sleep=1`): la señal se publica desde un
job, así que suma el tiempo de espera de la cola (hasta ~1 s por salto cuando los workers están ociosos).

### 6.5 Exportación grande mientras llegan mensajes

| Métrica                                             | Antes                                              | Después                          |
| --------------------------------------------------- | -------------------------------------------------- | -------------------------------- |
| Conversaciones XLSX (historial completo)            | 10 103 filas, 9 s de generación                    | 10 100 filas, 10 s de generación |
| Contactos XLSX                                      | 7 510 filas, 5 s (esperó 9 s en cola tras la otra) | 7 510 filas, 5 s (esperó 10 s)   |
| Ambas listas (desde la petición, vista del usuario) | 16,2 s                                             | 16,3 s                           |
| Webhooks línea base → durante exportación, p50/p95  | 12,9/30,9 → 14,2/20,3 ms                           | 13,0/36,7 → 14,0/20,7 ms         |
| Bandeja línea base → durante exportación, p50/p95   | 86,7/131,5 → 86,4/135,3 ms                         | 79,8/109 → 92,0/122,5 ms         |
| Mensajes recibidos durante el escenario / perdidos  | 357 / 0                                            | 357 / 0                          |
| _Deadlocks_ InnoDB                                  | 56                                                 | 0                                |
| RSS pico de workers (incluye el de exportación)     | 636 MB                                             | 620 MB                           |

La exportación corre en su propio worker (`exports`) y no afectó de forma medible la recepción ni la bandeja con este
volumen. Los 56 _deadlocks_ de "antes" eran de conversaciones nuevas de clientes históricos (corrección 7.2/7.3).

## 7. Cuellos de botella encontrados y corregidos

### 7.1 N+1 en la bandeja (permisos por fila)

- **Síntoma:** la vista cola de un agente hacía 284 consultas (263 ms) y la de una supervisora 227 (222 ms); crecía con
  cada fila listada.
- **Causa:** `ConversationResource` evalúa 13 permisos por fila; para alcances "asignado" y "departamentos" cada uno
  llamaba a `User::belongsToDepartment()`, `departmentIds()` y `ConversationAccess::acceptableDepartmentIds()`, que
  consultaban la base cada vez (135 + 133 consultas iguales en 25 filas).
- **Corrección:** `ConversationController@index` carga una vez los departamentos del usuario
  (`loadMissing('departments')`), y `acceptableDepartmentIds()` usa esa relación cuando está cargada (mismo filtro:
  activos y que no distribuyen manualmente). El motor ya cargaba esa relación bajo bloqueo (`freshUser`), así que las
  comprobaciones bajo bloqueo siguen usando datos frescos.
- **Resultado:** 284 → 16 y 227 → 18 consultas; cola p50 bajo carga 235–263 ms → 146–157 ms.
- **Prueba:** `tests/Feature/Performance/InboxListQueriesTest.php` (mismas consultas con 2 que con 12 filas; la cola
  sigue mostrando solo departamentos aceptables).

### 7.2 Bloqueo del departamento en cada conversación nueva

- **Síntoma:** en ráfagas, las conversaciones nuevas del mismo departamento esperaban unas a otras y participaban en
  _deadlocks_.
- **Causa:** `AssignAutomatically` hacía `SELECT … FOR UPDATE` sobre la fila del departamento **antes** de comprobar si
  distribuía automáticamente, así que cada conversación nueva de Ventas (compartido) bloqueaba Ventas hasta el final de
  su transacción.
- **Corrección:** primero una lectura sin bloqueo; solo los departamentos automáticos se bloquean (y el modo se vuelve
  a comprobar bajo el bloqueo).
- **Prueba:** `tests/Feature/Performance/InboundDepartmentLockTest.php` (departamentos compartido y manual: ningún
  bloqueo).

### 7.3 _Deadlocks_ por bloqueos de hueco al crear conversaciones

- **Síntoma:** 36 jobs de webhook agotaron sus 3 intentos de transacción con `Deadlock found` en
  `insert into conversations`, 150 eventos se reprocesaron (espera de 10 s de la cola) y el procesamiento llegó a 48 s.
- **Causa:** con REPEATABLE READ, las búsquedas con `FOR UPDATE` de la conversación abierta de un contacto que no tiene
  ninguna toman un bloqueo de hueco en el índice `(connection_id, contact_id, …)`. Los contactos nuevos tienen los ids
  más altos, así que todos caen en el **mismo hueco** al final del índice: dos clientes nuevos distintos se bloqueaban
  mutuamente al insertar. Los reintentos inmediatos volvían a chocar.
- **Corrección:** `ReceiveInboundMessage` ejecuta su transacción en READ COMMITTED cuando es la transacción externa
  (sin bloqueos de hueco), con hasta 3 intentos y una pausa aleatoria corta entre _deadlocks_. Dos mensajes del mismo
  contacto siguen serializados por las filas existentes o compiten por la clave única de conversación abierta, cuyo
  perdedor ya se reintentaba y se une a la conversación ganadora. Dentro de una transacción externa (pruebas) el
  comportamiento es el anterior. Ver ADR-LOAD-1 en [decisiones](decisiones.md).
- **Prueba:** los tests de concurrencia existentes con procesos reales (`tests/Feature/Concurrency`) y los de entrada
  (`InboundConversationTest`) siguen pasando; el efecto se mide con la batería (446 → 62 _deadlocks_, 150 → 0
  reprocesos).

### 7.4 _Deadlock_ al subir el bloqueo del departamento automático

- **Síntoma:** tras 7.3 quedaban _deadlocks_ en `select * from departments … for update` (departamento automático).
- **Causa:** insertar la conversación verifica la clave foránea del departamento con un bloqueo compartido; después
  `AssignAutomatically` pedía el exclusivo. Dos llegadas simultáneas tenían el compartido y ambas esperaban el exclusivo.
- **Corrección:** para departamentos automáticos, el bloqueo exclusivo se toma antes de insertar la conversación (orden
  fijo de bloqueos). La asignación automática ya estaba serializada por departamento; ahora sin ciclo.
- **Prueba:** `InboundDepartmentLockTest` (el bloqueo del departamento automático ocurre antes del insert y la
  conversación se asigna).

### 7.5 Ajustes del arnés (no son de la aplicación)

Límite de hilos de Apache (128 → 800) para las tormentas de reconexión, eliminación de una advertencia de OPcache por
re-declarar `opcache.enable` en FPM, y el estado de la base que se pasa a cada escenario para no reutilizar
conversaciones ya aceptadas por un escenario anterior.

## 8. Identificado y no corregido (con motivo)

| Hallazgo                                                     | Evidencia                                                                                                                                            | Por qué no se cambió / recomendación                                                                                                                                                                                                                    |
| ------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Cola `notifications` como cuello de botella del tiempo real  | ~13 000–14 000 jobs para 2 800 webhooks (≈5 por webhook: señales de tiempo real y notificaciones); con 2 workers la señal llega a 15–35 s en ráfagas | Es capacidad de workers, no un error. Con 3 workers dedicados la p95 bajó de 15,6 s a 7,0 s. Recomendación: workers propios para `notifications` y escalar según la cola (ver [infraestructura](infraestructura-y-despliegue.md)); Redis en producción. |
| Pool `notifications,default` deja sin atender `default`      | `default` (métricas de reportes) se quedó en ~1 500 jobs hasta vaciar `notifications`; con un worker dedicado a `default`, pico 83                   | Plantilla de supervisor del módulo de operación; recomendación: un worker propio para `default`.                                                                                                                                                        |
| Limitador de webhooks 1 200/min por conexión                 | 280–290 respuestas 429 en la ráfaga; ningún mensaje perdido porque el emisor reintenta, como hace Meta                                               | Es una protección deliberada (`WHATSAPP_WEBHOOK_RATE_LIMIT`). Meta reintenta durante días, pero retrasa la atención; ajustar a la demanda medida en producción.                                                                                         |
| _Deadlocks_ restantes en `notifications`                     | 2–6 por corrida en `InAppChannel` (`insert into notifications` tras `FOR UPDATE` de la clave de agregación: mismo patrón de hueco)                   | Código del módulo de notificaciones; la cola los reintenta sin pérdidas. Se recomienda el mismo tratamiento que 7.3.                                                                                                                                    |
| Conteos por estado de la bandeja recorren toda la tabla      | `EXPLAIN` de `select status, COUNT(*) … group by status`: `ALL`/`index` sobre ~9 700 filas (4–12 ms aquí)                                            | Escala lineal con el historial; con cientos de miles de conversaciones habrá que acotar (solo no cerradas) o cachear. No medido a esa escala.                                                                                                           |
| Lista de la superadministración: 120 ms de PHP para 25 filas | 43 consultas, 30 ms de SQL                                                                                                                           | 13 permisos × 25 filas y consulta global de departamentos aceptables por fila (alcance global no usa la relación cargada). Candidato a memoizar por petición.                                                                                           |
| Búsqueda con `LIKE '%…%'`                                    | `EXPLAIN` sin índice en contactos (ya documentado por la bandeja)                                                                                    | Con 7 500 contactos 12 ms; a gran escala requiere índice de texto completo.                                                                                                                                                                             |
| Tormenta de reconexión                                       | 600 autorizaciones simultáneas ocupan los 8 procesos PHP ~2 s                                                                                        | Esperado con 8 procesos; en producción más procesos FPM o _backoff_ aleatorio en el cliente.                                                                                                                                                            |

## 9. Límites de estas mediciones

- **Verificado aquí:** todo lo de la sección 6 se midió en esta máquina con las corridas de la tarea; la integridad
  (pérdidas, duplicados, un solo ganador, estados) se comprobó en la base después de cada escenario.
- **Simulado:** WhatsApp completo (webhooks firmados con secretos de prueba y transporte `simulated`); las sesiones de
  los agentes son clientes HTTP sin navegador (no se miden el renderizado ni el JavaScript del cliente); Reverb recibe
  clientes pusher de Node, no navegadores.
- **Pendiente de validación externa:** comportamiento con Meta real (latencia, reintentos y límites de Meta),
  producción con nginx + PHP-FPM + Redis + Supervisor en un servidor Linux dedicado, MariaDB con un _buffer pool_
  dimensionado, varios servidores web o varias instancias de Reverb, y volúmenes de historial de cientos de miles de
  conversaciones. Ninguna cifra de aquí indica cuántos agentes soporta producción.
- La máquina estaba compartida con otras pruebas; las corridas "antes" y "después" se hicieron con cargas ajenas
  distintas, por eso solo se atribuyen a las correcciones las diferencias grandes y explicadas (consultas por petición,
  _deadlocks_, reprocesos, latencia de la cola).

## 10. Resultados tras la fase de estabilización (2026-10-22)

> **Medición local en un portátil compartido, con WhatsApp simulado. No son cifras de producción ni la capacidad del
> VPS.** Misma máquina y mismo arnés que la sección 6 (Apple M2, 8 núcleos, 24 GB, MariaDB con _buffer pool_ de
> 128 MB, cola/caché/sesiones en base de datos, sin Redis), con otras pruebas del proyecto corriendo a ratos: el
> _load average_ máximo por corrida fue 3,3–10,0 (10,0 en `agents:10`). La capacidad de un VPS Linux con nginx,
> PHP-FPM, Redis y Supervisor no se ha medido.

Código: árbol de trabajo con los cambios de la fase de estabilización (topología de colas con `realtime` dedicada,
listeners vacíos eliminados, READ COMMITTED fiable con reintento registrado en `RegisterInboundContact` e
`InAppChannel`, upserts, búsqueda FULLTEXT, paginación por cursor, capacidad por agente, Web Push). Workers: la
topología actual (`LOAD_WORKERS` por defecto, 12 procesos). Los _deadlocks_ se cuentan de tres formas: delta global
`Innodb_deadlocks`, reintentos registrados por `ReadCommittedTransaction` (`Interbloqueo de base de datos reintentado.`,
por operación) y errores de _deadlock_ no recuperados en el log.

### 10.1 Corridas

| Corrida | Comando (con `export PATH=/usr/local/bin:$PATH` y `TEST_DB_LOCK_WAIT=3600`)                                                                               |
| ------- | --------------------------------------------------------------------------------------------------------------------------------------------------------- |
| A       | `php tests/load/run.php profile,webhooks:3000`                                                                                                            |
| B       | `LOAD_DURATION=45 php tests/load/run.php agents:10,agents:25,agents:50`                                                                                   |
| C       | `LOAD_DURATION=45 php tests/load/run.php agents:100,websocket:200,export`                                                                                 |
| D       | `LOAD_WEBHOOK_RATE=10 LOAD_TRANSFER_CONCURRENCY=20 LOAD_TRANSFER_WEBHOOKS_PER_S=10 php tests/load/run.php webhooks:600,export:30,reconnect,transfers:200` |

Una corrida previa de prueba de los escenarios nuevos (`webhooks:600,reconnect,transfers`) no se usa en las tablas:
su sonda de notificaciones aún no filtraba por tipo y su conteo de señales perdidas en `reconnect` tenía un error del
arnés (ambos corregidos antes de D).

Muestras: "n" es el número de mediciones; con n < 100 el p99 es prácticamente el máximo y se indica así.

### 10.2 Ráfaga de 3 000 webhooks (corrida A)

| Métrica                                                                     | Anterior (6.2, workers separados) | Ahora                                         |
| --------------------------------------------------------------------------- | --------------------------------- | --------------------------------------------- |
| Peticiones HTTP (incl. reintentos) / rendimiento                            | 3 406 / 52,6 por s                | 3 440 / 51,9 por s                            |
| Latencia webhook entrante p50/p95/p99 (n = 1 958)                           | 85 / 120 / 136 ms                 | 97 / 147 / 175 ms                             |
| Respuestas 429 del limitador (luego reentregadas)                           | 280                               | 300 (30 al primer intento, 270 en reintentos) |
| Mensajes únicos esperados / guardados / perdidos / duplicados               | 1 988 / 1 988 / 0 / 0             | 1 988 / 1 988 / 0 / 0                         |
| Duplicados exactos y reenvíos del mismo `wamid` enviados / guardados de más | — / 0                             | 331 + 104 / 0                                 |
| Estados en orden inverso con final correcto                                 | 247 de 247                        | 239 de 239                                    |
| Eventos de webhook procesados / reprocesados                                | 2 819 / 0                         | 2 804 / 0                                     |
| Procesamiento (recibido → procesado, resolución 1 s) p50/p95/p99/máx        | 7 / 11 / 11 / 12 s                | 7 / 11 / 12 / 12 s                            |
| _Deadlocks_ InnoDB (delta global) / reintentados (log) / no recuperados     | 55 / — / 3                        | **0 / 0 / 0**                                 |
| Esperas de bloqueo de fila / tiempo total                                   | 10 778 / 26,7 s                   | 3 492 / 39,9 s                                |
| Cola máxima: webhooks / messages / realtime / notifications / default       | 1 371 / 2 019 / — / 13 299 / 83   | 1 262 / 713 / 183 / **1 602** / 70            |
| `.message.recorded` (POST → señal) p50/p95/p99 (n = 29, p99 ≈ máx.)         | 834 / 7 000 / 22 121 ms           | 883 / 2 461 / 8 537 ms                        |
| `.inbox.changed` en `users.{responsable}` p50/p95/p99 (n = 29)              | no medido                         | 885 / 2 463 / 8 539 ms                        |
| `.notification` (campana, `conversation.message`) p50/p95/p99 (n = 29)      | no medido                         | 1 817 / 7 974 / 31 476 ms                     |
| Sondas sin señal en 60 s                                                    | —                                 | 1 de 30 (la misma sonda en los tres canales)¹ |
| CPU media / pico (100 % = 1 núcleo): web · workers · MariaDB                | 36/476 · 161/401 · 70/201         | 32/526 · 111/427 · 49/163                     |
| RSS pico: web (8 FPM) · workers · MariaDB · Reverb                          | 378 · 658 (9) · 225 · 63 MB       | 389 · 777 (12) · 208 · 64 MB                  |
| Drenaje                                                                     | —                                 | ver texto                                     |
| Trabajos fallidos                                                           | 0                                 | 0                                             |

¹ Una sonda recibió 429 en su primer intento; con la espera exponencial de reentrega es probable que su señal llegara
después de los 60 s de espera de la sonda. El mensaje se guardó (0 perdidos).

Drenaje: los webhooks llegan en dos olas (la segunda son las reentregas tras los 429, al abrirse la ventana del
limitador ~60 s después). Tras la última ola, `webhooks`, `messages`, `realtime` y `default` quedaron vacías en el
mismo segundo (t ≈ 80 s) y `notifications` ~23 s después (t ≈ 102 s). Al terminar el escenario las colas estaban
vacías (drenaje final 1,1 s).

Lectura: la separación de `realtime` y la eliminación de trabajos vacíos reducen la cola `notifications` ~8 veces y la
señal de mensaje ya no espera detrás de las notificaciones. Los _deadlocks_ de la ráfaga desaparecieron. El tiempo de
procesamiento de la ráfaga no cambió (lo limita el número de workers de `webhooks,messages` y la CPU compartida). La
campana sigue dependiendo de `notifications` (2 workers): en ráfaga su p95 es 8 s.

Variación entre corridas: el 0 de _deadlocks_ de la corrida A no se repite siempre. Con el orden actual
`messages,webhooks` del grupo `critical`, corridas posteriores de la misma ráfaga dieron 1 y 2 (con el orden anterior,
6), todos entre webhooks entrantes en `inbound.message` y reintentados con éxito al primer intento por
`ReadCommittedTransaction`. Entre 0 y unos 6 reintentados por cada 3 000 webhooks es la variación normal; lo que debe
ser 0 son los no recuperados (`deadlock_mentions`, `lock_wait_timeouts`), los trabajos fallidos y los mensajes
perdidos o duplicados.

### 10.3 Webhooks a ritmo sostenido (corrida D, `webhooks:600`, 10 por s)

| Métrica                                                | Valor                    |
| ------------------------------------------------------ | ------------------------ |
| Webhooks / errores HTTP / 429                          | 652 / 0 / 0              |
| Latencia webhook entrante p50/p95/p99 (n = 383)        | 19 / 35 / 43 ms          |
| Mensajes esperados / guardados / perdidos / duplicados | 413 / 413 / 0 / 0        |
| Procesamiento (resolución 1 s) p50/p95/p99             | 1 / 1 / 1 s              |
| `.message.recorded` p50/p95/p99 (n = 30)               | 877 / 1 883 / 1 887 ms   |
| `.inbox.changed` del responsable p50/p95/p99 (n = 30)  | 879 / 1 885 / 1 890 ms   |
| `.notification` p50/p95/p99 (n = 30)                   | 1 057 / 1 791 / 1 869 ms |
| _Deadlocks_ (InnoDB / reintentados / no recuperados)   | 0 / 0 / 0                |
| Cola máxima (cualquier cola)                           | 26 (`realtime`)          |
| CPU media/pico: workers · MariaDB                      | 45/130 · 28/57           |

Sin acumulación, la latencia de señal tiene un suelo de ~0,3–1 s: el mensaje pasa por dos colas de base de datos
(`webhooks`, luego `realtime`) y un worker ocioso consulta cada `--sleep=1` s. En la difusión del escenario
`websocket` (sin carga) las 10 rondas dieron p50 0,98–1,02 s. Con Redis (espera bloqueante) ese suelo debería bajar;
no se midió. Corrección: `block_for` está en `null` y Redis solo bloquea en la primera cola de cada worker; véase
[11.3](#113-redis-y-block_for-hallazgo-lat-10).

### 10.4 Agentes simultáneos (corridas B y C)

Latencias en ms, p50/p95/p99, 45 s por nivel con 2 webhooks/s de fondo. 0 errores HTTP en todos los niveles.

| Agentes | Peticiones / por s | Bandeja (lista)    | Bandeja (cola)      | Abrir conversación | Enviar respuesta  | Aceptar de cola  | Dashboard (resumen) | Reportes (analítica) | CPU web media/pico | CPU MariaDB media/pico |
| ------- | ------------------ | ------------------ | ------------------- | ------------------ | ----------------- | ---------------- | ------------------- | -------------------- | ------------------ | ---------------------- |
| 10      | 441 / 8,9          | 95/215/566 (n=69)  | 170/331/334 (n=36)  | 49/198/761 (n=40)  | 39/172/413 (n=26) | 35/51/51 (n=7)   | 39/62/112 (n=35)    | 74/118/130 (n=30)    | 38 / 201           | 15 / 32                |
| 25      | 824 / 16,6         | 72/149/168 (n=135) | 165/184/239 (n=67)  | 39/49/56 (n=115)   | 32/56/116 (n=86)  | 31/38/39 (n=24)  | 35/68/88 (n=61)     | 74/97/106 (n=34)     | 65 / 297           | 24 / 35                |
| 50      | 1 457 / 28,6       | 80/158/199 (n=254) | 171/246/343 (n=124) | 40/70/137 (n=235)  | 33/53/204 (n=174) | 31/51/91 (n=62)  | 37/72/167 (n=106)   | 83/118/207 (n=28)    | 125 / 406          | 31 / 45                |
| 100     | 2 694 / 55,2       | 78/166/216 (n=547) | 177/252/337 (n=268) | 38/75/91 (n=423)   | 31/67/123 (n=312) | 29/58/80 (n=126) | 28/52/79 (n=207)    | 77/154/158 (n=29)    | 254 / 561          | 37 / 54                |

Comparación con 6.3 ("después"): rendimiento igual (54,0 → 55,2 peticiones/s con 100 agentes); p50 de las demás
operaciones dentro de ±10 ms. La vista **cola** de la bandeja quedó algo más lenta (p50 146–157 → 165–177 ms; perfil en
proceso 147,5 → 165,2 ms con 16 → 17 consultas); no se atribuye a un cambio concreto porque la diferencia es del orden
del ruido de la máquina. Los p99 de 10 agentes (566–761 ms) coinciden con el _load average_ máximo de toda la fase
(10,0). Aceptación disputada: 5 de 5 rondas con un solo ganador en cada nivel (3, 8, 15 y 30 contendientes, el resto
409); con 30 contendientes p50 85 ms, p99 138 ms (antes 66 / 116). Respuestas: 26, 86, 174 y 312 aceptadas por la API,
todas guardadas y entregadas por el transporte simulado. 0 _deadlocks_ y 0 trabajos fallidos. RSS pico: web 410–419 MB,
workers 770 MB (12 procesos), MariaDB 210–213 MB, Reverb 63 MB.

Perfil en proceso (corrida A, mediana de 3, ms / consultas): bandeja mías 62,0/18; cola 165,2/17; búsqueda 19,1/12;
supervisión todas 147,1/18 y cola 141,3/18; superadministración todas 146,2/43; abrir conversación 24,7–25,7/22–24;
historial 13,6–15,2/17; Dashboard supervisión 19,4/12; reportes 90 días 18,5/14.

### 10.5 WebSocket (corrida C, 200 conexiones, 600 suscripciones)

| Métrica                                             | Anterior (6.4 "después") | Ahora                               |
| --------------------------------------------------- | ------------------------ | ----------------------------------- |
| Conexión p50/p95/p99 (n = 200)                      | 1,6 / 21 / 23 ms         | 1,3 / 24 / 26 ms                    |
| Autorización p50/p95/p99 (n = 600)                  | 180 / 201 / 208 ms       | 191 / 217 / 224 ms                  |
| Las 200 conexiones listas                           | 2,2 s                    | 2,4 s                               |
| Difusión a la cola de Ventas: recibidas / esperadas | 500 / 500                | 500 / 500                           |
| Difusión: POST → `.inbox.changed` (p50 por ronda)   | 0,13–1,80 s              | 0,98–1,02 s                         |
| Suscripciones ajenas rechazadas                     | 20 de 20                 | 20 de 20                            |
| Tormentas de reconexión: todas listas en            | 2,1–2,2 s                | 2,3–2,4 s                           |
| Conexiones que no quedaron listas por tormenta      | 2, 11, 5 de 200          | 0, 12, 15 de 200 (`UND_ERR_SOCKET`) |
| CPU pico / RSS pico de Reverb                       | 15 % / 72 MB             | 15 % / 71 MB                        |

Las fallas de tormenta siguen siendo del cliente de prueba (Apache cierra conexiones _keep-alive_ con 600
autorizaciones simultáneas sobre 8 procesos PHP y el cliente no reintenta), como en 6.4.

### 10.6 Reinicio de Reverb con agentes conectados (corrida D, `reconnect`, escenario nuevo)

96 sockets (uno por agente con conversación abierta), un mensaje de cliente cada 500 ms.

| Métrica                                                          | Valor                                                         |
| ---------------------------------------------------------------- | ------------------------------------------------------------- |
| Parada de Reverb / puerto escuchando de nuevo                    | 60 ms / 379 ms desde la orden                                 |
| Sockets caídos / listos de nuevo / sin reconectar                | 96 / 96 / 0                                                   |
| Caída → socket listo (reconexión + 2 autorizaciones) p50/p95/p99 | 1 301 / 1 524 / 1 532 ms (n = 96; reintento cada ~1 s)        |
| Todos listos desde la orden de reinicio                          | 2,4 s                                                         |
| Webhooks durante el reinicio: HTTP 200                           | 5 de 5 (65 de 65 en total; la recepción no depende de Reverb) |
| Mensajes esperados / guardados / perdidos / duplicados           | 65 / 65 / 0 / 0                                               |
| Señales `.message.recorded` perdidas                             | 3 de 65 (las publicadas con Reverb parado)                    |
| Avisos "No se pudo publicar una señal de tiempo real" en el log  | 8 (4 `inbox.changed`, 2 `message.recorded`, 2 `notification`) |
| Trabajos fallidos                                                | 0                                                             |
| `.message.recorded` antes / después del reinicio p50/p95         | 850 / 1 352 ms (n = 19) → 947 / 1 322 ms (n = 40)             |

Las señales perdidas son el comportamiento diseñado (_best effort_): el cliente recarga al reconectar. El cliente de
prueba reintenta cada ~1 s; `pusher-js` en un navegador tarda unos 25 s en reconectar (medido en
`tests/e2e/realtime.mjs`), así que en navegadores la recuperación es más lenta que lo medido aquí.

### 10.7 Transferencias concurrentes con recepción de mensajes (corrida D, `transfers:200`, escenario nuevo)

200 transferencias en 20 carriles + 10 rondas disputadas, con 10 webhooks/s en esas mismas conversaciones.

| Métrica                                                                    | Valor                           |
| -------------------------------------------------------------------------- | ------------------------------- |
| Transferencias a agente: HTTP 302 / p50/p95/p99                            | 132 de 132 / 115 / 144 / 159 ms |
| Transferencias a cola de otro departamento: HTTP 302 / p50/p95/p99         | 68 de 68 / 114 / 137 / 147 ms   |
| Rondas disputadas con exactamente un ganador (302 + 409)                   | 10 de 10                        |
| Verificación en base: destino correcto y un evento `transferred` por éxito | 210 de 210                      |
| `.inbox.changed` al destino: agente p50/p95/p99 (n = 132)                  | 189 / 1 105 / 1 136 ms          |
| `.inbox.changed` al destino: cola p50/p95/p99 (n = 68, p99 ≈ máx.)         | 809 / 1 070 / 1 105 ms          |
| Señales de destino perdidas                                                | 0                               |
| Mensajes durante las transferencias: esperados / guardados / duplicados    | 100 / 100 / 0                   |
| _Deadlocks_ (InnoDB / reintentados / no recuperados)                       | 0 / 0 / 0                       |
| CPU web media/pico                                                         | 155 / 542 %                     |

### 10.8 Exportaciones durante la recepción (corridas C y D)

Las sondas de tiempo real (5 responsables, un mensaje por segundo) miden POST → `.message.recorded` por fase.

| Métrica                                               | Anterior (6.5)           | Ahora, 10 webhooks/s                                          | Ahora, 30 webhooks/s                                      |
| ----------------------------------------------------- | ------------------------ | ------------------------------------------------------------- | --------------------------------------------------------- |
| Conversaciones XLSX: filas / generación               | 10 100 / 10 s            | 10 116 / 10 s                                                 | 10 505 / 12 s                                             |
| Contactos XLSX: filas / generación / espera en cola   | 7 510 / 5 / 10 s         | 7 510 / 5 / 11 s                                              | 7 703 / 6 / 12 s                                          |
| Ambas listas (vista del usuario)                      | 16,3 s                   | 16,3 s                                                        | 18,3 s                                                    |
| Webhook HTTP p50/p95: base → durante → después        | 13,0/36,7 → 14,0/20,7 ms | 15,8/30,4 → 15,8/21,3 → 14,6/23,2 ms                          | 15,6/25,5 → 17,6/24,8 → 14,8/21,9 ms                      |
| Bandeja p50/p95: base → durante                       | 79,8/109 → 92,0/122,5 ms | 86,7/121,9 → 84,0/128,7 ms                                    | 101,3/153,3 → 107,2/175,8 ms                              |
| `.message.recorded` p50/p95: base → durante → después | no medido                | 614/1 295 (n=10) → 1 018/1 897 (n=17) → 1 057/1 453 (n=10) ms | 917/1 758 (n=10) → 826/1 665 (n=19) → 951/1 501 (n=10) ms |
| Procesamiento de webhooks (resolución 1 s) p95 / máx. | —                        | 1 / 1 s                                                       | 1 / 2 s                                                   |
| Mensajes recibidos / perdidos / duplicados            | 357 / 0 / 0              | 396 / 0 / 0                                                   | 1 140 / 0 / 0                                             |
| _Deadlocks_ (InnoDB / reintentados / no recuperados)  | 0                        | 0 / 0 / 0                                                     | 5 / 5 (`inbound.message`, 1.er intento) / 0               |
| Cola máxima `notifications` / `exports`               | —                        | 38 / 3                                                        | 638 / 3                                                   |
| RSS pico de workers (incluye el de exportación)       | 620 MB                   | 873 MB (12 procesos)                                          | 874 MB                                                    |

La exportación no retrasó la recepción ni la señal del mensaje: las latencias "durante" están dentro de la variación
entre "base" y "después" en ambos ritmos. Las dos exportaciones se generan una tras otra porque hay un solo worker de
`exports` (la segunda espera 11–12 s). A 30 webhooks/s aparecieron 5 _deadlocks_ al crear conversaciones de clientes
históricos, todos recuperados en el primer reintento.

### 10.9 Objetivos de latencia de [tiempo real](tiempo-real.md) frente a lo medido

Los objetivos se definen "con los workers de `realtime` activos y sin acumulación", desde el commit; aquí se mide desde
el POST del webhook o de la acción (incluye además la recepción y la cola `webhooks`), así que lo medido es una cota
superior.

| Objetivo (p95)                                             | Sin acumulación (medido)                                                                         | En ráfaga de 3 000 (con acumulación) |
| ---------------------------------------------------------- | ------------------------------------------------------------------------------------------------ | ------------------------------------ |
| Mensaje nuevo `.message.recorded` / `.inbox.changed` ≤ 2 s | **Cumple**: 1,88 s (10/s, n=30); 1,67–1,90 s durante exportaciones; 1,32 s tras reiniciar Reverb | No cumple: 2,46 s (n=29)             |
| Cambios de bandeja (transferencias) ≤ 2 s                  | **Cumple**: 1,10 s a agente (n=132), 1,07 s a cola (n=68)                                        | no medido                            |
| Campana `.notification` ≤ 5 s                              | **Cumple**: 1,79 s (n=30)                                                                        | No cumple: 7,97 s (n=29; máx. 31 s)  |

### 10.10 Defectos y hallazgos de esta fase

- **Ningún defecto de integridad en la aplicación:** 0 mensajes perdidos, 0 duplicados, 0 conversaciones abiertas
  duplicadas, 0 contactos duplicados, estados siempre en su valor final, un solo ganador en aceptaciones y
  transferencias disputadas, 0 trabajos fallidos y 0 _deadlocks_ sin recuperar en todas las corridas.
- **Arnés (corregido):** el contador `deadlock_mentions` de `run.php` contaba cualquier "1213" del log y dio 14 falsos
  positivos en `agents:50` (eran `"conversation_id":1213` de conflictos de aceptación). Ahora solo cuenta textos de
  error (`Deadlock found`, `SQLSTATE[40001]`, `General error: 1213`); lo mismo para 1205.
- **Arnés (no corregido, diferencia con producción):** la batería arranca todos los workers con
  `--sleep=1 --tries=3 --backoff=10`, mientras `deploy/supervisor` usa valores por cola (`realtime` `--backoff=2`,
  `notifications` `--backoff=5`, `media`/`default` `--sleep=2`, `exports` `--sleep=3 --tries=1`). No se cambió para
  mantener comparables las corridas; afecta poco a lo medido porque no hubo reintentos de trabajos.
- **Capacidad (no es un error):** en ráfaga, la campana depende de los 2 workers de `notifications` (p95 8 s); los
  mensajes y sus señales ya no esperan detrás de ella. El suelo de ~1 s de las señales sin carga viene de las dos colas
  de base de datos con `--sleep=1`.
- **Estado de la sección 8:** ya no aplican la acumulación de `notifications` como cuello del tiempo real ni el pool
  `notifications,default` (colas separadas) ni los _deadlocks_ en `notifications` (0 en la ráfaga). Siguen vigentes el
  limitador de 1 200 webhooks/min (300 respuestas 429 en la ráfaga) y las tormentas de 600 autorizaciones
  simultáneas sobre 8 procesos PHP.

### 10.11 Lo que no se midió

- Más de 100 agentes (el conjunto tiene 100) y más de 96 sockets en el reinicio de Reverb.
- Producción: nginx, Redis como cola, varios servidores o Reverb escalado, Meta real y navegadores reales.
- Latencia de Web Push (no hay suscriptor de prueba que la reciba).
- Desglose del tiempo de procesamiento por debajo de 1 s: `received_at`/`processed_at` tienen resolución de segundos.

## 11. Latencia de envío en desarrollo y producción

> **Medición local (2026-10-22), Apple M2 compartido (_load average_ 1,6–1,9), `chatmanager_testing` bajo el candado
> de pruebas, cola `database`, OPcache CLI desactivado, sin Meta.** Son tiempos de cola, no de extremo a extremo: el
> "envío" es un trabajo de sondeo que reproduce el abanico de `QueueOutboundMessage`/`DeliverOutboundMessage` (trabajo
> en `messages` + señal "recorded" en `realtime` + métricas en `default`; dentro del envío, señal "sending", 300 ms de
> proveedor simulado y señal "accepted"). 10 envíos por topología, separados 4–5,3 s (desarrollo) o 0,7 s durante una
> ráfaga (producción). Con n = 10 el máximo es prácticamente el p99.

### 11.1 Desarrollo (`composer run dev`, hallazgos LAT-01 y LAT-02)

Antes: un solo `queue:listen` para todas las colas (`--sleep=3` por omisión, un proceso PHP nuevo por trabajo, en
serie) y `artisan serve` con un solo proceso. Ahora: dos `queue:work` persistentes con `--sleep=1` para
`messages,realtime,webhooks` y `webhooks,realtime,messages`, un `queue:listen` para el resto y
`PHP_CLI_SERVER_WORKERS=4 php artisan serve --no-reload` (detalle y avisos en
[infraestructura](infraestructura-y-despliegue.md#9-colas-y-workers)).

| Desde que se encola (ms), p50 / máx.        | Antes (`listen`, sleep 3) | Solo `--sleep=1` en el `listen` | Ahora (`work` ×2 + `listen`) |
| ------------------------------------------- | ------------------------- | ------------------------------- | ---------------------------- |
| Empieza el trabajo de entrega               | 1 035 / 2 724             | 236 / 1 152                     | 291 / 644                    |
| Señal `.message.recorded` ejecutada         | 1 540 / 3 237             | 740 / 1 656                     | 309 / 638                    |
| Señal "sending" ejecutada                   | 1 694 / 3 392             | 896 / 1 810                     | 519 / 943                    |
| Señal "accepted" ejecutada (incluye 300 ms) | 1 849 / 3 545             | 1 051 / 1 966                   | 614 / 960                    |

Lectura: con `queue:listen` cada trabajo arranca PHP y Laravel (el diagnóstico midió ~0,17 s por arranque en vacío, sin OPcache), y las tres señales de un
envío esperan en fila detrás de la entrega; bajar solo `--sleep` a 1 deja ese coste. Los workers persistentes quitan
el arranque por trabajo y reparten las colas críticas entre dos procesos: la señal "accepted" queda ~0,3 s por encima
del tiempo del proveedor (antes ~1,5 s, hasta 3,2 s). El servidor con `PHP_CLI_SERVER_WORKERS=4` arranca 4 procesos
hijos (comprobado con `ps`); no se midió su efecto en milisegundos. Se comprobó también que `php artisan queue:restart`
detiene el worker y el bucle de shell lo vuelve a levantar en 1,3 s, y que la señal al grupo de procesos (como hace
multiplex al salir) termina el bucle y el worker.

### 11.2 Producción (grupo `critical`, hallazgo LAT-09)

4 workers del grupo `critical`, 2 de `realtime` y 1 de `default`, con las opciones de `deploy/supervisor`. Ráfaga de
600 trabajos en `webhooks` de 120 ms cada uno (≈ el ritmo de la ráfaga de 3 000 webhooks de la sección 10.2:
~18 s de cola con 4 workers) y, 2 s después, los 10 envíos.

| Desde que se encola (ms), p50 / máx. | `--queue=webhooks,messages` (antes) | `--queue=messages,webhooks` (ahora) |
| ------------------------------------ | ----------------------------------- | ----------------------------------- |
| Empieza el trabajo de entrega        | 14 305 / 17 554                     | 20 / 60                             |
| Señal "accepted" (incluye 300 ms)    | 15 324 / 17 882                     | 798 / 1 332                         |

Con el orden anterior un envío esperaba a que se vaciara toda la cola `webhooks`. Los envíos van primero porque son
pocos y cortos (los de los agentes y los mensajes automáticos, que los generan los propios webhooks), así que no dejan
sin turno a la recepción; en esta corrida los 10 envíos ocuparon ~3 s de worker frente a ~72 s de la ráfaga. No se
repitió la batería completa (`webhooks:3000` con envíos simultáneos); `LOAD_WORKERS` de `tests/load/run.php` aún usa
`webhooks,messages` por omisión.

### 11.3 Redis y `block_for` (hallazgo LAT-10)

La sección 10.3 suponía que Redis bajaría el suelo de ~1 s con espera bloqueante, pero `block_for` estaba en `null`:
Redis también consulta cada `--sleep=1`. Ahora es configurable (`REDIS_QUEUE_BLOCK_FOR`, vacío por omisión; no afecta
a la conexión `database`) y se deja vacío: Redis solo bloquea en la **primera** cola de cada worker, así que un worker
`messages,webhooks` ocioso tardaría hasta `block_for` segundos en ver un webhook. Solo conviene con workers de una sola
cola (por ejemplo `realtime`) y con todos los workers de esa conexión así. No se midió (no hay Redis en esta máquina).

### 11.4 Tramos externos (LAT-07 y LAT-08, sin cambio de código)

- **Llamada a Meta** (de "sending" a "accepted"): 1–4 s observados en los mensajes reales del entorno local; incluye
  DNS, TCP y TLS (`MetaGraphClient` crea un cliente HTTP por llamada, sin _keep-alive_ entre trabajos) y el
  procesamiento de Meta. Con workers persistentes el proceso ya no arranca de cero en cada envío; reutilizar la
  conexión solo se valorará si una medición por etapa muestra que el _handshake_ domina.
- **Entrega y webhooks de estado** (accepted → sent → delivered): 0–2 s observados; dependen de Meta, del dispositivo
  del cliente y, en desarrollo, del túnel de Cloudflare hasta `artisan serve`. En producción no hay túnel (nginx +
  PHP-FPM) y los workers de `webhooks` consultan cada segundo.
- No hay instrumentación por etapa en milisegundos (columnas `timestamp` de 1 s); separar Meta + túnel de la cola
  propia requiere medir el desfase `occurredAt` → `received_at` → `processed_at`.
