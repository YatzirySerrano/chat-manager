import type { TourSteps } from './types';

export default {
    'roles.basico': [
        {
            id: 'nuevo',
            target: 'roles.new',
            title: 'Nuevo rol',
            body: 'Crea un rol con los permisos y el alcance que necesita un puesto.',
            permission: 'roles.create',
        },
        {
            id: 'resumen',
            target: 'roles.card',
            title: 'Resumen del rol',
            body: 'Alcance, personas que lo tienen y cuántos permisos incluye.',
        },
        {
            id: 'permisos',
            target: 'roles.view-permissions',
            title: 'Ver permisos',
            body: 'Consulta los permisos agrupados por módulo, sin modificar nada.',
        },
        {
            id: 'vista',
            target: 'roles.toolbar',
            title: 'Vista y estado',
            body: 'Cambia entre tarjetas y tabla, o muestra roles eliminados.',
        },
    ],
} satisfies TourSteps;
