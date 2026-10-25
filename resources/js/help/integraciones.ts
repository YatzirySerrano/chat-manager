import type { ModuleHelp } from './types';

const help: ModuleHelp = {
    title: 'Integraciones (API)',
    objective:
        'Tokens de la API de integración (/api/v1) para que otros sistemas, como un CRM, consulten conversaciones y mensajes o envíen mensajes. Cada token tiene capacidades, conexiones permitidas, IP opcionales y una fecha de vencimiento.',
    mainActions: [
        'Emitir un token con «Nuevo token de integración».',
        'Copiar el token recién emitido (solo se muestra una vez).',
        'Revocar un token de inmediato.',
        'Consultar el estado de cada token: activo, vencido o revocado.',
    ],
    workflow: [
        'Ponle un nombre que identifique al sistema que lo usará (por ejemplo «CRM de ventas»).',
        'Elige solo las capacidades necesarias y las conexiones (y, si quieres, departamentos e IP) que podrá usar.',
        'Define el vencimiento (máximo 365 días) y emítelo.',
        'Copia el token y guárdalo en el otro sistema; se envía en el encabezado «Authorization: Bearer …».',
        'Antes del vencimiento emite uno nuevo, cámbialo en el otro sistema y revoca el anterior.',
    ],
    fields: [
        {
            name: 'Capacidades',
            description:
                '«Consultar conversaciones», «Consultar mensajes» y «Enviar mensajes». Al menos una.',
        },
        {
            name: 'Conexiones',
            description: 'Entre 1 y 100 conexiones que el token puede usar.',
        },
        {
            name: 'Departamentos (opcional)',
            description: 'Vacío significa todos los departamentos.',
        },
        {
            name: 'IP permitidas (opcional)',
            description:
                'Hasta 50 IP o rangos CIDR; vacío permite cualquier IP.',
        },
        {
            name: 'Idempotency-Key',
            description:
                'Encabezado obligatorio al enviar mensajes (8 a 64 caracteres). Reintentar con la misma clave no duplica el mensaje.',
        },
    ],
    states: [
        {
            name: 'Activo',
            description: 'Puede usarse hasta su fecha de vencimiento.',
        },
        {
            name: 'Vencido',
            description: 'Pasó su fecha de vencimiento; emite uno nuevo.',
        },
        {
            name: 'Revocado',
            description:
                'Se rechaza desde el momento de la revocación; no puede deshacerse.',
        },
    ],
    permissions: [
        '«Ver los tokens de la API de integración» y «Emitir y revocar tokens de la API de integración» requieren alcance global; emitir es sensible.',
    ],
    commonErrors: [
        {
            problem: 'La API responde 401 «No autenticado».',
            solution:
                'El token falta, es incorrecto, venció, fue revocado o la IP no está permitida. Por seguridad la respuesta no indica cuál.',
        },
        {
            problem: '403 «forbidden».',
            solution:
                'Al token le falta la capacidad necesaria para esa operación.',
        },
        {
            problem: '409 «customer_window_closed» o «conversation_closed».',
            solution:
                'La ventana de 24 horas de WhatsApp está cerrada o la conversación terminó; no se puede enviar texto libre.',
        },
        {
            problem: '429 «rate_limited».',
            solution:
                'Se superó el límite por token (600 lecturas o 60 envíos por minuto). Espera y reintenta.',
        },
    ],
    tips: [
        'Usa un token por sistema para poder revocar uno sin afectar a los demás.',
    ],
    examples: [
        'CRM que consulta conversaciones cerradas cada hora: solo «Consultar conversaciones» y «Consultar mensajes».',
        'Sistema de pedidos que envía confirmaciones dentro de la ventana de 24 horas: «Enviar mensajes» en una sola conexión.',
    ],
};

export default help;
