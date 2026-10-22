<?php

namespace App\Rules;

use App\Support\AppColor;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A department or tag color: a key of the platform palette or a custom color
 * written as "#rrggbb" (six hexadecimal digits). Shorthand hex, color names,
 * rgb() and any other CSS syntax are rejected. Read the normalized value with
 * AppColor::from().
 */
class AppColorValue implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (AppColor::tryFrom($value) === null) {
            $fail('El :attribute no es válido. Elige un color de la paleta o un color personalizado con el formato #rrggbb.');
        }
    }
}
