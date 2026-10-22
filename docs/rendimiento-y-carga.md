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

## 2. Entorno medido

| Elemento                | Valor                                                                                                                                                                                                             |
| ----------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Máquina                 | Apple M2, 8 núcleos (4 de rendimiento + 4 de eficiencia), 24 GB RAM, macOS (Darwin 25.0.0)                                                                                                                        |
| Compartida              | Sí: otros procesos de desarrollo (pruebas E2E y Pest de otros módulos) corrían a la vez; el _load average_ llegó a 4–7 en algunas corridas. Los números tienen ese ruido.                                         |
| PHP                     | 8.4.24 (OPcache activo en FPM)                                                                                                                                                                                    |
| Base de datos           | MariaDB 11.4.13 local, `innodb_buffer_pool_size` = 128 MB (valor por defecto de Homebrew), aislamiento por defecto REPEATABLE READ                                                                                |
| Servidor web            | Apache httpd 2.4.62 de macOS (MPM event) + PHP-FPM `pm=static` con **8 procesos** (`LOAD_SERVER=fpm`, por defecto). Comparación: servidor integrado de PHP con `PHP_CLI_SERVER_WORKERS=8` (`LOAD_SERVER=builtin`) |
| Workers                 | Distribución de `deploy/supervisor`: 4 × `queue:work --queue=webhooks,messages`, 1 × `media`, 2 × `notifications,default`, 1 × `exports` (`--sleep=1 --tries=3 --backoff=10`)                                     |
| Cola / caché / sesiones | `database` (tabla `jobs`), `database`, `database`. **Sin Redis** (no está instalado en esta máquina)                                                                                                              |
| Tiempo real             | Reverb 1 proceso, protocolo pusher, autorización real por `/broadcasting/auth`                                                                                                                                    |
| WhatsApp                | **SIMULACIÓN**: `WHATSAPP_TRANSPORT=simulated`; webhooks firmados (`X-Hub-Signature-256`) con secretos desechables de conexiones de prueba                                                                        |
| Cliente de carga        | Node.js 24.16 (fetch y WebSocket nativos), un solo proceso                                                                                                                                                        |
| Entorno de la app       | `APP_ENV=testing`, `APP_DEBUG=false`, `BCRYPT_ROUNDS=4` (solo abarata el inicio de sesión de la preparación)                                                                                                      |

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
  (`"webhooks,messages:4;media:1;notifications,default:2;exports:1"`), `LOAD_CONVERSATIONS` (10000),
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

| Escenario       | Qué hace                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    |
| --------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `profile`       | En proceso: cada página/endpoint principal 3 veces por rol (agente, supervisión, superadministración); mediana, número de consultas, tiempo SQL, consultas repetidas (candidatas a N+1) y `EXPLAIN` de las más lentas.                                                                                                                                                                                                                                                                      |
| `webhooks[:n]`  | `n` webhooks firmados en 20 carriles concurrentes, sin pausa: clientes nuevos (algunos con 3 mensajes simultáneos), clientes históricos, mensajes a conversaciones abiertas, duplicados exactos (también dos copias a la vez), reenvíos del mismo `wamid` con otro sobre, y estados en orden inverso (`read`, `delivered`, `sent`). Como Meta, reintenta con espera los 429/5xx. 30 sondas miden de punta a punta el tiempo del POST a la señal `.message.recorded` en un socket de Reverb. |
| `agents[:n]`    | `n` agentes (más 4 supervisores y el administrador viendo reportes) durante `LOAD_DURATION` s con pausa de 1–3 s entre acciones: bandeja, cola, abrir conversación, historial, marcar leído, responder (transporte simulado), aceptar de la cola, Dashboard y reportes con filtros. Antes, 5 rondas de aceptación disputada: todos los agentes del departamento aceptan la misma conversación al mismo instante. Tráfico de fondo de 2 webhooks/s.                                          |
| `websocket[:n]` | `n` conexiones pusher (varias pestañas por agente) suscritas a `users.{id}`, `inbox.queues.{dep}` y una conversación propia; difusión de 10 conversaciones nuevas a la cola de Ventas; 20 suscripciones a conversaciones ajenas (deben rechazarse); 3 tormentas de reconexión (todas cierran y reconectan a la vez).                                                                                                                                                                        |
| `export`        | 10 s de línea base y luego exportación XLSX de conversaciones (historial completo) y contactos mientras entran 10 webhooks/s y 5 agentes usan la bandeja.                                                                                                                                                                                                                                                                                                                                   |

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
