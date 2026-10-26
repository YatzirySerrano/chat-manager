import type { TourSteps } from './types';

export default {
    'integraciones.basico': [
        {
            id: 'nueva',
            target: 'integrations.new',
            title: 'Nueva clave de acceso',
            body: 'Crea una clave de acceso (token) para que otro sistema, como tu CRM, se conecte.',
            permission: 'integrations.manage',
        },
        {
            id: 'claves',
            target: 'integrations.tokens',
            title: 'Claves de acceso',
            body: 'Estado, permisos y vencimiento de cada clave.',
        },
        {
            id: 'revocar',
            target: 'integrations.revoke',
            title: 'Revocar',
            body: 'Desactiva una clave al momento si ya no se usa o alguien más la conoce.',
        },
    ],
} satisfies TourSteps;
