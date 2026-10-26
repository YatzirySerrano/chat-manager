import type { TourSteps } from './types';

export default {
    'contactos.basico': [
        {
            id: 'nuevo',
            target: 'contacts.new',
            title: 'Nuevo contacto',
            body: 'Registra a un cliente a mano con su teléfono o correo.',
            permission: 'contacts.create',
        },
        {
            id: 'buscar',
            target: 'contacts.search',
            title: 'Buscar y filtrar',
            body: 'Busca por nombre, teléfono o correo y filtra por etiqueta u origen.',
        },
        {
            id: 'mas',
            target: 'contacts.more',
            title: 'Importar y revisar',
            body: 'Importa contactos desde Excel o revisa posibles duplicados.',
            mobile: { target: 'contacts.more-mobile' },
        },
        {
            id: 'lista',
            target: 'contacts.list',
            title: 'Tus contactos',
            body: 'Abre un contacto para ver su ficha, historial y notas.',
        },
    ],
    'contactos.ficha': [
        {
            id: 'acciones',
            target: 'page.actions',
            title: 'Acciones',
            body: 'Guarda, edita o elimina este contacto según tus permisos.',
        },
        {
            id: 'historial',
            target: 'contact.history',
            title: 'Historial',
            body: 'Todas las conversaciones de este cliente que puedes ver.',
        },
        {
            id: 'etiquetas',
            target: 'contact.tags',
            title: 'Etiquetas',
            body: 'Clasifica al cliente para encontrarlo y filtrar después.',
        },
    ],
} satisfies TourSteps;
