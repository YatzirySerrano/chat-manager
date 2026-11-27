import type { ModuleHelp } from './types';

const help: ModuleHelp = {
    title: 'Reportes',
    objective:
        'Indicadores de la atención en el periodo que elijas, en cuatro vistas: Resumen (volumen, tiempos y demanda), Productividad (qué hizo cada colaborador), Cumplimiento de SLA y Desgloses (departamento, categoría, conexión, canal y responsable actual). Siempre se comparan con el periodo anterior.',
    mainActions: [
        'Elegir el periodo («Hoy», «Últimos 7 días», «Últimos 30 días», «Este mes», «Rango personalizado»…).',
        'Filtrar por departamento, agente responsable, conexión, canal, categoría y estado entre los que puedes ver.',
        'Agrupar el volumen por día, semana o mes.',
        'Ordenar la tabla de productividad por cualquier columna y ver la evolución de cada colaborador.',
        'Exportar a Excel (.xlsx) o PDF la vista abierta: reporte general, productividad por colaborador o cumplimiento de SLA con el detalle de incumplimientos.',
    ],
    workflow: [
        'Elige el periodo; con «Rango personalizado» indica «Desde» y «Hasta» (máximo 366 días) y pulsa «Aplicar rango».',
        'Ajusta los filtros si quieres acotar.',
        'En «Resumen» lee el volumen y los tiempos; en «Productividad» compara a los colaboradores; en «Cumplimiento de SLA» revisa dónde hay retrasos.',
        'Usa el botón de exportar de la vista abierta: los archivos pequeños se descargan al momento; los grandes se preparan en segundo plano, te avisamos con una notificación y los descargas desde ella o desde «Archivos preparados recientemente» en el mismo menú.',
    ],
    fields: [
        {
            name: 'Recibidas / Atendidas',
            description:
                'Solicitudes nuevas del periodo y, de ellas, las que una persona aceptó, asignó o respondió. Una solicitud cuenta una sola vez aunque se reasigne o tenga muchos mensajes.',
        },
        {
            name: 'Finalizadas (de las recibidas)',
            description:
                'De las recibidas en el periodo, las que se cerraron al menos una vez. Su porcentaje es el «% resueltas».',
        },
        {
            name: 'Finalizadas en el periodo',
            description:
                'Solicitudes cuyo primer cierre ocurrió en el periodo, sin importar cuándo llegaron. Es la línea «Finalizadas» de la gráfica de volumen.',
        },
        {
            name: 'Acumuladas sin atender',
            description:
                'Solicitudes recibidas que aún no tienen atención de una persona ni cierre.',
        },
        {
            name: 'Espera hasta la primera atención',
            description:
                'Desde que llega la solicitud hasta que una persona la acepta, la asigna o la responde. La distribución automática no cuenta.',
        },
        {
            name: 'Espera hasta la primera respuesta',
            description:
                'Hasta el primer mensaje escrito por una persona que el canal aceptó, con su mínimo y máximo. No cuentan mensajes automáticos ni fallidos.',
        },
        {
            name: 'Tiempo de respuesta por mensaje del cliente',
            description:
                'Tiempo entre cada mensaje del cliente que esperaba respuesta y la respuesta de una persona, con mínimo y máximo. Se registra desde la versión que lo introdujo.',
        },
        {
            name: 'Duración total hasta la resolución',
            description: 'Desde que llega la solicitud hasta su primer cierre.',
        },
        {
            name: 'Solo horario de atención (tiempo hábil)',
            description:
                'Solo cuenta el horario de atención del departamento que recibió la solicitud. Sin horario configurado equivale al tiempo real (todas las horas).',
        },
        {
            name: 'Productividad: Atendidas',
            description:
                'Solicitudes del periodo en las que la persona participó: las aceptó, se le asignaron o transfirieron, o respondió. Si dos personas atendieron la misma solicitud, cuenta para ambas.',
        },
        {
            name: 'Productividad: Finalizadas',
            description:
                'Cierres hechos mientras la persona era responsable. Si una solicitud se reabre y se vuelve a cerrar, cada cierre cuenta para quien era responsable en ese momento.',
        },
        {
            name: 'Productividad: Tiempo de atención',
            description:
                'Promedio del tiempo en que la persona fue responsable de cada solicitud. Solo se promedian los tramos ya concluidos.',
        },
        {
            name: 'Pendientes actuales / Vencidas',
            description:
                'En vivo: solicitudes asignadas ahora y sin cerrar, y las que esperan respuesta fuera de su plazo.',
        },
        {
            name: 'Cumplimiento de SLA',
            description:
                'Respuestas dentro del plazo entre las evaluadas (dentro + fuera). El plazo es el vigente cuando el cliente escribió: cambiar el objetivo después no modifica lo ya medido. Por colaborador: lo cumplido cuenta para quien respondió y lo vencido para quien era responsable al vencer.',
        },
        {
            name: 'Cartera por responsable actual',
            description:
                'Solicitudes del periodo según quién es hoy (o fue al cerrar) su responsable. No mide el trabajo de cada persona: para eso está «Productividad».',
        },
        {
            name: 'Archivos preparados',
            description:
                'Archivos grandes que pediste preparar. Solo tú puedes descargarlos y se eliminan 24 horas después de generarse.',
        },
    ],
    states: [
        {
            name: 'En espera / Generando',
            description:
                'El archivo se está preparando; te avisaremos con una notificación cuando esté listo.',
        },
        {
            name: 'Listo',
            description: 'Disponible para descargar hasta su vencimiento.',
        },
        {
            name: 'Falló / Archivo eliminado (venció)',
            description:
                'No se pudo generar o ya se eliminó al vencer su plazo. Vuelve a solicitarlo.',
        },
    ],
    permissions: [
        '«Ver reportes» permite consultar; si tu rol ve «Sus departamentos», ves tus departamentos y lo que atiendes; si ve «Solo lo asignado», solo tus indicadores.',
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
            problem: 'Una tarjeta dice «Sin datos» o «Sin datos suficientes».',
            solution:
                'Aún no hay solicitudes con el dato necesario en el periodo; no se muestra un cero falso ni un 100 % de cumplimiento.',
        },
        {
            problem: 'El SLA indica «Disponible desde…».',
            solution:
                'El cumplimiento por respuesta se registra desde esa fecha; el historial anterior no se reconstruye. La tarjeta «Referencia con el objetivo general de hoy» da una orientación para periodos anteriores.',
        },
        {
            problem:
                'Productividad avisa «sin calcular para parte del periodo» o muestra «Sin datos» en lugar de cifras.',
            solution:
                'Tras una actualización, el detalle por persona de las solicitudes anteriores se construye con la reconstrucción de analítica que ejecuta quien administra el servidor. Hasta que termina, las cifras por persona son parciales y no se muestran ceros falsos; después el aviso desaparece solo.',
        },
        {
            problem:
                'En «Cumplimiento de SLA» hay dos columnas de exceso promedio.',
            solution:
                'El exceso de cada respuesta se mide en la unidad de su objetivo: tiempo hábil si el objetivo cuenta solo el horario de atención, tiempo corrido si no. Por eso se promedian por separado y nunca se mezclan.',
        },
        {
            problem:
                'La suma de «Atendidas» por colaborador es mayor que las solicitudes atendidas.',
            solution:
                'Es correcto: una solicitud atendida por dos personas cuenta para ambas. El total de solicitudes distintas aparece en la nota de la vista.',
        },
    ],
    tips: [
        'Los resultados se recalculan cada pocos minutos; los tiempos en horario de atención ya calculados no cambian si después editas el horario.',
        'Para revisar a un colaborador, abre «Productividad», ordena la tabla y elige su nombre en «Evolución individual».',
        'Si el periodo no ha terminado, la comparación usa el mismo tramo transcurrido del periodo anterior.',
    ],
    examples: [
        'Medir el efecto de una campaña: compara «Recibidas» de esta semana contra la anterior.',
        'Revisar si Soporte cumple su plazo de respuesta: en «Cumplimiento de SLA» filtra el departamento y exporta el detalle de incumplimientos.',
    ],
};

export default help;
