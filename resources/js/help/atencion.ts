import type { ModuleHelp } from './types';

const help: ModuleHelp = {
    title: 'Seguimiento de atención',
    objective:
        'Muestra qué atiende cada agente, qué clientes esperan respuesta y desde cuándo, y —en «Monitoreo de SLA»— qué solicitudes están en tiempo, por vencer o vencidas frente a su tiempo máximo de respuesta. Solo ves lo que te corresponde por tu rol y tus departamentos.',
    mainActions: [
        'Revisar por agente cuántas conversaciones atiende y cuántos clientes esperan su respuesta.',
        'Abrir «Monitoreo de SLA» para ver las solicitudes esperando respuesta, ordenadas de la más urgente a la menos urgente.',
        'Filtrar el monitoreo por estado del SLA, departamento, categoría, agente responsable y conexión.',
        'Consultar el «Historial de incumplimientos» de un periodo: qué solicitud venció, quién era responsable y qué pasó después.',
        'Abrir cualquier conversación directamente desde la tabla.',
        'Enviar recordatorios a uno, varios o todos los agentes (si tienes ese permiso).',
    ],
    workflow: [
        'Un cliente escribe: empieza a contar el tiempo de respuesta de esa solicitud, con el objetivo vigente en ese momento.',
        'Al llegar al porcentaje de aviso, la solicitud pasa a «Por vencer» y el sistema avisa al agente responsable (y a supervisión, si está activado).',
        'Si el plazo se agota sin que una persona responda, pasa a «Vencida»: se avisa al agente y a supervisión, queda registrada en el historial y en la conversación.',
        'Mientras siga sin respuesta, el sistema puede repetir el aviso cada cierto tiempo, hasta el máximo de recordatorios configurado.',
        'Cuando una persona responde (o la conversación se cierra) la solicitud sale del monitoreo. El siguiente mensaje del cliente inicia una nueva.',
    ],
    fields: [
        {
            name: 'En tiempo / Por vencer / Vencida',
            description:
                'Semáforo de la solicitud frente a su plazo: verde dentro del plazo, amarillo desde el aviso de «por vencer» y rojo cuando el plazo se agotó. Siempre se acompaña de texto e ícono.',
        },
        {
            name: 'Sin objetivo configurado',
            description:
                'El cliente espera respuesta, pero cuando escribió no había un tiempo máximo definido para su departamento o categoría. Se registra cuánto tarda la respuesta, pero no se compara contra un plazo.',
        },
        {
            name: 'Tiempo transcurrido y tiempo restante o vencido',
            description:
                'Cuánto lleva esperando el cliente y cuánto falta para la fecha límite (o cuánto la superó). Se actualizan solos en pantalla.',
        },
        {
            name: 'Objetivo: primera respuesta o respuesta',
            description:
                '«Primera respuesta» aplica mientras nadie del equipo ha contestado nunca en la conversación; después, cada nuevo mensaje del cliente se mide contra el objetivo de «respuesta».',
        },
        {
            name: 'Horario de atención',
            description:
                'Si el objetivo cuenta solo el horario de atención, el reloj se detiene fuera de horario, fines de semana y días no laborables del departamento; la fecha límite ya lo considera.',
        },
        {
            name: 'Responsable al vencer',
            description:
                'Quién tenía asignada la conversación en el instante en que venció. Si estaba en la cola, aparece «En cola, sin responsable». Reasignar después no cambia este dato.',
        },
        {
            name: 'Excedió por',
            description:
                'Tiempo transcurrido desde la fecha límite hasta que se respondió o se cerró. Si la solicitud sigue abierta, sigue contando.',
        },
    ],
    states: [
        {
            name: 'Recordatorios enviados por administradores',
            description:
                'Mensajes que una persona escribe y envía a los agentes desde «Seguimiento de agentes». El agente los ve en pantalla hasta que pulsa «Entendido».',
        },
        {
            name: 'Alertas automáticas de SLA',
            description:
                'Avisos que genera el sistema cuando una solicitud está por vencer o venció. Llegan a la campana de notificaciones con la marca «SLA automático»; nadie los escribe.',
        },
        {
            name: 'Control de conversaciones pendientes',
            description:
                'Regla que impide a un agente aceptar conversaciones nuevas mientras tenga clientes esperando más del tiempo tolerado. No envía avisos y no depende de los objetivos de SLA.',
        },
    ],
    permissions: [
        '«Ver el seguimiento de atención» permite abrir esta pantalla, el monitoreo de SLA y recibir las alertas de SLA de supervisión.',
        '«Enviar alertas de atención» permite además enviar recordatorios a los agentes y consultar su historial.',
        'Siempre ves únicamente las conversaciones que tu rol te permite ver: todas, las de tus departamentos o las tuyas.',
        'El enlace a «Objetivos de servicio» solo aparece a quien puede editar la configuración.',
    ],
    commonErrors: [
        {
            problem:
                'El monitoreo dice «No hay objetivos de respuesta configurados».',
            solution:
                'Un administrador debe definirlos en Configuración → Objetivos de servicio (o en las reglas de un departamento o categoría). Aplican a las solicitudes que inicien después.',
        },
        {
            problem:
                'Cambié el objetivo y las solicitudes en curso conservan el plazo anterior.',
            solution:
                'Es lo esperado: el plazo se fija cuando el cliente escribe y no se mueve con cambios de configuración, transferencias ni cambios de categoría. El nuevo valor aplica desde la siguiente solicitud.',
        },
        {
            problem:
                'Envié un mensaje automático o una nota y la solicitud sigue contando.',
            solution:
                'Solo la respuesta de una persona (texto, archivo o plantilla) detiene el reloj. Los mensajes automáticos, las respuestas de integraciones, las notas internas y marcar como leído no cuentan.',
        },
        {
            problem: 'No recibo las alertas de SLA.',
            solution:
                'Revisa en tu perfil → Notificaciones que «Alertas automáticas de SLA» estén activadas, y pide a un administrador que confirme en Configuración → Alertas automáticas a quién se avisa.',
        },
        {
            problem:
                'Una notificación de SLA dice que la solicitud «ya fue respondida».',
            solution:
                'El texto refleja el estado actual: alguien respondió después de que se generó el aviso. Ya no requiere acción.',
        },
    ],
    tips: [
        'Deja abierto «Monitoreo de SLA» con el filtro «Por vencer» para actuar antes de que una solicitud venza.',
        'Reasignar una conversación no reinicia el reloj: el cliente lleva esperando todo ese tiempo.',
        'Una respuesta cuenta desde que el agente la envía, aunque el canal tarde en entregarla.',
        'Si supervisas varios departamentos, recibes un solo aviso por departamento que agrupa las solicitudes vencidas.',
    ],
    examples: [
        'Objetivo de primera respuesta de 10 minutos con aviso al 80 %: el cliente escribe a las 10:00, la solicitud pasa a «Por vencer» a las 10:08 y a «Vencida» después de las 10:10.',
        'Con horario de atención de 9:00 a 18:00 y un objetivo de 60 minutos, un mensaje recibido a las 20:00 vence a las 10:00 del siguiente día hábil.',
        'Una solicitud vence estando asignada a Ana y luego se reasigna a Beto, que responde: el historial muestra a Ana como responsable al vencer y a Beto como quien respondió.',
    ],
};

export default help;
