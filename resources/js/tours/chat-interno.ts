import type { TourSteps } from './types';

export default {
    'chat-interno.basico': [
        {
            id: 'nuevo',
            target: 'team-chat.new',
            title: 'Nuevo chat',
            body: 'Escribe a un compañero o crea un grupo.',
        },
        {
            id: 'buscar',
            target: 'team-chat.search',
            title: 'Buscar',
            body: 'Encuentra un chat por nombre.',
        },
        {
            id: 'chats',
            target: 'team-chat.threads',
            title: 'Tus chats',
            body: 'Los que tienen mensajes nuevos se marcan con un contador.',
        },
        {
            id: 'escribir',
            target: 'team-chat.composer',
            title: 'Escribir',
            body: 'Envía mensajes y archivos al equipo. Los clientes nunca ven este chat.',
            mobile: { placement: 'top' },
        },
    ],
} satisfies TourSteps;
