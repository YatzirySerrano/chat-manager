import type { ModuleHelp } from './types';

const help: ModuleHelp = {
    title: 'Configuración',
    objective:
        'Parámetros generales de la plataforma: nombre, zona horaria, horario de atención, días no laborables, reglas de operación y objetivos de servicio. También incluye la personalización del color principal. Los cambios quedan registrados en la Auditoría.',
    mainActions: [
        'Cambiar el nombre de la plataforma y la zona horaria.',
        'Activar el horario de atención y definirlo por día.',
        'Registrar días no laborables (una vez o «Cada año»).',
        'Definir el departamento predeterminado, el máximo de conversaciones por agente, el cierre por inactividad y si el motivo de transferencia es obligatorio.',
        'Fijar objetivos de primera respuesta y de resolución.',
        'Elegir el color principal en Personalización.',
    ],
    workflow: [
        'Revisa la zona horaria: de ella dependen los horarios, «Finalizadas hoy» y los reportes.',
        'Activa «Aplicar horario de atención» y define los días; si está desactivado, se considera atención continua.',
        'Agrega los días no laborables y pulsa «Guardar días» (se guardan aparte).',
        'Ajusta las reglas de operación y pulsa «Guardar configuración».',
    ],
    fields: [
        {
            name: 'Departamento predeterminado',
            description:
                'Recibe las conversaciones nuevas que no tienen departamento. Si queda vacío, solo las ven quienes tienen un rol que ve toda la organización.',
        },
        {
            name: 'Máximo de conversaciones por agente',
            description:
                'De 1 a 200; vacío significa sin límite. El límite del departamento o del usuario tiene prioridad.',
        },
        {
            name: 'Cierre por inactividad (horas)',
            description:
                'De 1 a 720; vacío lo desactiva. Se revisa cada hora y no envía nada al cliente.',
        },
        {
            name: 'Motivo obligatorio al transferir',
            description:
                'Si se activa, cada transferencia pide un motivo. El motivo queda en el historial y nunca se envía al cliente.',
        },
        {
            name: 'Objetivos de servicio (minutos)',
            description:
                'De 1 a 43 200, contando solo el horario de atención. Vacío para no medir cumplimiento.',
        },
        {
            name: 'Color principal (Personalización)',
            description:
                'Ocho paletas sobrias para toda la plataforma, en modo claro y oscuro. No cambia los colores personales de los chats.',
        },
    ],
    states: [
        {
            name: 'Cambios sin guardar',
            description: 'Hay cambios pendientes; guárdalos antes de salir.',
        },
    ],
    permissions: [
        '«Ver configuración» y «Modificar configuración» son solo para administradores de toda la organización; dar «Modificar configuración» pide confirmación.',
        '«Ver personalización» y «Modificar la apariencia global» controlan el color principal.',
    ],
    commonErrors: [
        {
            problem:
                '«La hora de cierre debe ser posterior a la hora de apertura.»',
            solution: 'Revisa el día indicado.',
        },
        {
            problem: '«Esta fecha ya está en la lista.»',
            solution: 'Cada día no laborable solo se registra una vez.',
        },
    ],
    tips: [
        'Cada departamento puede tener su propio horario, que reemplaza al de la plataforma.',
        'Los días no laborables no aplican cuando la atención es continua.',
    ],
    examples: [
        'Horario de lunes a viernes de 9:00 a 18:00 y sábado de 9:00 a 14:00.',
        'Objetivo de primera respuesta de 15 minutos y de resolución de 480 minutos.',
    ],
};

export default help;
