<?php

namespace App\Support;

/**
 * Normalizes phone numbers to E.164 ("+" followed by 8 to 15 digits) without
 * external dependencies, so contacts entered by hand and identities received
 * from WhatsApp can be compared.
 *
 * Limitations (it is NOT a full libphonenumber replacement):
 * - it does not validate numbering plans, area codes or number types;
 * - a number without an international prefix is only accepted when it has the
 *   national length of the default country (10 digits for Mexico), and a
 *   Mexican (+52) number must always have 10 national digits not starting
 *   with 0 or 1;
 * - trunk prefixes other than Mexico's legacy mobile "1" (+52 1 ...) are not
 *   removed, so the same number written with and without them may differ;
 * - extensions and vanity letters are rejected instead of being interpreted.
 */
class PhoneNumber
{
    /**
     * Calling code and national significant number length per default country.
     *
     * @var array<string, array{calling_code: string, national_length: int}>
     */
    private const COUNTRIES = [
        'MX' => ['calling_code' => '52', 'national_length' => 10],
        'US' => ['calling_code' => '1', 'national_length' => 10],
        'CA' => ['calling_code' => '1', 'national_length' => 10],
    ];

    /**
     * A Mexican number: calling code 52 and 10 national digits that never
     * start with 0 or 1 (a leading 1 is the legacy mobile prefix).
     */
    private const MEXICAN_NUMBER = '/^52[2-9]\d{9}$/';

    private const MIN_DIGITS = 8;

    private const MAX_DIGITS = 15;

    /**
     * The E.164 representation of the number, or null when it cannot be
     * normalized reliably.
     */
    public static function normalize(?string $number, string $defaultCountry = 'MX'): ?string
    {
        $number = trim((string) $number);

        if ($number === '') {
            return null;
        }

        $compact = preg_replace('/[\s().\-\x{00A0}]+/u', '', $number) ?? '';

        if (preg_match('/^(\+|00)?(\d+)$/', $compact, $matches) !== 1) {
            return null;
        }

        $hasInternationalPrefix = $matches[1] !== '';
        $digits = $matches[2];

        if (! $hasInternationalPrefix) {
            $country = self::COUNTRIES[strtoupper($defaultCountry)] ?? null;

            if ($country === null || strlen($digits) !== $country['national_length']) {
                return null;
            }

            $digits = $country['calling_code'].$digits;
        }

        if (strlen($digits) === 13 && str_starts_with($digits, '521')) {
            $digits = '52'.substr($digits, 3);
        }

        if (str_starts_with($digits, '52') && preg_match(self::MEXICAN_NUMBER, $digits) !== 1) {
            return null;
        }

        if (strlen($digits) < self::MIN_DIGITS || strlen($digits) > self::MAX_DIGITS || str_starts_with($digits, '0')) {
            return null;
        }

        return '+'.$digits;
    }

    /**
     * Whether the number can be normalized.
     */
    public static function isValid(?string $number, string $defaultCountry = 'MX'): bool
    {
        return self::normalize($number, $defaultCountry) !== null;
    }
}
