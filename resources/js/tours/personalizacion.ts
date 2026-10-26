import type { TourSteps } from './types';

export default {
    'personalizacion.basico': [
        {
            id: 'paletas',
            target: 'appearance.palettes',
            title: 'Color de la plataforma',
            body: 'Elige la paleta para todo el equipo. Todas se leen bien en claro y oscuro.',
        },
        {
            id: 'vista-previa',
            target: 'appearance.preview',
            title: 'Vista previa',
            body: 'Mira cómo quedarán botones, insignias y gráficas antes de guardar.',
        },
        {
            id: 'guardar',
            target: 'appearance.actions',
            title: 'Guardar',
            body: 'Aplica la paleta a todos o vuelve a la predeterminada.',
            permission: 'appearance.update',
        },
    ],
} satisfies TourSteps;
