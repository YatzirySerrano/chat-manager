import type { ModuleHelp } from './types';

const help: ModuleHelp = {
    title: 'Etiquetas',
    objective:
        'Catálogo de etiquetas de color para clasificar contactos y conversaciones. Solo clasifican: no otorgan permisos, no cambian estados ni mueven conversaciones.',
    mainActions: [
        'Crear una etiqueta con nombre, descripción y color.',
        'Editar, eliminar y restaurar etiquetas.',
        'Ver cuántos contactos y conversaciones usan cada etiqueta.',
        'Aplicar etiquetas desde la ficha del contacto, el panel de detalles de la conversación o el menú de la bandeja.',
    ],
    workflow: [
        'Un administrador crea las etiquetas que usará el equipo y describe cuándo aplicarlas.',
        'Los agentes las aplican desde la conversación o el contacto con «Agregar» o «Editar».',
        'Filtra la bandeja, los contactos o los reportes por etiqueta para encontrar grupos de clientes.',
        'Si una etiqueta deja de usarse, elimínala: ya no podrá asignarse, pero se conserva donde ya estaba aplicada.',
    ],
    fields: [
        {
            name: 'Nombre',
            description:
                'Obligatorio, hasta 60 caracteres y único. No se distinguen mayúsculas de minúsculas: «VIP» y «vip» son la misma etiqueta.',
        },
        {
            name: 'Descripción (opcional)',
            description:
                'Hasta 255 caracteres. Explica cuándo aplicarla para que todos la usen igual.',
        },
        {
            name: 'Color de la etiqueta',
            description:
                'Elige un color de la paleta o uno personalizado con formato #rrggbb.',
        },
    ],
    states: [
        {
            name: 'Activa',
            description: 'Se puede asignar a contactos y conversaciones.',
        },
        {
            name: 'Eliminada',
            description:
                'No puede asignarse, pero se conserva en los contactos y conversaciones donde ya está aplicada. Puede restaurarse.',
        },
    ],
    permissions: [
        '«Ver etiquetas» muestra el catálogo.',
        '«Administrar etiquetas (crear, editar, eliminar y restaurar)» requiere un rol que vea toda la organización; restaurar también requiere «Consultar registros eliminados».',
        '«Asignar etiquetas a contactos y conversaciones» permite aplicarlas. En conversaciones, solo el responsable o quien puede asignar conversaciones.',
    ],
    commonErrors: [
        {
            problem: '«Ya existe una etiqueta con ese nombre…»',
            solution:
                'Usa la existente o elige otro nombre; las mayúsculas no cuentan como diferencia.',
        },
        {
            problem: '«La etiqueta «X» está eliminada y no puede asignarse.»',
            solution:
                'Pide a un administrador que la restaure o elige otra etiqueta.',
        },
        {
            problem: '«El color no es válido…»',
            solution:
                'Elige un color de la paleta o escribe uno con el formato #rrggbb (por ejemplo #2563eb).',
        },
    ],
    tips: [
        'Prefiere nombres de una o dos palabras y un catálogo corto; muchas etiquetas parecidas dificultan los reportes.',
        'Cada contacto o conversación admite hasta 50 etiquetas.',
    ],
    examples: [
        '«VIP» para clientes con atención preferente.',
        '«Garantía» en conversaciones sobre reclamaciones, para medirlas en Reportes.',
    ],
};

export default help;
