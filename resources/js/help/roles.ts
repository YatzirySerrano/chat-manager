import type { ModuleHelp } from './types';

const help: ModuleHelp = {
    title: 'Roles y permisos',
    objective:
        'Define qué puede hacer cada persona (permisos) y qué información puede ver (alcance). Los cambios se aplican de inmediato a todos los usuarios con ese rol.',
    mainActions: [
        'Crear un rol con «Nuevo rol», elegir su alcance y marcar sus permisos.',
        'Abrir «Ver permisos» en una tarjeta para consultar todos los permisos del rol, agrupados por módulo.',
        'Editar los permisos y el alcance de un rol existente.',
        'Eliminar roles sin usuarios y restaurarlos.',
    ],
    workflow: [
        'Parte de un rol predeterminado parecido (Administrador, Supervisor o Agente) para decidir qué cambiar.',
        'Escribe el nombre y elige el alcance: qué información puede ver.',
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
                'Puede consultar y trabajar con la información de todos los departamentos, usuarios y conversaciones. Ejemplo: una gerente revisa las conversaciones y los reportes de todas las áreas.',
        },
        {
            name: 'Sus departamentos',
            description:
                'Solo ve y trabaja con la información de los departamentos a los que pertenece. Ejemplo: un supervisor de Ventas ve las conversaciones de Ventas, pero no las de Soporte.',
        },
        {
            name: 'Solo lo asignado',
            description:
                'Solo ve y trabaja con lo que tiene asignado a su nombre y con lo pendiente que puede tomar. Ejemplo: un agente ve las conversaciones que atiende y las pendientes de sus departamentos que puede aceptar.',
        },
        {
            name: 'Etiquetas de los permisos',
            description:
                '«Alto impacto» da control sobre cuentas, configuración o información delicada: el editor te avisa al retirarlo. «Solo funciona con el alcance «Toda la organización»» no se puede dar a roles con un alcance menor.',
        },
    ],
    states: [
        {
            name: 'Administrador',
            description:
                'Administra usuarios, departamentos, conversaciones y configuraciones dentro del alcance autorizado de la organización.',
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
        'El alcance define qué información ve el rol; los permisos, qué acciones puede hacer con ella.',
        '«Ver roles y permisos» permite consultar; crear, editar y eliminar roles solo funcionan con «Toda la organización» y son de alto impacto.',
        'Solo puedes otorgar permisos que tú tienes y un alcance que no supere el tuyo.',
        'Antes de guardar verás a cuántos usuarios activos afecta el cambio. Si el rol no está asignado a nadie, el cambio no afecta a nadie todavía: revisa en Usuarios qué rol tienen las personas.',
        'Retirar un permiso de un rol no se lo quita a quien también lo recibe por otro rol o por una asignación directa; el editor te avisa cuántos lo conservan.',
        '«Supervisar conversaciones» es de solo lectura; «Enviar plantillas de WhatsApp» y la supervisión de elementos personales se otorgan explícitamente.',
    ],
    commonErrors: [
        {
            problem: '«Ya existe un rol con ese nombre.»',
            solution: 'Elige otro nombre o edita el rol existente.',
        },
        {
            problem:
                '«Estos permisos requieren el alcance «Toda la organización»…»',
            solution:
                'Cambia el alcance a «Toda la organización» o quita esos permisos.',
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
