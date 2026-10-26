import type { TourSteps } from './types';

export default {
    'dashboard.basico': [
        {
            id: 'filtros',
            target: 'dashboard.filters',
            title: 'Periodo y filtros',
            body: 'Elige el periodo, departamento, agente o conexión. Todo el panel se recalcula con lo que elijas.',
            permission: 'dashboard.view',
        },
        {
            id: 'indicadores',
            target: 'dashboard.kpis',
            title: 'Cómo va la atención',
            body: 'Pendientes, en atención, finalizadas hoy y el tiempo promedio de primera respuesta.',
            permission: 'dashboard.view',
        },
        {
            id: 'actividad',
            target: 'dashboard.activity',
            title: 'Actividad',
            body: 'Compara cuántas conversaciones llegaron y cuántas se atendieron cada día.',
            permission: 'dashboard.view',
        },
        {
            id: 'equipo',
            target: 'dashboard.team',
            title: 'Equipo',
            body: 'Revisa la carga y la atención de cada agente que puedes ver.',
            permission: 'dashboard.view',
        },
        {
            id: 'exportar',
            target: 'dashboard.export',
            title: 'Exportar',
            body: 'Descarga este resumen en Excel o PDF con los mismos filtros.',
            permission: 'reports.export',
        },
        {
            id: 'reportes',
            target: 'dashboard.reports-link',
            title: 'Más detalle',
            body: 'Abre Reportes para ver todos los indicadores y tablas.',
            permission: 'reports.view',
        },
        {
            id: 'sin-indicadores',
            target: 'dashboard.no-metrics',
            title: 'Tu panel',
            body: 'Tu rol no incluye indicadores. Usa el menú para ir a tus módulos.',
        },
    ],
} satisfies TourSteps;
