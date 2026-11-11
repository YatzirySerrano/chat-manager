import type { ModuleHelp } from './types';

const help: ModuleHelp = {
    title: 'Conexiones',
    objective:
        'Canales por los que los clientes escriben a la empresa: números de WhatsApp Business (WhatsApp Cloud API), chats para sitios web (Chat web), páginas de Facebook (Facebook Messenger) y cuentas profesionales de Instagram. Todos llegan a la misma bandeja, con los mismos departamentos, agentes y transferencias. Cada conexión muestra su estado real de configuración, recepción y envío.',
    mainActions: [
        'Crear una conexión con «Nueva conexión» y elegir el canal (no se puede cambiar después).',
        'Capturar las credenciales de Meta y pulsar «Verificar credenciales».',
        'Revisar el webhook y la suscripción de la app en el diagnóstico de la conexión.',
        'Configurar departamentos autorizados, departamento predeterminado y mensajes automáticos.',
        'Configurar el widget de Chat web y copiar el fragmento de código para el sitio.',
        'Conectar una página de Facebook o una cuenta de Instagram con «Autorizar acceso», volver a autorizarla o desconectarla.',
        'Activar, desactivar, eliminar y restaurar conexiones.',
        'Vigilar varias conexiones a la vez: cada una indica si su evidencia es reciente («Comprobada hace 12 minutos») o histórica («Sin comprobar desde hace 3 días») y sus advertencias.',
    ],
    workflow: [
        'Pulsa «Nueva conexión» y elige «WhatsApp Cloud API», «Chat web», «Facebook Messenger» o «Instagram».',
        'WhatsApp: captura Phone number ID, WABA ID, token de acceso, app secret y token de verificación del webhook, y guarda.',
        'Pulsa «Verificar credenciales». Meta debe aceptarlas y reportar el número como CONNECTED.',
        'Registra en Meta la URL del webhook y el token de verificación que muestra el diagnóstico, y usa «Suscribir app».',
        'Elige los departamentos que recibirán las conversaciones y el predeterminado.',
        'Activa la conexión. La lista de verificación se completa cuando se confirma la recepción y el envío de mensajes.',
        'Chat web: agrega al menos un dominio autorizado, personaliza el widget y pega el fragmento antes de </body> en tu sitio.',
        'Messenger e Instagram: revisa los requisitos, pulsa «Autorizar acceso», inicia sesión en Facebook o Instagram, acepta los permisos y elige la página o la cuenta. La conexión queda inactiva: asigna departamentos y actívala.',
    ],
    fields: [
        {
            name: 'Phone number ID',
            description:
                'Identificador numérico del número en Meta (5 a 64 dígitos). Es único: no puede usarse en dos conexiones.',
        },
        {
            name: 'WABA ID',
            description:
                'Identificador de la cuenta de WhatsApp Business (WABA ID); necesario para activar la conexión y para actualizar plantillas desde Meta.',
        },
        {
            name: 'Token de acceso, App secret y Token de verificación',
            description:
                'Se guardan cifrados y nunca vuelven a mostrarse. Déjalos vacíos para conservar el valor guardado o usa «Reemplazar».',
        },
        {
            name: 'Departamentos autorizados / predeterminado',
            description:
                'Qué departamentos pueden recibir conversaciones de esta conexión y a cuál llegan las nuevas.',
        },
        {
            name: 'Mensajes automáticos',
            description:
                'Bienvenida, Fuera de horario y Despedida (hasta 1024 caracteres). Se envían como texto dentro de la ventana de 24 horas; los mensajes del departamento tienen prioridad.',
        },
        {
            name: 'Monitoreo de la conexión',
            description:
                'Estado de configuración, última verificación de credenciales, aviso de mensajes nuevos (webhook), suscripción de la app, último mensaje recibido, último envío, último error y advertencias. Solo aparecen los apartados que aplican al canal. La plataforma vuelve a comprobar con Meta las conexiones activas cada 30 minutos.',
        },
        {
            name: 'Evidencia reciente / actividad histórica',
            description:
                'Reciente (palomita): Meta respondió o hubo mensajes en la última hora y media. Histórica (reloj con flecha): funcionó entonces, pero no confirma el estado de hoy. Pasar un tiempo sin mensajes es normal y no es un problema.',
        },
        {
            name: 'Ventana de reapertura',
            description:
                'Minutos (1 a 10080) durante los que un mensaje del cliente reabre la conversación cerrada en lugar de crear una nueva.',
        },
        {
            name: 'Cuenta autorizada (Messenger e Instagram)',
            description:
                'Nombre, identificador e imagen de la página o cuenta, permisos que concediste, quién autorizó y cuándo, vigencia del token (Instagram vence a los 60 días y se renueva solo) y estado de la suscripción del webhook. El token nunca se muestra.',
        },
        {
            name: 'Dominios autorizados (Chat web)',
            description:
                'De 1 a 20 orígenes https sin ruta ni comodines (http solo para localhost). El widget solo funciona en esos sitios.',
        },
    ],
    states: [
        {
            name: 'Inactiva',
            description: 'Desactivada: no recibe ni envía mensajes.',
        },
        {
            name: 'Sin configurar',
            description: 'Faltan identificadores o el token de acceso.',
        },
        {
            name: 'Configurada sin verificar',
            description:
                'Hay datos guardados pero aún no se verificaron con Meta.',
        },
        {
            name: 'Credenciales inválidas',
            description:
                'Meta rechazó las credenciales en la última verificación.',
        },
        {
            name: 'Credenciales verificadas / Recibiendo mensajes',
            description:
                'Meta aceptó las credenciales; después se confirma el webhook o la llegada del primer mensaje.',
        },
        {
            name: 'Operativa',
            description:
                'Credenciales verificadas, recepción y envío confirmados.',
        },
        {
            name: 'Con error',
            description:
                'El evento más reciente fue un error posterior al último éxito. Revisa «Último error» en la lista de verificación.',
        },
    ],
    permissions: [
        '«Ver conexiones» y «Administrar conexiones y credenciales (incluye eliminar y restaurar)» requieren un rol que vea toda la organización; administrar es un permiso de alto impacto (se pide confirmar al darlo o quitarlo).',
        'Restaurar una conexión eliminada requiere también «Consultar registros eliminados».',
    ],
    commonErrors: [
        {
            problem:
                '«Este Phone number ID ya está registrado en otra conexión.»',
            solution:
                'Cada número solo puede tener una conexión. Si pertenece a una conexión eliminada, restáurala en lugar de crear otra.',
        },
        {
            problem: '«Meta rechazó las credenciales…»',
            solution:
                'El token pudo vencer o no tener permisos sobre la cuenta. Genera un token de sistema nuevo en Meta, reemplázalo y verifica de nuevo.',
        },
        {
            problem: '«…reporta el número con estado X. Debe estar CONNECTED…»',
            solution:
                'Termina el registro del número en WhatsApp Manager y vuelve a verificar.',
        },
        {
            problem:
                '«Para activar la conexión guarda primero el Phone number ID, el WABA ID y el token de acceso.»',
            solution: 'Completa esos tres datos y guarda antes de activar.',
        },
        {
            problem:
                '«Para activar el chat web configura primero su widget con al menos un dominio autorizado.»',
            solution: 'Agrega el dominio https de tu sitio en el widget.',
        },
        {
            problem:
                '«Requiere configurar la aplicación de Meta en el servidor…»',
            solution:
                'Falta configurar la aplicación de Meta en el servidor. Pide al equipo técnico que la configure (en la pantalla de autorización, «Detalles técnicos» indica qué falta).',
        },
        {
            problem: '«La solicitud de autorización no es válida o venció…»',
            solution:
                'Pasaron más de 10 minutos o se abrió desde otra pestaña o sesión. Vuelve a pulsar «Autorizar acceso».',
        },
        {
            problem: '«…Meta no confirmó la suscripción al webhook.»',
            solution:
                'La app necesita pages_manage_metadata (Messenger) o instagram_business_manage_messages (Instagram) y debes administrar la página. Vuelve a autorizar.',
        },
        {
            problem:
                '«Llegaron eventos de otro número (ID …) que no está registrado aquí.»',
            solution:
                'Tu app de Meta tiene otro número que envía sus avisos a la dirección de esta conexión. Si debe atenderse aquí, crea su conexión con el mismo App secret (sus mensajes entrarán solos); si no, retira ese número de la app o dale su propia dirección en Meta. Esos mensajes no se guardaron.',
        },
        {
            problem:
                '«Llegaron eventos de otro número (ID …) registrado en una conexión con un App Secret distinto…»',
            solution:
                'Las dos conexiones deben guardar el mismo App secret para comprobar que son de la misma app de Meta. Reemplaza el App secret en la conexión que lo tenga distinto. Si son apps diferentes, cada una necesita su propia dirección en Meta.',
        },
        {
            problem:
                '«El último intento automático no pudo comprobarse con Meta…»',
            solution:
                'Meta no respondió o limitó las solicitudes. No es un fallo de tus credenciales: se reintenta solo. Si persiste varias horas, pulsa «Verificar credenciales».',
        },
        {
            problem: '«Demasiados intentos de verificación…»',
            solution:
                'Espera los segundos indicados antes de verificar otra vez.',
        },
    ],
    tips: [
        'Messenger e Instagram usan la autorización oficial de Meta. Para atender a clientes reales la app de Meta debe estar publicada y aprobada en App Review (acceso avanzado); antes solo llegan mensajes de personas con un rol en la app.',
        'Messenger e Instagram solo permiten responder dentro de las 24 horas posteriores al último mensaje del cliente.',
        'Desactivar o eliminar una conexión conserva sus conversaciones e historial.',
        'Varios números en la misma app de Meta: guarda el mismo App secret en todas sus conexiones. Meta puede enviar los avisos de todos a una sola dirección y cada mensaje llega a la conexión de su número.',
        'Cuando una conexión con problema vuelve a funcionar, la notificación cambia a «La conexión … se recuperó»; al abrirla llegas a su diagnóstico.',
        'Si rotas la clave pública del Chat web, reemplaza el fragmento en tu sitio: el anterior deja de funcionar.',
    ],
    examples: [
        'Número de ventas «+52 55 1234 5678» autorizado para Ventas y Atención, con Ventas como predeterminado.',
        'Chat web en https://www.tuempresa.com con disponibilidad «Según el horario de atención».',
    ],
};

export default help;
