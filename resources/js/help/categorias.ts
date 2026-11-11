import type { ModuleHelp } from './types';

const help: ModuleHelp = {
    title: 'Categorías de solicitud',
    objective:
        'Catálogo de los tipos de solicitud que hacen los clientes (por ejemplo «Garantías» o «Cotizaciones»). Una categoría clasifica la conversación y puede tener reglas de servicio propias, que tienen prioridad sobre las del departamento y las generales.',
    mainActions: [
        'Crear una categoría con nombre, descripción y color.',
        'Editar, eliminar y restaurar categorías.',
        'Definir las reglas de servicio propias de cada categoría con «Reglas de servicio».',
        'Ver cuántas conversaciones tiene cada categoría y cuántas reglas personaliza.',
    ],
    workflow: [
        'Crea las categorías que usa tu operación y describe cuándo elegir cada una.',
        'Abre «Reglas de servicio» en la categoría que necesite un trato distinto y pulsa «Personalizar» solo en las reglas que cambian.',
        'Lo que no personalices se hereda: primero del departamento de la solicitud y, si este tampoco lo define, de Configuración.',
        'Si una categoría deja de usarse, elimínala: ya no podrá elegirse y sus reglas dejan de aplicarse, pero se conserva en las conversaciones que ya la tenían.',
    ],
    fields: [
        {
            name: 'Nombre',
            description:
                'Obligatorio, hasta 120 caracteres y único. No se distinguen mayúsculas de minúsculas.',
        },
        {
            name: 'Descripción (opcional)',
            description:
                'Hasta 255 caracteres. Explica cuándo elegirla para que todos clasifiquen igual.',
        },
        {
            name: 'Tiempo máximo de respuesta (SLA)',
            description:
                'Plazos en minutos para la primera respuesta, para cada mensaje posterior y para la resolución, y el porcentaje del plazo a partir del cual se avisa «próxima a vencer». Se aplica a las solicitudes nuevas; las que ya están en curso conservan su plazo.',
        },
        {
            name: 'Solicitud completa / Lista para atención',
            description:
                'Si se exige, las solicitudes nuevas de la categoría nacen como «Necesita información» y no pueden tomarse hasta que alguien las confirme como completas.',
        },
        {
            name: 'Tiempo mínimo de espera',
            description:
                'Minutos que una solicitud lista debe permanecer en la cola antes de poder aceptarse (0 lo desactiva). Se aplica de inmediato a la cola.',
        },
        {
            name: '«Usa el valor general» y «Valor propio»',
            description:
                'Cada regla indica si la categoría hereda el valor o tiene uno propio. «Personalizar» fija un valor propio; «Usar el valor general» vuelve a heredar.',
        },
    ],
    states: [
        {
            name: 'Activa',
            description:
                'Puede elegirse en las conversaciones y sus reglas propias se aplican.',
        },
        {
            name: 'Eliminada',
            description:
                'No puede elegirse y sus reglas dejan de aplicarse. Se conserva en las conversaciones que ya la tenían y puede restaurarse.',
        },
    ],
    permissions: [
        '«Administrar categorías de solicitud» permite ver y cambiar el catálogo y sus reglas; requiere un rol que vea toda la organización. Restaurar también requiere «Consultar registros eliminados».',
        '«Contar solo el horario de atención» y «Atender en orden de llegada» no se definen por categoría: se configuran en el departamento o en Configuración.',
    ],
    commonErrors: [
        {
            problem: '«Ya existe una categoría con ese nombre…»',
            solution:
                'Usa la existente o elige otro nombre; las mayúsculas no cuentan como diferencia.',
        },
        {
            problem:
                'Cambié un plazo y una solicitud abierta sigue con el anterior.',
            solution:
                'Es lo esperado: el plazo se fija cuando el cliente escribe. El cambio se aplica desde el siguiente mensaje del cliente y en las solicitudes nuevas.',
        },
        {
            problem:
                'La categoría muestra «Usa el valor general», pero se aplica otro valor.',
            solution:
                'El departamento de esa solicitud tiene un valor propio para esa regla; la categoría hereda primero del departamento.',
        },
    ],
    tips: [
        'Personaliza solo lo que de verdad cambia: un catálogo con pocas excepciones es más fácil de explicar al equipo.',
        'Todos los cambios de reglas quedan en la Auditoría con el valor anterior y el nuevo.',
    ],
    examples: [
        '«Urgencias» con primera respuesta en 5 minutos, aunque el general sea de 15.',
        '«Garantías» con confirmación de solicitud completa, para no atenderlas sin número de pedido.',
    ],
};

export default help;
