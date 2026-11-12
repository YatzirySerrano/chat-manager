# Migración desde Whaticket

Chat Manager tiene dos importadores para migrar desde Whaticket:

- **Contactos** (desde antes de la fase 5): el importador de contactos con el adaptador `whaticket-contacts`
  (secciones siguientes). El adaptador es una heurística marcada como **pendiente de validación** hasta revisar una
  exportación real de la instalación de la empresa.
- **Conversaciones y mensajes históricos** (fase 5.2): el importador de historial, con tres destinos (importar,
  archivar, no importar), inventario, reglas, confirmación y ejecución en cola. El CSV de «Exportar mensajes» está
  **verificado con una exportación auténtica** (2026-11-12, 447 429 mensajes) y se importa sin mapeo manual; el
  reporte de chats, el CSV de contactos y los `Chat.txt` siguen sin verificar. Ver
  [Importador de historial](#importador-de-historial-de-whaticket-fase-52) y, para importar tu archivo,
  [Procedimiento paso a paso](#procedimiento-paso-a-paso-para-importar-la-exportación-real).

## Importador de contactos: qué incluye

| Requisito                 | Dónde está                                                                                                                                                                                                        |
| ------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Asistente administrativo  | `/contacts/import`, en tres pasos: origen y archivo → mapeo y validación → resultado. Incluye el historial de lotes.                                                                                              |
| Carga segura              | `UploadContactImportRequest` revisa tamaño, extensión y MIME real. `ImportFileReader` revisa macros, bombas zip y límites de filas y columnas. El archivo se guarda en un disco privado y se borra al procesarlo. |
| Validación y vista previa | `ContactImportAnalyzer` valida cada fila con las mismas reglas del formulario de contactos. La vista previa muestra errores, advertencias y duplicados, y todo queda en el reporte final.                         |
| Mapeo configurable        | El origen sugiere el mapeo y el usuario puede cambiar cualquier columna.                                                                                                                                          |
| Duplicados                | Se detectan dentro del archivo (mismo teléfono o correo) y contra los contactos existentes (teléfono E.164 o correo en minúsculas). Para los existentes se elige entre omitirlos o solo completar campos vacíos.  |
| Normalización             | Teléfonos a E.164 con `PhoneNumber` y correos en minúsculas. El adaptador de Whaticket quita sufijos de WhatsApp, agrega el `+` y descarta grupos.                                                                |
| Reporte de errores        | Un XLSX descargable con el resultado de cada fila.                                                                                                                                                                |
| Importación por lotes     | El trabajo `ImportContacts`, en la cola `exports`, procesa la importación en bloques de `imports.batch_size` y muestra el progreso.                                                                               |
| Trazabilidad              | Tabla `import_batch_records`, descrita más abajo.                                                                                                                                                                 |
| Idempotencia              | Hash SHA-256 del archivo, aviso si ya se importó y una nueva comprobación de duplicados justo antes de escribir. Volver a importar el mismo archivo no duplica contactos ni crea registros en el lote nuevo.      |
| Rollback lógico           | `ImportBatchRollback` con vista previa, confirmación explícita, trabajo en cola y auditoría. Se describe más abajo.                                                                                               |

Lo que el importador de contactos **no** hace: no ejecuta SQL, no restaura bases de datos, no importa modelos arbitrarios ni usuarios,
contraseñas, tokens o mensajes. Solo importa los campos de contacto: nombre, teléfono, correo, etiquetas existentes y
una nota.

El importador de contactos está reservado al **Superadministrador** con el permiso `contacts.import`, que es exclusivo de root.
Cada lote pertenece a quien lo subió: otro root recibe 404. Toda la auditoría es restringida: `import.requested`,
`import.completed`, `import.failed`, `import.rollback_requested`, `import.rolled_back` e `import.rollback_failed`.

## Adaptadores de origen

Los adaptadores están en `App\Services\Migration`. Implementan `MigrationSourceAdapter` con `key`, `label`,
`description`, `isValidated`, `validationNote`, `expectedColumns`, `detect`, `suggestMapping` y `normalizeRow`.
`MigrationSourceRegistry` los registra. Solo existen los adaptadores listados en el registro, así que un origen nunca
se resuelve desde la entrada del usuario.

| Clave                | Estado                                   | Comportamiento                                                                                                                                                                                                                                                                                                                                                                                                     |
| -------------------- | ---------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `generic`            | Validado. Es el comportamiento original. | Sugiere el mapeo a partir de nombres comunes en español e inglés (`ContactImportAnalyzer::HEADER_HINTS`) y no transforma valores.                                                                                                                                                                                                                                                                                  |
| `whaticket-contacts` | **Pendiente de validación**              | Busca encabezados `name`, `number` y `email` como señales heurísticas. `profilePicUrl`, `isGroup`, `createdAt` y `updatedAt` suben la puntuación pero no prueban nada. Normaliza el número así: quita `@c.us` y `@s.whatsapp.net`, rechaza `@g.us` (grupos) y antepone `+` a números de 11 a 15 dígitos sin `+`, que es como WhatsApp guarda los números con lada. Rechaza las filas cuyo `isGroup` sea verdadero. |

La detección da a cada origen una puntuación de 0 a 100. Si otro origen se parece más al archivo, la interfaz lo
sugiere y aclara que «es solo una coincidencia de nombres, no confirma el formato». Al cambiar de origen se vuelve a
llenar el mapeo sugerido, que sigue siendo editable.

### Validar el adaptador con una exportación real

1. Obtener un CSV o XLSX auténtico de contactos de la instalación de Whaticket a migrar, en un entorno seguro.
2. Comparar sus encabezados con `WhaticketContactsAdapter::expectedColumns()` y agregar los que falten.
3. Revisar el formato real de los números (sufijos, lada, guiones) y de la columna de grupos, y ajustar `normalizeRow`.
4. Agregar una prueba con una muestra anonimizada del archivo real en `tests/Feature/Migration`.
5. Solo después de eso, cambiar `isValidated()` a `true` y actualizar `validationNote()`.

## Trazabilidad por lote

El lote es el `DataTransfer` de la importación. Cada contacto escrito por el lote genera un registro en
`import_batch_records`. El registro se escribe en la misma transacción que el contacto:

- `action = created` cuando el lote creó el contacto. `applied_values` guarda una instantánea del contacto justo
  después: nombre, teléfono, correo, nombre de perfil, campos personalizados, estado y etiquetas.
- `action = filled` cuando el lote completó campos vacíos de un contacto existente. `previous_values` guarda los
  valores reemplazados (siempre vacíos) y `applied_values` los valores escritos.
- `added_tag_ids` y `added_note_ids` guardan las etiquetas y la nota que agregó el lote.
- `audit_watermark` guarda el último ID de `audit_logs` al terminar con el registro. Cualquier entrada de auditoría
  posterior sobre ese contacto cuenta como actividad posterior.

Si una fila posterior del mismo archivo vuelve a tocar el mismo contacto, se fusiona con su registro: hay una sola
fila por lote y contacto.

## Reversión lógica (rollback) de un lote

La reversión está disponible para importaciones terminadas con trazabilidad, de los últimos `imports.rollback.days`
días (30 por defecto, variable `IMPORTS_ROLLBACK_DAYS`). Se puede desactivar con `IMPORTS_ROLLBACK_ENABLED=false`.
Las importaciones anteriores a esta versión no tienen trazabilidad y no se pueden revertir. Una importación que
falló a la mitad (por ejemplo, por tiempo de espera del trabajo) también se puede revertir: las filas que alcanzó a
procesar quedaron ligadas al lote.

**Contacto creado por el lote.** Se elimina lógicamente con `DeleteContact`, que lo archiva y deja auditoría, solo si
no tiene ninguna actividad posterior:

- conversaciones (y, por lo tanto, mensajes);
- identidades de WhatsApp;
- atribuciones de origen;
- casos de revisión;
- vínculos con conexiones;
- notas distintas de las que agregó el lote;
- entradas de auditoría posteriores al lote;
- registros de una importación posterior;
- cambios en sus datos o etiquetas respecto de la instantánea, aunque se hayan hecho sin auditoría.

**Campo completado por el lote.** Se vuelve a dejar vacío solo si conserva el valor que escribió el lote. El teléfono
no se revierte si hubo actividad de WhatsApp después de la importación (una conversación nueva o con mensajes
posteriores, o una identidad registrada o vista después). Tampoco se revierte
nada si el contacto quedaría sin teléfono ni correo. Las etiquetas que agregó el lote se quitan si siguen asignadas.
**Las notas se conservan siempre**, porque son historial.

Cada registro termina como `reverted` (revertido), `partial` (revertido en parte) o `kept` (conservado), junto con el
motivo. La interfaz muestra los conservados.

El flujo tiene cuatro pasos:

1. **Vista previa.** El botón «Revisar y revertir» carga `rollbackPreview`, una prop opcional de Inertia que no
   escribe nada. Muestra contadores y los primeros registros que se conservarían, con su motivo.
2. **Confirmación explícita.** Se marca la casilla y se envía `POST contacts/import/{transfer}/rollback` con
   `confirmation=1`.
3. **Ejecución.** El trabajo `RollbackContactImport` (único por lote, cola `exports`) toma el estado
   `queued → processing` con bloqueo. Para cada registro bloquea el contacto, **vuelve a evaluar las reglas con datos
   frescos** y solo entonces escribe.
4. **Resultado.** Se audita `import.rolled_back` con el resumen. Si falla (o no se pudo poner en la cola), el estado
   queda `failed` y se puede pedir de nuevo; los registros ya procesados no se tocan dos veces.

Cuando el mismo archivo se importa después de revertirlo, los contactos archivados por la reversión se reportan como
«eliminado al revertir la importación #N». Para conservarlos hay que restaurarlos desde Contactos (eliminados).

Limitaciones conocidas:

- Un contacto modificado por un lote posterior no se revierte desde el lote anterior, aunque el lote posterior también
  se haya revertido. La regla es conservadora a propósito.
- Las notas agregadas a contactos existentes no se eliminan.

## Importador de historial de Whaticket (fase 5.2)

Desde la fase 5.2 existe un importador de **conversaciones y mensajes históricos** de Whaticket, separado del
importador de contactos. Está en Administración → «Importar de Whaticket» (`/admin/history-import`) y la
consulta de lo archivado en Administración → «Historial importado» (`/admin/history`).

> **Qué está verificado.** El CSV de **«Exportar mensajes»** se contrastó el 2026-11-12 con una exportación
> auténtica (447 429 mensajes, 67 MB): se lee sin mapeo manual, el archivo muestra la insignia «Formato verificado» y
> la corrida completa (lectura, inventario, ejecución e idempotencia) está medida más abajo. **Siguen sin verificar**
> el reporte de chats, el CSV de contactos y los `Chat.txt`: sus columnas se reconocen por nombre (alias), muestran
> «Sin verificar» y su mapeo se puede corregir. Las pruebas usan solo archivos con datos ficticios
> (`tests/fixtures/whaticket`); `messages-export.csv` reproduce el formato real exacto.
>
> **Migraciones.** Las de la fase 5.2 ya están aplicadas en la base local `chatmanager`. Falta aplicar **una**
> migración nueva y aditiva (`2026_11_12_180000_add_details_to_history_import_messages_table`). Ver
> [Migraciones](#migraciones-del-importador).

### Exportación real de mensajes (verificada el 2026-11-12)

Archivo: `whaticket_messages_<desde>_<hasta>.csv`, de Configuración → Exportación → «Exportar mensajes». UTF-8 sin
BOM, separado por comas, textos multilínea entre comillas. La exportación revisada tiene 447 429 mensajes (justo
debajo del tope de 450 mil por archivo de Whaticket), 1 926 tickets y 67 MB. **Contiene datos personales: nunca se
versiona** (`docs/export/` está en `.gitignore`) y no se copia a `tests/`.

```
ticketId,contact_name,contact_number,createdAt,fromMe,body,mediaType,mediaUrl,campaignId
```

| Columna          | Qué trae                                                                                                     | Cómo se importa                                                                                                                                                                                                                                              |
| ---------------- | ------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `ticketId`       | UUID del ticket                                                                                              | Una conversación por ticket (`ticket:<uuid>`)                                                                                                                                                                                                                |
| `contact_name`   | Nombre del contacto, **solo en los mensajes del cliente**                                                    | El contacto de la conversación se toma de las filas hermanas del mismo ticket (las filas enviadas por la empresa vienen sin nombre ni número)                                                                                                                |
| `contact_number` | Número sin `+`, **solo en los mensajes del cliente**: 13 dígitos (`521…`), 12 (`52…`) o 10                   | E.164: `521XXXXXXXXXX`, `52XXXXXXXXXX` y los 10 dígitos nacionales son el mismo `+52XXXXXXXXXX`, el que usará la conexión real de WhatsApp. Un número que no se puede normalizar (9 dígitos, por ejemplo) se conserva como viene, sin relacionarlo con nadie |
| `createdAt`      | `dd/mm/aaaa HH:MM`, sin segundos ni zona                                                                     | Hora local de la zona del lote (por defecto la de la plataforma), guardada en UTC. Dentro del mismo minuto el orden es **el del archivo**                                                                                                                    |
| `fromMe`         | `true` (empresa, bot o campaña) / `false` (cliente)                                                          | Saliente «Enviado» / entrante «Recibido», nunca «Entregado» ni «Leído»                                                                                                                                                                                       |
| `body`           | Texto; JSON en `location` y `contact`; vacío en la mayoría de los archivos                                   | Texto plano. `location` (`{"degreesLatitude":…,"degreesLongitude":…}`) se muestra como ubicación con enlace al mapa; `contact` (`[{"name":…,"number":…}]`) como contacto compartido con nombre y número                                                      |
| `mediaType`      | vacío, `chat`, `image`, `video`, `audio`, `application`, `document`, `file`, `location`, `contact`, `missed` | Vacío y `chat` → texto; `application`/`document`/`file` → documento; `missed` → mensaje «Llamada perdida»                                                                                                                                                    |
| `mediaUrl`       | Casi siempre vacío; a veces un enlace firmado de Facebook (ya vencido)                                       | **Nunca se descarga ni se guarda el enlace**: solo queda el nombre del archivo. La política de contenido de producción (`img-src 'self'`) tampoco permitiría mostrarlo                                                                                       |
| `campaignId`     | UUID de la campaña que envió el mensaje (pocas filas)                                                        | Se conserva como dato del mensaje (`metadata.import.campaign_id`)                                                                                                                                                                                            |

Lo que **no** trae esta exportación y por tanto no se puede recuperar de ella:

- **Los archivos** (fotos, audios, videos, documentos). Cada uno queda como mensaje con su tipo y su fecha y el aviso
  «Archivo histórico no disponible» (adjunto `unavailable`, `historical_missing`): no es un error. En la exportación
  revisada son 66 619 de 447 429 mensajes. Para recuperar archivos hay que subir además los ZIP
  `Whaticket-medias.zip` de los chats que interesen.
- **Identificador de mensaje.** La idempotencia usa una huella por fila (abajo).
- **Agente, departamento, conexión, etiquetas y estado.** Todo el historial queda sin agente ni departamento y bajo
  una sola conexión (la que elijas o la «Conexión histórica sin envío»). Si se sube también el reporte de chats
  (sin verificar), aporta esos datos por ticket.
- **El teléfono de quien escribió por Messenger, Instagram o Facebook**: solo trae su nombre.

#### Huella de cada mensaje (idempotencia)

Como no hay identificador, cada fila recibe una huella estable (`HistoryFileIngestor::messageFingerprint`): ticket +
fecha y hora tal como vienen + dirección + tipo + contenido + **cuántas veces se repitió ya esa misma fila en el
archivo**. Así dos mensajes idénticos en el mismo minuto (el saludo del bot enviado dos veces, dos fotos sin texto;
en la exportación revisada hay 6 027 grupos así, hasta 30 iguales) son dos mensajes, y la misma fila de otra
exportación que se traslapa es el mismo mensaje. No depende del número de línea ni de la zona horaria del lote, y no
permite reconstruir el contenido. Consecuencias:

- **Volver a subir el mismo archivo** (en otro lote) no crea nada: todas las conversaciones terminan «Ya importada
  antes».
- **Una exportación posterior o traslapada** solo agrega lo nuevo: los tickets nuevos se crean y los ya importados se
  **amplían** con los mensajes que no tenían (antes un ticket repetido se omitía entero).
- **Dos archivos traslapados en el mismo lote** cuentan cada mensaje una vez (aviso «Mensajes que ya venían en otro
  archivo de esta importación»).
- Hay que usar siempre el **mismo identificador de instalación** (`whaticket` por defecto) en todos los lotes del
  mismo Whaticket: es parte de la clave.

#### Tickets sin teléfono y tickets sin contacto

| Grupo (en la exportación revisada)                                                    | Qué se sabe                | Destinos permitidos             | Recomendación mostrada | Contacto que se crea                                                                                        |
| ------------------------------------------------------------------------------------- | -------------------------- | ------------------------------- | ---------------------- | ----------------------------------------------------------------------------------------------------------- |
| Con número (916 tickets, 888 números distintos)                                       | Nombre y teléfono          | Importar, archivar, no importar | —                      | El contacto activo con ese número si existe; si no, uno provisional (fuera del directorio) por número       |
| Con nombre y sin número (506: Messenger/Instagram/Facebook) o con número inválido (2) | Nombre (y número tal cual) | Importar, archivar, no importar | Archivar               | Un contacto provisional **por ticket**, con su nombre y sin teléfono. Nunca se fusiona por nombre           |
| Sin nombre ni número (502; casi todos solo con mensajes de la empresa)                | Nada                       | **Solo** archivar o no importar | Archivar               | Un único contacto provisional compartido, «Contacto no identificado (Whaticket)», por instalación de origen |

Nada falla y no se inventa ningún teléfono. El inventario tiene los filtros **«Con número de teléfono»** y **«Sin
número de teléfono»** para decidir cada grupo de una vez (o con una regla).

#### Resultados medidos con el archivo real

Corrida completa del 2026-11-12 en una base MariaDB 11.4 aparte (`chatmanager_import_check`, creada y eliminada para
la prueba; la base `chatmanager` no se tocó), en el equipo de desarrollo (Windows, PHP 8.4 sin Xdebug, un solo
proceso, cola `sync`):

| Paso                                           | Resultado                                                                                                                                                                      | Tiempo        | Memoria pico |
| ---------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------- | ------------ |
| Subida, lectura e inventario                   | 447 429 filas leídas, **447 429 aceptadas, 0 rechazadas**, 0 columnas desconocidas, 0 campos faltantes, 447 429 huellas distintas; 1 926 conversaciones                        | 87,5 s        | 80 MB        |
| Clasificación (2 acciones masivas) y resumen   | 916 «Importar» (con número) y 1 010 «Archivar» (sin número); 0 bloqueos                                                                                                        | < 1 s         | —            |
| Ejecución                                      | 1 926 conversaciones (916 operativas, 1 010 archivadas), 447 429 mensajes (176 931 entrantes, 270 498 salientes), 1 397 contactos provisionales (888 con teléfono), 0 fallidas | 447 s         | 50 MB        |
| Segunda corrida del mismo archivo (lote nuevo) | Las 1 926 conversaciones detectadas como ya importadas; **0 mensajes, 0 conversaciones, 0 contactos y 0 claves nuevas** (1 926 «Ya importada antes»)                           | 120 s + 205 s | 76 MB        |

Por tipo: 360 806 textos (343 de ellos «Llamada perdida»), 27 154 imágenes, 21 439 videos, 19 979 ubicaciones, 17 397
documentos, 629 audios y 25 contactos compartidos; 66 619 archivos «no disponibles»; 113 mensajes con campaña. La
lectura es por _streaming_ (el archivo nunca se carga en memoria) y cabe con margen en el tope de 600 s del trabajo
de lectura; la ejecución se reparte sola en trabajos de 240 s (2 en esta corrida) y es reanudable por conversación.
El ticket más grande tiene 11 607 mensajes y se escribe en una sola transacción.

### Procedimiento paso a paso para importar la exportación real

Lo hace una cuenta raíz (permisos `history-import.*`). Nada se importa hasta confirmar el resumen.

1. **Antes de empezar.**
    - Respaldo verificado de la base ([recuperacion-y-respaldos.md](recuperacion-y-respaldos.md)): es la única forma
      de deshacer una importación (ver «Cómo revertir» abajo).
    - `php artisan migrate` (aplica `2026_11_12_180000_add_details_to_history_import_messages_table`) y workers de la
      cola `exports` en marcha.
    - **Límite de subida.** Por el navegador el máximo es el menor entre `HISTORY_IMPORT_MAX_KILOBYTES` (100 MB por
      defecto) y lo que acepte PHP (`upload_max_filesize`, `post_max_size`) y el servidor web
      (`client_max_body_size`). Las plantillas de `deploy/` traen 100M / 110M / 110m y el archivo real (67 MB) entra;
      un PHP con los valores de fábrica (`upload_max_filesize = 2M`, `post_max_size = 8M`, como el WAMP local) lo
      rechaza y la pantalla lo dice antes de subir. Dos salidas: subir esos tres valores, o copiar el archivo al
      servidor y agregarlo por consola (paso 3).
    - Zona horaria de la plataforma correcta (Administración → Ajustes): las fechas del archivo no traen zona.
2. **Crear el lote** en Administración → «Importar de Whaticket»: deja el identificador de instalación por defecto
   (`whaticket`) —**usa siempre el mismo** en lotes futuros— y la zona horaria en la que se usaba Whaticket.
3. **Subir el CSV** como «Mensajes (CSV)». Si es demasiado grande para el navegador:
   `php artisan history-import:add-file <número de lote> /ruta/al/archivo.csv --kind=messages` (mismos controles,
   misma copia privada y misma lectura en cola; el original no se mueve: bórralo al terminar). La lectura tarda entre
   uno y dos minutos por cada 450 mil mensajes; al terminar el archivo debe mostrar «Formato verificado», todas las
   filas aceptadas y ninguna columna desconocida.
4. **Asignar la conexión.** El archivo no dice por qué conexión entró cada chat: en «Asignaciones», a «sin conexión
   indicada» asígnale la conexión real del número (si ya existe) o «Conexión histórica sin envío». Lo importado es
   solo lectura en ambos casos y nunca envía nada.
5. **Clasificar.** Lo habitual: filtro «Con número de teléfono» → «Importar» (queda en la bandeja, filtro
   «Cerradas», y en la ficha del contacto); filtro «Sin número de teléfono» → «Archivar» (solo en «Historial
   importado») o «No importar». Marca «Guardar contacto» solo en los que quieras en el directorio; el resto queda
   como contacto provisional.
6. **Revisar el resumen y confirmar.** Debe decir 0 bloqueos; los «archivos faltantes» son los que la exportación
   no incluye.
7. **Esperar la ejecución** (unos 8 minutos por 450 mil mensajes en la medición; se puede cerrar la pantalla). Si se
   interrumpe, «Continuar» sigue sin duplicar.
8. **Comprobar el resultado**: importadas + archivadas = conversaciones clasificadas, 0 fallidas, y abrir dos o tres
   conversaciones (una con ubicación, una con archivos) para ver fechas y orden.
9. **Siguientes archivos** (periodos posteriores o traslapados): lote nuevo con el mismo identificador de
   instalación y los mismos pasos. Los tickets ya importados aparecen con el aviso «solo se agregarán los mensajes
   que todavía no tiene».

**Cuando escriba un cliente del historial por la conexión real**, su conversación en vivo se liga al mismo contacto
(mismo `+52…`) y su historial importado aparece en su ficha. Las conversaciones importadas nunca se reabren.

**Cómo revertir.** No hay reversión por lote: lo importado es historial de solo lectura que no afecta métricas,
ventanas de 24 h ni asignaciones, así que lo normal es no necesitarla. Para deshacer una importación completa se
restaura el respaldo tomado en el paso 1. Para probar sin riesgo, haz antes un lote piloto con un archivo pequeño (o
la misma exportación en una copia de la base) y archiva en vez de importar lo dudoso: lo archivado no aparece en la
operación diaria.

### Fuentes oficiales y qué se sabe de cada formato

**Hecho comprobado (Centro de ayuda de Whaticket, consultado el 2026-10-25; los artículos no muestran fecha):**

| Exportación de Whaticket          | Cómo se obtiene                                                                                           | Formato y límites documentados                                                                                                                                                                                                                                                            | Fuente                                                                                                          |
| --------------------------------- | --------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------- |
| Reporte de chats                  | Informes → gráfico «Chats creados»/«Chats resueltos» → Exportar (permisos «Ver» y «Exportar» de Informes) | XLSX o CSV, una fila por chat, máximo 90 días por exportación, respeta los filtros. Columnas documentadas: `contactName`, `contactNumber`, `user`, `department`, `connection`, `createdAt`, `resolvedAt`, `conversationId`, `chatId`, `chatLink`, `tags`, `conversationTags`, entre otras | [artículo 20776](https://help.whaticket.com/es/articles/20776-como-obtener-datos-personalizados-en-whaticket)   |
| Mensajes por periodo              | Configuración → Exportación → «Exportar mensajes» (permiso «Exportar mensajes» de Chats)                  | CSV, una fila por mensaje, hasta 450 mil mensajes por archivo (si hay más, se descarga por partes). Las columnas no están documentadas por Whaticket; **verificadas con una exportación auténtica** (ver arriba): no incluye multimedia                                                   | [artículo 20655](https://help.whaticket.com/es/articles/20655-como-exportar-o-transferir-los-mensajes-enviados) |
| Mensajes seleccionados de un chat | En el chat: «Seleccione mensajes» → Descargar                                                             | ZIP `Whaticket-medias.zip` con los archivos multimedia y `Chat.txt` (texto, fecha, hora y remitente). **El formato de las líneas de `Chat.txt` no está documentado**                                                                                                                      | [artículo 20655](https://help.whaticket.com/es/articles/20655-como-exportar-o-transferir-los-mensajes-enviados) |
| Contactos                         | Contactos → Exportar (permiso Exportar en Contactos)                                                      | CSV con number, name, nickname, email, fecha de creación, etiquetas y campos personalizados                                                                                                                                                                                               | [artículo 20889](https://help.whaticket.com/es/articles/20889-como-exportar-e-importar-el-archivo-de-la-lista)  |

- «Transferir» en Whaticket significa reenviar dentro de la misma cuenta; no hay migración a otra cuenta.
- La exportación nativa de un chat desde la app de WhatsApp (Más → Exportar chat, «Sin archivos» / «Incluir
  archivos») produce un texto que, según el resumen del FAQ oficial, «no se puede volver a importar». El FAQ
  (<https://faq.whatsapp.com/1180414079177245>) no se pudo leer completo: los formatos de línea de Android e iOS, los
  marcadores de archivo omitido y los límites de mensajes por exportación **no proceden de una fuente oficial** y se
  tratan como inferencia.

**Decisión de arquitectura:** como el formato no está documentado, el importador es tolerante (varios alias por
columna, varias variantes de `Chat.txt`), informa en vez de adivinar (columnas desconocidas, campos obligatorios
faltantes, filas rechazadas con su número de línea y sin contenido) y deja que el administrador corrija el mapeo.

### Archivos aceptados

| Tipo de archivo (al subir) | Extensiones     | Qué aporta                                                                                                                                                                 |
| -------------------------- | --------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Reporte de chats           | `.xlsx`, `.csv` | Una fila del inventario por chat: contacto, agente, departamento, conexión, etiquetas, fechas y estado tal como vienen                                                     |
| Mensajes                   | `.csv`          | Los mensajes, leídos en _streaming_ a una tabla de preparación y agrupados por identificador de conversación (o por número de contacto + conexión si no hay identificador) |
| Contactos                  | `.csv`          | Nombres para los números del inventario (mismo mapeo que el adaptador `whaticket-contacts`)                                                                                |
| Archivos y `Chat.txt`      | `.zip`, `.txt`  | Los archivos multimedia (indexados por nombre, no extraídos) y, si hay un `Chat.txt` dentro o se sube un `.txt` solo, una conversación propia                              |

Controles de carga (`HistoryFileIngestor`, `SafeZip`, `TabularFileReader`): tipo real con `finfo` y extensión por tipo
de archivo («Para «Mensajes (CSV)» solo se aceptan archivos .csv.»), huella SHA-256 (el mismo archivo no se agrega
dos veces al lote), tamaño máximo por el navegador `HISTORY_IMPORT_MAX_KILOBYTES` (**102 400 KB = 100 MB por
defecto**, igual que las plantillas del servidor: Nginx `client_max_body_size 110m` y PHP-FPM
`upload_max_filesize = 100M` / `post_max_size = 110M`, ver `deploy/nginx/chat-manager.conf` y
`deploy/php-fpm/chat-manager.conf`; si el PHP que atiende la petición acepta menos, la pantalla y la validación usan
ese valor menor, `HistoryImportFileRequest::maxKilobytes()`. Un archivo mayor se agrega desde el servidor con
`php artisan history-import:add-file <lote> <ruta> --kind=chats|messages|contacts|media`, hasta
`HISTORY_IMPORT_MAX_CLI_KILOBYTES`, 2 GB, con los mismos controles de contenido), filas (`HISTORY_IMPORT_MAX_ROWS`,
460 000), columnas (80), un registro de CSV de como máximo 1 MB (`HISTORY_IMPORT_MAX_RECORD_BYTES`; uno mayor se
rechaza en vez de leerse entero), texto por mensaje (10 000 caracteres; los más largos se cortan y se señalan) y fechas
entre 1971 y 2037 (fuera de ese rango se tratan como inválidas). Los `.xlsx` se leen en _streaming_ con `XMLReader`,
sin evaluar fórmulas («=HYPERLINK(…)» llega como texto). Los ZIP nunca se
extraen con `extractTo()`: se revisa cada entrada antes de leer nada y se rechaza el archivo completo ante rutas
absolutas o con `..`, enlaces simbólicos, entradas cifradas, más de 20 000 entradas, más de 4 GB descomprimidos, una
entrada de más de 100 MB o una tasa de compresión superior a 100 en entradas de más de 1 MB (bombas zip); `Chat.txt`
tiene su propio tope de 64 MB. Los archivos viven en el disco privado de importaciones (`history-imports/`), nunca en
la base ni en una carpeta pública, y se borran al terminar o vencer el lote. Durante la ejecución, si el disco de
importaciones es remoto, cada ZIP se copia una vez a `storage/app/private/history-import-work/{lote}` y esa copia de
trabajo se conserva entre corridas y se borra con los datos de preparación del lote.

Un archivo se puede **quitar** del lote mientras es borrador («Quitar archivo», `DELETE …/files/{file}`,
`files.destroy`): se borran el archivo y lo leído de él y se reconstruye el inventario (las conversaciones que solo
venían de él desaparecen). Uno que falló al leerse (o cuya lectura se interrumpió) se vuelve a leer con «Reintentar»
(`POST …/files/{file}/retry`, `files.retry`, 202). Ambos responden 409 `batch_locked` si el lote ya no es borrador.

### Mapeo de columnas

`App\Services\Migration\History\HistoryColumns` define los campos lógicos y sus alias (en minúsculas, sin acentos y
con `_`, `-` y `.` leídos como espacio: `contact_name` es «contact name»). Cada campo toma la primera columna cuyo
nombre coincide con un alias; una columna sirve a un solo campo. El encabezado exacto de la exportación auténtica
(`HistoryColumns::VERIFIED_MESSAGES_HEADER`) se etiqueta `whaticket-messages-csv:verificado`; cualquier otro,
`…:no-verificado`.

- **Reporte de chats.** Obligatorio al menos uno de: número del contacto, identificador de conversación o
  identificador del chat. Campos: `conversation_id` (`conversationId`, `ticketId`, `protocolo`…), `chat_id`,
  `chat_link`, `contact_name`, `contact_number`, `user` (agente), `department` (`queue`, `fila`, `cola`…),
  `connection` (`whatsapp`, `conexion`…), `created_at`, `resolved_at`, `status`, `tags`, `conversation_tags`.
- **Mensajes.** Obligatorios: fecha y hora; texto **o** archivo; identificador de conversación **o** número del
  contacto. Campos: `message_id`, `conversation_id`, `contact_number`, `contact_name`, `from_me` («Enviado por la
  empresa»), `sender`, `user`, `body`, `media_type`, `media_name` (`mediaUrl`, `filename`…; solo se usa el nombre del
  archivo para buscarlo en los ZIP, **nunca se descarga una URL**), `created_at`, `time` (si la hora viene aparte),
  `quoted_id`, `connection`, `department`, `is_deleted`, `campaign_id`. Salvo el encabezado verificado, los alias de
  este archivo son **suposiciones razonables** (campos del Whaticket de código abierto y variantes en español,
  portugués e inglés), no un formato documentado.
- **`Chat.txt`** (`ChatTextParser`, tolerante): Android `dd/mm/aa, hh:mm - Nombre: texto` (también «p. m.»/«PM», con
  o sin coma, «-» o «–»), iOS `[dd/mm/aa, hh:mm:ss] Nombre: texto`; día o mes primero (se decide con todo el archivo;
  día primero por defecto); años de 2 o 4 dígitos; líneas sin encabezado continúan el mensaje anterior; las líneas de
  sistema (aviso de cifrado, alguien se unió) se apartan y no se importan; adjuntos `<adjunto: X>`, `<attached: X>`,
  `X (archivo adjunto)`; multimedia omitida (`<Multimedia omitido>`, `<Media omitted>`…) queda como mensaje con
  archivo faltante. Si el archivo no está en UTF-8 se lee como Windows-1252. Las fechas se interpretan en la zona
  horaria del lote. Todo esto es inferencia, pendiente de contrastar con exportaciones reales.
- **Fechas.** Sin zona: se leen en la zona horaria del lote (`Y-m-d H:i:s`, con o sin fracción, día primero,
  serial de Excel). Con zona explícita se respeta y la zona del lote no influye: ISO 8601 y volcados SQL con `T` o
  espacio, fracción opcional y `Z`, `+hh`, `+hhmm` o `+hh:mm` (por ejemplo `timestamptz` de PostgreSQL
  `2023-05-01 15:34:56.789+00`). El Whaticket de código abierto guarda las fechas con la zona de Sequelize (en su
  código, `-03:00`): para un volcado de MySQL sin zona, crea el lote con la zona en que se guardaron (por ejemplo
  `America/Sao_Paulo`), no con la de la empresa. Inferido del código público, no validado con una exportación real.
- **Marcadores de nulo de volcados SQL.** Una celda que es exactamente `\N` (mysqldump / `SELECT … INTO OUTFILE`,
  `COPY` de PostgreSQL en modo texto) o `NULL` (herramientas de base de datos) se lee como vacía en **todas las
  columnas salvo el texto del mensaje** (identificadores, fechas, archivo, tipo, banderas, nombres, contactos): un
  `mediaUrl` `\N` ya no se vuelve un archivo faltante. El texto se conserva tal cual porque un cliente puede haber
  escrito «NULL» de verdad; un mensaje cuyo texto es solo un marcador se importa con ese texto y se revisa en la
  vista previa. Los escapes con barra invertida (`\n`, `\t`, `\\`) de `mysqldump --tab` **no se decodifican**:
  esos archivos no están soportados todavía (exporta como CSV estándar, con comillas dobles).
- **Codificación.** Cada celda de un CSV se revisa por separado: UTF-8 válido se conserva y cualquier otra cosa se
  convierte desde Windows-1252, aunque los primeros 64 KB del archivo sean ASCII. Ninguna secuencia inválida llega
  a la base.
- Columnas sin campo → «columnas desconocidas»; campos obligatorios sin columna → «campos faltantes». Ambos se
  muestran; nunca se inventan columnas. Corregir el mapeo de un archivo lo vuelve a leer.

### Flujo de un lote

1. **Crear el lote** (`history-import.classify`): nombre opcional, identificador de la instalación de origen (por
   defecto `whaticket`; distingue dos Whaticket distintos en la idempotencia) y zona horaria.
2. **Subir archivos.** Cada archivo se lee en la cola `exports` (`ParseHistoryImportFile`); la pantalla muestra el
   avance y, por archivo, filas leídas/aceptadas/rechazadas, columnas desconocidas, campos faltantes y avisos con
   número de línea (sin contenido).
3. **Mapear** conexiones (obligatorio para lo que se importe o archive: una conexión existente o «Conexión histórica
   sin envío»), departamentos, agentes y etiquetas (opcional: lo que no se mapea queda sin departamento, sin agente o
   sin etiqueta, y el valor original se conserva en los datos de origen).
4. **Clasificar** el inventario (tabla paginada, 50 por página, con filtros por texto, fechas, inactividad, agente,
   departamento, conexión, estado, etiqueta, destino, tipo, duplicados, vacías, con archivos, archivos faltantes,
   grupos y avisos): por conversación, por selección (hasta 5000), sobre todo un filtro o con **reglas** que se
   previsualizan con conteos antes de aplicarse («Coinciden N; cambiarían M») y que nunca pisan una decisión manual
   salvo que se marque «sobrescribir». Borrar una regla devuelve sus decisiones a «sin decidir». Las
   **recomendaciones** son solo sugerencias: «No importar» para conversaciones sin mensajes o ya importadas sin clave
   por mensaje, «Archivar» para chats de grupo, para contactos sin número y para tickets sin contacto identificable
   (estos últimos no admiten «Importar»). Filtros añadidos con la exportación real: «Con número de teléfono» y «Sin
   número de teléfono».
5. **Resumen y confirmación** (`history-import.execute`): totales por destino, contactos (existentes, nuevos, a
   guardar), mensajes, archivos recuperables y faltantes con tamaño estimado, duplicados, grupos y bloqueos
   (archivos sin leer, conversaciones sin decidir, avisos bloqueantes, conexiones sin mapear y **asignaciones
   vencidas**: `stale_mappings`, una conexión, departamento, agente o etiqueta mapeados que se eliminaron después de
   mapearlos; hay que reasignarlos o quitarlos). La ejecución vuelve a revisarlo: un departamento, agente o etiqueta
   eliminado después de confirmar nunca se asigna (la conversación queda sin él) y una conexión eliminada deja esa
   conversación `failed` con el motivo. El resumen lleva una
   huella (`confirmation_token`, SHA-256 del resumen, los mapeos, el último cambio y el número de filas); si algo
   cambió desde que se revisó, la confirmación responde **409 `summary_changed`** («El resumen cambió desde que lo
   revisaste…») y hay que revisarlo de nuevo; si todavía se están leyendo archivos o reconstruyendo el inventario,
   **409 `batch_busy`** («Todavía se están leyendo archivos o actualizando el inventario…»). Hay que marcar «Revisé el
   resumen». Otro bloqueo es `storage_unavailable`: con el disco de adjuntos no disponible no se puede confirmar,
   porque los archivos recuperables no podrían guardarse. Toda modificación de un borrador (clasificación, mapeos,
   archivos) y la confirmación toman el mismo bloqueo de la fila del lote (`HistoryImportBatches::whileDraft`), así que
   nunca se confirma un lote a medio cambiar.
6. **Ejecución en cola** (`ExecuteHistoryImport`, cola `exports`): 50 conversaciones por tramo
   (`HISTORY_IMPORT_EXECUTE_CHUNK_SIZE`), como máximo 240 s por trabajo (`HISTORY_IMPORT_JOB_SECONDS`) y luego se
   vuelve a encolar. Por conversación, primero se copian sus archivos al disco de adjuntos **fuera de toda
   transacción**, uno a uno, anotando el resultado en cada mensaje de preparación (si el trabajo llega a su límite a
   mitad de una conversación con miles de fotos, la siguiente corrida sigue desde el siguiente archivo, sin subir nada
   dos veces). Después, **una transacción corta**: la conversación, sus mensajes, sus adjuntos, sus etiquetas, la clave
   de origen y el registro del lote, o nada. Un fallo de una conversación queda aislado y reportado sin contenido (la
   fila queda `failed` con su número de intentos). Un fallo general deja el lote `failed` y reanudable; si el disco de
   adjuntos deja de responder durante la ejecución, el lote se **pausa** (`HistoryImportPaused`, `failed` con el motivo
   en `batch.error`) y la conversación en curso queda pendiente. Cada corrida lleva un identificador propio
   (`options.run_token`) que es el dueño del bloqueo del lote: una corrida vieja que el worker mató y que la cola da
   por fallida más tarde (al vencer `retry_after`) nunca libera el bloqueo de otra corrida ni marca `failed` un lote
   que otra corrida está ejecutando. Los trabajos de lectura, inventario y ejecución tienen un tope duro
   `HISTORY_IMPORT_JOB_TIMEOUT` (600 s por defecto, como `EXPORTS_JOB_TIMEOUT`), siempre por debajo del `--timeout`
   del worker `exports` (660 s) y de `retry_after` (900 s): un archivo que tarda más se marca fallido con
   «Reintentar» en vez de que el worker lo mate. «Continuar» (`resume`, 409 `not_resumable` si no queda
   trabajo) se ejecuta como quien lo reanuda.
7. **Resultado y reporte**: `result.outcome` es `completed` o **`completed_with_errors`**; importadas, archivadas,
   duplicadas, fallidas, excluidas, mensajes, archivos disponibles y faltantes, contactos creados y guardados. Si hay
   conversaciones fallidas que se pueden reintentar (`result.retryable`), aparece **«Reintentar fallidas»** (`resume`):
   cada conversación se reintenta como máximo 3 veces (`HISTORY_IMPORT_MAX_ATTEMPTS`) y sus datos de preparación se
   conservan hasta reintentar, cancelar o vencer. Reporte CSV (UTF-8 con BOM, una fila por conversación, sin contenido
   de mensajes); toda celda que una hoja de cálculo leería como fórmula (`=`, `+`, `-`, `@`, sus formas de ancho
   completo, también tras espacios, tabulador o retorno) lleva un apóstrofo delante.

Estados del lote: `draft` (subir, leer, clasificar) → `queued` → `processing` → `completed` (con o sin errores), o
`failed` (reanudable: interrupción o pausa por almacenamiento) y `expired` (archivos borrados por retención o lote cancelado). Un lote confirmado o cancelado ya no
cambia (409 `batch_locked`). Límites: crear, confirmar y reanudar 10/min; subir archivos 30/min; acciones masivas
60/min.

### Destinos

| Destino         | Qué crea                                                                   | Dónde se ve                                                                                                | Qué nunca hace                                                                                         |
| --------------- | -------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------ |
| **Importar**    | Conversación **cerrada** con `origin = whaticket`, sus mensajes y archivos | Bandeja (filtro «Cerradas»), búsqueda, exportaciones y ficha del contacto, con la etiqueta de importada    | Entrar en métricas, abrir la ventana de 24 h, notificar, asignar, recibir mensajes nuevos o reabrirse  |
| **Archivar**    | Lo mismo, con `history_only_at` (solo historial)                           | Solo en «Historial importado» (solo lectura, con `history-import.archive-view`)                            | Aparecer en la bandeja, la búsqueda, las exportaciones ni la API; en rutas operativas responde **404** |
| **No importar** | Nada                                                                       | Solo una referencia mínima en el reporte (tras terminar, se borran los datos identificables de esas filas) | —                                                                                                      |

Reglas comunes de lo importado (`HistoryImportExecutor`):

- Fechas originales: `created_at`/`sent_at` de cada mensaje son la hora original; `closed_at` es el fin histórico,
  nunca «ahora».
- Mensajes entrantes como «Recibido» y salientes como «Enviado» con `metadata.import` (fuente y remitente original),
  **nunca** «Entregado» ni «Leído»; sin `whatsapp_message_id`. La burbuja no muestra palomitas y dice «Importado de
  Whaticket».
- No se emite ningún evento (`MessageRecorded`, `ConversationChanged`): ni notificaciones, ni automatizaciones, ni
  señales de tiempo real, ni métricas, ni asignaciones. `last_inbound_at` queda vacío y la actividad de la conexión no
  cambia: **nada se envía a Meta y no se abre ninguna ventana**.
- Solo lectura para siempre: aceptar, asignar, transferir, devolver, cerrar, reabrir, etiquetar, notas y responder
  responden 403 «Esta conversación es un historial importado y es de solo consulta.» (ver
  [bandejas §Errores](bandejas-y-asignacion.md#errores-de-las-acciones-403-404-y-409-fase-52)). El siguiente mensaje
  real del cliente abre o continúa una conversación **en vivo** (`ReceiveInboundMessage` solo busca conversaciones
  `live`).
- La API v1 nunca expone conversaciones importadas (de ningún destino).

### Contactos

`HistoryContactResolver`, **sin fusionar por nombre**:

1. El contacto al que ya se ligó ese contacto de Whaticket en un lote anterior (`import_source_keys`).
2. Si no, el **único** contacto activo con el mismo número normalizado (E.164; `521…` se convierte en `52…`).
3. Si no, un **contacto provisional** nuevo (fuera del directorio, `saved_at` nulo,
   [ADR-CONTACTS-1](decisiones.md#adr-contacts-1--contactos-provisionales-escribir-no-guarda-en-el-directorio)),
   salvo que el administrador marque «Guardar contacto» en la fila.

Varios contactos activos con el número, o solo uno eliminado, detienen esa conversación con un motivo en vez de
adivinar. Nunca se crea una identidad de WhatsApp ni se toca un `wa_id`: el primer mensaje real del cliente la liga
por el flujo normal. Un número que no se puede normalizar se conserva tal cual, sin relacionarlo con otro contacto.
Un contacto con nombre y sin número (Messenger, Instagram) es un contacto provisional por ticket, y los tickets que
no identifican a nadie comparten un único contacto provisional «Contacto no identificado (Whaticket)» y solo pueden
archivarse ([detalle](#tickets-sin-teléfono-y-tickets-sin-contacto)).

### Duplicados e idempotencia

- **Dentro del lote:** el mismo archivo (SHA-256) no se agrega dos veces; el mismo identificador de mensaje en dos
  exportaciones que se solapan se guarda una vez; cada conversación tiene una clave de origen única en el lote
  (`ticket:<id>`, `contact:<número>|<conexión>` o, para un `Chat.txt`, `chattxt:` más un hash del contenido
  normalizado: el mismo chat subido con otro nombre de archivo es el mismo).
- **Entre lotes:** la tabla `import_source_keys` (única por sistema, instalación, tipo e identificador de origen) se
  escribe en la misma transacción que la conversación o el contacto. Reanudar un lote o volver a importar la misma
  exportación no duplica.
- **Tickets del CSV de mensajes y conversaciones por contacto:** cada mensaje lleva su clave de origen (su
  identificador de Whaticket o, en la exportación real, que no lo trae, su
  [huella](#huella-de-cada-mensaje-idempotencia)) y la clave de la conversación lo anota (`details.message_keys`). Si
  un lote anterior ya la importó, no se duplica ni se omite entera: se **amplía** con los mensajes que no tenía. En el
  inventario, `already_imported` en esas filas significa «solo se agregarán los mensajes que todavía no tiene» y no
  hay recomendación de excluirlas; si no había nada nuevo, la fila termina `duplicate`.
- **Lo demás** (un `Chat.txt`, o un ticket importado por una versión anterior, sin clave por mensaje) aparece como
  duplicado («Ya se importó en una importación anterior; se omitirá para no duplicarla») y la ejecución lo cuenta
  como duplicado sin crear nada.

### Archivos históricos

Durante la ejecución, cada archivo que un mensaje referencia se busca por nombre **en su propio ZIP** (el del
`Chat.txt` que lo menciona) o, para los mensajes del CSV (o de un `Chat.txt` subido solo), en todos los ZIP del lote,
y solo si exactamente uno tiene un archivo con ese nombre: los nombres que genera el teléfono
(`IMG-20240101-WA0001.jpg`) se repiten entre exportaciones, así que nunca se adivina. Cada ZIP se revisa de nuevo con `SafeZip`, la entrada se copia a un temporal, se detecta su tipo real por
contenido y se guarda en el disco de adjuntos bajo `media/history/{aaaa}/{mm}/…`
(`MEDIA_DISK`, [almacenamiento](infraestructura-y-despliegue.md#almacenamiento-de-adjuntos-fase-52)). Los límites de
envío de WhatsApp **no** aplican al historial: se aceptan archivos de hasta 100 MB
(`HISTORY_IMPORT_MAX_MEDIA_BYTES`). Un archivo no recuperado queda como adjunto `unavailable` («Archivo histórico no
disponible») con su motivo:

| `error_code`           | Motivo                                               |
| ---------------------- | ---------------------------------------------------- |
| `historical_missing`   | No venía en los ZIP                                  |
| `historical_ambiguous` | El mismo nombre aparece en varios ZIP; no se adivina |
| `zip_entry_invalid`    | La entrada del ZIP está dañada                       |
| `type_not_allowed`     | El contenido no es un tipo permitido                 |
| `too_large`            | Más de 100 MB                                        |

En el inventario, el aviso `attachments_ambiguous` anticipa los nombres repetidos. Nunca se sirven archivos
rechazados. El historial archivado sirve sus archivos por su propia ruta autorizada (`nosniff`, CSP `sandbox`).

### Grupos

Un chat con identificador `@g.us`, o un `Chat.txt` con más de dos participantes (o, una vez marcados los de la empresa,
más de un participante que no lo sea), es un **chat de grupo**: solo puede **archivarse** o quedar como «No importar» (nunca «Importar», porque una conversación de grupo en
la bandeja invitaría a responder en privado a algo dicho en grupo). En un `Chat.txt` el administrador marca qué
participantes son la empresa («es la empresa») para saber quién envió cada mensaje; sin eso la fila tiene el aviso
bloqueante `participants_unidentified`. Ver [grupos de WhatsApp](mensajeria-whatsapp.md#12-grupos-de-whatsapp-fase-52).

### Conexión histórica sin envío

Un origen de conexión mapeado a «Conexión histórica sin envío» crea (una vez por valor de origen y lote, solo si una
conversación la necesita) la conexión «Whaticket (histórico) · <nombre de origen>»: canal WhatsApp, **sin
credenciales, inactiva y eliminada lógicamente**. No puede enviar, recibir, ni la alcanzan webhooks o la API; sus
conversaciones siguen resolviendo porque las conexiones eliminadas conservan su historial
([ADR-MIG-5](decisiones.md#adr-mig-5--conexión-histórica-sin-envío-para-el-historial-importado)). Mapear a una conexión
existente también es válido: la conversación importada sigue siendo solo lectura y nada se envía por ella.

### Permisos y auditoría

| Permiso                       | Qué permite                                                       | Reservado a raíz | Alcance global | Quién lo tiene al migrar                                        |
| ----------------------------- | ----------------------------------------------------------------- | ---------------- | -------------- | --------------------------------------------------------------- |
| `history-import.view`         | Ver lotes, inventario, resumen y reporte                          | sí               | sí             | Roles raíz                                                      |
| `history-import.classify`     | Crear lote, subir archivos, mapear, decidir, reglas, cancelar     | sí               | sí             | Roles raíz                                                      |
| `history-import.execute`      | Confirmar y reanudar                                              | sí               | sí             | Roles raíz                                                      |
| `history-import.archive-view` | Consultar el historial archivado (solo lectura), con sus archivos | no               | sí             | Roles raíz y el rol Administrador por defecto (`administrator`) |

Todos son sensibles. El grupo de permisos es «Historial de Whaticket». La migración de datos concede
`history-import.archive-view` al rol Administrador por defecto también en instalaciones existentes, igual que en una
instalación nueva; cualquier otro rol lo recibe solo desde el editor de roles
([seguridad §3.5](seguridad-y-auditoria.md#35-permisos-de-la-fase-52)). En el historial, las cuentas protegidas (raíz)
se muestran como «Administración del sistema» a quien no puede verlas y no aparecen en el filtro de agentes. Sin el permiso: 403 en la pantalla; lote,
fila o conversación que no se puede ver o no es importada: 404. Auditoría restringida: `history_import.created`,
`file_added`, `classified`, `rule_applied`, `requested`, `completed`, `failed` y `discarded` (sin contenido de
mensajes; de los nombres de archivo solo guarda su huella SHA-256).

### Retención

`history-import:prune` (diaria, 04:50): un lote no confirmado o no terminado en `HISTORY_IMPORT_RETENTION_DAYS` (30),
o uno terminado cuyo reporte venció (30 días), en **cualquier estado**, pierde sus archivos y mensajes de preparación
(y, si nunca se ejecutó, su inventario) y **todos los datos identificables** del inventario y de los archivos
(nombres, números, participantes, identificadores originales, textos de error y nombres de archivo), y queda
`expired`. Los mensajes de
preparación de un lote se borran en cuanto termina, falla o vence. **Lo importado nunca se toca.** A diferencia de las
demás transferencias (24 h), el lote de historial tiene su propia retención porque se clasifica durante días
(`DataTransferType::HistoryImport`).

### Limitaciones

- Verificado solo el CSV de «Exportar mensajes». Siguen **sin verificar** con exportaciones auténticas el reporte de
  chats, el CSV de contactos y las líneas de `Chat.txt` (inferidos).
- La exportación de mensajes no trae los archivos, ni agente, departamento, conexión o etiquetas, ni el teléfono de
  los contactos de Messenger/Instagram ([detalle](#exportación-real-de-mensajes-verificada-el-2026-11-12)).
- Un texto que parece HTML (`<b>…</b>`) se guarda sin sus etiquetas (1 mensaje de 447 429 en la exportación real).
- Sin reversión por lote (a diferencia del importador de contactos): lo importado es historial de solo lectura; para
  deshacer una importación real hay que restaurar el respaldo tomado antes.
- Sin descarga de URLs de multimedia: solo se recuperan archivos incluidos en los ZIP subidos.
- Fechas fuera de 1971–2037 se consideran inválidas.
- No se importan usuarios, contraseñas, colas, chatbots, campañas ni la configuración de Whaticket; agentes y
  departamentos solo se mapean a los existentes.
- Grupos solo como historial archivado.
- Volcados de la base del Whaticket de código abierto (tablas `Messages`, `Tickets`, `Contacts`): las columnas
  `isGroup`, `userId`, `whatsappId`, `queueId` y `contactId` **no tienen alias todavía** y no hay unión de mensajes
  con contactos por `contactId` (un volcado solo de mensajes no trae números). Inferido del código público, no
  validado con la exportación real del cliente; pendiente.
- Si el worker muere justo después de copiar un archivo al disco de adjuntos y antes de anotarlo en el mensaje de
  preparación, la siguiente corrida lo copia de nuevo y el primero queda huérfano en el disco (pendiente: anotar la
  intención antes de copiar).
- Medido con un archivo real de 447 429 mensajes en el equipo de desarrollo
  ([resultados](#resultados-medidos-con-el-archivo-real)); no se ha medido en el servidor de producción ni con varios
  archivos de ese tamaño en el mismo lote. Los límites por archivo siguen los de Whaticket (450 mil mensajes por CSV).
- La corrida real se hizo con los servicios del importador y cola `sync`; la subida por el navegador de un archivo de
  67 MB y la lectura en un worker real de la cola `exports` no se ejercitaron.

### Requisitos previos para la primera migración

1. **Autorización** para aplicar las migraciones y sincronizar permisos (abajo), con respaldo verificado de base y
   archivos ([recuperacion-y-respaldos.md](recuperacion-y-respaldos.md)).
2. **Almacenamiento de adjuntos decidido** (disco local o S3 privado) y `php artisan app:preflight` sin bloqueantes en
   `storage.media`: los archivos históricos se copian ahí.
3. El CSV de mensajes ya está verificado. Para los **demás archivos** (reporte de chats, contactos, un
   `Whaticket-medias.zip` con `Chat.txt`) siguen haciendo falta muestras auténticas y anonimizadas, revisadas en un
   **entorno que no sea de producción** (copia restaurada de la base): comparar encabezados con `HistoryColumns`,
   revisar fechas, zona horaria, dirección («Enviado por la empresa») y nombres de archivo; ajustar alias y añadir
   una prueba con la muestra antes de usarlos en producción.
4. **Conexiones, departamentos, agentes y etiquetas** creados en Chat Manager si se quieren mapear.
5. **Reglas de clasificación** acordadas con el responsable (qué se importa, qué se archiva, qué no).
6. Workers de la cola `exports` y programador en marcha.
7. Un lote piloto pequeño, revisado (inventario, resumen, resultado y reporte) antes del lote completo.

### Migraciones del importador

Las ocho migraciones son aditivas y reversibles. Las siete de la fase 5.2 **ya figuran como aplicadas** en la base
local `chatmanager` (`php artisan migrate:status`, 2026-11-12); la octava,
`2026_11_12_180000_add_details_to_history_import_messages_table`, **está pendiente** en `chatmanager` y en cualquier
servidor: se aplica con `php artisan migrate` (una columna JSON nula en una tabla de preparación, instantánea).
Procedimiento general en
[infraestructura §16 «Procedimiento de la fase 5.2»](infraestructura-y-despliegue.md#procedimiento-de-la-fase-52).

| Migración                                                        | Para qué                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             |
| ---------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `2026_10_25_210000_add_history_origin_to_conversations_table`    | `conversations.origin` (`live` por defecto: toda fila existente queda en vivo), `history_only_at`, `imported_by_transfer_id` e índices `(origin, history_only_at, last_message_at)` y `(imported_by_transfer_id)`. **Sin clave foránea a propósito:** añadir una FK a una tabla InnoDB existente usa `ALGORITHM=COPY`, reconstruiría `conversations` y bloquearía la recepción y las respuestas mientras dura; columnas con valor por defecto o nulas (instantáneas en MariaDB) e índices en línea, así que **no hay copia de la tabla**. Solo el importador escribe la columna (validada en código) |
| `2026_10_25_210001_create_import_source_keys_table`              | Idempotencia global de migraciones desde otros sistemas (clave única por origen); guarda identificadores de origen, nunca contenido                                                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| `2026_10_25_210002_create_history_import_files_table`            | Archivos del lote: huella, formato detectado, encabezados, mapeo, desconocidas, faltantes, índice del ZIP (nombres y tamaños) y conteos                                                                                                                                                                                                                                                                                                                                                                                                                                                              |
| `2026_10_25_210003_create_history_import_rules_table`            | Reglas de clasificación (filtro + destino + sobrescribir), con conteos                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |
| `2026_10_25_210004_create_history_import_conversations_table`    | Inventario: una fila por conversación detectada, con datos de origen, avisos, recomendación, decisión y resultado; sin contenido de mensajes                                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| `2026_10_25_210005_create_history_import_messages_table`         | Mensajes de preparación (texto plano) agrupados por conversación; se purgan al terminar, fallar o vencer el lote                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| `2026_10_25_210006_add_history_import_permissions`               | Crea los 4 permisos `history-import.*`, los concede a los roles raíz y concede `archive-view` al rol Administrador por defecto                                                                                                                                                                                                                                                                                                                                                                                                                                                                       |
| `2026_11_12_180000_add_details_to_history_import_messages_table` | `history_import_messages.details` (JSON nulo): ubicación, contactos compartidos, llamada perdida y campaña de cada mensaje de preparación de la exportación real                                                                                                                                                                                                                                                                                                                                                                                                                                     |

## Archivos

- `app/Services/Migration/*`: adaptadores y registro.
- `app/Services/Imports/ContactImporter.php`, `ContactImportAnalyzer.php`, `ImportBatchTracker.php` e
  `ImportBatchRollback.php`.
- `app/Jobs/ImportContacts.php` y `app/Jobs/RollbackContactImport.php`.
- `app/Models/ImportBatchRecord.php` y la migración `2026_10_22_042625_create_import_batch_records_table.php`.
- `app/Http/Controllers/Contacts/ContactImportController.php`, `app/Http/Requests/Imports/*` y `routes/imports.php`.
- `resources/js/pages/contacts/Import.vue`, `resources/js/components/imports/ImportRollbackDialog.vue` y
  `resources/js/types/imports.ts`.
- `tests/Feature/Imports/ContactImportTest.php` y `tests/Feature/Migration/*`.
- `config/imports.php`, sección `rollback`.

Importador de historial (fase 5.2):

- `app/Services/Migration/History/*` (lectura, inventario, clasificación, resumen, ejecución, archivo histórico,
  `SafeZip`, `ChatTextParser`, `HistoryColumns`), `app/Jobs/ParseHistoryImportFile.php`,
  `app/Jobs/ExecuteHistoryImport.php`, `app/Actions/HistoryImport/PruneHistoryImports.php` y
  `app/Console/Commands/PruneHistoryImports.php`.
- `app/Models/HistoryImport*.php`, `app/Enums/HistoryImport*.php`, `app/Enums/ConversationOrigin.php`,
  `app/Policies/HistoryImportPolicy.php`, `config/history-import.php`.
- `app/Http/Controllers/Admin/HistoryImport*Controller.php`, `app/Http/Requests/Admin/HistoryImport*.php` y
  `routes/admin.php` (`admin.history-import.*`, `admin.history.*`).
- `resources/js/pages/admin/history-import/*`, `resources/js/pages/admin/history/*`,
  `resources/js/components/history-import/*`, `resources/js/types/history-import.ts`, `resources/js/help/historial.ts`
  y `resources/js/tours/history-import.ts`.
- Migraciones `2026_10_25_21000{0..6}_*` (tabla de la sección anterior).
- Pruebas: `tests/Feature/HistoryImport/*`, `tests/Feature/Conversations/ImportedConversationAccessTest.php`,
  `tests/e2e/historial.mjs` (+ `historial-seed.php`) y archivos sintéticos en `tests/fixtures/whaticket/`.

Documentos relacionados: [reportes-y-exportaciones.md](reportes-y-exportaciones.md) para el motor de archivos y la
expiración, y [decisiones.md](decisiones.md) para ADR-MIG-1 a ADR-MIG-6.
