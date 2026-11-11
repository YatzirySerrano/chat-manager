import type {
    FieldHelpContent,
    ServiceRuleDescription,
    ServiceRuleGroups,
    ServiceRuleKey,
    ServiceRuleLevel,
    ServiceRuleSet,
    ServiceRuleValue,
    ServiceRuleValues,
} from '@/types';

/**
 * Presentation of the service rules (App\Services\ServiceRules): the three
 * blocks the screens always show apart, and the wording of each rule. The
 * bounds, units and names come from the server (`describe()`); only the
 * explanations live here.
 */
export type ServiceRuleBlockId = 'sla' | 'intake' | 'queue';

export type ServiceRuleBlock = {
    id: ServiceRuleBlockId;
    title: string;
    description: string;
    /** When a change of these rules takes effect. */
    effect: string;
    keys: ServiceRuleKey[];
};

export const serviceRuleBlocks: ServiceRuleBlock[] = [
    {
        id: 'sla',
        title: 'Tiempo máximo de respuesta (SLA)',
        description:
            'Cuánto puede tardar el equipo en responder y en resolver. Sirve para avisar a tiempo y para medir el cumplimiento; no impide atender.',
        effect: 'Se aplica a las solicitudes nuevas; las que ya están en curso conservan su plazo.',
        keys: [
            'sla.first_response_minutes',
            'sla.response_minutes',
            'sla.resolution_minutes',
            'sla.warning_percent',
            'sla.business_hours_only',
        ],
    },
    {
        id: 'intake',
        title: 'Solicitud completa / Lista para atención',
        description:
            'Decide si una solicitud debe confirmarse como completa antes de que un agente pueda tomarla. No es un plazo ni cambia el orden de la cola.',
        effect: 'Solo las solicitudes nuevas nacerán como pendientes de información.',
        keys: ['intake.confirmation_required'],
    },
    {
        id: 'queue',
        title: 'Tiempo mínimo de espera y orden de atención',
        description:
            'Cuándo y en qué orden pueden tomarse las solicitudes que ya están listas en la cola. Es lo contrario del SLA: aquí se define una espera mínima, no un plazo máximo.',
        effect: 'Se aplica de inmediato a la cola.',
        keys: ['queue.min_wait_minutes', 'queue.chronological_order'],
    },
];

type RuleCopy = {
    help: FieldHelpContent;
    /** State text of a switch rule. */
    on?: string;
    off?: string;
    /** Shown for a nullable rule without value. */
    empty?: string;
    /** Starting value when a level begins customizing a rule that has no inherited value. */
    suggested?: number;
};

export const serviceRuleCopy: Record<ServiceRuleKey, RuleCopy> = {
    'sla.first_response_minutes': {
        help: {
            description:
                'Tiempo máximo desde que llega la solicitud hasta que una persona del equipo responde por primera vez. Los mensajes automáticos no cuentan como respuesta.',
            example:
                '15: si el cliente escribe a las 10:00, la primera respuesta debe salir antes de las 10:15.',
            warning:
                'Sin valor no hay plazo: la solicitud no se marca como vencida ni genera alertas.',
        },
        empty: 'Sin objetivo',
        suggested: 15,
    },
    'sla.response_minutes': {
        help: {
            description:
                'Tiempo máximo para contestar cada mensaje posterior del cliente, una vez que ya hubo una primera respuesta. El plazo empieza cuando el cliente escribe y termina cuando el equipo responde.',
            example:
                '30: si el cliente vuelve a escribir a las 11:00, hay que responderle antes de las 11:30.',
            warning:
                'Varios mensajes seguidos del cliente cuentan como una sola espera, desde el primero sin responder.',
        },
        empty: 'Sin objetivo',
        suggested: 30,
    },
    'sla.resolution_minutes': {
        help: {
            description:
                'Tiempo máximo desde que llega la solicitud hasta que se cierra por primera vez.',
            example: '480 equivale a una jornada de 8 horas de atención.',
            warning:
                'Es un objetivo de medición: no cierra la solicitud por sí solo ni impide seguir atendiéndola.',
        },
        empty: 'Sin objetivo',
        suggested: 480,
    },
    'sla.warning_percent': {
        help: {
            description:
                'Porcentaje del plazo que debe haberse consumido para que una solicitud se muestre como «próxima a vencer».',
            example:
                '80 con un plazo de 15 minutos: el aviso aparece a los 12 minutos.',
            warning:
                'Un valor bajo avisa antes, pero genera más avisos; uno alto deja menos margen para reaccionar.',
        },
    },
    'sla.business_hours_only': {
        help: {
            description:
                'Si está activado, el plazo solo corre durante el horario de atención del departamento (o el de la plataforma) y se detiene de noche, en fin de semana y en días no laborables.',
            example:
                'Con horario de 9:00 a 18:00 y plazo de 60 minutos, una solicitud que llega a las 17:30 vence a las 9:30 del siguiente día laborable.',
            warning:
                'Si se desactiva, el plazo corre las 24 horas, también cuando no hay nadie atendiendo.',
        },
        on: 'El plazo solo corre en horario de atención',
        off: 'El plazo corre las 24 horas, todos los días',
    },
    'intake.confirmation_required': {
        help: {
            description:
                'Si está activado, cada solicitud nueva nace como «Necesita información» y nadie puede tomarla hasta que alguien con permiso la confirme como «Solicitud completa / Lista para atención».',
            example:
                'Una solicitud de garantía no se atiende hasta confirmar que el cliente envió su número de pedido.',
            warning:
                'Mientras una solicitud no se confirme, permanece fuera de la cola de atención. Asegúrate de que alguien tenga el permiso «Confirmar que una solicitud está completa».',
        },
        on: 'Las solicitudes nuevas esperan la confirmación',
        off: 'Las solicitudes nuevas quedan listas de inmediato',
    },
    'queue.min_wait_minutes': {
        help: {
            description:
                'Minutos que una solicitud lista debe permanecer en la cola antes de que un agente pueda aceptarla. Sirve para que no se tomen solo las recién llegadas. 0 lo desactiva.',
            example:
                '2: una solicitud que entró a la cola a las 10:00 puede aceptarse a partir de las 10:02.',
            warning:
                'El cliente espera al menos este tiempo. Quien tiene el permiso de hacer excepciones puede tomarla antes, con un motivo.',
        },
    },
    'queue.chronological_order': {
        help: {
            description:
                'Si está activado, los agentes deben tomar primero la solicitud lista más antigua del departamento; no pueden elegir una más reciente.',
            example:
                'Con tres solicitudes en la cola, solo se puede aceptar la que lleva más tiempo esperando.',
            warning:
                'Quien tiene el permiso de hacer excepciones puede saltarse el orden, con un motivo que queda registrado.',
        },
        on: 'Se atiende primero la solicitud más antigua',
        off: 'Los agentes eligen qué solicitud tomar',
    },
};

/** Name of the level a value is inherited from, as the screens call it. */
export function serviceRuleSourceLabel(
    source: ServiceRuleLevel | null,
): string {
    return source === 'department'
        ? 'el valor del departamento'
        : source === 'category'
          ? 'el valor de la categoría'
          : 'el valor general';
}

/** "Tiempo máximo… (minutos)": the unit is always part of the visible name. */
export function serviceRuleLabel(rule: ServiceRuleDescription): string {
    return rule.unit ? `${rule.label} (${rule.unit})` : rule.label;
}

/** A value as text: "15 minutos", "80 % del plazo", the switch state or "Sin objetivo". */
export function formatServiceRuleValue(
    key: ServiceRuleKey,
    rule: ServiceRuleDescription,
    value: ServiceRuleValue,
): string {
    const copy = serviceRuleCopy[key];

    if (typeof value === 'boolean') {
        return (value ? copy.on : copy.off) ?? (value ? 'Sí' : 'No');
    }

    if (value === null) {
        return copy.empty ?? 'Sin valor';
    }

    if (key === 'queue.min_wait_minutes' && value === 0) {
        return 'Sin espera mínima';
    }

    if (rule.unit === 'minutos') {
        return value === 1 ? '1 minuto' : `${value} minutos`;
    }

    return rule.unit?.startsWith('%')
        ? `${value} ${rule.unit}`
        : `${value}${rule.unit ? ` ${rule.unit}` : ''}`;
}

function split(key: ServiceRuleKey): [keyof ServiceRuleGroups, string] {
    const [group, name] = key.split('.') as [keyof ServiceRuleGroups, string];

    return [group, name];
}

/** Read a rule from an object grouped like the settings. */
export function readServiceRule(
    groups: Partial<ServiceRuleGroups>,
    key: ServiceRuleKey,
): ServiceRuleValue {
    const [group, name] = split(key);

    return groups[group]?.[name] ?? null;
}

/** Write a rule into an object grouped like the settings. */
export function writeServiceRule(
    groups: ServiceRuleGroups,
    key: ServiceRuleKey,
    value: ServiceRuleValue,
): void {
    const [group, name] = split(key);

    groups[group][name] = value;
}

/**
 * The rules a level sets on its own, grouped for its form: the own value of
 * each rule the level may override, null where it inherits.
 */
export function ownServiceRules(
    rules: ServiceRuleSet,
    level: ServiceRuleLevel,
): ServiceRuleGroups {
    const groups: ServiceRuleGroups = { sla: {}, intake: {}, queue: {} };

    for (const key of Object.keys(rules) as ServiceRuleKey[]) {
        if (rules[key].overridable) {
            writeServiceRule(
                groups,
                key,
                rules[key].source === level ? rules[key].value : null,
            );
        }
    }

    return groups;
}

/** Grouped rules as the flat map the card edits. */
export function flattenServiceRules(
    rules: ServiceRuleSet,
    groups: Partial<ServiceRuleGroups>,
): ServiceRuleValues {
    const values: ServiceRuleValues = {};

    for (const key of Object.keys(rules) as ServiceRuleKey[]) {
        values[key] = readServiceRule(groups, key);
    }

    return values;
}

/**
 * Problems of the numbers typed, by rule key, in the wording of the server.
 * Empty values are fine: no objective at the platform, inherit elsewhere.
 */
export function serviceRuleErrors(
    rules: ServiceRuleSet,
    values: ServiceRuleValues,
    level: ServiceRuleLevel,
): Partial<Record<ServiceRuleKey, string>> {
    const errors: Partial<Record<ServiceRuleKey, string>> = {};

    for (const key of Object.keys(rules) as ServiceRuleKey[]) {
        const rule = rules[key];
        const value = values[key];

        if (rule.type !== 'integer' || !rule.overridable) {
            continue;
        }

        if (value === null || value === undefined) {
            if (level === 'platform' && !rule.nullable) {
                errors[key] = 'Escribe un valor.';
            }

            continue;
        }

        if (typeof value !== 'number' || !Number.isInteger(value)) {
            errors[key] = 'Escribe un número entero, sin decimales.';
        } else if (
            (rule.min !== null && value < rule.min) ||
            (rule.max !== null && value > rule.max)
        ) {
            errors[key] = `Escribe un valor entre ${rule.min} y ${rule.max}.`;
        }
    }

    return errors;
}
