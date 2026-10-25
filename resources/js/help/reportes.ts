import type { ModuleHelp } from './types';

const help: ModuleHelp = {
    title: 'Reportes',
    objective:
        'Indicadores de la atención en el periodo que elijas: volumen, tiempos de respuesta y resolución, cumplimiento de objetivos y carga por agente, departamento y conexión. Siempre se comparan con el periodo anterior de la misma duración.',
    mainActions: [
        'Elegir el periodo («Hoy», «Últimos 7 días», «Últimos 30 días», «Este mes», «Rango personalizado»…).',
        'Filtrar por departamento, agente y conexión dentro de tu alcance.',
        'Revisar tiempos en tiempo calendario y en tiempo hábil.',
        'Exportar el reporte a Excel (.xlsx) o PDF y descargarlo desde Exportaciones.',
    ],
    workflow: [
        'Elige el periodo; con «Rango personalizado» indica «Desde» y «Hasta» (máximo 366 días) y pulsa «Aplicar rango».',
        'Ajusta departamento, agente o conexión si quieres acotar.',
        'Lee la fila «Atención del periodo» y después los tiempos y las tablas por agente, departamento y conexión.',
        'Usa «Exportar reporte»: los archivos pequeños se descargan al momento; los grandes se generan en segundo plano y aparecen en Exportaciones.',
    ],
    fields: [
        {
            name: 'Recibidas / Atendidas / Resueltas',
            description:
                'Conversaciones nuevas del periodo; las que una persona aceptó, asignó o respondió; y las cerradas al menos una vez.',
        },
        {
            name: 'Primera atención (prom.)',
            description:
                'Desde que llega la conversación hasta que una persona la acepta, la asigna o la responde. La distribución automática no cuenta.',
        },
        {
            name: 'Primera respuesta humana (prom.)',
            description:
                'Hasta el primer mensaje escrito por una persona que el canal aceptó. No cuentan mensajes automáticos ni fallidos.',
        },
        {
            name: 'Tiempo hábil',
            description:
                'Solo cuenta el horario de atención del departamento que recibió la conversación. Sin horario configurado equivale al tiempo calendario.',
        },
        {
            name: 'Objetivos de servicio',
            description:
                'Porcentaje de conversaciones dentro del objetivo de primera respuesta y de resolución definidos en Configuración.',
        },
        {
            name: 'Exportaciones',
            description:
                'Archivos que solicitaste. Solo tú puedes descargarlos y se eliminan 24 horas después de generarse.',
        },
    ],
    states: [
        {
            name: 'En cola / Generando',
            description:
                'El archivo se está preparando; la página se actualiza sola.',
        },
        {
            name: 'Listo',
            description: 'Disponible para descargar hasta su vencimiento.',
        },
        {
            name: 'Falló / Expirado',
            description:
                'No se pudo generar o ya se eliminó al vencer su plazo. Vuelve a solicitarlo.',
        },
    ],
    permissions: [
        '«Ver reportes» permite consultar; con alcance «Sus departamentos» ves tus departamentos y lo que atiendes, con «Solo lo asignado» solo tus indicadores.',
        '«Exportar información a Excel y PDF» habilita los botones de exportación.',
    ],
    commonErrors: [
        {
            problem:
                '«No se pudieron aplicar los filtros… Se muestran los últimos 30 días.»',
            solution:
                'Revisa las fechas: «Hasta» debe ser igual o posterior a «Desde».',
        },
        {
            problem: '«El reporte tiene N registros y el máximo para … es …»',
            solution:
                'Excel admite hasta 50 000 filas y PDF hasta 5000. Aplica filtros o un periodo más corto.',
        },
        {
            problem: 'Una tarjeta dice «Sin datos suficientes».',
            solution:
                'Aún no hay conversaciones con los eventos necesarios en el periodo; no se muestra un cero falso.',
        },
    ],
    tips: [
        'Los resultados se recalculan cada pocos minutos; los valores en tiempo hábil ya calculados no cambian si después editas el horario.',
        'Para revisar a un agente, filtra por él y compara contra el periodo anterior.',
    ],
    examples: [
        'Medir el efecto de una campaña: compara «Recibidas» de esta semana contra la anterior.',
        'Revisar si Soporte cumple el objetivo de primera respuesta de 15 minutos hábiles.',
    ],
};

export default help;
