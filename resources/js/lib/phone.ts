/**
 * Client-side mirror of App\Support\PhoneNumber, used only to preview how a
 * number will be stored. The server normalizes and validates again.
 */
const COUNTRIES: Record<
    string,
    { callingCode: string; nationalLength: number }
> = {
    MX: { callingCode: '52', nationalLength: 10 },
    US: { callingCode: '1', nationalLength: 10 },
    CA: { callingCode: '1', nationalLength: 10 },
};

export function normalizePhone(
    value: string | null | undefined,
    defaultCountry = 'MX',
): string | null {
    const trimmed = (value ?? '').trim();

    if (trimmed === '') {
        return null;
    }

    const compact = trimmed.replace(/[\s().\- ]+/g, '');
    const match = /^(\+|00)?(\d+)$/.exec(compact);

    if (!match) {
        return null;
    }

    let digits = match[2];

    if (!match[1]) {
        const country = COUNTRIES[defaultCountry.toUpperCase()];

        if (!country || digits.length !== country.nationalLength) {
            return null;
        }

        digits = country.callingCode + digits;
    }

    if (digits.length === 13 && digits.startsWith('521')) {
        digits = '52' + digits.slice(3);
    }

    if (digits.startsWith('52') && !/^52[2-9]\d{9}$/.test(digits)) {
        return null;
    }

    if (digits.length < 8 || digits.length > 15 || digits.startsWith('0')) {
        return null;
    }

    return `+${digits}`;
}

/**
 * Readable rendering of an E.164 number: "+52 55 1234 5678",
 * "+1 415 555 2671"; other countries are grouped generically.
 */
export function formatPhone(e164: string | null | undefined): string {
    if (!e164) {
        return '—';
    }

    const digits = e164.replace(/\D/g, '');

    if (digits.startsWith('52') && digits.length === 12) {
        return `+52 ${digits.slice(2, 4)} ${digits.slice(4, 8)} ${digits.slice(8)}`;
    }

    if (digits.startsWith('1') && digits.length === 11) {
        return `+1 ${digits.slice(1, 4)} ${digits.slice(4, 7)} ${digits.slice(7)}`;
    }

    const groups = digits.slice(2).match(/.{1,4}/g) ?? [];

    return `+${digits.slice(0, 2)} ${groups.join(' ')}`.trim();
}
