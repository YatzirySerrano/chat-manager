import type { TourSteps } from './types';

export default {
    'usuarios.basico': [
        {
            id: 'nuevo',
            target: 'users.new',
            title: 'Nuevo usuario',
            body: 'Crea una cuenta y elige su rol y departamentos.',
            permission: 'users.create',
        },
        {
            id: 'resumen',
            target: 'users.stats',
            title: 'Resumen',
            body: 'Cuántas cuentas activas, inactivas y eliminadas hay entre los usuarios que puedes administrar.',
        },
        {
            id: 'buscar',
            target: 'users.search',
            title: 'Buscar y filtrar',
            body: 'Encuentra personas por nombre, correo, rol o departamento.',
        },
        {
            id: 'acciones',
            target: 'users.row-actions',
            title: 'Acciones',
            body: 'Edita, desactiva o elimina una cuenta. Los cambios de rol se aplican al momento.',
        },
    ],
} satisfies TourSteps;
