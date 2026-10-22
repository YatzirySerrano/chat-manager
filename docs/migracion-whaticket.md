# Migración desde Whaticket

Chat Manager está preparado para recibir **contactos** exportados de Whaticket con el importador de contactos que ya
existía. No asumimos que haya una exportación compatible: el adaptador de Whaticket es una heurística y está marcado
como **pendiente de validación** hasta revisar una exportación real de la instalación de la empresa.

La importación de **conversaciones y mensajes históricos no está implementada**. Está **pendiente de validación
externa**. La última sección explica qué se necesita.

## Qué incluye

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

Lo que **no** hace: no ejecuta SQL, no restaura bases de datos, no importa modelos arbitrarios ni usuarios,
contraseñas, tokens o mensajes. Solo importa los campos de contacto: nombre, teléfono, correo, etiquetas existentes y
una nota.

Todo el módulo está reservado al **Superadministrador** con el permiso `contacts.import`, que es exclusivo de root.
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

## Conversaciones y mensajes históricos: pendiente de validación externa

No se implementó un importador de tickets ni de mensajes, porque no hay muestras auténticas y no vamos a inventar la
estructura interna de Whaticket. Para hacerlo se necesita:

1. **Muestras reales y anonimizadas** de la exportación de tickets (conversaciones) y de mensajes de la instalación a
   migrar. Puede ser un volcado de su base de datos o un CSV por tabla, con los nombres reales de sus columnas.
2. **Archivos multimedia** referenciados por los mensajes (imágenes, documentos, audio), con su ruta o URL de origen,
   para copiarlos al disco privado (`media/{yyyy}/{mm}/{uuid}`) y validar su MIME real.
3. **Mapeo de usuarios y colas** de Whaticket a usuarios y departamentos de Chat Manager. Lo define la empresa, porque
   nunca se importan cuentas ni contraseñas.
4. **Mapeo de las conexiones** (los "whatsapps" de Whaticket) a conexiones de Chat Manager, por número de teléfono.
5. Las **reglas de estado** de cada ticket (abierto, pendiente, cerrado) y cómo representarlas como historial
   cerrado sin reabrir conversaciones ni enviar nada.

La arquitectura ya lo admite:

- un `DataTransfer` por archivo con su propia clave de reporte (por ejemplo `whaticket-tickets`);
- un adaptador por formato;
- el mapeo en el asistente;
- trabajos en cola por lotes;
- el ID de Whaticket como clave de idempotencia por registro;
- trazabilidad en `import_batch_records` con otro `record_type`;
- un reporte descargable.

Ese importador deberá escribir el historial **sin enviar ningún mensaje** a Meta y sin abrir ventanas de atención.

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

Documentos relacionados: [reportes-y-exportaciones.md](reportes-y-exportaciones.md) para el motor de archivos y la
expiración, y [decisiones.md](decisiones.md) para ADR-MIG-1 a ADR-MIG-3.
