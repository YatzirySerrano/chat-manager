import type { TourSteps } from './types';

export default {
    'auditoria.basico': [
        {
            id: 'filtros',
            target: 'audit.filters',
            title: 'Filtros',
            body: 'Busca por acción, persona, registro o fechas.',
        },
        {
            id: 'historial',
            target: 'audit.table',
            title: 'Historial',
            body: 'Cada fila es una operación: quién, qué y cuándo.',
        },
        {
            id: 'detalle',
            target: 'audit.row',
            title: 'Detalle',
            body: 'Abre una fila para ver qué cambió antes y después.',
        },
        {
            id: 'exportar',
            target: 'audit.export',
            title: 'Exportar',
            body: 'Descarga el historial filtrado.',
            permission: 'audit.export',
        },
    ],
} satisfies TourSteps;
