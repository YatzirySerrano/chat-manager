import type { TourSteps } from './types';

export default {
    'reportes.basico': [
        {
            id: 'filtros',
            target: 'reports.filters',
            title: 'Filtros',
            body: 'Elige periodo, departamento, agente o conexión. Aplican a todo el reporte y a la exportación.',
        },
        {
            id: 'indicadores',
            target: 'reports.kpis',
            title: 'Indicadores clave',
            body: 'Recibidas, atendidas, resueltas y pendientes, comparadas con el periodo anterior.',
        },
        {
            id: 'tendencia',
            target: 'reports.trend',
            title: 'Tendencia',
            body: 'Cómo cambió la demanda día a día.',
        },
        {
            id: 'tabla',
            target: 'reports.table-toggle',
            title: 'Ver como tabla',
            body: 'Cambia cualquier gráfica por sus datos exactos.',
        },
        {
            id: 'tiempos',
            target: 'reports.times',
            title: 'Tiempos de atención',
            body: 'Primera respuesta y resolución promedio, comparadas con el periodo anterior.',
        },
        {
            id: 'horarios',
            target: 'reports.heatmap',
            title: 'Horarios de mayor demanda',
            body: 'Qué días y horas llegan más conversaciones, para planear turnos.',
        },
        {
            id: 'exportar',
            target: 'reports.export',
            title: 'Exportar',
            body: 'Descarga el reporte en Excel o PDF con los filtros actuales.',
            permission: 'reports.export',
        },
    ],
    'reportes.exportaciones': [
        {
            id: 'archivos',
            target: 'exports.list',
            title: 'Tus archivos',
            body: 'Aquí aparecen los Excel y PDF que generaste. Se eliminan al vencer.',
        },
        {
            id: 'descargar',
            target: 'exports.download',
            title: 'Descargar',
            body: 'Descarga el archivo cuando esté listo.',
        },
    ],
} satisfies TourSteps;
