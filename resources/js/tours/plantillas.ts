import type { TourSteps } from './types';

export default {
    'plantillas.basico': [
        {
            id: 'estado',
            target: 'templates.stats',
            title: 'Estado de tus plantillas',
            body: 'Cuántas están aprobadas, en revisión o rechazadas por Meta.',
        },
        {
            id: 'actualizar',
            target: 'templates.sync',
            title: 'Actualizar desde Meta',
            body: 'Trae las plantillas nuevas y su estado actual.',
        },
        {
            id: 'buscar',
            target: 'templates.search',
            title: 'Buscar',
            body: 'Filtra por estado, categoría o conexión.',
        },
        {
            id: 'detalle',
            target: 'templates.table',
            title: 'Detalle',
            body: 'Abre una plantilla para ver su contenido y los datos que pide.',
        },
    ],
} satisfies TourSteps;
