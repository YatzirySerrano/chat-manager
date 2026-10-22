# Documentación de Chat Manager

Chat Manager es una bandeja multiagente para WhatsApp Business sobre la API oficial de WhatsApp Cloud de Meta, con
departamentos, asignación, tiempo real, chat interno, reportes y exportaciones. Esta carpeta contiene un documento por
área; cada tema se explica en un solo lugar y los demás lo enlazan.

> La integración con Meta está implementada pero **no se ha probado contra Meta real**: todo el tráfico de WhatsApp
> de las pruebas es simulado. Ver [limitaciones.md](limitaciones.md).

## Documentos

| Documento                                                          | Contenido                                                                                          |
| ------------------------------------------------------------------ | -------------------------------------------------------------------------------------------------- |
| [arquitectura.md](arquitectura.md)                                 | Arquitectura general, mapa de módulos, modelo de datos, flujos, estados, disponibilidad, colas     |
| [bandejas-y-asignacion.md](bandejas-y-asignacion.md)               | Bandeja de tres paneles, compositor, visibilidad, distribución, asignación, adjuntos de la bandeja |
| [mensajeria-whatsapp.md](mensajeria-whatsapp.md)                   | Webhooks, conexiones, mensajes entrantes y salientes, multimedia, plantillas, activación real      |
| [tiempo-real.md](tiempo-real.md)                                   | Reverb, canales privados, eventos, reconexión y respaldo por sondeo                                |
| [chat-interno.md](chat-interno.md)                                 | Chat entre empleados: privacidad, grupos, adjuntos, tiempo real                                    |
| [notificaciones-y-horarios.md](notificaciones-y-horarios.md)       | Notificaciones, horario de atención, días no laborables, mensajes automáticos                      |
| [reportes-y-exportaciones.md](reportes-y-exportaciones.md)         | Métricas, reportes, panel principal, motor de exportaciones XLSX/PDF                               |
| [migracion-whaticket.md](migracion-whaticket.md)                   | Importación de contactos, adaptador Whaticket, trazabilidad y reversión por lote                   |
| [seguridad-y-auditoria.md](seguridad-y-auditoria.md)               | Modelo de amenazas, controles, permisos, bitácora, privacidad, hallazgos y riesgos                 |
| [pruebas.md](pruebas.md)                                           | Pest sobre MariaDB, análisis estático, E2E, concurrencia, carga, real frente a simulado            |
| [rendimiento-y-carga.md](rendimiento-y-carga.md)                   | Batería de carga y resistencia, resultados medidos y cuellos de botella                            |
| [infraestructura-y-despliegue.md](infraestructura-y-despliegue.md) | Servidor, variables de producción, Nginx, colas, Reverb, salud, alertas, preflight, despliegue     |
| [recuperacion-y-respaldos.md](recuperacion-y-respaldos.md)         | Respaldos de base y archivos, verificación, restauración                                           |
| [limitaciones.md](limitaciones.md)                                 | Dependencias externas; probado, simulado, pendiente de validación y trabajo futuro                 |
| [decisiones.md](decisiones.md)                                     | Registro de decisiones (ADR) por módulo                                                            |

## Índice de temas obligatorios

| #   | Tema                                 | Dónde                                                                                                                                                                                                                                                                         |
| --- | ------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | Arquitectura general                 | [arquitectura.md §1](arquitectura.md#1-arquitectura-general)                                                                                                                                                                                                                  |
| 2   | Mapa de módulos                      | [arquitectura.md §2](arquitectura.md#2-mapa-de-módulos)                                                                                                                                                                                                                       |
| 3   | Modelo de datos                      | [arquitectura.md §3](arquitectura.md#3-modelo-de-datos); tablas propias en [chat-interno.md](chat-interno.md#modelo-de-datos), [notificaciones](notificaciones-y-horarios.md#11-modelo) y [reportes §4](reportes-y-exportaciones.md#4-arquitectura-de-agregación)             |
| 4   | Flujo de mensajes entrantes          | [arquitectura.md §4](arquitectura.md#4-flujo-de-mensajes-entrantes) y [mensajeria-whatsapp.md §3](mensajeria-whatsapp.md#3-mensajes-entrantes)                                                                                                                                |
| 5   | Flujo de mensajes salientes          | [arquitectura.md §5](arquitectura.md#5-flujo-de-mensajes-salientes) y [mensajeria-whatsapp.md §5.2](mensajeria-whatsapp.md#52-entrega-deliveroutboundmessage-cola-messages)                                                                                                   |
| 6   | Webhooks y validación                | [mensajeria-whatsapp.md §2](mensajeria-whatsapp.md#2-conexiones-y-webhooks); controles en [seguridad §2](seguridad-y-auditoria.md#webhook-de-whatsapp)                                                                                                                        |
| 7   | Conexiones de WhatsApp               | [mensajeria-whatsapp.md §2](mensajeria-whatsapp.md#2-conexiones-y-webhooks) (administración, salud y diagnóstico) y [§9 activación real](mensajeria-whatsapp.md#9-activación-real-paso-a-paso)                                                                                |
| 8   | Estados de mensajes                  | [arquitectura.md §8](arquitectura.md#8-estados-de-mensajes) y [mensajeria-whatsapp.md §5.1](mensajeria-whatsapp.md#51-estados)                                                                                                                                                |
| 9   | Bandejas y permisos                  | [bandejas-y-asignacion.md §1–§2 y §8](bandejas-y-asignacion.md#2-visibilidad-requisito-innegociable); modelo de permisos en [seguridad §3](seguridad-y-auditoria.md#3-modelo-de-permisos)                                                                                     |
| 10  | Distribución entre departamentos     | [bandejas-y-asignacion.md §3–§4](bandejas-y-asignacion.md#3-distribución-entre-departamentos)                                                                                                                                                                                 |
| 11  | Disponibilidad de agentes            | [arquitectura.md §11](arquitectura.md#11-disponibilidad-de-agentes) y [bandejas §5](bandejas-y-asignacion.md#5-ausencias-y-cambios-de-acceso)                                                                                                                                 |
| 12  | Chat interno                         | [chat-interno.md](chat-interno.md)                                                                                                                                                                                                                                            |
| 13  | WebSockets                           | [tiempo-real.md](tiempo-real.md)                                                                                                                                                                                                                                              |
| 14  | Adjuntos                             | Clientes: [bandejas §7](bandejas-y-asignacion.md#7-archivos-adjuntos) y [mensajeria-whatsapp.md §4](mensajeria-whatsapp.md#4-multimedia); chat interno: [chat-interno.md](chat-interno.md#adjuntos); controles en [seguridad](seguridad-y-auditoria.md#archivos-y-multimedia) |
| 15  | Plantillas de Meta                   | [mensajeria-whatsapp.md §7](mensajeria-whatsapp.md#7-plantillas)                                                                                                                                                                                                              |
| 16  | Reportes                             | [reportes-y-exportaciones.md §1–§5](reportes-y-exportaciones.md#1-qué-ofrece)                                                                                                                                                                                                 |
| 17  | Exportaciones                        | [reportes-y-exportaciones.md §6](reportes-y-exportaciones.md#6-exportaciones-motor-existente)                                                                                                                                                                                 |
| 18  | Migración desde Whaticket            | [migracion-whaticket.md](migracion-whaticket.md)                                                                                                                                                                                                                              |
| 19  | Seguridad y auditoría                | [seguridad-y-auditoria.md](seguridad-y-auditoria.md)                                                                                                                                                                                                                          |
| 20  | Pruebas                              | [pruebas.md](pruebas.md) y [rendimiento-y-carga.md](rendimiento-y-carga.md)                                                                                                                                                                                                   |
| 21  | Infraestructura                      | [infraestructura-y-despliegue.md §1–§15](infraestructura-y-despliegue.md#1-componentes-y-procesos)                                                                                                                                                                            |
| 22  | Procedimiento de despliegue          | [infraestructura-y-despliegue.md §16–§18](infraestructura-y-despliegue.md#16-despliegue)                                                                                                                                                                                      |
| 23  | Recuperación y respaldos             | [recuperacion-y-respaldos.md](recuperacion-y-respaldos.md)                                                                                                                                                                                                                    |
| 24  | Limitaciones y dependencias externas | [limitaciones.md](limitaciones.md)                                                                                                                                                                                                                                            |

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
   `php artisan migrate`, `php artisan app:sync-permissions` y, la primera vez, `php artisan analytics:rebuild`.
4. **Tiempo real (opcional):** `BROADCAST_CONNECTION=reverb` y credenciales propias `REVERB_APP_ID`, `REVERB_APP_KEY`,
   `REVERB_APP_SECRET` generadas al azar ([tiempo-real.md §Configuración](tiempo-real.md#configuración)). Sin Reverb la
   interfaz se actualiza por sondeo.
5. **WhatsApp simulado:** `WHATSAPP_TRANSPORT=simulated` (solo desarrollo; opcionalmente `WHATSAPP_SIMULATE_STATUSES` y
   `WHATSAPP_SIMULATE_READ`). Mensajes de prueba: `php artisan chat-manager:simulate-inbound laura "Hola" --confirm`
   ([mensajeria-whatsapp.md §8](mensajeria-whatsapp.md#8-transportes)). Todo lo enviado así lleva la etiqueta
   «Simulación». El valor por defecto `unavailable` no envía nada.
6. **Arrancar:** `composer run dev` levanta el servidor (`php artisan serve`), Vite, Reverb, un `queue:listen` con todas
   las colas en orden de prioridad, `schedule:work` y `pail` para los logs. Si un cambio de interfaz no aparece o falta
   el manifiesto de Vite, ejecuta `npm run build` o `npm run dev`.
7. **Pruebas:** configura `.env.testing` (base `chatmanager_testing`) y sigue [pruebas.md](pruebas.md).

Producción: [infraestructura-y-despliegue.md](infraestructura-y-despliegue.md). Antes de salir a producción revisa la
lista de [limitaciones.md §7](limitaciones.md#7-antes-de-producción).
