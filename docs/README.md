# Documentación de Chat Manager

Chat Manager es una bandeja multiagente para WhatsApp Business sobre la API oficial de WhatsApp Cloud de Meta y, desde
la fase 4, para un canal de **Chat web** insertable en sitios externos, con una API de integración `/api/v1`. Incluye
departamentos, asignación, tiempo real, chat interno, reportes y exportaciones. Esta carpeta contiene un documento por
área; cada tema se explica en un solo lugar y los demás lo enlazan.

> La integración con Meta (WhatsApp y, desde la fase 5, Messenger e Instagram) está implementada pero **no se ha
> probado contra Meta real**: todo el tráfico de las pruebas es simulado. Ver [limitaciones.md](limitaciones.md).

## Documentos

| Documento                                                          | Contenido                                                                                                                |
| ------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------ |
| [arquitectura.md](arquitectura.md)                                 | Arquitectura, módulos, modelo de datos, flujos, estados, disponibilidad, colas, ayuda por módulo, bitácora legible       |
| [bandejas-y-asignacion.md](bandejas-y-asignacion.md)               | Bandeja de tres paneles, compositor, búsqueda y paginación, visibilidad, distribución, asignación, capacidad             |
| [mensajeria-whatsapp.md](mensajeria-whatsapp.md)                   | Webhooks, conexiones, mensajes entrantes y salientes, multimedia, plantillas, simulador, activación real                 |
| [canal-chat-web.md](canal-chat-web.md)                             | Canal Chat web: arquitectura, modelo, configuración, ciclo del visitante, identidad firmada, privacidad                  |
| [meta-messenger-instagram.md](meta-messenger-instagram.md)         | Facebook Messenger e Instagram: autorización OAuth, webhook de la app, envío, permisos, App Review, validación pendiente |
| [widget-integracion.md](widget-integracion.md)                     | Insertar el widget: fragmento, API JS, jQuery, `postMessage`, CSP del sitio, móviles, caché, tamaños, demo               |
| [api-v1.md](api-v1.md) y [openapi/v1.json](openapi/v1.json)        | API `/api/v1`: integración y visitante, tokens, capacidades, errores, paginación, límites, idempotencia                  |
| [tiempo-real.md](tiempo-real.md)                                   | Reverb, canales privados y de presencia, eventos, cola `realtime`, reconexión y respaldo por sondeo                      |
| [chat-interno.md](chat-interno.md)                                 | Chat entre empleados: privacidad, grupos, menciones, reacciones, «escribiendo…», presencia, adjuntos                     |
| [notificaciones-y-horarios.md](notificaciones-y-horarios.md)       | Notificaciones, Web Push, horario de atención, días no laborables, mensajes automáticos                                  |
| [reportes-y-exportaciones.md](reportes-y-exportaciones.md)         | Métricas, tiempo hábil y objetivos, reportes, panel principal, motor de exportaciones XLSX/PDF                           |
| [migracion-whaticket.md](migracion-whaticket.md)                   | Importación de contactos, adaptador Whaticket, trazabilidad y reversión por lote                                         |
| [seguridad-y-auditoria.md](seguridad-y-auditoria.md)               | Modelo de amenazas, controles, permisos por rol, bitácora, privacidad, hallazgos, riesgos y dependencias                 |
| [pruebas.md](pruebas.md)                                           | Pest sobre MariaDB, análisis estático, E2E, concurrencia, carga, real frente a simulado                                  |
| [guia-pruebas-manuales.md](guia-pruebas-manuales.md)               | Guía práctica de pruebas manuales por módulo (fase 5 en §29), con cuentas, simulador y resultados esperados              |
| [rendimiento-y-carga.md](rendimiento-y-carga.md)                   | Batería de carga y resistencia, resultados medidos y cuellos de botella                                                  |
| [infraestructura-y-despliegue.md](infraestructura-y-despliegue.md) | Servidor, variables, Nginx, colas y workers, Reverb, salud, alertas, preflight, despliegue (fase 5 incluida), PWA        |
| [recuperacion-y-respaldos.md](recuperacion-y-respaldos.md)         | Respaldos de base y archivos, copia externa, verificación, restauración                                                  |
| [limitaciones.md](limitaciones.md)                                 | Dependencias externas; probado, simulado, pendiente de validación, resuelto y trabajo futuro                             |
| [estabilizacion-final.md](estabilizacion-final.md)                 | Registro de la fase de estabilización: problemas del informe anterior, estado, cómo se resolvió y archivos               |
| [decisiones.md](decisiones.md)                                     | Registro de decisiones (ADR) por módulo                                                                                  |

## Índice de temas obligatorios

| #   | Tema                                 | Dónde                                                                                                                                                                                                                                                                           |
| --- | ------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | Arquitectura general                 | [arquitectura.md §1](arquitectura.md#1-arquitectura-general)                                                                                                                                                                                                                    |
| 2   | Mapa de módulos                      | [arquitectura.md §2](arquitectura.md#2-mapa-de-módulos)                                                                                                                                                                                                                         |
| 3   | Modelo de datos                      | [arquitectura.md §3](arquitectura.md#3-modelo-de-datos); tablas propias en [chat-interno.md](chat-interno.md#modelo-de-datos), [notificaciones](notificaciones-y-horarios.md#11-modelo) y [reportes §4](reportes-y-exportaciones.md#4-arquitectura-de-agregación)               |
| 4   | Flujo de mensajes entrantes          | [arquitectura.md §4](arquitectura.md#4-flujo-de-mensajes-entrantes) y [mensajeria-whatsapp.md §3](mensajeria-whatsapp.md#3-mensajes-entrantes)                                                                                                                                  |
| 5   | Flujo de mensajes salientes          | [arquitectura.md §5](arquitectura.md#5-flujo-de-mensajes-salientes) y [mensajeria-whatsapp.md §5.2](mensajeria-whatsapp.md#52-entrega-deliveroutboundmessage-cola-messages)                                                                                                     |
| 6   | Webhooks y validación                | [mensajeria-whatsapp.md §2](mensajeria-whatsapp.md#2-conexiones-y-webhooks); controles en [seguridad §2](seguridad-y-auditoria.md#webhook-de-whatsapp)                                                                                                                          |
| 7   | Conexiones de WhatsApp               | [mensajeria-whatsapp.md §2](mensajeria-whatsapp.md#2-conexiones-y-webhooks) (administración, salud, bloqueo de envío y diagnóstico) y [§9 activación real](mensajeria-whatsapp.md#9-activación-real-paso-a-paso)                                                                |
| 8   | Estados de mensajes                  | [arquitectura.md §8](arquitectura.md#8-estados-de-mensajes) y [mensajeria-whatsapp.md §5.1](mensajeria-whatsapp.md#51-estados)                                                                                                                                                  |
| 9   | Bandejas y permisos                  | [bandejas-y-asignacion.md §1–§2 y §8](bandejas-y-asignacion.md#2-visibilidad-requisito-innegociable); búsqueda y paginación en [bandejas §1](bandejas-y-asignacion.md#búsqueda-y-paginación); permisos por rol en [seguridad §3](seguridad-y-auditoria.md#3-modelo-de-permisos) |
| 10  | Distribución entre departamentos     | [bandejas-y-asignacion.md §3–§4](bandejas-y-asignacion.md#3-distribución-entre-departamentos) (incluye capacidad y destinos de transferencia)                                                                                                                                   |
| 11  | Disponibilidad de agentes            | [arquitectura.md §11](arquitectura.md#11-disponibilidad-de-agentes) y [bandejas §5](bandejas-y-asignacion.md#5-ausencias-y-cambios-de-acceso)                                                                                                                                   |
| 12  | Chat interno                         | [chat-interno.md](chat-interno.md)                                                                                                                                                                                                                                              |
| 13  | WebSockets                           | [tiempo-real.md](tiempo-real.md); avisos con la plataforma cerrada en [notificaciones §1.8](notificaciones-y-horarios.md#18-web-push-avisos-con-la-plataforma-cerrada)                                                                                                          |
| 14  | Adjuntos                             | Clientes: [bandejas §7](bandejas-y-asignacion.md#7-archivos-adjuntos) y [mensajeria-whatsapp.md §4](mensajeria-whatsapp.md#4-multimedia); chat interno: [chat-interno.md](chat-interno.md#adjuntos); controles en [seguridad](seguridad-y-auditoria.md#archivos-y-multimedia)   |
| 15  | Plantillas de Meta                   | [mensajeria-whatsapp.md §7](mensajeria-whatsapp.md#7-plantillas)                                                                                                                                                                                                                |
| 16  | Reportes                             | [reportes-y-exportaciones.md §1–§5](reportes-y-exportaciones.md#1-qué-ofrece)                                                                                                                                                                                                   |
| 17  | Exportaciones                        | [reportes-y-exportaciones.md §6](reportes-y-exportaciones.md#6-exportaciones-motor-existente)                                                                                                                                                                                   |
| 18  | Migración desde Whaticket            | [migracion-whaticket.md](migracion-whaticket.md)                                                                                                                                                                                                                                |
| 19  | Seguridad y auditoría                | [seguridad-y-auditoria.md](seguridad-y-auditoria.md)                                                                                                                                                                                                                            |
| 20  | Pruebas                              | [pruebas.md](pruebas.md), [guia-pruebas-manuales.md](guia-pruebas-manuales.md) y [rendimiento-y-carga.md](rendimiento-y-carga.md)                                                                                                                                               |
| 21  | Infraestructura                      | [infraestructura-y-despliegue.md §1–§15](infraestructura-y-despliegue.md#1-componentes-y-procesos)                                                                                                                                                                              |
| 22  | Procedimiento de despliegue          | [infraestructura-y-despliegue.md §16–§18](infraestructura-y-despliegue.md#16-despliegue)                                                                                                                                                                                        |
| 23  | Recuperación y respaldos             | [recuperacion-y-respaldos.md](recuperacion-y-respaldos.md)                                                                                                                                                                                                                      |
| 24  | Limitaciones y dependencias externas | [limitaciones.md](limitaciones.md) y el registro de la fase [estabilizacion-final.md](estabilizacion-final.md)                                                                                                                                                                  |

### Fase 4: chat web y API de integración (§16)

| #   | Tema                        | Dónde                                                                                                                                                                                                                                                              |
| --- | --------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| 1   | Arquitectura del canal      | [canal-chat-web.md §1](canal-chat-web.md#1-arquitectura-del-canal) y [§2 Modelo](canal-chat-web.md#2-modelo); decisiones [ADR-WEB-1 a 4](decisiones.md#adr-web-1--widget-en-iframe-servido-por-chat-manager)                                                       |
| 2   | Instalación                 | [widget-integracion.md §1](widget-integracion.md#1-instalación)                                                                                                                                                                                                    |
| 3   | Ejemplo JavaScript          | [widget-integracion.md §2](widget-integracion.md#2-api-javascript)                                                                                                                                                                                                 |
| 4   | Ejemplo jQuery              | [widget-integracion.md §3](widget-integracion.md#3-adaptador-jquery)                                                                                                                                                                                               |
| 5   | Configuración de conexiones | [canal-chat-web.md §3](canal-chat-web.md#3-configuración-de-conexiones)                                                                                                                                                                                            |
| 6   | API v1                      | [api-v1.md](api-v1.md) y [openapi/v1.json](openapi/v1.json)                                                                                                                                                                                                        |
| 7   | Autenticación y seguridad   | [api-v1.md §3](api-v1.md#3-autenticación-y-seguridad), [seguridad §Chat web y API v1](seguridad-y-auditoria.md#chat-web-y-api-v1), [widget-integracion.md §5–§6](widget-integracion.md#5-aislamiento) e [identidad firmada](canal-chat-web.md#5-identidad-firmada) |
| 8   | Permisos y tokens           | [api-v1.md §2](api-v1.md#2-tokens-de-integración) y [seguridad §3](seguridad-y-auditoria.md#3-modelo-de-permisos)                                                                                                                                                  |
| 9   | WebSocket y reconexión      | [tiempo-real.md §Canal del visitante](tiempo-real.md#canal-del-visitante-del-chat-web)                                                                                                                                                                             |
| 10  | Errores                     | [api-v1.md §4](api-v1.md#4-respuestas-y-errores) y [widget-integracion.md §11](widget-integracion.md#11-problemas-frecuentes)                                                                                                                                      |
| 11  | Límites                     | [api-v1.md §8](api-v1.md#8-límites) y [canal-chat-web.md §4](canal-chat-web.md#política-de-inactividad-wc-09)                                                                                                                                                      |
| 12  | Pruebas manuales            | [guia-pruebas-manuales.md §28](guia-pruebas-manuales.md#28-chat-web-e-integraciones); automáticas en [pruebas.md §2.2 y §6.2](pruebas.md#22-pruebas-añadidas-en-la-fase-4)                                                                                         |
| 13  | Infraestructura             | [infraestructura-y-despliegue.md §5, §6 y §10](infraestructura-y-despliegue.md#10-reverb) y [widget-integracion.md §8](widget-integracion.md#8-caché-del-cargador)                                                                                                 |
| 14  | Producción                  | [infraestructura-y-despliegue.md §16](infraestructura-y-despliegue.md#16-despliegue) y [limitaciones.md §8](limitaciones.md#8-antes-de-producción)                                                                                                                 |

### Fase 5: operación, personalización, canales de Meta y PWA

| Tema                                     | Dónde                                                                                                                                                                                                      |
| ---------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Bandeja: indicadores, filtros, etiquetas | [bandejas-y-asignacion.md §1](bandejas-y-asignacion.md#vista-general-chips-y-contadores) y [filtro por etiquetas](bandejas-y-asignacion.md#filtro-por-etiquetas)                                           |
| «En espera» y disponibilidad retirados   | [bandejas §5](bandejas-y-asignacion.md#5-ausencias-y-cambios-de-acceso) y [arquitectura §11](arquitectura.md#11-disponibilidad-de-agentes)                                                                 |
| Permisos nuevos y supervisión            | [seguridad §3.2](seguridad-y-auditoria.md#32-permisos-nuevos-de-la-fase-5) y [§5.1 elementos personales](seguridad-y-auditoria.md#51-elementos-personales-alias-apariencia-del-chat-respuestas-personales) |
| Messenger e Instagram (pendiente real)   | [meta-messenger-instagram.md](meta-messenger-instagram.md)                                                                                                                                                 |
| Adjuntos por canal y ZIP por enlace      | [mensajeria-whatsapp.md §4](mensajeria-whatsapp.md#4-multimedia) y límites del servidor en [infraestructura §6](infraestructura-y-despliegue.md#6-https-y-nginx)                                           |
| Ayuda, lenguaje claro, bitácora          | [arquitectura.md §13](arquitectura.md#13-ayuda-por-módulo-lenguaje-claro-y-presentación-de-la-bitácora-fase-5)                                                                                             |
| PWA                                      | [infraestructura §20](infraestructura-y-despliegue.md#20-aplicación-instalable-pwa)                                                                                                                        |
| Migraciones y despliegue (no ejecutados) | [infraestructura §16 «Procedimiento de la fase 5»](infraestructura-y-despliegue.md#procedimiento-de-la-fase-5)                                                                                             |
| Pruebas manuales                         | [guia-pruebas-manuales.md §29](guia-pruebas-manuales.md#29-fase-5)                                                                                                                                         |

Bandeja general con ambos canales: [bandejas-y-asignacion.md](bandejas-y-asignacion.md#vista-general-chips-y-contadores).
Terminología de estados: [arquitectura.md §8](arquitectura.md#8-estados-de-mensajes).

Registro de decisiones: [decisiones.md](decisiones.md).

## Ejecutar el proyecto en local

Requisitos: PHP 8.4 con las extensiones de [infraestructura §2](infraestructura-y-despliegue.md#2-dependencias-del-servidor),
Composer, MariaDB 11.4, Node 22 o superior (en esta máquina, `export PATH=/usr/local/bin:$PATH`) y, opcionalmente,
Chromium para los PDF.

1. **Dependencias:** `composer install` y `npm install` (no agregues paquetes nuevos sin aprobación).
2. **Entorno:** copia `.env.example` a `.env` y ejecuta `php artisan key:generate`. Configura `DB_*` hacia tu base local
   `chatmanager` (usuario con privilegios solo sobre ella). Las credenciales de Meta **no** van en `.env`: se capturan
   cifradas por conexión desde la interfaz.
3. **Base de datos (solo en una instalación nueva):** `php artisan migrate` y `php artisan db:seed`. En `APP_ENV=local`
   el seeder crea departamentos y cuentas de demostración (ver `database/seeders/DatabaseSeeder.php`); en producción el
   primer Superadministrador se crea con `php artisan app:create-administrator`. Tras actualizar el código:
   `php artisan migrate`, `php artisan app:sync-permissions` (y, para poner al día los roles por defecto, revisar
   `php artisan app:sync-permissions --upgrade-default-roles` antes de añadir `--apply`) y, la primera vez,
   `php artisan analytics:rebuild`. Fase 5: ver el
   [procedimiento](infraestructura-y-despliegue.md#procedimiento-de-la-fase-5) (incluye `conversations:retire-on-hold`).
4. **Tiempo real (opcional):** `BROADCAST_CONNECTION=reverb` y credenciales propias `REVERB_APP_ID`, `REVERB_APP_KEY`,
   `REVERB_APP_SECRET` generadas al azar ([tiempo-real.md §Configuración](tiempo-real.md#configuración)). Sin Reverb la
   interfaz se actualiza por sondeo.
5. **Web Push (opcional):** `php artisan webpush:vapid` imprime `VAPID_PUBLIC_KEY` y `VAPID_PRIVATE_KEY`; cópialas a
   `.env` con `VAPID_SUBJECT=mailto:…` y ejecuta `php artisan config:clear`. Funciona en `http://localhost`; en otro
   host exige HTTPS ([notificaciones §1.8](notificaciones-y-horarios.md#18-web-push-avisos-con-la-plataforma-cerrada)).
6. **WhatsApp simulado:** `WHATSAPP_TRANSPORT=simulated` (solo desarrollo; opcionalmente `WHATSAPP_SIMULATE_STATUSES` y
   `WHATSAPP_SIMULATE_READ`). Mensajes de prueba: `php artisan chat-manager:simulate-inbound laura "Hola" --confirm`
   ([mensajeria-whatsapp.md §8](mensajeria-whatsapp.md#8-transportes)). Todo lo enviado así lleva la etiqueta
   «Simulación». El valor por defecto `unavailable` no envía nada.
7. **Arrancar:** `composer run dev` levanta el servidor (`php artisan serve`), Vite, Reverb, un `queue:listen` con todas
   las colas en orden de prioridad (`realtime` incluida), `schedule:work` y `pail` para los logs. Si un cambio de
   interfaz no aparece o falta el manifiesto de Vite, ejecuta `npm run build` o `npm run dev`.
8. **Pruebas:** configura `.env.testing` (base `chatmanager_testing`) y sigue [pruebas.md](pruebas.md); para probar a
   mano, [guia-pruebas-manuales.md](guia-pruebas-manuales.md).

Producción: [infraestructura-y-despliegue.md](infraestructura-y-despliegue.md). Antes de salir a producción revisa la
lista de [limitaciones.md §8](limitaciones.md#8-antes-de-producción).
