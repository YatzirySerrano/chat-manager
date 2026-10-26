import type { ModuleHelp } from './types';

export default {
    title: 'Historial de Whaticket',
    objective:
        'Traer conversaciones anteriores de Whaticket para consultarlas en Chat Manager, decidiendo antes qué se importa, qué se archiva y qué no. Nada se crea hasta que confirmas y nunca se envía nada a los clientes.',
    mainActions: [
        'Crear un lote y subir los archivos exportados de Whaticket.',
        'Revisar cómo se leyó cada archivo y corregir sus columnas.',
        'Elegir a qué conexión, departamento, agente y etiqueta corresponde cada dato.',
        'Decidir el destino de cada conversación: una por una, en grupo o con reglas.',
        'Revisar el resumen, confirmar y descargar el reporte final.',
        'Consultar el historial importado en «Historial importado».',
    ],
    workflow: [
        'Crea un lote con la zona horaria en la que Whaticket guardó las fechas.',
        'Sube el reporte de chats, los mensajes, los contactos y el ZIP de archivos (o un Chat.txt).',
        'Revisa los archivos: columnas no reconocidas, datos que faltan y filas rechazadas.',
        'En «Correspondencias» elige la conexión de cada línea (o «Conexión histórica sin envío»).',
        'En «Clasificar» filtra, selecciona y aplica un destino, o crea reglas con vista previa.',
        'En «Resumen y confirmación» revisa los números, marca la casilla y confirma.',
        'Sigue el avance; al terminar descarga el reporte CSV.',
    ],
    fields: [
        {
            name: 'Importar',
            description:
                'Crea una conversación cerrada visible en Cerradas, la búsqueda y las exportaciones. No se reabre, no envía nada, no notifica y no cuenta en métricas.',
        },
        {
            name: 'Archivar como histórico',
            description:
                'La guarda solo para consulta en «Historial importado». No aparece en la bandeja ni en métricas.',
        },
        {
            name: 'Fechas al clasificar',
            description:
                '«Última actividad entre» usa la fecha del último mensaje de cada conversación. «Sin actividad a partir del» toma las que no tienen mensajes desde ese día, incluidas las vacías.',
        },
        {
            name: 'Fechas en el historial importado',
            description:
                '«Con actividad entre» muestra las conversaciones que tuvieron mensajes en algún momento del periodo, aunque hayan empezado antes o terminado después.',
        },
        {
            name: 'No importar',
            description:
                'No crea nada. Tampoco borra nada en Whaticket; solo queda una referencia mínima en el reporte.',
        },
        {
            name: 'Conexión histórica sin envío',
            description:
                'Una conexión de solo consulta, sin credenciales: guarda el historial de una línea que ya no usas y nunca puede enviar mensajes.',
        },
        {
            name: 'Sugerencia',
            description:
                'Una recomendación según los datos (por ejemplo, «sin mensajes»). Es solo una ayuda: tú decides.',
        },
    ],
    states: [
        {
            name: 'Borrador',
            description: 'Puedes subir archivos, corregir y clasificar.',
        },
        {
            name: 'En proceso',
            description:
                'La importación se está ejecutando; puedes seguir trabajando.',
        },
        {
            name: 'Completado',
            description: 'Terminó; el reporte final está disponible.',
        },
        {
            name: 'Con error',
            description:
                'Se detuvo. «Continuar importación» retoma sin repetir lo ya importado.',
        },
    ],
    permissions: [
        'Importar historial está reservado a la cuenta principal (superadministrador).',
        'Consultar el historial importado puede darse a otros roles, por ejemplo Administrador.',
        'Nadie puede responder ni reabrir una conversación importada desde el historial.',
    ],
    commonErrors: [
        {
            problem: '«El resumen cambió desde que lo revisaste».',
            solution:
                'Alguien cambió decisiones (u otra pestaña). Revisa el resumen actualizado y vuelve a confirmar.',
        },
        {
            problem: 'Subí un archivo equivocado (o con el tipo equivocado).',
            solution:
                'Mientras el lote sea borrador, usa «Quitar archivo» en el paso Archivos y sube el correcto. Las conversaciones que solo venían de ese archivo salen del inventario.',
        },
        {
            problem: 'Un archivo quedó «Con error» al leerlo.',
            solution:
                'Pulsa «Volver a leer». Si vuelve a fallar, revisa el motivo que aparece en el archivo, quítalo y sube una exportación nueva.',
        },
        {
            problem: 'La importación terminó con errores.',
            solution:
                '«Reintentar fallidas» vuelve a intentar solo las conversaciones que fallaron; lo ya importado no se repite. El reporte CSV indica el motivo de cada una.',
        },
        {
            problem: 'Faltan datos obligatorios en un archivo.',
            solution:
                'Abre «Corregir columnas» y elige qué columna contiene ese dato; el archivo se vuelve a leer.',
        },
        {
            problem: 'Un Chat.txt no sabe quién es la empresa.',
            solution:
                'Abre la conversación en «Clasificar» y marca los participantes que son la empresa.',
        },
        {
            problem:
                'Un archivo aparece como «Archivo histórico no disponible».',
            solution:
                'El ZIP no lo incluía. Sube un Whaticket-medias.zip que lo contenga antes de confirmar.',
        },
    ],
    tips: [
        'Empieza con una regla «No importar las conversaciones sin mensajes».',
        'Archiva lo antiguo y deja «Importar» para lo que el equipo aún consulta.',
        'Usa «Ver cuántas cambian» antes de aplicar una regla.',
    ],
    examples: [
        'Archivar como histórico todas las conversaciones sin actividad desde hace un año.',
        'Importar las del último año del departamento Ventas y archivar el resto.',
    ],
} satisfies ModuleHelp;
