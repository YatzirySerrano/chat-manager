import type { ModuleHelp } from './types';

const help: ModuleHelp = {
    title: 'Panel principal',
    objective:
        'Vista rápida del estado de la atención según tu alcance: conversaciones activas y pendientes, cerradas hoy, tiempo de primera respuesta, demanda del periodo y equipo.',
    mainActions: [
        'Elegir el periodo («Hoy», «Últimos 7 días», «Últimos 30 días») y filtrar por departamento, agente o conexión.',
        'Ir a Reportes con «Ver reportes completos» para más detalle.',
        'Exportar el resumen, si tienes permiso.',
    ],
    workflow: [
        'Revisa «Pendientes»: son conversaciones en cola sin agente.',
        'Compara la demanda del periodo con la tendencia.',
        'Si algo llama la atención, abre Reportes o la bandeja de conversaciones.',
    ],
    fields: [
        {
            name: 'Activas',
            description: 'Conversaciones asignadas y en atención.',
        },
        {
            name: 'Pendientes',
            description: 'En cola, sin agente asignado.',
        },
        {
            name: 'Cerradas hoy',
            description: 'Desde las 00:00 en la zona horaria de la plataforma.',
        },
        {
            name: 'Primera respuesta humana (prom.)',
            description:
                'Tiempo hasta el primer mensaje escrito por una persona en el periodo.',
        },
        {
            name: 'Equipo',
            description:
                'Agentes activos que pueden atender y departamentos activos.',
        },
    ],
    states: [],
    permissions: [
        '«Ver el panel principal» muestra los indicadores; sin él la página indica que tu rol no los incluye.',
        'Los datos respetan tu alcance: toda la operación, tus departamentos o tu propia bandeja.',
        'Exportar el resumen requiere «Exportar información a Excel y PDF».',
    ],
    commonErrors: [
        {
            problem: '«La mensajería de WhatsApp aún no está conectada».',
            solution:
                'No hay una conexión con tráfico confirmado. Un administrador debe configurarla en Conexiones.',
        },
        {
            problem: '«Sin datos suficientes en el periodo».',
            solution:
                'Elige un periodo más largo o espera a que haya actividad.',
        },
    ],
    tips: [
        'Los indicadores se actualizan cada pocos segundos; usa Reportes para análisis históricos.',
    ],
    examples: [
        'Un supervisor revisa al iniciar el turno cuántas conversaciones siguen pendientes en su departamento.',
    ],
};

export default help;
