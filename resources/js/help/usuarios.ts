import type { ModuleHelp } from './types';

const help: ModuleHelp = {
    title: 'Usuarios',
    objective:
        'Administra las cuentas de los empleados: sus datos de acceso, su rol (qué pueden hacer y sobre qué datos) y los departamentos a los que pertenecen.',
    mainActions: [
        'Crear un usuario con «Nuevo usuario» y su contraseña inicial.',
        'Cambiar rol, departamentos y límite de conversaciones.',
        'Restablecer la contraseña de otra persona.',
        'Otorgar o retirar el permiso individual de respuestas rápidas personales.',
        'Eliminar (desactivar) y restaurar cuentas.',
    ],
    workflow: [
        'Pulsa «Nuevo usuario» y captura nombre completo y correo electrónico.',
        'Elige el rol y, si su alcance es «Sus departamentos» o «Solo lo asignado», sus departamentos.',
        'Define una contraseña inicial y compártela por un medio seguro; la persona puede cambiarla en Mi cuenta → Seguridad.',
        'Si cambias el rol, revisa el resumen de permisos que perderá u obtendrá antes de confirmar.',
    ],
    fields: [
        {
            name: 'Correo electrónico',
            description: 'Obligatorio y único; con él inicia sesión.',
        },
        {
            name: 'Rol',
            description:
                'Define permisos y alcance. Solo puedes asignar roles que tú podrías administrar.',
        },
        {
            name: 'Límite de conversaciones en atención (opcional)',
            description:
                'De 1 a 200. Tiene prioridad sobre el límite del departamento y el de la plataforma.',
        },
        {
            name: 'Contraseña inicial',
            description:
                'Al menos 12 caracteres con mayúsculas, minúsculas, número y símbolo, y que no aparezca en filtraciones conocidas.',
        },
    ],
    states: [
        {
            name: 'Activo',
            description: 'Puede iniciar sesión y trabajar según su rol.',
        },
        {
            name: 'Eliminado',
            description:
                'Pierde el acceso de inmediato y sus conversaciones abiertas regresan a la cola. Su historial se conserva y puede restaurarse; al restaurarlo no se le reasignan conversaciones.',
        },
    ],
    permissions: [
        '«Ver usuarios», «Crear usuarios», «Editar usuarios», «Asignar roles a usuarios» y «Eliminar y restaurar usuarios» se otorgan por separado; todos excepto ver son sensibles.',
        'Solo administras usuarios de tu alcance con roles que podrías asignar; nunca tu propia cuenta. Solo un Superadministrador administra a otro Superadministrador.',
    ],
    commonErrors: [
        {
            problem: '«El valor de correo electrónico ya está en uso.»',
            solution:
                'Ya existe una cuenta con ese correo (puede estar eliminada). Búscala y restáurala.',
        },
        {
            problem:
                '«No puedes asignar un rol con más privilegios de los que administras.»',
            solution: 'Pide a alguien con un rol superior que haga el cambio.',
        },
        {
            problem: '«Es el último superadministrador activo…»',
            solution:
                'Siempre debe quedar al menos un Superadministrador. Nombra a otro antes de cambiarlo o eliminarlo.',
        },
        {
            problem: 'No puedo cambiar mi correo o contraseña aquí.',
            solution:
                'Tus propios datos se cambian en Mi cuenta → Perfil o Seguridad.',
        },
    ],
    tips: [
        'Activa la verificación en dos pasos para las cuentas con permisos sensibles.',
        'Antes de eliminar a alguien, revisa sus conversaciones abiertas: regresarán a la cola.',
    ],
    examples: [
        'Nueva agente de Soporte: rol Agente, departamento Soporte, límite 6.',
        'Un supervisor cambia de área: edita sus departamentos; sus conversaciones abiertas en el área anterior regresan a la cola.',
    ],
};

export default help;
