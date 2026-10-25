import type { ModuleHelp } from './types';

const help: ModuleHelp = {
    title: 'Plantillas de WhatsApp',
    objective:
        'Consulta de las plantillas aprobadas por Meta, que son el único tipo de mensaje que WhatsApp permite enviar fuera de la ventana de 24 horas. Se crean y editan en WhatsApp Manager; aquí se sincronizan y consultan. Solo los administradores con permiso las envían.',
    mainActions: [
        'Sincronizar las plantillas de cada conexión con «Sincronizar» (también se sincronizan solas cada 6 horas y cuando Meta avisa un cambio de estado).',
        'Filtrar por conexión, estado, categoría e idioma, y exportar el listado.',
        'Abrir una plantilla para ver su vista previa, sus variables, su calidad y el motivo de rechazo si lo hay.',
        'Enviar una plantilla aprobada desde una conversación de WhatsApp (solo administradores con «Enviar plantillas»).',
    ],
    workflow: [
        'Crea o edita la plantilla en WhatsApp Manager de Meta y espera su aprobación.',
        'En esta página pulsa «Sincronizar» en la conexión correspondiente para traer su estado actual.',
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
                'Imagen, video o documento indicados con una URL https pública.',
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
                'Meta ya no la devuelve. Se conserva para el historial pero no puede enviarse; si vuelve a existir, la siguiente sincronización la restaura.',
        },
    ],
    permissions: [
        '«Ver plantillas de WhatsApp» permite consultar este módulo.',
        '«Sincronizar plantillas de WhatsApp con Meta» requiere alcance global.',
        '«Enviar plantillas de WhatsApp (administrativo)» es un permiso sensible, con alcance global, que se suma al de responder la conversación. Los agentes y supervisores no lo tienen de forma predeterminada.',
    ],
    commonErrors: [
        {
            problem:
                '«Guarda el WhatsApp Business Account ID y el token de acceso antes de sincronizar plantillas.»',
            solution:
                'Completa esos datos en la conexión (Conexiones → Editar) y vuelve a sincronizar.',
        },
        {
            problem: '«Demasiadas sincronizaciones seguidas…»',
            solution:
                'Se permiten pocas sincronizaciones por minuto. Espera los segundos indicados.',
        },
        {
            problem: '«La plantilla «X» no está aprobada por Meta…»',
            solution:
                'Solo se envían plantillas aprobadas. Revisa su estado en WhatsApp Manager y sincroniza.',
        },
        {
            problem: '«Completa la variable «X».»',
            solution: 'Todas las variables son obligatorias para enviar.',
        },
        {
            problem: '«No hay plantillas aprobadas para esta conexión…»',
            solution:
                'Las plantillas pertenecen a la cuenta de WhatsApp Business de cada conexión. Sincroniza la conexión de esa conversación.',
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
