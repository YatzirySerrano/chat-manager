import type { TourSteps } from './types';

export default {
    'conexiones.basico': [
        {
            id: 'nueva',
            target: 'connections.new',
            title: 'Nueva conexión',
            body: 'Conecta un número de WhatsApp, el chat de tu sitio, Messenger o Instagram.',
            permission: 'connections.manage',
        },
        {
            id: 'estado',
            target: 'connections.stats',
            title: 'Estado general',
            body: 'Cuántas conexiones funcionan y cuántas necesitan atención.',
        },
        {
            id: 'conexion',
            target: 'connections.item',
            title: 'Conexión',
            body: 'Ábrela para revisar su diagnóstico y lo que falta para recibir mensajes.',
        },
    ],
} satisfies TourSteps;
