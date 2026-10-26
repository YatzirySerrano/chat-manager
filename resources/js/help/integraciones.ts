import type { ModuleHelp } from './types';

const help: ModuleHelp = {
    title: 'Integraciones',
    objective:
        'Claves de acceso (tokens) para que otros sistemas, como un CRM, consulten conversaciones y mensajes o envíen mensajes. Cada clave tiene permisos, conexiones permitidas, direcciones IP opcionales y una fecha de vencimiento.',
    mainActions: [
        'Crear una clave con «Nueva clave de acceso».',
        'Copiar la clave recién creada (solo se muestra una vez).',
        'Revocar una clave de inmediato.',
        'Consultar el estado de cada clave: activa, vencida o revocada.',
    ],
    workflow: [
        'Ponle un nombre que identifique al sistema que la usará (por ejemplo «CRM de ventas»).',
        'Elige solo los permisos necesarios y las conexiones (y, si quieres, departamentos y direcciones IP) que podrá usar.',
        'Define el vencimiento (máximo 365 días) y pulsa «Crear clave».',
        'Copia la clave y entrégala a quien configura el otro sistema (se envía en el encabezado «Authorization: Bearer …»).',
        'Antes del vencimiento crea una nueva, cámbiala en el otro sistema y revoca la anterior.',
    ],
    fields: [
        {
            name: 'Permisos de la clave',
            description:
                '«Consultar conversaciones», «Consultar mensajes» y «Enviar mensajes». Al menos uno.',
        },
        {
            name: 'Conexiones',
            description: 'Entre 1 y 100 conexiones que la clave puede usar.',
        },
        {
            name: 'Departamentos (opcional)',
            description:
                'Si no eliges ninguno, la clave podrá usar todos los departamentos de sus conexiones.',
        },
        {
            name: 'Direcciones IP permitidas (opcional)',
            description:
                'Hasta 50 direcciones (o rangos) desde donde se permite usar la clave; pídelas al proveedor del sistema. Vacío: desde cualquier lugar.',
        },
        {
            name: 'Clave anti-duplicados (Idempotency-Key)',
            description:
                'Dato que el otro sistema debe incluir al enviar mensajes (8 a 64 caracteres). Si reintenta con la misma clave, el mensaje no se envía dos veces.',
        },
    ],
    states: [
        {
            name: 'Activa',
            description: 'Puede usarse hasta su fecha de vencimiento.',
        },
        {
            name: 'Vencida',
            description: 'Pasó su fecha de vencimiento; crea una nueva.',
        },
        {
            name: 'Revocada',
            description:
                'Se rechaza desde el momento en que se revocó; no puede deshacerse.',
        },
    ],
    permissions: [
        '«Ver las claves de acceso de integraciones» y «Crear y revocar claves de acceso de integraciones» requieren un rol que vea toda la organización; crear claves pide confirmación al otorgarse.',
    ],
    commonErrors: [
        {
            problem: 'Error 401: «No autenticado».',
            solution:
                'La clave falta, es incorrecta, venció, fue revocada o la dirección IP no está permitida. Por seguridad la respuesta no indica cuál.',
        },
        {
            problem: 'Error 403: la clave no tiene permiso para esta acción.',
            solution:
                'Los permisos de una clave no se pueden cambiar: crea una nueva con el permiso que falta (por ejemplo «Enviar mensajes») y revoca la anterior.',
        },
        {
            problem:
                'Error 409: la ventana de 24 horas está cerrada o la conversación terminó.',
            solution:
                'WhatsApp no permite enviar texto libre fuera de la ventana de 24 horas ni en conversaciones cerradas.',
        },
        {
            problem:
                'Error 429: se enviaron demasiadas solicitudes por minuto.',
            solution:
                'Cada clave admite 600 consultas o 60 envíos por minuto. Espera un momento y vuelve a intentarlo.',
        },
    ],
    tips: [
        'Usa una clave por sistema para poder revocar una sin afectar a los demás.',
    ],
    examples: [
        'CRM que consulta conversaciones cerradas cada hora: solo «Consultar conversaciones» y «Consultar mensajes».',
        'Sistema de pedidos que envía confirmaciones dentro de la ventana de 24 horas: «Enviar mensajes» en una sola conexión.',
    ],
};

export default help;
