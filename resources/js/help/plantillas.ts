import type { ModuleHelp } from './types';

const help: ModuleHelp = {
    title: 'Plantillas de WhatsApp',
    objective:
        'Consulta de las plantillas aprobadas por Meta, que son el único tipo de mensaje que WhatsApp permite enviar fuera de la ventana de 24 horas. Se crean y editan en WhatsApp Manager; aquí se consultan y se actualizan desde Meta. Solo los administradores con permiso las envían.',
    mainActions: [
        'Traer las plantillas de cada conexión con «Actualizar desde Meta» (también se actualizan solas cada 6 horas y cuando Meta avisa un cambio de estado).',
        'Filtrar por conexión, estado, categoría e idioma, y exportar el listado.',
        'Abrir una plantilla para ver su vista previa, sus variables, su calidad y el motivo de rechazo si lo hay.',
        'Enviar una plantilla aprobada desde una conversación de WhatsApp (solo administradores con «Enviar plantillas»).',
    ],
    workflow: [
        'Crea o edita la plantilla en WhatsApp Manager de Meta y espera su aprobación.',
        'En esta página pulsa «Actualizar desde Meta» en la conexión correspondiente para traer su estado actual.',
        'Cuando un cliente lleva más de 24 horas sin escribir, el administrador abre la conversación y usa «Enviar plantilla».',
        'Elige una plantilla «Aprobada», completa todas sus variables, revisa la vista previa y envíala.',
        'Si el cliente responde, se abre de nuevo la ventana de 24 horas y el agente puede continuar con mensajes normales.',
    ],
    fields: [
        {
            name: 'Categoría',
            description:
                '«Marketing», «Utilidad» o «Autenticación». Meta cobra según la categoría; las de autenticación (códigos de un solo uso) no se envían desde la bandeja.',
        },
        {
            name: 'Variables',
            description:
                'Datos que se completan al enviar: encabezado hasta 60 caracteres, cuerpo hasta 1024 y botones hasta 2000. Sin saltos de línea, tabuladores ni más de cuatro espacios seguidos (regla de Meta).',
        },
        {
            name: 'Encabezado con archivo',
            description:
                'Imagen, video o documento indicados con un enlace público que empiece con https://.',
        },
        {
            name: 'Ventana de 24 horas',
            description:
                'Se cuenta solo desde el último mensaje del cliente. Responder, agregar notas, transferir o reabrir la conversación no la extienden.',
        },
    ],
    states: [
        {
            name: 'Aprobada',
            description: 'La única que puede enviarse.',
        },
        {
            name: 'En revisión',
            description: 'Meta la está evaluando.',
        },
        {
            name: 'Rechazada, Pausada o Deshabilitada',
            description:
                'Requieren atención en WhatsApp Manager; el detalle muestra el motivo cuando Meta lo informa.',
        },
        {
            name: 'Eliminada en Meta',
            description:
                'Meta ya no la devuelve. Se conserva para el historial pero no puede enviarse; si vuelve a existir, se restaura la próxima vez que se actualicen las plantillas.',
        },
    ],
    permissions: [
        '«Ver plantillas de WhatsApp» permite consultar este módulo.',
        '«Actualizar plantillas de WhatsApp desde Meta» requiere un rol que vea toda la organización.',
        '«Enviar plantillas de WhatsApp (administrativo)» es un permiso de alto impacto, para roles que ven toda la organización, que se suma al de responder la conversación. Los agentes y supervisores no lo tienen de forma predeterminada.',
    ],
    commonErrors: [
        {
            problem:
                '«Esta conexión está incompleta: un administrador debe completar sus datos de Meta en Conexiones antes de actualizar plantillas.»',
            solution:
                'Completa esos datos en la conexión (Conexiones → Editar) y vuelve a pulsar «Actualizar desde Meta».',
        },
        {
            problem:
                '«Ya se actualizaron las plantillas varias veces seguidas…»',
            solution:
                'Solo se permiten unas pocas actualizaciones por minuto. Espera los segundos indicados.',
        },
        {
            problem: '«La plantilla «X» no está aprobada por Meta…»',
            solution:
                'Solo se envían plantillas aprobadas. Revisa su estado en WhatsApp Manager y pulsa «Actualizar desde Meta».',
        },
        {
            problem: '«Completa la variable «X».»',
            solution: 'Todas las variables son obligatorias para enviar.',
        },
        {
            problem: '«No hay plantillas aprobadas para esta conexión…»',
            solution:
                'Las plantillas pertenecen a la cuenta de WhatsApp Business de cada conexión. Actualiza desde Meta las plantillas de la conexión de esa conversación.',
        },
    ],
    tips: [
        'Que la ventana esté abierta no significa que el mensaje sea gratuito: Meta puede cobrar por cada conversación iniciada con una plantilla.',
        'Las plantillas solo existen en conversaciones de WhatsApp; Chat web no las usa.',
    ],
    examples: [
        'Recordatorio de cita (Utilidad): «Hola {{1}}, te recordamos tu cita del {{2}}.»',
        'Seguimiento a un cliente que escribió hace tres días y no tiene ventana abierta.',
    ],
};

export default help;
