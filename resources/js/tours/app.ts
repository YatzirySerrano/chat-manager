import type { TourSteps } from './types';

/** Platform tour, offered on every screen («Conoce la plataforma»). */
export default {
    'app.inicio': [
        {
            id: 'menu',
            target: 'shell.nav',
            title: 'Menú principal',
            body: 'Aquí están los módulos que tu rol puede usar. Si no ves alguno, no lo tienes asignado.',
            placement: 'right',
            mobile: {
                target: 'shell.menu',
                body: 'Abre el menú para ver los módulos de tu rol.',
                placement: 'bottom',
            },
        },
        {
            id: 'notificaciones',
            target: 'shell.notifications',
            title: 'Notificaciones',
            body: 'Te avisamos de conversaciones nuevas, asignaciones y transferencias. Ábrelas para ir directo.',
        },
        {
            id: 'tiempo-real',
            target: 'shell.realtime',
            title: 'Conexión en vivo',
            body: 'Indica si la pantalla se actualiza sola. Si se desconecta, recarga para ver lo último.',
        },
        {
            id: 'apariencia',
            target: 'shell.theme',
            title: 'Modo claro u oscuro',
            body: 'Cambia la apariencia para trabajar más cómodo. Solo te afecta a ti.',
        },
        {
            id: 'ayuda',
            target: 'shell.help',
            title: 'Ayuda',
            body: 'Desde aquí abres los recorridos de cada pantalla y la guía completa del módulo.',
        },
    ],
} satisfies TourSteps;
