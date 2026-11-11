import type { ModuleHelp } from './types';

const help: ModuleHelp = {
    title: 'Panel principal',
    objective:
        'Vista ejecutiva de la atención con la información que puedes ver. Cada gráfica responde una pregunta: si estamos respondiendo más rápido, qué porcentaje cumplió el SLA, dónde se acumulan los retrasos, quién tiene más solicitudes y cuándo llega la demanda.',
    mainActions: [
        'Elegir el periodo («Hoy», «Últimos 7 días», «Últimos 30 días») y filtrar por departamento, agente responsable, conexión, canal, categoría o estado.',
        'Cambiar la gráfica de volumen entre día, semana y mes.',
        'Abrir en la bandeja la lista detrás de una cifra en vivo: «Pendientes», «En atención» o el nombre de una persona en «¿Quién tiene más solicitudes pendientes?».',
        'Ver cualquier gráfica como tabla con «Ver como tabla».',
        'Ir a Reportes con «Ver reportes completos» o exportar el resumen, si tienes permiso.',
    ],
    workflow: [
        'Empieza por «Ahora»: lo que está pendiente y en atención en este momento.',
        'Revisa «Resultado del periodo»: recibidas, atendidas, finalizadas y la espera hasta la primera respuesta, contra el periodo anterior.',
        'En «Tiempos y cumplimiento» mira si la espera baja y qué porcentaje de respuestas llegó dentro del plazo.',
        'En «Carga de trabajo» localiza quién tiene más solicitudes y si se están acumulando sin atender.',
        'Si algo llama la atención, abre la lista en la bandeja o el detalle en Reportes.',
    ],
    fields: [
        {
            name: 'Pendientes / En atención',
            description:
                'En vivo: las que esperan que alguien las acepte y las que tienen una persona responsable. No dependen del periodo ni del filtro de estado.',
        },
        {
            name: 'Finalizadas hoy',
            description:
                'En vivo: cerradas desde las 00:00 (zona horaria de la plataforma) y que siguen cerradas. Es distinta de «Finalizadas» del periodo.',
        },
        {
            name: 'Recibidas / Atendidas / Finalizadas',
            description:
                'Solicitudes que llegaron en el periodo; de ellas, las que una persona aceptó, asignó o respondió, y las cerradas al menos una vez. Cada solicitud cuenta una sola vez.',
        },
        {
            name: 'Espera hasta la primera respuesta',
            description:
                'Promedio desde que llega la solicitud hasta el primer mensaje escrito por una persona. Indica cuántas solicitudes se midieron (n).',
        },
        {
            name: '¿Qué porcentaje cumplió el SLA?',
            description:
                'De las veces que un cliente esperó respuesta con un plazo definido, cuántas se respondieron a tiempo. Una solicitud sin responder que ya superó su plazo cuenta como fuera del plazo; las que no tienen objetivo o siguen dentro del plazo no se evalúan.',
        },
        {
            name: '¿Qué departamento acumula más retrasos?',
            description:
                'Respuestas fuera del plazo según el departamento que tenía la solicitud en ese momento.',
        },
        {
            name: '¿Quién tiene más solicitudes pendientes?',
            description:
                'En vivo: solicitudes asignadas ahora y sin cerrar por persona, más las que esperan en cola sin responsable y las vencidas.',
        },
        {
            name: '¿Se nos están acumulando solicitudes?',
            description:
                'Solicitudes recibidas que seguían sin atención de una persona ni cierre al terminar cada día, semana o mes.',
        },
        {
            name: 'Comparación',
            description:
                'Si el periodo no ha terminado (por ejemplo «Hoy» a mediodía), se compara con el mismo tramo transcurrido del periodo anterior, no con el periodo completo.',
        },
    ],
    states: [],
    permissions: [
        '«Ver el panel principal» muestra los indicadores; sin él la página indica que tu rol no los incluye.',
        'Solo ves los datos que tu rol permite: toda la organización, tus departamentos o tus propias conversaciones. Con alcance propio no aparecen cifras de compañeros.',
        'Exportar el resumen requiere «Exportar información a Excel y PDF».',
    ],
    commonErrors: [
        {
            problem: '«Aún no hay canales con mensajes confirmados».',
            solution:
                'No hay una conexión con tráfico confirmado. Un administrador debe configurarla en Conexiones.',
        },
        {
            problem:
                'El cumplimiento de SLA dice «Sin datos» o «Disponible desde…».',
            solution:
                'El cumplimiento se registra por cada respuesta desde la versión que lo introdujo; los días anteriores no tienen registro y no se inventan. También aparece «Sin datos» si no hay objetivos de servicio configurados.',
        },
        {
            problem: 'Un panel indica que el filtro de estado no se aplica.',
            solution:
                'Las cifras en vivo, el acumulado y el SLA no dependen del estado actual de la solicitud. El resto del panel sí sigue el filtro.',
        },
        {
            problem: '«Sin solicitudes recibidas en este periodo».',
            solution:
                'Elige un periodo más largo o revisa los filtros aplicados.',
        },
    ],
    tips: [
        'Los indicadores se actualizan cada pocos segundos; usa Reportes para análisis históricos y productividad por persona.',
        'Los filtros quedan en la dirección de la página: puedes compartir el enlace con la misma vista.',
    ],
    examples: [
        'Un supervisor revisa al iniciar el turno cuántas solicitudes siguen pendientes en su departamento y abre la lista desde la tarjeta.',
        'La dirección compara el cumplimiento de SLA de los últimos 30 días contra los 30 anteriores.',
    ],
};

export default help;
