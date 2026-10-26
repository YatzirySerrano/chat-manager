import type { TourSteps } from './types';

export default {
    'etiquetas.basico': [
        {
            id: 'nueva',
            target: 'tags.new',
            title: 'Nueva etiqueta',
            body: 'Crea una etiqueta con nombre y color.',
            permission: 'tags.manage',
        },
        {
            id: 'buscar',
            target: 'tags.search',
            title: 'Buscar',
            body: 'Encuentra etiquetas y muestra las eliminadas si las necesitas.',
        },
        {
            id: 'uso',
            target: 'tags.list',
            title: 'Uso',
            body: 'Cuántos contactos y conversaciones tienen cada etiqueta.',
        },
    ],
} satisfies TourSteps;
