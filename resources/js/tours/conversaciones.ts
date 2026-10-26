import type { TourSteps } from './types';

/**
 * On phones the inbox shows either the list or the open chat
 * (`data-pane` on the page grid): list steps only apply to the list.
 */
function listVisible(): boolean {
    return (
        document.querySelector('[data-pane]')?.getAttribute('data-pane') !==
        'chat'
    );
}

function chatOpen(): boolean {
    return document.querySelector('[data-tour="chat.actions"]') !== null;
}

export default {
    'conversaciones.basico': [
        {
            id: 'bandeja',
            target: 'inbox.general',
            title: 'Bandeja general',
            body: 'Aquí encuentras las conversaciones que puedes atender o supervisar.',
            when: listVisible,
        },
        {
            id: 'pendientes',
            target: 'inbox.pending',
            title: 'Pendientes',
            body: 'Estas conversaciones todavía necesitan que alguien las acepte.',
            when: listVisible,
        },
        {
            id: 'no-leidas',
            target: 'inbox.unread',
            title: 'No leídas',
            body: 'Muestra solo las conversaciones con mensajes que aún no revisas.',
            when: listVisible,
        },
        {
            id: 'buscar',
            target: 'inbox.search',
            title: 'Buscar',
            body: 'Encuentra clientes por nombre, teléfono u otros datos permitidos.',
            when: listVisible,
        },
        {
            id: 'filtros',
            target: 'inbox.filters',
            title: 'Filtros',
            body: 'Refina la lista por agente, estado, conexión o etiqueta. Pulsa «Aplicar» para ver el resultado.',
            when: listVisible,
        },
        {
            id: 'conversacion',
            target: 'inbox.item',
            title: 'Conversación',
            body: 'Selecciona un cliente para consultar su historial y responder cuando estés autorizado.',
            when: listVisible,
        },
        {
            id: 'acciones',
            target: 'chat.actions',
            title: 'Acciones',
            body: 'Desde aquí puedes gestionar la conversación según tus permisos: aceptar, transferir o cerrar.',
            when: chatOpen,
        },
        {
            id: 'responder',
            target: 'chat.composer',
            title: 'Responder',
            body: 'Escribe al cliente o deja una nota interna que solo ve el equipo.',
            when: chatOpen,
            mobile: { placement: 'top' },
        },
        {
            id: 'detalles',
            target: 'chat.details',
            title: 'Detalles del cliente',
            body: 'Consulta datos, etiquetas y otras conversaciones del cliente.',
            when: chatOpen,
        },
    ],
} satisfies TourSteps;
