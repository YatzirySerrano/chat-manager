import type { ModuleHelp } from './types';

const help: ModuleHelp = {
    title: 'Contactos',
    objective:
        'Directorio de las personas que atiende tu equipo, con sus datos, etiquetas, procedencia e historial de conversaciones. Solo incluye contactos guardados: quien escribe por primera vez se registra de forma provisional y entra al directorio cuando alguien lo guarda.',
    mainActions: [
        'Crear un contacto con «Nuevo contacto» o guardarlo desde la conversación con «Guardar contacto».',
        'Buscar por nombre, teléfono o correo y filtrar por etiquetas, fuente y origen. Activa «Incluir no guardados» para ver también a quienes escribieron y nadie ha guardado.',
        'Abrir la ficha para ver datos, identidades por canal, campos personalizados, notas privadas e historial.',
        'Editar, eliminar (de forma lógica) y restaurar contactos.',
        'Resolver posibles duplicados en «Revisión de duplicados».',
        'Exportar la lista o importar contactos desde Excel/CSV (solo Superadministrador).',
    ],
    workflow: [
        'Un cliente escribe por primera vez: se crea un contacto provisional para poder atenderlo, pero todavía no aparece en el directorio.',
        'Desde la conversación, quien tiene permiso pulsa «Guardar contacto», revisa el nombre y lo guarda. Desde ese momento aparece en Contactos.',
        'Completa teléfono, correo, campos personalizados y etiquetas desde la ficha del contacto.',
        'Si el sistema detecta un posible duplicado, revísalo: nunca se fusionan personas de forma automática. Si el duplicado es un contacto no guardado, usa «Guardar este contacto» en lugar de crear otro.',
    ],
    fields: [
        {
            name: 'Nombre',
            description: 'Obligatorio, hasta 160 caracteres.',
        },
        {
            name: 'Teléfono',
            description:
                'Escribe 10 dígitos para México o el número completo con lada internacional (por ejemplo +1 415 555 2671). Se guarda en formato internacional.',
        },
        {
            name: 'Correo electrónico',
            description:
                'Opcional, pero se necesita al menos un teléfono o un correo.',
        },
        {
            name: 'Campos personalizados',
            description:
                'Hasta 10 datos adicionales con nombre (máx. 60) y valor (máx. 500); cada nombre debe ser distinto.',
        },
        {
            name: 'Identidades',
            description:
                'Identificadores técnicos con los que cada canal reconoce a la persona (por ejemplo, su número de WhatsApp o su visitante de Chat web). Permiten unir sus conversaciones a la misma ficha; no se editan a mano.',
        },
        {
            name: 'Fuente',
            description:
                'Cómo se registró: «Registro manual», «Importación» o automático por canal (WhatsApp, Chat web, Messenger, Instagram).',
        },
        {
            name: 'Procedencia',
            description:
                'Por qué llegó el contacto (anuncio, sitio web, código QR, recomendación…). «Confirmado por el proveedor» viene de Meta; «Declarado manualmente» lo registra un administrador con alcance global.',
        },
        {
            name: 'Notas privadas',
            description:
                'Solo las ven las personas con permiso para notas de este contacto. El cliente nunca las recibe (máx. 2000 caracteres).',
        },
    ],
    states: [
        {
            name: 'Activo',
            description: 'Aparece en el directorio y en las búsquedas.',
        },
        {
            name: 'Eliminado',
            description:
                'No aparece en las listas pero conserva todo su historial. Si vuelve a escribir, se sigue usando el mismo contacto. Puede restaurarse.',
        },
        {
            name: 'No guardado (provisional)',
            description:
                'Creado automáticamente al recibir un mensaje. Se puede atender y buscar desde la bandeja, pero no forma parte del directorio hasta guardarlo.',
        },
    ],
    permissions: [
        '«Ver contactos» muestra los contactos de tu alcance: con «Sus departamentos», los que tuvieron conversación en tus departamentos o asignada a ti, más los que tú creaste.',
        '«Crear contactos» permite registrar contactos y guardar los provisionales desde la conversación.',
        '«Editar contactos» y «Eliminar y restaurar contactos» se otorgan por separado; restaurar también requiere «Consultar registros eliminados».',
        '«Registrar notas privadas de contactos» permite ver y escribir notas privadas.',
        'La revisión de duplicados requiere ver y editar contactos con alcance global.',
    ],
    commonErrors: [
        {
            problem: '«Captura al menos un teléfono o un correo electrónico.»',
            solution: 'Agrega uno de los dos datos para poder guardar.',
        },
        {
            problem: '«El teléfono no es válido…»',
            solution:
                'Escribe 10 dígitos para México o el número completo con «+» y lada del país.',
        },
        {
            problem: 'Aparece «Posible contacto duplicado».',
            solution:
                'Ya existe alguien con el mismo teléfono o correo. Abre la ficha existente; si es otra persona, confirma con «Es otra persona, guardar de todos modos».',
        },
        {
            problem: 'Un cliente que te escribió no aparece en Contactos.',
            solution:
                'Todavía no se ha guardado. Actívalo con «Incluir no guardados» o ábrelo desde la conversación y usa «Guardar contacto».',
        },
    ],
    tips: [
        'Usa etiquetas para segmentar (por ejemplo «VIP» o «Mayorista») en lugar de escribirlo en el nombre.',
        'Antes de crear un contacto, búscalo por teléfono para evitar duplicados.',
        'El alias que pones en la bandeja es personal y no cambia el nombre de la ficha; «Guardar contacto» sí actualiza el directorio compartido.',
        'Las etiquetas del contacto son corporativas: las ve todo el equipo con permiso, igual que el directorio.',
    ],
    examples: [
        'Un prospecto escribió desde un anuncio: guárdalo desde la conversación y agrega la etiqueta «Prospecto».',
        'Un cliente cambió de correo: edita la ficha; sus conversaciones anteriores se conservan.',
    ],
};

export default help;
