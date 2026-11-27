import type { ModuleHelp } from './types';

const help: ModuleHelp = {
    title: 'Conversaciones',
    objective:
        'Bandeja única donde el equipo atiende a los clientes que escriben por WhatsApp, Chat web y los demás canales conectados. Cada conversación tiene un estado y, cuando está en atención, una sola persona responsable de responder.',
    mainActions: [
        'Aceptar una conversación pendiente de tus departamentos para hacerte responsable de ella.',
        'Responder al cliente con texto, emojis, archivos adjuntos y respuestas rápidas (escribe «/» y el atajo).',
        'Agregar notas internas que solo ve el equipo; el cliente nunca las recibe.',
        'Transferir la conversación a otro departamento o agente, o devolverla a «Pendientes» de su departamento.',
        'Cerrar la conversación cuando la atención terminó y reabrirla si el cliente necesita seguimiento.',
        'Aplicar etiquetas a la conversación desde la bandeja o desde el panel de detalles.',
        'Tomar los datos de una solicitud de crédito (Cliente, Producto, Monto solicitado…) desde el mensaje del gestor con «Tomar datos de la solicitud»; quedan a la vista bajo el encabezado del chat y completan las respuestas rápidas.',
        'Guardar al cliente en el directorio de Contactos con «Guardar contacto» cuando aún no está registrado.',
        'Ponerle a un contacto un alias privado (solo lo ves tú) y personalizar los colores de tus chats.',
        'Enviar una plantilla aprobada de WhatsApp, solo si eres administrador con ese permiso.',
    ],
    workflow: [
        'Un cliente escribe: la conversación entra como «Pendiente» en el departamento de la conexión, o se asigna sola si el departamento usa asignación automática.',
        'Revisa «Pendientes» y pulsa «Aceptar». A partir de ese momento tú eres el responsable y la conversación pasa a «En atención». Si solo es un «gracias» o un emoji de una solicitud ya atendida, pulsa «No es solicitud»: se cierra sin enviar nada y no cuenta en los reportes.',
        'Responde desde el compositor. Usa «Nota interna» para dejar contexto al equipo sin que el cliente lo vea.',
        'Si otra área debe atenderla, usa «Más acciones» → «Transferir». Se conservan mensajes, notas, etiquetas y procedencia, y no se envía nada al cliente.',
        'Al terminar, «Solicitud completada» (envía la despedida) o «Completada sin despedida». Al completarla pulsas un solo botón con el resultado del crédito y se completa: «Crédito aprobado · $3,000» (el monto sale de «Resolución»), «Rechazada» o «Sin resultado». El sugerido por tu mensaje «Se autoriza…» o «Solicitud rechazada» viene resaltado, así que basta con Enter. Su historial se conserva en «Cerradas» y, si la persona vuelve a escribir, entra como solicitud nueva a «Pendientes».',
        'Si el cliente vuelve a escribir después del cierre, la conversación regresa a la bandeja para atenderla de nuevo.',
    ],
    fields: [
        {
            name: 'Indicador de SLA: En tiempo, Por vencer, Vencida',
            description:
                'Aparece en la lista, en el encabezado del chat y en «Detalles» mientras el cliente espera la respuesta de una persona y existe un tiempo máximo de respuesta. Muestra cuánto falta o cuánto se excedió; al pasar el cursor o enfocarlo verás el tiempo transcurrido, la fecha límite, el objetivo que aplica y si solo cuenta el horario de atención. El plazo se fija cuando el cliente escribe y no cambia aunque la conversación se reasigne o cambien las reglas. Solo la respuesta de una persona lo detiene: los mensajes automáticos, las notas y marcar como leído no cuentan.',
        },
        {
            name: 'Necesita información / Lista para atención',
            description:
                'Preparación de la solicitud, independiente de su estado. Donde el departamento o la categoría exige confirmar la solicitud como completa, una conversación nueva nace como «Necesita información» y no puede tomarse hasta que alguien con el permiso la confirma («Confirmar solicitud completa», en el menú de la fila o en «Más acciones»). «Marcar que necesita información» la devuelve, indicando qué falta. El cliente puede seguir escribiendo y no se pierde nada del historial.',
        },
        {
            name: 'Esperando información del cliente',
            description:
                'Cuando alguien marca que a una solicitud le falta información (desde «Pendientes» o mientras la atiende), la solicitud sale de «Pendientes» y espera en su propia pestaña, «Esperando información», con su contador; ahí se ordena por el momento en que se devolvió. No cuenta como pendiente ni bloquea el orden de llegada. En cuanto el cliente escribe de nuevo (texto, foto o documento), regresa sola a «Pendientes» como lista para atención y con la hora de ese mensaje: entra detrás de las que llegaron mientras esperaba, en la misma conversación y con el mismo folio. Si el cliente ya envió lo que faltaba por otro medio, pulsa el botón verde «Confirmar solicitud completa» del chat o «Completa» en la fila.',
        },
        {
            name: 'Disponible para atención en 02:35',
            description:
                'Tiempo mínimo de espera en la cola: la cuenta regresiva indica cuándo podrás aceptar la solicitud. «Aceptar» se habilita solo al llegar a cero. El tiempo cuenta desde que la solicitud entró a la cola de listas (al llegar, al confirmarse como completa o al volver a «Pendientes»).',
        },
        {
            name: 'Orden de llegada',
            description:
                'Si tu departamento atiende en orden de llegada, solo puedes aceptar la primera solicitud lista de su cola. En las demás verás «Existe una solicitud anterior que debe atenderse primero.» y el botón «Ir a la solicitud que puedes tomar». Las solicitudes de otros departamentos, las que necesitan información (incluidas las que esperan información del cliente) y las que siguen en su tiempo de espera no cuentan.',
        },
        {
            name: 'Hacer una excepción',
            description:
                'Solo para quien tiene el permiso de hacer excepciones a las reglas de la cola: permite aceptar una solicitud saltándose la confirmación, el tiempo de espera o el orden, siempre con un motivo. Queda en el historial de la conversación y en la auditoría. No omite tu límite de conversaciones ni el control de conversaciones pendientes de atención.',
        },
        {
            name: 'Resultado del crédito',
            description:
                '«Solicitud completada» solo dice que se atendió; el resultado dice si el crédito se aprobó (lo único que comisiona, con su monto autorizado) o se rechazó. Es opcional: puedes completar sin resultado y registrarlo después en «Detalles» → «Resultado del crédito» → «Registrar» o «Cambiar». Quien completó la solicitud, o quien la supervisa, puede corregirlo; el cambio queda en el historial de la conversación y en la auditoría. Si la solicitud se reabre, su resultado se borra y se registra de nuevo al completarla.',
        },
        {
            name: 'Resolución',
            description:
                'En «Detalles» → «Datos de la solicitud», «Resolución» tiene «Monto autorizado», «Desembolso» y «Cantidad a pagar»: escríbelos una vez y se guardan al salir del campo o con Enter (Esc deshace). También se toman solos del mensaje de autorización que envías («Se autoriza…» con sus líneas de monto), aunque venga con faltas de ortografía o sin acentos; el último mensaje manda. Las respuestas rápidas completan con ellos sus líneas «Monto autorizado: $», «Desembolso: $» y «Cantidad a pagar: $», y el monto autorizado es el que aparece al completar la solicitud.',
        },
        {
            name: 'No es solicitud',
            description:
                'Para cuando, después de una solicitud ya atendida, el cliente solo responde «gracias», un emoji o «ok» y eso entra como conversación nueva a «Pendientes». Pulsa «No es solicitud» (junto a «Aceptar» en la fila, en el encabezado del chat o en el menú de la conversación): no hace falta aceptarla antes y no la frenan el orden de llegada ni el tiempo de espera. Se cierra al instante sin enviar nada al cliente (tampoco la despedida), pasa a «Cerradas» con la marca «No es solicitud» y no cuenta en los reportes ni en el tablero (recibidas, atendidas, resueltas, tiempos ni SLA). Si te equivocaste, pulsa «Deshacer» en el aviso o en el encabezado durante los siguientes 10 minutos: vuelve a donde estaba, con su lugar en la fila. Si el cliente escribe otra vez, entra como conversación nueva a «Pendientes». Queda en el historial de la conversación y en la auditoría.',
        },
        {
            name: 'Categoría de la solicitud',
            description:
                'Tipo de solicitud (por ejemplo «Facturación»). Se muestra en la fila y se elige en «Detalles»; puede cambiarla el responsable de la conversación o quien asigna o confirma solicitudes. Una categoría puede tener sus propias reglas de servicio. Filtra la bandeja por categoría o por preparación desde «Filtros».',
        },
        {
            name: 'Vista previa',
            description:
                'Mantén presionada una fila, haz clic derecho o elige «Vista previa» en su menú: verás el mensaje inicial, los mensajes recientes, cuándo se recibió, su área y categoría, si es nueva, pendiente, en proceso o finalizada, sus archivos y si está disponible para atención. No marca nada como leído ni cambia la conversación.',
        },
        {
            name: 'Solicitudes anteriores',
            description:
                'En «Detalles», las conversaciones anteriores del mismo contacto que puedes ver, cada una como la solicitud que fue: «Solicitud de» y el nombre del cliente (el dato «Cliente»; si no se capturó dice «Cliente sin capturar»), la fecha y hora, el tipo, producto y monto solicitado cuando se tomaron, el departamento, quién la atendió y cómo terminó: «Crédito aprobado» con su monto, «Rechazada», «Completada», «Cerrada» o «No es solicitud». Se ven las cinco más recientes; «Ver todas» muestra el resto. Un clic abre esa conversación. Recuerda que el contacto suele ser el gestor, que envía solicitudes de muchos clientes.',
        },
        {
            name: 'Conversación completa',
            description:
                'Todos los mensajes del contacto, de todas sus solicitudes que puedes ver, en orden de fecha y sin cortes, como un solo chat. Ábrela con el botón de mensajes del encabezado del chat (en el teléfono, en «Más acciones») o con «Conversación completa» en «Solicitudes anteriores». Cada solicitud empieza con un separador que dice su fecha, el cliente, el resultado y quién la atendió, con «Abrir» para ir a esa conversación. Al subir se cargan los mensajes anteriores. Es solo de lectura: no marca nada como leído y no incluye conversaciones que tu rol no puede ver ni el historial archivado de Whaticket.',
        },
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
                'Vista, estado, departamento, responsable, conexión, canal, etiquetas, orden y «Buscar en». «Bandeja general», «Pendientes» y «No leídas» siempre están a la vista. Con varias etiquetas eliges «Con al menos una de estas etiquetas» (por defecto) o «Con todas estas etiquetas a la vez». Los filtros aplicados se muestran como chips y «Limpiar filtros» los quita todos.',
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
                'Tiene un responsable. Responde esa persona y, sin quitársela, quien tenga el permiso «Responder conversaciones que atiende otra persona»; los demás la ven según su permiso.',
        },
        {
            name: 'Cerrada',
            description:
                'La atención terminó y su historial se conserva. Cerrar no envía ningún mensaje al cliente ni cambia la ventana de WhatsApp.',
        },
    ],
    permissions: [
        '«Ver las conversaciones que le corresponden» muestra las conversaciones de tus departamentos o las asignadas a ti, según tu rol.',
        '«Aceptar conversaciones pendientes de sus departamentos» permite tomar las pendientes; «Atender y responder sus conversaciones» permite responder las tuyas.',
        '«Asignar conversaciones a otras personas» permite entregar conversaciones a otra persona; «Transferir» y «Cerrar» se otorgan por separado.',
        '«Supervisar conversaciones (solo lectura)» deja ver todas las conversaciones que tu rol puede ver, incluidas las cerradas y las que pasaron por tus departamentos, pero no permite responder, aceptar ni transferir.',
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
                'La atiende otra persona y tu rol no tiene el permiso «Responder conversaciones que atiende otra persona». Deja una nota interna o pide que te la transfieran.',
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
            problem:
                '«WhatsApp no admite… como archivo adjunto. Puedes enviarlo como enlace privado solo si lo eliges…»',
            solution:
                'WhatsApp no admite archivos ZIP como adjunto. Si pulsas «Usar enlace privado» y luego «Enviar», el cliente recibe un mensaje con un enlace privado para descargarlo, que vence a los pocos días (la burbuja muestra la fecha). Puedes deshacerlo con «No usar enlace». Si el enlace venció, adjunta el archivo de nuevo.',
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
        '«Tomar datos de la solicitud» es opcional: aparece bajo los mensajes que parecen una solicitud (o junto al mensaje, al pasar el cursor). Marca los datos que quieres, corrige el valor y elige qué es cada uno. Un clic en un dato de la franja lo copia; el lápiz permite corregir o quitar.',
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
