import type { TourSteps } from './types';

export default {
    'sistema.basico': [
        {
            id: 'estado',
            target: 'system.overall',
            title: 'Estado general',
            body: 'Resumen de la salud técnica de la plataforma.',
        },
        {
            id: 'verificaciones',
            target: 'system.checks',
            title: 'Verificaciones',
            body: 'Cada servicio con su estado y qué hacer si falla.',
        },
        {
            id: 'tareas',
            target: 'system.failed',
            title: 'Tareas con error',
            body: 'Reintenta o descarta tareas que no se completaron.',
        },
        {
            id: 'actualizar',
            target: 'system.refresh',
            title: 'Actualizar',
            body: 'Vuelve a consultar el estado.',
        },
    ],
} satisfies TourSteps;
