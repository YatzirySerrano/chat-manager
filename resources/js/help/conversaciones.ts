import type { ModuleHelp } from './types';

const help: ModuleHelp = {
    title: 'Conversaciones',
    objective:
        'Bandeja única donde el equipo atiende a los clientes que escriben por WhatsApp, Chat web y los demás canales conectados. Cada conversación tiene un estado y, cuando está en atención, una sola persona responsable de responder.',
    mainActions: [
        'Aceptar una conversación pendiente de tus departamentos para hacerte responsable de ella.',
        'Responder al cliente con texto, emojis, archivos adjuntos y respuestas rápidas (escribe «/» y el atajo).',
        'Agregar notas internas que solo ve el equipo; el cliente nunca las recibe.',
        'Transferir la conversación a otro departamento o agente, o devolverla a la cola de su departamento.',
        'Cerrar la conversación cuando la atención terminó y reabrirla si el cliente necesita seguimiento.',
        'Aplicar etiquetas a la conversación desde la bandeja o desde el panel de detalles.',
        'Guardar al cliente en el directorio de Contactos con «Guardar contacto» cuando aún no está registrado.',
        'Ponerle a un contacto un alias privado (solo lo ves tú) y personalizar los colores de tus chats.',
        'Enviar una plantilla aprobada de WhatsApp, solo si eres administrador con ese permiso.',
    ],
    workflow: [
        'Un cliente escribe: la conversación entra como «Pendiente» en la cola del departamento de la conexión, o se asigna sola si el departamento usa asignación automática.',
        'Revisa «Pendientes» y pulsa «Aceptar». A partir de ese momento tú eres el responsable y la conversación pasa a «En atención».',
        'Responde desde el compositor. Usa «Nota interna» para dejar contexto al equipo sin que el cliente lo vea.',
        'Si otra área debe atenderla, usa «Más acciones» → «Transferir». Se conservan mensajes, notas, etiquetas y procedencia, y no se envía nada al cliente.',
        'Al terminar, «Cerrar conversación» (puedes indicar un motivo). Su historial se conserva y queda en «Cerradas».',
        'Si el cliente vuelve a escribir después del cierre, la conversación regresa a la bandeja para atenderla de nuevo.',
    ],
    fields: [
        {
            name: 'Bandeja general',
            description:
                'Todo lo que espera por ti: las pendientes que puedes aceptar y las que atiendes.',
        },
        {
            name: 'Mis conversaciones',
            description: 'Solo las conversaciones de las que eres responsable.',
        },
        {
            name: 'Buscar',
            description:
                'Busca por nombre, teléfono o correo del contacto, o cambia «Buscar en» a «Contenido de los mensajes».',
        },
        {
            name: 'Filtros',
            description:
                'Vista, estado, departamento, responsable, conexión, canal, etiquetas, orden y «Buscar en». «Bandeja general», «Pendientes» y «No leídas» siempre están a la vista. Con varias etiquetas eliges «Cualquiera de las etiquetas (O)» (por defecto) o «Todas las etiquetas (Y)». Los filtros aplicados se muestran como chips y «Limpiar filtros» los quita todos.',
        },
        {
            name: 'Ventana de 24 horas',
            description:
                'En WhatsApp solo puedes responder con mensajes normales durante las 24 horas siguientes al último mensaje del cliente. Responder, agregar notas, transferir o reabrir no la extienden.',
        },
        {
            name: 'Alias del contacto',
            description:
                'Nombre privado que tú eliges para reconocer a un contacto. Solo lo ves tú, se mantiene en todas sus conversaciones y no cambia el nombre oficial, el teléfono ni lo que recibe el cliente.',
        },
        {
            name: 'Apariencia del chat',
            description:
                'Colores personales de las burbujas y del fondo, para todos tus chats o para una conversación. Solo te afectan a ti; si un color de texto no se lee bien, el sistema elige uno legible.',
        },
    ],
    states: [
        {
            name: 'Pendiente',
            description:
                'Espera a que alguien de su departamento la acepte o a que se le asigne. Para responder primero hay que aceptarla.',
        },
        {
            name: 'En atención',
            description:
                'Tiene un responsable. Solo esa persona puede responder al cliente; los demás la ven según su permiso.',
        },
        {
            name: 'Cerrada',
            description:
                'La atención terminó y su historial se conserva. Cerrar no envía ningún mensaje al cliente ni cambia la ventana de WhatsApp.',
        },
    ],
    permissions: [
        '«Ver conversaciones de su alcance» muestra las conversaciones de tus departamentos o las asignadas a ti, según el alcance de tu rol.',
        '«Aceptar conversaciones pendientes de sus departamentos» permite tomar las pendientes; «Atender y responder sus conversaciones» permite responder las tuyas.',
        '«Asignar conversaciones y gestionar las de su alcance» permite entregar conversaciones a otra persona; «Transferir» y «Cerrar» se otorgan por separado.',
        '«Supervisar conversaciones de su alcance» es solo lectura: deja ver todas las conversaciones del alcance, incluidas las cerradas y las que pasaron por tus departamentos, pero no permite responder, aceptar ni transferir.',
        'Las etiquetas desde la bandeja requieren «Asignar etiquetas a contactos y conversaciones» y ser el responsable (o poder asignar).',
        'Las plantillas de WhatsApp solo las envía quien tiene «Enviar plantillas de WhatsApp (administrativo)»; los agentes no las ven en el compositor.',
        '«Guardar contacto» requiere el permiso «Crear contactos».',
    ],
    commonErrors: [
        {
            problem: '«Otra persona ya aceptó esta conversación.»',
            solution:
                'Un compañero la tomó antes. La lista se actualiza sola; elige otra pendiente.',
        },
        {
            problem: '«Ya atiendes el máximo de N conversaciones permitido.»',
            solution:
                'Llegaste a tu límite de carga. Cierra o libera alguna conversación antes de aceptar otra.',
        },
        {
            problem:
                '«La conversación está pendiente: acéptala para responder.»',
            solution:
                'El compositor se habilita cuando eres el responsable. Pulsa «Aceptar».',
        },
        {
            problem:
                '«Solo X, responsable de la conversación, puede responder al cliente.»',
            solution:
                'La atiende otra persona. Deja una nota interna o pide que te la transfieran.',
        },
        {
            problem: '«La ventana de atención está cerrada…»',
            solution:
                'Pasaron más de 24 horas desde el último mensaje del cliente. Podrás responder cuando vuelva a escribir; solo un administrador puede enviar una plantilla aprobada.',
        },
        {
            problem:
                '«El archivo pesa…; el máximo para… es…» o «Este tipo de archivo no se puede enviar…»',
            solution:
                'Cada canal tiene límites por tipo de archivo (por ejemplo, en WhatsApp: imágenes 5 MB, audio y video 16 MB, documentos 100 MB; en Instagram solo PDF). El ícono de adjuntar muestra lo que admite el canal de la conversación. Reduce el tamaño o envíalo en un formato admitido, y revisa que la extensión del nombre corresponda al archivo.',
        },
        {
            problem: '«Se enviará como enlace de descarga seguro…»',
            solution:
                'WhatsApp no admite archivos ZIP: el cliente recibe un mensaje con un enlace seguro para descargarlo, que vence a los pocos días (la burbuja muestra la fecha). Si venció, adjunta el archivo de nuevo.',
        },
        {
            problem:
                '«El archivo es demasiado grande para que el servidor lo reciba…» o «El servidor no pudo recibir el archivo…»',
            solution:
                'El archivo supera el límite de subida configurado en el servidor. Intenta con uno más pequeño o pide a un administrador que amplíe ese límite.',
        },
        {
            problem: '«No hay conexión con el servidor…»',
            solution:
                'Revisa tu red y vuelve a intentarlo: el mensaje no se duplica.',
        },
    ],
    tips: [
        'Escribe «/» en el compositor para buscar respuestas rápidas; se insertan para revisarlas y nunca se envían solas.',
        'Usa los chips de filtro aplicados para saber siempre qué estás viendo y quítalos con un clic.',
        'Antes de transferir, deja una nota interna con el contexto para que la siguiente persona no tenga que preguntar de nuevo.',
        'Los contactos que escriben por primera vez no aparecen en el directorio hasta que alguien los guarda con «Guardar contacto».',
    ],
    examples: [
        'Un cliente pregunta por una factura: acéptala, responde con la respuesta rápida «/factura» y ciérrala con el motivo «Factura enviada».',
        'Un cliente de Ventas pide soporte técnico: transfiérela al departamento Soporte con el motivo «Falla en el equipo».',
        'Atiendes a dos personas con el mismo nombre: ponle a una el alias «Juan (proveedor)» para distinguirla; solo tú lo verás.',
    ],
};

export default help;
