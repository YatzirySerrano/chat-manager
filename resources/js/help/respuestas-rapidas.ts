import type { ModuleHelp } from './types';

const help: ModuleHelp = {
    title: 'Respuestas rápidas',
    objective:
        'Textos predefinidos que se insertan en el chat escribiendo «/» y su atajo. Se insertan para revisarlos antes de enviar; nunca se envían solos. Hay respuestas corporativas, que administra la empresa, y respuestas personales, que solo ve quien las crea.',
    mainActions: [
        'Crear, editar, eliminar y restaurar respuestas corporativas (para todos o por departamento).',
        'Crear tus propias respuestas personales, si un administrador te dio ese permiso.',
        'Probar cómo se inserta una respuesta con «Probar respuesta».',
        'Cambiar entre «Corporativas», «Mis respuestas» y, si supervisas, «De otros usuarios».',
        'Buscar, filtrar por departamento o estado, ordenar por atajo, título o edición reciente, y exportar.',
    ],
    workflow: [
        'Define un título claro y un atajo corto (por ejemplo «horario»).',
        'Escribe el mensaje. Puedes usar formato de WhatsApp y variables como {contacto.nombre}.',
        'Elige el tipo (si puedes crear ambos) y, en las corporativas, la disponibilidad: para todos los departamentos o solo para algunos. Las personales no se comparten.',
        'En la conversación escribe «/horario»: la respuesta aparece (tus personales primero, en «Mis respuestas»), la eliges y se inserta en el compositor para revisarla y enviarla.',
    ],
    fields: [
        {
            name: 'Título',
            description:
                'Obligatorio, hasta 120 caracteres. Nombre para encontrarla en la lista.',
        },
        {
            name: 'Atajo',
            description:
                'Obligatorio, hasta 32 caracteres: letras minúsculas sin acentos, números, guion y guion bajo; empieza con letra o número. Se escribe sin «/». Una corporativa no puede repetirse con otra disponible para los mismos departamentos; una personal solo no puede repetirse con otra de tus personales activas.',
        },
        {
            name: 'Mensaje',
            description:
                'Hasta 4096 caracteres (límite de WhatsApp). Admite *negritas*, _cursivas_ y ~tachado~; no admite HTML.',
        },
        {
            name: 'Variables',
            description:
                '{contacto.nombre}, {agente.nombre}, {departamento.nombre} y {empresa.nombre}. Si falta un dato se deja en blanco y se te avisa antes de enviar.',
        },
        {
            name: 'Disponibilidad',
            description:
                '«Disponible para todos los departamentos» (solo si tu rol ve toda la organización) o «Departamentos seleccionados».',
        },
    ],
    states: [
        {
            name: 'Activa',
            description:
                'Aparece al escribir «/» para quienes la tienen disponible.',
        },
        {
            name: 'Eliminada',
            description:
                'Nadie puede usarla. El atajo de una corporativa queda reservado mientras esté eliminada; el de una personal queda libre para su propietario. Los mensajes ya enviados no cambian.',
        },
        {
            name: 'Corporativa',
            description:
                'La administra la empresa y la ven todos los departamentos elegidos.',
        },
        {
            name: 'Personal',
            description:
                'Solo la ves tú (y los supervisores autorizados). Nunca se comparte por departamento.',
        },
    ],
    permissions: [
        '«Usar respuestas rápidas» permite insertarlas en el chat.',
        '«Administrar respuestas rápidas» permite crear y editar las corporativas; si tu rol no ve toda la organización, solo las de tus departamentos.',
        '«Crear y administrar sus respuestas rápidas personales» lo otorga un administrador por rol o por usuario. Si se retira, tus respuestas personales no se borran: puedes seguir usándolas, pero ya no editarlas.',
        'Los supervisores con el permiso «Ver alias, colores de chat y respuestas personales de otros usuarios» pueden consultar o corregir las respuestas personales de los usuarios que supervisan; esos cambios quedan en la auditoría.',
    ],
    commonErrors: [
        {
            problem: '«El atajo /x ya se usa en…»',
            solution:
                'Otra respuesta corporativa disponible para los mismos departamentos usa ese atajo (también si está eliminada), o ya tienes otra respuesta personal con ese atajo. Elige otro atajo.',
        },
        {
            problem: '«La variable {x} no existe…»',
            solution:
                'Usa solo las variables de la lista, escritas exactamente igual y con llaves completas.',
        },
        {
            problem: '«El mensaje no puede contener etiquetas HTML…»',
            solution:
                'WhatsApp envía solo texto; usa *negritas* o _cursivas_ para dar formato.',
        },
        {
            problem: 'No aparece la opción de crear respuestas personales.',
            solution:
                'Tu rol o tu usuario no tienen ese permiso. Un administrador puede activarlo en Usuarios › Editar › «Puede crear respuestas rápidas personales».',
        },
    ],
    tips: [
        'Usa atajos cortos y fáciles de recordar: «hola», «horario», «pago».',
        'Revisa siempre el texto insertado antes de enviarlo; puedes ajustarlo para cada cliente.',
    ],
    examples: [
        '«/horario» → «Hola {contacto.nombre}, nuestro horario es de lunes a viernes de 9:00 a 18:00.»',
        'Una respuesta personal «/firma» con tu nombre y extensión.',
    ],
};

export default help;
