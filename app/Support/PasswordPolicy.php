<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

/**
 * Describes the effective password policy (Password::defaults()) so forms can
 * show the requirements and check them while the user types. The server
 * keeps validating the password, including the "uncompromised" check that
 * cannot run in the browser.
 */
class PasswordPolicy
{
    /**
     * @return array{min: int, max: int|null, letters: bool, mixed_case: bool, numbers: bool, symbols: bool, uncompromised: bool}
     */
    public static function current(): array
    {
        /** @var array{min: int, max: int|null, mixedCase: bool, letters: bool, numbers: bool, symbols: bool, uncompromised: bool} $rules */
        $rules = Password::default()->appliedRules();

        return [
            'min' => (int) $rules['min'],
            'max' => $rules['max'] === null ? null : (int) $rules['max'],
            'letters' => (bool) $rules['letters'],
            'mixed_case' => (bool) $rules['mixedCase'],
            'numbers' => (bool) $rules['numbers'],
            'symbols' => (bool) $rules['symbols'],
            'uncompromised' => (bool) $rules['uncompromised'],
        ];
    }
}
