# Limitaciones y dependencias externas

Estado real de Chat Manager tras la fase de estabilización (2026-10-22): qué está probado, qué es simulado, qué
depende de una validación externa, qué se resolvió del informe anterior y qué sigue abierto. Cada módulo conserva el
detalle técnico en su documento; el registro completo de la fase (problema por problema, archivos y funciones
intervenidas) está en [estabilizacion-final.md](estabilizacion-final.md).

Convenciones:

- **Implementado y probado:** existe en el código y lo cubren pruebas automatizadas sobre MariaDB o escenarios de
  navegador en este repositorio.
- **Simulado:** se probó con dobles explícitamente etiquetados (webhooks generados, `Http::fake`, transporte
  `simulated`); el comportamiento real del proveedor no se observó.
- **Pendiente de validación externa:** necesita Meta real, servicios Web Push reales, una exportación real de
  Whaticket o un servidor real.
- **No implementado / trabajo futuro:** decisión consciente de no hacerlo en esta versión.

Algo que solo está documentado **no** figura como resuelto: «resuelto» significa cambio de código con prueba
automatizada.

## 1. Dependencias externas

| Dependencia                                             | Para qué                                                                                            | Sin ella                                                                                           |
| ------------------------------------------------------- | --------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------- |
| Meta: WhatsApp Cloud API (Graph)                        | Recibir y enviar mensajes, multimedia, plantillas                                                   | No hay mensajería real; `WHATSAPP_TRANSPORT=unavailable` falla cada envío de forma explícita       |
| App de Meta, WABA, número y token permanente            | Credenciales de cada conexión                                                                       | La conexión no se verifica, no se puede activar sin credenciales guardadas y no envía              |
| Dominio con HTTPS válido                                | Webhooks de Meta, cookies seguras, WSS, Web Push, historial cifrado                                 | Meta no acepta `localhost`; Web Push y el historial cifrado solo funcionan con HTTPS o `localhost` |
| MariaDB 11.4                                            | Base única, FULLTEXT y colas/caché/sesiones por defecto                                             | —                                                                                                  |
| Reverb (proceso supervisado)                            | Tiempo real                                                                                         | La interfaz sigue funcionando con sondeo cada 20 s                                                 |
| Workers de colas (6 grupos) y programador               | Webhooks, envíos, señales, notificaciones, multimedia, métricas, exportaciones                      | Los mensajes se acumulan; la salud del sistema y el preflight lo marcan                            |
| Servicios push del navegador (FCM, Mozilla, Apple, WNS) | Avisos con la plataforma cerrada                                                                    | Solo hay avisos con una pestaña abierta                                                            |
| Node, npm, Chromium y puppeteer                         | PDF de exportaciones                                                                                | Solo fallan los PDF; Excel sigue funcionando                                                       |
| SMTP                                                    | Restablecer contraseña y alertas                                                                    | No llegan correos                                                                                  |
| Monitor externo de `/health/ready`                      | Detectar una caída total                                                                            | La aplicación no puede avisar de su propia caída                                                   |
| Destino de copia externa (rsync/rclone)                 | Respaldos fuera del servidor                                                                        | Perder el servidor implica perder los respaldos                                                    |
| Redis (opcional)                                        | Escalar colas, caché y varias instancias de Reverb                                                  | Todo corre en `database`                                                                           |
| Sitio del cliente que inserta el widget                 | Chat web: fragmento, orígenes permitidos, CSP compatible y, si identifica, firma HMAC en su backend | El widget no monta en orígenes no listados ni con una CSP del sitio que bloquee `frame-src`        |

## 2. Implementado y probado

Conteos y comandos en [pruebas.md](pruebas.md) (suite completa: 1 661 pruebas, 10 562 aserciones; Pint, PHPStan
nivel 7, `vue-tsc` y build de producción sin errores).

- Bandeja de tres paneles, visibilidad por alcance, cola compartida, asignación manual y automática, transferencias,
  espera, cierre y reapertura con matriz de estados y versión; un solo ganador en aceptaciones disputadas.
- **Paginación por cursor sin tope**, búsqueda de contactos con FULLTEXT y prefijos telefónicos, **búsqueda en el
  contenido de los mensajes** y historial sin pérdidas por encima de 300 notas o eventos.
- **Capacidad** agente > departamento > plataforma, excepciones auditadas y redistribución acotada al liberarse
  capacidad; destinos de transferencia por elegibilidad; orden único de bloqueos.
- Webhooks: firma, verificación, tamaño, tasa, idempotencia por cuerpo y por `wamid`, orden y antigüedad de las
  actualizaciones de plantillas; entrega con reclamo bajo bloqueo y **revalidación del autor**; reintento manual.
- **Bloqueo de envío por conexión** en compositor, encolado, plantillas, reintentos y job, con señal en tiempo real
  `connection_changed`; «Entrega no confirmada» diferenciada y auditada al reintentar; piloto por destinatarios; tope
  de multimedia durante el _streaming_.
- Tiempo real con cola `realtime` dedicada y un solo evento por mensaje; señal de notificación publicada en el acto.
- Chat interno con **menciones, reacciones, «escribiendo…» y presencia en línea** por departamento.
- Notificaciones, horarios y mensajes automáticos; **Web Push** (firma VAPID, encabezados, respuestas 404/410/429/5xx,
  límite por usuario, limpieza) con pruebas automáticas; sin aviso duplicado pestaña + push.
- Reportes con primera atención humana, **tiempo hábil y objetivos de servicio**, horario de verano, PDF sin totales
  huérfanos y exportaciones coherentes con la pantalla; importación con trazabilidad y reversión.
- Seguridad: barrido de todas las rutas (Web Push incluido), autorizar antes de validar, regla 403/404 uniforme,
  exportaciones por POST con CSRF, límite por IP en recuperación, cierre de sesiones al cambiar la contraseña,
  historial de Inertia cifrado.
- Concurrencia con procesos PHP reales: READ COMMITTED compartido con reintento registrado y _upsert_ de notificaciones
  agregadas; en los escenarios medidos los deadlocks bajaron de 5–19 a 0–1 por escenario, sin pérdidas ni duplicados
  ([estabilizacion-final.md §4.2](estabilizacion-final.md#42-asignación-capacidad-y-concurrencia)).
- Preflight ampliado (Redis, colas, webhooks, fallidos, demo, Meta sin credenciales verificadas, disco, respaldos,
  Web Push) y plantillas de despliegue con pruebas de sintaxis, rutas de root y elección de release.
- Roles por defecto actualizados de forma auditada; simulador local con pruebas de cada rama.
- **Fase 4:** canal Chat web (widget en iframe con CSP `frame-ancestors` propia, sesiones de visitante, identidad
  firmada, tiempo real del visitante con respaldo por sondeo, adjuntos del agente al visitante), bandeja general con
  ambos canales, API de integración `/api/v1` con tokens propios, terminología única de estados y botón «Cerrar
  notificación» en los toasts. Cubierto por Pest (`tests/Feature/WebChat`, `tests/Feature/Api`, concurrencia del
  widget con procesos reales), E2E (`widget.mjs`, `bandeja-general.mjs`, `chat-movil.mjs`) y el escenario de carga
  `widget`. No se ejecutó la suite completa al cerrar la fase: queda para quien integra.

## 3. Simulado

- **Todo el tráfico de WhatsApp.** Mensajes y estados entrantes son cuerpos generados con el formato documentado por
  Meta y firmados con secretos desechables; los envíos usan `Http::fake`, el transporte `simulated` (IDs `sim.`,
  `metadata.simulated = true`, etiqueta «Simulación») o un transporte de registro en las pruebas de concurrencia. Con el
  transporte simulado, «Enviado», «Entregado» y «Leído» también son simulados. La etiqueta «Simulación» de un mensaje
  saliente aparece cuando llega el resultado de la entrega, no al confirmarse el envío.
- **Multimedia simulada** sin `--file`: queda «no disponible» (`simulated_media`).
- **Web Push:** los servicios push se sustituyen por respuestas HTTP falsas; el _service worker_ no se ejecutó en un
  navegador dentro de las pruebas automáticas.
- **Agentes de la batería de carga:** clientes HTTP sin navegador; los WebSockets de carga son clientes Node.
- **Concurrencia:** las peticiones web de las pruebas con procesos se sirven por el kernel HTTP con el usuario fijado,
  no por un servidor web; los workers de larga vida se representan con `queue:work` acotado.
- **Escenarios E2E** sin worker usan `QUEUE_CONNECTION=sync`; los assets vienen del servidor de Vite si existe
  `public/hot`.

## 4. Pendiente de validación externa

### 4.1 Meta real

Nunca se ha intercambiado un mensaje auténtico con Meta ([mensajeria-whatsapp.md §10](mensajeria-whatsapp.md#10-lo-que-no-se-ha-validado-contra-meta-real)):

- firma real de `X-Hub-Signature-256` y verificación GET con la app real;
- descarga desde `lookaside.fbsbx.com` (incluido el corte por tamaño durante el _streaming_), carga multipart a
  `/{phone-number-id}/media`;
- formato exacto de `subscribed_apps`, sincronización y webhooks de plantillas, aprobación real de plantillas;
- límites reales (429/130429), latencia y reintentos de Meta, y los textos de error de cURL del servidor de
  producción que distinguen «no llegó a Meta» de «ambiguo»;
- estados reales de entrega y lectura; los IDs de multimedia subida caducan a los 30 días;
- el piloto controlado (`WHATSAPP_PILOT_RECIPIENTS`) y el procedimiento de activación paso a paso
  ([§9](mensajeria-whatsapp.md#9-activación-real-paso-a-paso)) están escritos pero no ejecutados.

### 4.2 Web Push real

La entrega extremo a extremo contra FCM, Mozilla, Apple y WNS y el comportamiento del _service worker_ en navegadores
reales requieren claves VAPID y una prueba manual (HTTPS o `localhost`;
[guia-pruebas-manuales.md](guia-pruebas-manuales.md#17-notificaciones)).

### 4.3 Whaticket real

- El adaptador `whaticket-contacts` es heurístico y está marcado **pendiente de validación** hasta revisar una
  exportación auténtica ([migracion-whaticket.md](migracion-whaticket.md#validar-el-adaptador-con-una-exportación-real)).
- La importación de conversaciones y mensajes históricos requiere muestras reales anonimizadas.

### 4.4 Servidor real

- Nginx, PHP-FPM, Supervisor, systemd, logrotate, cron, sudoers y los scripts `deploy.sh`, `rollback.sh`,
  `prune-releases.sh`, `backup-*.sh`, `restore-*.sh` solo se validaron por sintaxis (`bash -n`; `shellcheck` no estaba
  disponible), `visudo -c` y pruebas sobre árboles temporales; nunca se ejecutaron en un VPS. Sin prueba automática:
  la escritura del marcador `FAILED` tras un rollback real (usa `mv -T` de GNU), la línea base de `/health/ready` y el
  `cache:forget` del veredicto en `deploy.sh`.
- WSS a través de Nginx hacia Reverb, CSP en modo producción, cookies `Secure` sobre https real, HSTS y proxies de
  confianza.
- Redis para colas, caché y escalado de Reverb; varias instancias web o de Reverb.
- MariaDB con historiales de cientos de miles de conversaciones; los planes de búsqueda se midieron con 8 000
  conversaciones y 40 000 mensajes.
- **Latencia y capacidad con la nueva topología de colas**: los objetivos (p95 ≤ 2 s para mensajes y bandejas, ≤ 5 s
  para la campana) son metas de diseño; las cifras medidas están en [rendimiento-y-carga.md](rendimiento-y-carga.md).
  Ninguna cifra de carga es una promesa de producción.
- La ventana de mantenimiento del despliegue (503 a los webhooks; Meta reintenta) no se ha medido.
- Restauración de respaldos y objetivos RPO/RTO: deben medirse en la primera verificación mensual.

## 5. Resuelto en la estabilización

Resumen de las limitaciones del informe anterior que ya no aplican (detalle, archivos e intervenciones en
[estabilizacion-final.md §4](estabilizacion-final.md#4-problemas-del-informe-anterior--estado--cómo-se-resolvió--archivos)):

| Limitación anterior                                                                                                                           | Cómo se resolvió                                                                                                |
| --------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------- |
| Búsqueda `LIKE` con comodín inicial; 250 filas cargadas como máximo; > 300 notas/eventos por página                                           | FULLTEXT de MariaDB, cursor _keyset_ sin tope, cursor `until` compuesto en el historial                         |
| Solo límite de carga global                                                                                                                   | Límite propio del agente y por departamento con precedencia y redistribución                                    |
| Un agente asignado no podía transferir a un compañero concreto                                                                                | Endpoint de destinos por elegibilidad                                                                           |
| Chat interno sin presencia, «escribiendo…», reacciones ni menciones                                                                           | Implementados, con presencia acotada por departamento                                                           |
| Sin Web Push / _service worker_                                                                                                               | Web Push VAPID sin carga útil ni dependencias (sin validar con servicios reales, §4.2)                          |
| Reportes sin tiempo hábil ni SLA; desfase horario fijo por periodo                                                                            | Tiempo hábil congelado, objetivos globales y agrupación por el desfase vigente en cada fecha                    |
| Seguridad: historial de Inertia sin cifrar, recuperación sin límite por IP, sesiones abiertas al cambiar la contraseña, exportaciones por GET | Cifrado y `clearHistory`, limitador `password-reset`, `closeSessions()`, POST con CSRF                          |
| Cola `notifications` compartida por señales y notificaciones (15–35 s en ráfagas)                                                             | Cola y workers `realtime` propios, un solo evento por mensaje, sin trabajos vacíos (latencia nueva en medición) |
| Deadlocks residuales en `InAppChannel`                                                                                                        | _Upsert_ atómico en READ COMMITTED con reintento registrado                                                     |
| Conexión pausada: el compositor permitía escribir                                                                                             | Bloqueo con motivo en el compositor y 409 en el servidor                                                        |
| Descarga de multimedia sin tope en _streaming_                                                                                                | Corte en el primer bloque que excede el máximo                                                                  |
| Workers con conexión `database` fija y grupo `notifications,default`                                                                          | Workers sin conexión fija, un grupo por cola                                                                    |

## 6. No implementado / trabajo futuro

| Área           | Pendiente                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| -------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Mensajería     | Crear o editar plantillas desde la app; cálculo de costos; comparar el `sha256` de Meta; plantillas OTP, Flow, catálogo, carrusel y ofertas; encabezados multimedia sin URL pública; **alerta proactiva** por umbral de `delivery_unknown` (hoy solo conteo en la ficha de la conexión); guarda de orden para las actualizaciones de **calidad** de plantillas (no hay marca de tiempo propia)                                                                                                                                                             |
| Bandeja        | Búsqueda por subcadena dentro de una palabra o por sufijo de teléfono; fragmento coincidente en la búsqueda de mensajes; búsqueda en notas; índice para la prioridad con `status=all` (columna `status_rank`); el expediente exportado incluye como máximo 500 eventos y 500 notas                                                                                                                                                                                                                                                                         |
| Asignación     | Reglas de enrutamiento por contenido, menús o bots; reabrir no mira la capacidad del responsable anterior; el reintento de `delivery_unknown` sigue reservado al responsable con la conversación abierta (decisión de producto)                                                                                                                                                                                                                                                                                                                            |
| Chat interno   | Búsqueda con índice de texto completo (sigue `LIKE`, 20 resultados con aviso); más de 500 chats en la lista; exportación (decisión de privacidad) y retención automática; vista previa en línea de audio y video                                                                                                                                                                                                                                                                                                                                           |
| Notificaciones | Alertas operativas en el centro de notificaciones (hoy log y correo); Web Push en iOS/iPadOS (requiere PWA con _manifest_); título del push con el nombre configurable de la plataforma                                                                                                                                                                                                                                                                                                                                                                    |
| Reportes       | Objetivos de servicio por departamento; turnos que crucen medianoche, varios tramos por día y festivos por departamento; calificar contra el SLA conversaciones aún sin respuesta o sin cierre; historial de quién tuvo cada conversación; atribución de mensajes por su fecha; XLSX en _streaming_                                                                                                                                                                                                                                                        |
| Operación      | Panel de métricas históricas (Horizon/Pulse no instalados); replicación o respaldos incrementales para RPO < 24 h; ver los procesos de Supervisor desde el preflight (se detectan por la acumulación de las colas)                                                                                                                                                                                                                                                                                                                                         |
| Migración      | Importador de tickets y mensajes de Whaticket; revertir desde un lote anterior un contacto modificado por uno posterior                                                                                                                                                                                                                                                                                                                                                                                                                                    |
| Chat web       | **Adjuntos que sube el visitante** (requieren cuotas antiabuso y antivirus; hoy solo el agente envía archivos); CAPTCHA o desafío anti-bot (hoy: trampa, límites y contacto solo con el primer mensaje); acción visible para que el visitante termine el chat (la API `DELETE /session` existe); `locale` del widget (solo español); botón con estilos si la CSP del sitio no permite `'unsafe-inline'` en `style-src`; página de llegada, referrer y UTM en Detalles o Atribución (solo `landing_url` en la sesión); segunda app de Reverb para el widget |
| API v1         | Webhooks salientes hacia la integración (hoy se sondea con `updated_since` y `after`); envío de plantillas o adjuntos por la API; restringir el envío por la API a conversaciones sin asignar o en atención (pregunta abierta: hoy se permite en cualquier conversación no cerrada de la allowlist, sin cambiar la asignación)                                                                                                                                                                                                                             |
| Datos          | Fecha y autor de eliminación en las entidades con `is_active` (decisión: la auditoría es la fuente; [ADR-SEC-8](decisiones.md#adr-sec-8--eliminación-lógica-heterogénea-con-la-auditoría-como-fuente))                                                                                                                                                                                                                                                                                                                                                     |

## 7. Riesgos residuales y comportamientos observados

Detalle y mitigaciones en [seguridad-y-auditoria.md §7](seguridad-y-auditoria.md#7-riesgos-residuales-y-recomendaciones)
y [rendimiento-y-carga.md](rendimiento-y-carga.md).

- **Interbloqueos residuales documentados:** dos ciclos del motor de asignación (lectura de la carga después de la
  fila del agente y `ReleaseAssignedConversations`), tres o más mensajes simultáneos del mismo contacto nuevo y
  entradas _delete-marked_ de índices únicos. MariaDB revierte una transacción completa; el reintento o una respuesta
  controlada lo resuelven sin pérdidas. `NotificationCenter::markAllRead` sigue en REPEATABLE READ con su propio
  reintento. Ver los deadlocks con detalle exige `innodb_print_all_deadlocks` (privilegios de administrador).
- **READ COMMITTED y reconexión:** si Laravel reconecta entre el `SET TRANSACTION` y el `BEGIN`, ese intento corre en
  REPEATABLE READ (sigue siendo correcto). Un `DB::transaction` anidado dentro del _callback_ no se reintentaría.
- **Contacto manual simultáneo:** con la recepción en READ COMMITTED, un contacto creado a mano con el mismo teléfono
  en el mismo instante puede quedar como posible duplicado (el directorio ya lo admite).
- **Señales del chat interno durante una caída de Reverb:** siguen siendo difusiones en cola (cola `realtime`);
  fallan como trabajos y pueden disparar la alerta de fallidos. Las de bandejas y notificaciones son _best effort_.
  «Escribiendo…» puede retrasar su propia petición si Reverb está lento.
- **Revocación en tiempo real:** el protocolo no permite expulsar a un suscriptor; entre el cambio y la
  re-autorización solo pueden llegar IDs. Las señales de bandejas de equipo pueden anunciar IDs de conversaciones
  cerradas a quien no tiene permiso de historial (riesgo aceptado).
- **Presencia:** quien llega a todos no ve en línea a usuarios departamentales de otros departamentos; es por pestaña
  y Reverb tarda unos segundos en notar una desconexión abrupta.
- **Web Push:** al cerrar sesión la suscripción se conserva (avisos genéricos sin datos); en un equipo compartido el
  aviso puede mostrar los contadores de quien tenga la sesión abierta.
- **Sesiones:** con un driver de sesión distinto de `database`, cambiar o restablecer la contraseña no cierra las
  sesiones abiertas sin «recordarme». Un administrador que cambia el correo de **otro** usuario lo deja verificado.
- **Credenciales cifradas:** comprobar si una conexión tiene credenciales descifra el token una vez por fila; rotar
  `APP_KEY` sin `previous_keys` provocaría errores 500 en la bandeja.
- **Roles:** `--upgrade-default-roles` vuelve a añadir permisos de la definición que un administrador quitó a
  propósito; revisar siempre la vista previa.
- **Dependencias:** 5 alertas de `npm audit` solo de desarrollo (`vitest` 4.1.11 y `vite-plus` ≤ 1.0.0; nada llega al
  build); no exponer `npm run dev` a la red. Paquetes sin uso pendientes de eliminar con aprobación.
- **Conteos por estado de la bandeja** y la búsqueda por teléfono en la bandeja crecen de forma lineal con el
  historial visible; la lista de la superadministración tarda ~120 ms de PHP para 25 filas.
- **Limitador del webhook** (1 200/min por conexión) responde 429 en ráfagas; Meta reintenta, pero retrasa la atención.
- **Reconexión de Reverb:** pusher-js tarda ~25 s en reconectar tras un reinicio; mientras tanto las páginas sondean.
- **Exportación consistente:** `ReportExporter::render()` mantiene abierta una transacción de lectura durante toda la
  exportación; el límite de filas XLSX por celdas solo cuenta las columnas de la tabla principal.
- **Check-then-send:** un mensaje cuyo envío ya se decidió puede salir aunque la conexión se elimine durante la llamada
  de red.
- **PDF en vista previa sin `sandbox`**; diagnóstico de conexiones visible para `connections.view` (último error
  saneado).
- **Inertia DevTools** con `APP_ENV=local` expone las props registradas sin autenticación; nunca usar `local` con
  datos reales.
- **Prueba de preparación:** con la caché caída responde 5xx en lugar del JSON `fail`; el veredicto se cachea 10 s
  (`deploy.sh` lo borra tras `artisan up`).
- **Batería de carga:** su lanzador sigue fijando la conexión `database` y `--tries=3` en todos sus workers (también en
  exportaciones, que en producción usan `--tries=1`).
- **Límite del framework bajo simultaneidad exacta:** `ThrottleRequests` comprueba y después cuenta sin atomicidad
  entre procesos. Con 10 procesos simultáneos sobre un límite fresco de 30 pasaron 25 de 30; dos procesos de 15
  envíos reciben exactamente 20 aceptados y 10 respuestas 429, y con el límite ya gastado 10 de 10 son 429
  (`tests/Feature/Concurrency/WebWidgetConcurrencyTest.php`).
- **Cuota de Reverb compartida:** los visitantes del chat web usan la misma app y `max_connections` que los agentes.
- **Origen del visitante:** Origin y Referer se pueden falsificar fuera de un navegador; la barrera real es la CSP
  `frame-ancestors`. Un script que copie el ticket de un frame autorizado puede crear sesiones durante 600 s, acotadas
  por los límites por IP y por widget.
- **Tiempo real del widget:** verificado con el sondeo de respaldo y en E2E con `E2E_REVERB=1`; la batería de carga del
  widget se midió con 40 visitantes y 5 agentes sobre 1 000 conversaciones, no con el conjunto de 10 000.
- **Frontend:** sin pruebas de componentes Vue (diálogo de transferencia, carrera «Cargar más» / recarga en vivo,
  autocompletado de menciones); los cubren el backend, `vue-tsc`, los E2E y la prueba manual.

## 8. Antes de producción

1. Decidir si el Administrador recibe `system.view` / `system.manage`
   ([seguridad-y-auditoria.md §3.1](seguridad-y-auditoria.md#31-permisos-por-rol-tras-la-actualización-de-la-estabilización)).
2. En el servidor, con respaldo previo y en una ventana de bajo tráfico, aplicar las migraciones (aditivas; el primer
   FULLTEXT reconstruye la tabla), ejecutar `php artisan app:sync-permissions`, revisar y aplicar
   `--upgrade-default-roles` si corresponde y `php artisan analytics:rebuild` una vez. En la base local `chatmanager`
   esto ya se hizo el 2026-10-22 ([estabilizacion-final.md §2](estabilizacion-final.md#2-acciones-sobre-la-base-local-chatmanager)).
3. Generar las claves VAPID (`php artisan webpush:vapid`) y guardarlas con el `.env`.
4. Ejecutar la suite completa y `npm run build`.
5. Seguir la activación real de Meta ([mensajeria-whatsapp.md §9](mensajeria-whatsapp.md#9-activación-real-paso-a-paso))
   con un número de prueba y `WHATSAPP_PILOT_RECIPIENTS`.
6. Validar el adaptador de Whaticket con una exportación real antes de importar.
7. Desplegar con [infraestructura-y-despliegue.md](infraestructura-y-despliegue.md), pasar `php artisan app:preflight`
   sin bloqueantes, activar la copia externa de respaldos y restaurar un respaldo de prueba
   ([recuperacion-y-respaldos.md](recuperacion-y-respaldos.md)).
8. Repetir la batería de carga en el servidor con la nueva topología de workers, incluido el escenario `widget`.
9. **Chat web y API v1:** configurar `TRUSTED_PROXIES` (Preflight lo exige con un chat web activo), añadir la caché del
   cargador en Nginx ([widget-integracion.md §8](widget-integracion.md#8-caché-del-cargador)), confirmar que el
   despliegue ejecuta `npm run build` (genera `public/widget`), ejecutar `php artisan app:sync-permissions` (permisos
   `integrations.*`), y decidir con legal el texto del aviso de privacidad y la retención de sesiones y contactos web.
   Revisar la cuota de Reverb antes de un volumen alto de visitantes. Los expedientes exportados usan ya las etiquetas
   nuevas de estado (Enviando, Fallido, Entrega no confirmada): avisar a quien compare con exportaciones anteriores.
