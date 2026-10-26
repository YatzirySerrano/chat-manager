import type { ModuleHelp } from './types';

const help: ModuleHelp = {
    title: 'Auditoría',
    objective:
        'Registro inalterable de las acciones administrativas: quién hizo qué, cuándo y sobre qué registro. No incluye contraseñas, claves de acceso ni secretos, ni el contenido de los mensajes con clientes.',
    mainActions: [
        'Filtrar por acción, responsable y rango de fechas.',
        'Abrir un evento para ver quién lo realizó, la entidad afectada, los cambios y los detalles.',
        'Exportar los eventos que puedes consultar.',
    ],
    workflow: [
        'Elige la acción (por ejemplo, cambios de rol) o el responsable que quieres revisar.',
        'Acota con «Desde» y «Hasta».',
        'Abre el evento para ver el antes y el después de los cambios.',
        'Si necesitas compartirlo, exporta con los mismos filtros.',
    ],
    fields: [
        {
            name: 'Acción',
            description:
                'Qué ocurrió: usuarios, roles, departamentos, conexiones, contactos, etiquetas, respuestas rápidas, importaciones, seguridad de la cuenta, claves de acceso de integraciones, grupos del chat interno, supervisión de elementos personales y más.',
        },
        {
            name: 'Responsable',
            description:
                'Quien realizó la acción; «Sistema» cuando la hizo un proceso automático.',
        },
        {
            name: 'Cambios',
            description:
                'Valores anteriores y nuevos. Los datos sensibles se omiten.',
        },
        {
            name: 'Dirección IP y navegador',
            description: 'Desde dónde se hizo la acción.',
        },
    ],
    states: [
        {
            name: 'Reservado',
            description:
                'Evento con acceso restringido: solo lo ven las cuentas autorizadas para consultarlo.',
        },
    ],
    permissions: [
        '«Ver registros de auditoría»: si tu rol ve toda la organización, ves todos los eventos; si no, solo los tuyos.',
        '«Exportar registros de auditoría» exporta únicamente lo que ya puedes consultar.',
    ],
    commonErrors: [
        {
            problem: 'No encuentro un mensaje enviado a un cliente.',
            solution:
                'La auditoría no registra conversaciones ni mensajes; consúltalos en el historial de la conversación.',
        },
        {
            problem: '«Sin resultados para estos filtros».',
            solution:
                'Amplía el rango de fechas o quita filtros con «Limpiar».',
        },
    ],
    tips: [
        'Los eventos no pueden editarse ni eliminarse.',
        'Los mensajes del chat interno nunca se auditan; solo la administración de grupos.',
    ],
    examples: [
        'Saber quién cambió los permisos del rol Supervisor la semana pasada.',
        'Revisar qué claves de acceso de integraciones se crearon o revocaron este mes.',
    ],
};

export default help;
