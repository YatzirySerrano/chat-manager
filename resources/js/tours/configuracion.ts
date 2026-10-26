import type { TourSteps } from './types';

export default {
    'configuracion.basico': [
        {
            id: 'general',
            target: 'settings.general',
            title: 'General',
            body: 'Nombre de la plataforma y zona horaria de todos los reportes.',
        },
        {
            id: 'horario',
            target: 'settings.hours',
            title: 'Horario de atención',
            body: 'Cuándo atiende la organización; se usa para mensajes fuera de horario y para medir tiempos en horario de atención.',
        },
        {
            id: 'operacion',
            target: 'settings.operations',
            title: 'Operación',
            body: 'Departamento que recibe las conversaciones nuevas, límites y cierre automático.',
        },
        {
            id: 'objetivos',
            target: 'settings.sla',
            title: 'Objetivos',
            body: 'Tiempos meta de respuesta y resolución para los reportes.',
        },
        {
            id: 'guardar',
            target: 'settings.save',
            title: 'Guardar',
            body: 'Guarda o descarta todos los cambios de esta página.',
            permission: 'settings.update',
        },
    ],
} satisfies TourSteps;
