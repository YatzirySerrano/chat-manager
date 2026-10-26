import type { TourSteps } from './types';

export default {
    'respuestas-rapidas.basico': [
        {
            id: 'tipos',
            target: 'quick-replies.segments',
            title: 'Corporativas y personales',
            body: 'Las corporativas son del equipo; las personales solo las ves tú.',
        },
        {
            id: 'nueva',
            target: 'quick-replies.new',
            title: 'Nueva respuesta',
            body: 'Escribe un texto y un atajo. En el chat lo insertas escribiendo «/» y el atajo.',
        },
        {
            id: 'buscar',
            target: 'quick-replies.search',
            title: 'Buscar',
            body: 'Filtra por departamento, tipo o autor.',
        },
        {
            id: 'prueba',
            target: 'quick-replies.sandbox',
            title: 'Pruébala',
            body: 'Practica aquí: escribe «/» como en el chat. No se envía nada.',
            permission: 'quick-replies.use',
        },
    ],
} satisfies TourSteps;
