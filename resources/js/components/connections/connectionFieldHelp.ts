import type { FieldHelpContent } from '@/types';

/**
 * Contextual help of the connection form. Copy is written for
 * administrators who are not necessarily familiar with Meta's tools.
 */
export const connectionFieldHelp = {
    name: {
        description:
            'Nombre interno para reconocer esta conexión dentro de la plataforma. Los clientes no lo ven.',
        howTo: 'Usa algo que identifique el número o su propósito.',
        example: 'Ventas CDMX',
    },
    displayPhoneNumber: {
        description:
            'Número de WhatsApp tal como lo verán los clientes. Al verificar las credenciales se reemplaza por el que reporta Meta.',
        howTo: 'Escríbelo con lada internacional. Es opcional si vas a verificar las credenciales.',
        example: '+52 55 1234 5678',
    },
    phoneNumberId: {
        description:
            'Identificador numérico que Meta asigna a tu número de WhatsApp Business. No es el número de teléfono.',
        howTo: 'En developers.facebook.com abre tu app › WhatsApp › Configuración de la API y copia el «Identificador de número de teléfono». También aparece en WhatsApp Manager › Números de teléfono.',
        example: '106540352242922',
        warning:
            'Si lo cambias, la verificación anterior deja de ser válida y tendrás que verificar de nuevo.',
    },
    businessAccountId: {
        description:
            'Identificador de tu cuenta de WhatsApp Business (WABA) en Meta, a la que pertenece el número.',
        howTo: 'Cópialo en Configuración de la API de tu app («Identificador de la cuenta de WhatsApp Business») o en Business Manager › Cuentas › Cuentas de WhatsApp.',
        example: '102290129340398',
        warning:
            'Es obligatorio para activar la conexión: con él se suscribe la app al webhook y se sincronizan las plantillas.',
    },
    metaAppId: {
        description:
            'Identificador de la app de Meta que usa la API de WhatsApp. Es opcional por ahora y ayuda a identificar la configuración.',
        howTo: 'Aparece en la parte superior del panel de tu app en developers.facebook.com.',
        example: '1234567890123456',
    },
    accessToken: {
        description:
            'Llave con la que la plataforma se autentica ante Meta para consultar y enviar mensajes de este número.',
        howTo: 'Genera un token de usuario del sistema en Business Manager › Configuración del negocio › Usuarios del sistema, con los permisos whatsapp_business_management y whatsapp_business_messaging, y asígnale la app y la cuenta de WhatsApp.',
        example: 'EAAG… (texto largo sin espacios)',
        warning:
            'Se guarda cifrado y nunca se vuelve a mostrar. Los tokens temporales del panel de pruebas expiran en horas; usa un token de usuario del sistema. Si lo reemplazas, deberás verificar de nuevo.',
    },
    appSecret: {
        description:
            'Clave secreta de tu app de Meta. Con ella se comprueba que cada aviso del webhook realmente viene de Meta.',
        howTo: 'En tu app › Configuración de la app › Básica, pulsa «Mostrar» junto a «Clave secreta de la app».',
        warning:
            'Se guarda cifrada y nunca se vuelve a mostrar. Sin ella el webhook rechaza los mensajes entrantes.',
    },
    webhookVerifyToken: {
        description:
            'Texto secreto que tú eliges. Meta lo enviará al configurar el webhook para confirmar que la URL te pertenece.',
        howTo: 'Pulsa «Generar» o escribe un texto aleatorio de al menos 16 caracteres, y guárdalo: deberás pegarlo en Meta al configurar la URL del webhook que muestra el diagnóstico.',
        example: '9f2c4e7a1b8d6f3e0a5c2b9d',
        warning:
            'Se guarda cifrado y no podrás volver a verlo aquí; cópialo antes de guardar si lo necesitas.',
    },
    departments: {
        description:
            'Departamentos que pueden recibir conversaciones de este número.',
        howTo: 'Marca uno o varios. Solo se pueden autorizar departamentos activos.',
        warning:
            'Si no autorizas ninguno, las conversaciones nuevas irán al departamento predeterminado de la plataforma.',
    },
    defaultDepartment: {
        description:
            'Departamento que recibe las conversaciones nuevas de este número cuando ninguna otra regla aplica.',
        howTo: 'Elige uno de los departamentos autorizados. Si lo dejas vacío se usa el departamento predeterminado de la plataforma.',
        example: 'Atención a clientes',
        warning:
            'Debe ser uno de los departamentos autorizados; si quitas su autorización, se limpia.',
    },
    automaticMessages: {
        description:
            'Mensajes que la plataforma enviará sola en momentos específicos de la conversación.',
        howTo: 'Activa cada mensaje y escribe su texto. Puedes usar *negritas*, _cursivas_, ~tachado~ y ```monoespaciado```.',
        example: '¡Hola! Gracias por escribir a *Mi Empresa*.',
        warning:
            'Solo se envían como texto, dentro de la ventana de 24 horas de atención al cliente de WhatsApp y mientras la conexión está activa. Meta puede cobrar los mensajes según su tarifa vigente.',
    },
    welcome: {
        description:
            'Se envía cuando un cliente inicia una conversación nueva con este número.',
        howTo: 'Saluda, identifica a la empresa y explica qué pasará después.',
        example:
            '¡Hola! Gracias por escribir a *Mi Empresa*. En breve te atiende un asesor.',
    },
    away: {
        description:
            'Se envía cuando un cliente escribe fuera del horario de atención configurado.',
        howTo: 'Indica cuándo se responderá. El horario se toma de la configuración de la plataforma o del departamento.',
        example:
            'Nuestro horario es de lunes a viernes de _9:00 a 18:00_. Te responderemos en cuanto abramos.',
    },
    farewell: {
        description: 'Se envía cuando un asesor cierra la conversación.',
        howTo: 'Agradece y, si aplica, indica cómo volver a contactar.',
        example:
            'Gracias por contactarnos. Si necesitas algo más, escríbenos aquí.',
    },
    reopenWindow: {
        description:
            'Si un cliente vuelve a escribir poco después de que se cerró su conversación, se reabre la misma en lugar de crear una nueva.',
        howTo: 'Indica los minutos durante los que se reabre. Déjalo desactivado para crear siempre una conversación nueva.',
        example: '120 (dos horas)',
        warning:
            'Una ventana muy larga mezcla temas distintos en la misma conversación; una muy corta genera conversaciones duplicadas.',
    },
    active: {
        description:
            'Solo las conexiones activas recibirán y enviarán mensajes cuando se habilite la integración.',
        howTo: 'Actívala cuando el Phone number ID, el WABA ID y el token estén guardados. Se recomienda verificar las credenciales antes.',
        warning:
            'Al desactivarla, la plataforma deja de procesar los mensajes de este número; el historial se conserva.',
    },
} satisfies Record<string, FieldHelpContent>;
