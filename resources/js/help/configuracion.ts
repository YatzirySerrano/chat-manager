import type { ModuleHelp } from './types';

const help: ModuleHelp = {
    title: 'Configuración',
    objective:
        'Parámetros generales de la plataforma: nombre, zona horaria, horario de atención, días no laborables, reglas de operación, reglas de servicio (SLA, solicitud completa, espera mínima y orden de atención) y alertas automáticas de SLA. También incluye la personalización del color principal. Los cambios quedan registrados en la Auditoría.',
    mainActions: [
        'Cambiar el nombre de la plataforma y la zona horaria.',
        'Activar el horario de atención y definirlo por día.',
        'Registrar días no laborables (una vez o «Cada año»).',
        'Definir el departamento predeterminado, el máximo de conversaciones por agente, el cierre por inactividad y si el motivo de transferencia es obligatorio.',
        'Fijar los tiempos máximos de respuesta y de resolución (SLA) y cuándo avisar que una solicitud está próxima a vencer.',
        'Exigir la confirmación de «Solicitud completa / Lista para atención», un tiempo mínimo de espera en la cola o la atención en orden de llegada.',
        'Elegir a quién avisan las alertas automáticas de SLA y cada cuánto se repiten.',
        'Elegir el color principal en Personalización.',
    ],
    workflow: [
        'Revisa la zona horaria: de ella dependen los horarios, «Finalizadas hoy» y los reportes.',
        'Activa «Aplicar horario de atención» y define los días; si está desactivado, se considera atención continua.',
        'Agrega los días no laborables y pulsa «Guardar días» (se guardan aparte).',
        'Define las reglas de servicio generales; cada departamento y cada categoría de solicitud puede personalizarlas después.',
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
            name: 'Tiempo máximo de respuesta (SLA)',
            description:
                'Plazos en minutos para la primera respuesta y para cada mensaje posterior (1 a 43 200) y para la resolución (1 a 525 600). Vacío significa sin objetivo. Se aplica a las solicitudes nuevas; las que ya están en curso conservan su plazo.',
        },
        {
            name: 'Aviso de «próxima a vencer» (% del plazo)',
            description:
                'De 50 a 95. Con 80 y un plazo de 15 minutos, el aviso aparece a los 12 minutos.',
        },
        {
            name: 'Contar solo el horario de atención',
            description:
                'Activado, el plazo se detiene fuera del horario y en días no laborables. Desactivado, corre las 24 horas.',
        },
        {
            name: 'Solicitud completa / Lista para atención',
            description:
                'Si se exige la confirmación, las solicitudes nuevas nacen como «Necesita información» y nadie puede tomarlas hasta que se confirmen. No afecta a las que ya existían.',
        },
        {
            name: 'Tiempo mínimo de espera en la cola (minutos)',
            description:
                'De 0 a 1440; 0 lo desactiva. Una solicitud lista no puede aceptarse antes de ese tiempo. Se aplica de inmediato a la cola.',
        },
        {
            name: 'Atender en orden de llegada',
            description:
                'Los agentes deben tomar primero la solicitud lista más antigua de su departamento. Se aplica de inmediato a la cola.',
        },
        {
            name: 'Alertas automáticas de SLA',
            description:
                'A quién se avisa (agente responsable, supervisión) cuando una solicitud está próxima a vencer o vence, cada cuántos minutos se recuerda una vencida (5 a 1440; vacío para no recordar) y cuántas veces como máximo (1 a 10). Son distintas de las alertas que un administrador envía a mano desde «Seguimiento de atención».',
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
        'Jerarquía de las reglas de servicio: la categoría de la solicitud tiene prioridad sobre el departamento, y el departamento sobre esta configuración general.',
        'El SLA es un tiempo máximo para responder; el tiempo mínimo de espera es lo contrario: cuánto debe aguardar una solicitud antes de poder tomarse.',
    ],
    examples: [
        'Horario de lunes a viernes de 9:00 a 18:00 y sábado de 9:00 a 14:00.',
        'Primera respuesta en 15 minutos, cada mensaje posterior en 30 y resolución en 480, con aviso al 80 % del plazo.',
    ],
};

export default help;
