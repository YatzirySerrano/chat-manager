import type { ModuleHelp } from './types';

const help: ModuleHelp = {
    title: 'Roles y permisos',
    objective:
        'Define qué puede hacer cada persona (permisos) y sobre qué datos (alcance). Los cambios se aplican de inmediato a todos los usuarios con ese rol.',
    mainActions: [
        'Crear un rol con «Nuevo rol», elegir su alcance y marcar sus permisos.',
        'Ver y editar los permisos de un rol existente.',
        'Eliminar roles sin usuarios y restaurarlos.',
    ],
    workflow: [
        'Parte de un rol predeterminado parecido (Administrador, Supervisor o Agente) para decidir qué cambiar.',
        'Escribe el nombre y elige el «Alcance de los datos».',
        'Marca los permisos por grupo; usa «Buscar permiso» para encontrarlos.',
        'Revisa el resumen de cambios (cuántos usuarios se afectan y qué permisos sensibles se retiran) y guarda.',
    ],
    fields: [
        {
            name: 'Nombre del rol',
            description: 'De 3 a 80 caracteres, único.',
        },
        {
            name: 'Toda la organización',
            description:
                'Actúa sobre todos los departamentos, usuarios y conversaciones.',
        },
        {
            name: 'Sus departamentos',
            description:
                'Actúa únicamente sobre los departamentos a los que pertenece la persona.',
        },
        {
            name: 'Solo lo asignado',
            description:
                'Actúa únicamente sobre los registros asignados a la persona.',
        },
        {
            name: 'Etiquetas de los permisos',
            description:
                '«Sensible» pide confirmación al otorgarlo o retirarlo; «Requiere alcance de toda la organización» solo funciona con alcance global; «Solo superadministrador» está reservado.',
        },
    ],
    states: [
        {
            name: 'Superadministrador',
            description:
                'Autoridad raíz: siempre tiene alcance global, no puede eliminarse y conserva los permisos de administración de usuarios y roles.',
        },
        {
            name: 'Administrador',
            description: 'Todos los permisos excepto los reservados.',
        },
        {
            name: 'Supervisor',
            description:
                'Supervisa las conversaciones y los miembros de sus departamentos.',
        },
        {
            name: 'Agente',
            description: 'Atiende las conversaciones que tiene asignadas.',
        },
    ],
    permissions: [
        '«Ver roles y permisos» permite consultar; crear, editar y eliminar roles requieren alcance global y son sensibles.',
        'Solo puedes otorgar permisos que tú tienes y un alcance que no supere el tuyo.',
        '«Supervisar conversaciones de su alcance» es de solo lectura; «Enviar plantillas de WhatsApp» y la supervisión de elementos personales se otorgan explícitamente.',
    ],
    commonErrors: [
        {
            problem: '«Ya existe un rol con ese nombre.»',
            solution: 'Elige otro nombre o edita el rol existente.',
        },
        {
            problem:
                '«Estos permisos requieren el alcance «Toda la organización»…»',
            solution: 'Cambia el alcance a global o quita esos permisos.',
        },
        {
            problem: '«Solo puedes otorgar permisos que tú mismo tienes…»',
            solution: 'Pide el cambio a alguien con esos permisos.',
        },
        {
            problem: '«El rol X está asignado a N…»',
            solution:
                'Asigna otro rol a esas personas (incluidas las eliminadas) antes de eliminarlo.',
        },
    ],
    tips: [
        'Da a cada rol solo lo necesario; es más seguro agregar un permiso después que retirarlo.',
        'Los cambios de rol quedan registrados en la Auditoría.',
    ],
    examples: [
        'Rol «Agente de cobranza»: alcance Solo lo asignado, con responder, cerrar y usar respuestas rápidas.',
        'Rol «Coordinador»: alcance Sus departamentos, con supervisar y transferir conversaciones.',
    ],
};

export default help;
