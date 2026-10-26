import type { ModuleHelp } from './types';

const help: ModuleHelp = {
    title: 'Departamentos',
    objective:
        'Organiza a los equipos y define quién atiende cada tipo de conversación: sus miembros, la forma de distribuir conversaciones, el límite de carga, el horario y los mensajes automáticos.',
    mainActions: [
        'Crear, editar, eliminar y restaurar departamentos.',
        'Agregar y retirar miembros (supervisores y agentes).',
        'Elegir la distribución: bandeja compartida, asignación manual o automática.',
        'Definir el límite de conversaciones por agente, el horario de operación y los mensajes automáticos.',
        'Consultar sus métricas, la atención en este momento y las conexiones asociadas.',
    ],
    workflow: [
        'Crea el departamento con nombre, descripción y color.',
        'Agrega a sus miembros con «Agregar miembros». Quien tiene un rol que ve toda la organización no necesita ser miembro de departamentos.',
        'Elige la distribución y, si quieres, un límite de conversaciones por agente.',
        'Define el horario propio o usa el de la plataforma, y los mensajes de bienvenida y fuera de horario.',
        'En Conexiones, autoriza el departamento en las conexiones que deben llegarle.',
    ],
    fields: [
        {
            name: 'Nombre',
            description: 'Obligatorio, único, hasta 120 caracteres.',
        },
        {
            name: 'Bandeja compartida',
            description:
                'Las conversaciones nuevas quedan en «Pendientes» y cualquier agente del departamento con permiso puede tomarlas.',
        },
        {
            name: 'Asignación manual',
            description:
                'Solo quien tiene permiso de asignar entrega las conversaciones; los agentes no las aceptan por su cuenta.',
        },
        {
            name: 'Asignación automática',
            description:
                'Cada conversación nueva va al miembro activo que puede atender y tiene menos conversaciones; si nadie puede recibirla, queda en «Pendientes».',
        },
        {
            name: 'Límite de conversaciones por agente (opcional)',
            description:
                'De 1 a 200. Vacío usa el límite de la plataforma; el límite propio de un usuario tiene prioridad.',
        },
        {
            name: 'Horario de operación',
            description:
                '«Horario de la plataforma» o «Horario propio» por día, con apertura y cierre o «Cerrado».',
        },
        {
            name: 'Mensajes automáticos',
            description:
                'Bienvenida y Fuera de horario (hasta 1024 caracteres). Tienen prioridad sobre los de la conexión; vacíos usan los de la conexión.',
        },
    ],
    states: [
        {
            name: 'Activo',
            description: 'Recibe y atiende conversaciones.',
        },
        {
            name: 'Eliminado',
            description:
                'No recibe conversaciones; sus miembros e historial se conservan y puede restaurarse.',
        },
    ],
    permissions: [
        '«Ver departamentos»: si tu rol no ve toda la organización, solo ves los departamentos a los que perteneces.',
        '«Crear departamentos» y «Eliminar y restaurar departamentos» requieren un rol que vea toda la organización.',
        '«Editar departamentos» incluye horario, distribución y mensajes; «Administrar miembros» se otorga aparte.',
    ],
    commonErrors: [
        {
            problem: '«Es el departamento predeterminado de la plataforma…»',
            solution:
                'Elige otro predeterminado en Configuración antes de eliminarlo.',
        },
        {
            problem:
                '«El departamento tiene conversaciones pendientes o activas…»',
            solution: 'Ciérralas o transfiérelas antes de eliminarlo.',
        },
        {
            problem:
                '«El rol X ya ve toda la organización; no necesita pertenecer a departamentos.»',
            solution: 'Esa persona ya ve todo; no hace falta agregarla.',
        },
        {
            problem:
                '«La hora de cierre debe ser posterior a la hora de apertura.»',
            solution: 'Revisa el horario del día indicado.',
        },
    ],
    tips: [
        'Retirar a un miembro devuelve a «Pendientes» sus conversaciones abiertas en ese departamento.',
        'Cambiar la forma de distribución nunca quita conversaciones ya asignadas.',
    ],
    examples: [
        'Soporte con asignación automática y límite de 8 conversaciones por agente.',
        'Ventas con bandeja compartida y horario propio de lunes a sábado.',
    ],
};

export default help;
