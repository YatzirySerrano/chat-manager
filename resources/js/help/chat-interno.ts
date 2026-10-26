import type { ModuleHelp } from './types';

const help: ModuleHelp = {
    title: 'Chat interno',
    objective:
        'Mensajes entre el equipo, en chats privados o grupos. Son privados entre sus participantes (nadie más puede leerlos, ni siquiera la administración) y nunca se envían a WhatsApp ni se mezclan con las conversaciones de clientes.',
    mainActions: [
        'Iniciar un chat privado o crear un grupo con «Nuevo».',
        'Responder, editar y eliminar tus propios mensajes, y reaccionar con emojis.',
        'Adjuntar archivos y mencionar a personas en grupos.',
        'Silenciar un chat, archivar o reactivar grupos y administrar participantes.',
        'Buscar en chats y mensajes y filtrar por no leídos, privados, grupos o archivados.',
    ],
    workflow: [
        'Pulsa «Nuevo» → «Chat privado» y elige a la persona, o «Grupo» con nombre y participantes.',
        'Escribe tu mensaje; puedes adjuntar hasta 5 archivos de 10 MB cada uno.',
        'En grupos, menciona con @ para avisar a alguien aunque haya silenciado el chat.',
        'Quien administra el grupo puede agregar o retirar personas, nombrar administradores y archivarlo.',
    ],
    fields: [
        {
            name: 'Nombre del grupo',
            description:
                'De 2 a 80 caracteres, por ejemplo «Turno matutino de soporte».',
        },
        {
            name: 'Participantes',
            description: 'De 1 a 100 personas además de ti.',
        },
        {
            name: 'Mensaje',
            description:
                'Hasta 4000 caracteres. Archivos admitidos: imágenes, PDF, texto, CSV, Office, OpenDocument, audio y video MP4.',
        },
    ],
    states: [
        {
            name: 'Silenciado',
            description:
                'Sus mensajes no suman al contador del menú lateral ni notifican, excepto las menciones.',
        },
        {
            name: 'Archivado',
            description:
                'El grupo queda de solo lectura: ya no se pueden enviar mensajes hasta reactivarlo.',
        },
        {
            name: 'Editado / Mensaje eliminado',
            description:
                'Marcas visibles para todos cuando el autor cambia o borra un mensaje.',
        },
    ],
    permissions: [
        '«Usar el chat interno» habilita el módulo; si tu rol no ve toda la organización, solo puedes escribir a personas de tus departamentos.',
        '«Iniciar chats internos con personas de otros departamentos» amplía a toda la organización.',
        '«Crear grupos internos» y «Administrar los grupos internos en los que participa» se otorgan aparte.',
    ],
    commonErrors: [
        {
            problem: '«No puedes iniciar un chat con esta persona.»',
            solution:
                'No comparten departamento o no usa el chat interno. Pide el permiso para otros departamentos si lo necesitas.',
        },
        {
            problem:
                '«El grupo está archivado: ya no se pueden enviar mensajes.»',
            solution: 'Un administrador del grupo puede reactivarlo.',
        },
        {
            problem: '«Cada archivo debe pesar como máximo 10 MB.»',
            solution: 'Comprime el archivo o compártelo en partes.',
        },
        {
            problem: '«El grupo debe conservar al menos un administrador.»',
            solution: 'Nombra a otro administrador antes de quitarte ese rol.',
        },
    ],
    tips: [
        'Para hablar de un cliente usa la nota interna de la conversación: queda en su historial y la ve quien la atienda después.',
        'Eliminar un mensaje lo borra para todos y no se puede recuperar.',
    ],
    examples: [
        'Grupo «Cierre de mes» con Cobranza y Ventas.',
        'Chat privado con tu supervisor para pedir apoyo en un caso.',
    ],
};

export default help;
