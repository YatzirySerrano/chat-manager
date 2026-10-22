import { normalizePhone } from '@/lib/phone';
import type { PasswordPolicy } from '@/types';

/**
 * Client-side validation rules. They only give immediate feedback while the
 * user types: every rule mirrors (and is never stricter than) a rule the
 * server applies again, and the server answer is always authoritative.
 *
 * A rule receives the field value and the whole form data and returns the
 * message to show, or nothing when the value is acceptable.
 */
export type FieldRule = (
    value: unknown,
    data: Record<string, unknown>,
) => string | null | undefined;

/** Rules per field path ("name", "general.timezone", "custom_fields.0.label"). */
export type FieldRules = Record<string, FieldRule[]>;

/** Turns a value into the representation used to detect effective changes. */
export type ValueNormalizer = (value: unknown) => unknown;

/**
 * Value at a dot path of a nested object ("business_hours.days.monday.end").
 */
export function valueAt(data: unknown, path: string): unknown {
    return path.split('.').reduce<unknown>((carry, key) => {
        if (carry === null || typeof carry !== 'object') {
            return undefined;
        }

        return (carry as Record<string, unknown>)[key];
    }, data);
}

function isBlank(value: unknown): boolean {
    if (value === null || value === undefined) {
        return true;
    }

    if (typeof value === 'string') {
        return value.trim() === '';
    }

    if (Array.isArray(value)) {
        return value.length === 0;
    }

    return false;
}

/** Text of a scalar form value ('' for anything else). */
export function toText(value: unknown): string {
    if (typeof value === 'string') {
        return value;
    }

    return typeof value === 'number' || typeof value === 'boolean'
        ? String(value)
        : '';
}

/**
 * Length in Unicode code points, like PHP's mb_strlen used by the server.
 */
function codePoints(text: string): number {
    return Array.from(text).length;
}

function characters(value: unknown): number {
    return codePoints(toText(value).trim());
}

export function required(message = 'Este campo es obligatorio.'): FieldRule {
    return (value) => (isBlank(value) ? message : null);
}

export function minLength(min: number, message?: string): FieldRule {
    return (value) =>
        !isBlank(value) && characters(value) < min
            ? (message ?? `Escribe al menos ${min} caracteres.`)
            : null;
}

export function maxLength(max: number, message?: string): FieldRule {
    return (value) =>
        !isBlank(value) && characters(value) > max
            ? (message ??
              `Escribe como máximo ${max} caracteres (llevas ${characters(value)}).`)
            : null;
}

export function pattern(regex: RegExp, message: string): FieldRule {
    return (value) =>
        !isBlank(value) && !regex.test(toText(value).trim()) ? message : null;
}

/**
 * Basic email syntax: something before the @, a domain and its extension,
 * no spaces. The server validates the address again.
 */
export const EMAIL_PATTERN = /^[^\s@]+@[^\s@.]+(\.[^\s@.]+)+$/;

export function email(
    message = 'Escribe un correo válido, por ejemplo nombre@empresa.com.',
): FieldRule {
    return pattern(EMAIL_PATTERN, message);
}

/**
 * Whole numbers within a range; an empty value is left to `required`.
 */
export function integerBetween(
    min: number,
    max: number,
    message?: string,
): FieldRule {
    return (value) => {
        if (isBlank(value)) {
            return null;
        }

        const text = toText(value).trim();

        if (!/^-?\d+$/.test(text)) {
            return 'Escribe un número entero, sin decimales.';
        }

        const number = Number(text);

        return number < min || number > max
            ? (message ?? `Escribe un número entre ${min} y ${max}.`)
            : null;
    };
}

/**
 * The value must repeat another field (password confirmations).
 */
export function matches(otherPath: string, message: string): FieldRule {
    return (value, data) =>
        toText(value) === toText(valueAt(data, otherPath)) ? null : message;
}

/**
 * Apply the rules only while the condition holds (for example, only when the
 * user chose to change the password).
 */
export function when(
    condition: (data: Record<string, unknown>) => boolean,
    rules: FieldRule[],
): FieldRule {
    return (value, data) => {
        if (!condition(data)) {
            return null;
        }

        for (const rule of rules) {
            const message = rule(value, data);

            if (message) {
                return message;
            }
        }

        return null;
    };
}

const MEXICO_NATIONAL_LENGTH = 10;

function missingDigits(count: number): string {
    return `Faltan ${count} ${count === 1 ? 'dígito' : 'dígitos'}`;
}

/**
 * Why a phone number cannot be normalized, mirroring App\Support\PhoneNumber
 * and normalizePhone(): without a prefix it must be a 10-digit Mexican
 * number; with "+" (or "00") it is an international number of 8 to 15
 * digits, and a +52 number must still have 10 national digits.
 */
export function phoneProblem(value: string | null | undefined): string | null {
    const trimmed = (value ?? '').trim();

    if (trimmed === '') {
        return null;
    }

    const compact = trimmed.replace(/[\s().\- ]+/g, '');
    const match = /^(\+|00)?(\d+)$/.exec(compact);

    if (!match) {
        return /[a-z]/i.test(compact)
            ? 'El teléfono solo lleva dígitos; no se aceptan letras ni extensiones.'
            : 'Usa solo dígitos, espacios, guiones o paréntesis, y el signo + únicamente al inicio.';
    }

    let digits = match[2];

    if (!match[1]) {
        const missing = MEXICO_NATIONAL_LENGTH - digits.length;

        if (missing > 0) {
            return `${missingDigits(missing)}: un número de México tiene 10 dígitos.`;
        }

        if (missing < 0) {
            return 'Un número de México tiene 10 dígitos. Si es de otro país, escríbelo con + y su lada internacional (por ejemplo +1 415 555 2671).';
        }

        digits = `52${digits}`;
    }

    if (digits.length === 13 && digits.startsWith('521')) {
        digits = `52${digits.slice(3)}`;
    }

    if (digits.startsWith('52')) {
        const national = digits.slice(2);

        if (national.length < MEXICO_NATIONAL_LENGTH) {
            return `${missingDigits(MEXICO_NATIONAL_LENGTH - national.length)}: después de la lada +52 van 10 dígitos.`;
        }

        if (national.length > MEXICO_NATIONAL_LENGTH) {
            return 'Sobran dígitos: después de la lada +52 van 10 dígitos.';
        }

        if (/^[01]/.test(national)) {
            return 'Un número de México no empieza con 0 ni con 1.';
        }
    }

    if (digits.startsWith('0')) {
        return 'Después del + va la lada del país, que no empieza con 0.';
    }

    if (digits.length < 8) {
        return 'Faltan dígitos: un número internacional tiene al menos 8 dígitos contando la lada.';
    }

    if (digits.length > 15) {
        return 'Sobran dígitos: un número internacional tiene como máximo 15 dígitos.';
    }

    return null;
}

export function phone(): FieldRule {
    return (value) => phoneProblem(value as string | null | undefined);
}

export type PasswordRequirement = {
    key: 'min' | 'max' | 'letters' | 'mixed_case' | 'numbers' | 'symbols';
    label: string;
    met: boolean;
};

/**
 * Requirements of the effective password policy and whether the value meets
 * each one, using the same checks as Illuminate\Validation\Rules\Password.
 */
export function passwordRequirements(
    policy: PasswordPolicy,
    value: string,
): PasswordRequirement[] {
    const length = codePoints(value);
    const requirements: PasswordRequirement[] = [
        {
            key: 'min',
            label: `Al menos ${policy.min} caracteres`,
            met: length >= policy.min,
        },
    ];

    if (policy.max !== null) {
        requirements.push({
            key: 'max',
            label: `Como máximo ${policy.max} caracteres`,
            met: length <= policy.max,
        });
    }

    if (policy.mixed_case) {
        requirements.push({
            key: 'mixed_case',
            label: 'Mayúsculas y minúsculas',
            met: /(\p{Ll}+.*\p{Lu})|(\p{Lu}+.*\p{Ll})/u.test(value),
        });
    } else if (policy.letters) {
        requirements.push({
            key: 'letters',
            label: 'Al menos una letra',
            met: /\p{L}/u.test(value),
        });
    }

    if (policy.numbers) {
        requirements.push({
            key: 'numbers',
            label: 'Al menos un número',
            met: /\p{N}/u.test(value),
        });
    }

    if (policy.symbols) {
        requirements.push({
            key: 'symbols',
            label: 'Al menos un símbolo (por ejemplo ! # $ %)',
            met: /\p{Z}|\p{S}|\p{P}/u.test(value),
        });
    }

    return requirements;
}

export function password(policy: PasswordPolicy): FieldRule {
    return (value) => {
        if (isBlank(value)) {
            return null;
        }

        const unmet = passwordRequirements(policy, toText(value)).find(
            (requirement) => !requirement.met,
        );

        return unmet
            ? `La contraseña no cumple: ${unmet.label.toLowerCase()}.`
            : null;
    };
}

/**
 * Default comparison form: trimmed strings (empty ones equal null), numbers
 * as strings (so 60 equals "60"), objects with sorted keys and arrays of
 * plain values (id lists) sorted, because their order is not meaningful.
 */
export function normalizeValue(value: unknown, ordered = false): unknown {
    if (value === undefined || value === null) {
        return null;
    }

    if (typeof value === 'string') {
        const trimmed = value.replace(/\r\n?/g, '\n').trim();

        return trimmed === '' ? null : trimmed;
    }

    if (typeof value === 'number') {
        return Number.isFinite(value) ? String(value) : null;
    }

    if (Array.isArray(value)) {
        const items = value.map((item) => normalizeValue(item));
        const plain = items.every(
            (item) => item === null || typeof item !== 'object',
        );

        return !ordered && plain
            ? [...items].sort((a, b) => String(a).localeCompare(String(b)))
            : items;
    }

    if (typeof value === 'object') {
        return Object.fromEntries(
            Object.keys(value as Record<string, unknown>)
                .sort()
                .map((key) => [
                    key,
                    normalizeValue((value as Record<string, unknown>)[key]),
                ]),
        );
    }

    return value;
}

/**
 * Field-specific comparison forms, matching how the server normalizes them.
 */
export const normalizers = {
    /** Collapses inner whitespace (names the server "squishes"). */
    squish: (value: unknown): unknown =>
        normalizeValue(
            typeof value === 'string' ? value.replace(/\s+/g, ' ') : value,
        ),
    /** Case-insensitive email. */
    email: (value: unknown): unknown =>
        normalizeValue(typeof value === 'string' ? value.toLowerCase() : value),
    /** Phone numbers compared in E.164, as the server stores them. */
    phone: (value: unknown): unknown =>
        normalizePhone(typeof value === 'string' ? value : null) ??
        normalizeValue(value),
    /** Lists whose order matters (custom fields). */
    ordered: (value: unknown): unknown => normalizeValue(value, true),
} satisfies Record<string, ValueNormalizer>;
