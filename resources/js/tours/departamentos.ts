import type { TourSteps } from './types';

export default {
    'departamentos.basico': [
        {
            id: 'nuevo',
            target: 'departments.new',
            title: 'Nuevo departamento',
            body: 'Crea un equipo para repartir conversaciones.',
            permission: 'departments.create',
        },
        {
            id: 'lista',
            target: 'departments.list',
            title: 'Departamentos',
            body: 'Abre uno para ver miembros, horarios y reparto de conversaciones.',
        },
    ],
    'departamentos.detalle': [
        {
            id: 'reparto',
            target: 'department.distribution',
            title: 'Reparto',
            body: 'Elige si las conversaciones se asignan solas o esperan en «Pendientes».',
        },
        {
            id: 'horario',
            target: 'department.hours',
            title: 'Horario',
            body: 'Define cuándo atiende este equipo y qué mensaje recibe el cliente fuera de horario.',
        },
        {
            id: 'miembros',
            target: 'department.members',
            title: 'Miembros',
            body: 'Quiénes atienden aquí. Agrega personas con «Agregar miembros».',
        },
    ],
} satisfies TourSteps;
