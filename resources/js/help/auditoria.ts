import type { ModuleHelp } from './types';

const help: ModuleHelp = {
    title: 'Auditoría',
    objective:
        'Registro inalterable de las acciones administrativas: quién hizo qué, cuándo y sobre qué registro. No incluye contraseñas, tokens ni secretos, ni el contenido de los mensajes con clientes.',
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
                'Qué ocurrió: usuarios, roles, departamentos, conexiones, contactos, etiquetas, respuestas rápidas, importaciones, seguridad de la cuenta, tokens de API, grupos del chat interno, supervisión de elementos personales y más.',
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
                'Evento visible solo para superadministradores (acciones sobre la cuenta o el rol raíz e importaciones).',
        },
    ],
    permissions: [
        '«Ver registros de auditoría»: con alcance global ves todos los eventos; sin él, solo las operaciones que tú realizaste.',
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
        'Revisar qué tokens de API se emitieron o revocaron este mes.',
    ],
};

export default help;
