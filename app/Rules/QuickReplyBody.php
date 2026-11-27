<?php

namespace App\Rules;

use App\Enums\QuickReplyVariable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A quick reply body is plain text: no HTML-like tags, and braces only around
 * the variables of the fixed catalog.
 */
class QuickReplyBody implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if (preg_match('/<[\p{L}\/!?]/u', $value) === 1) {
            $fail('El mensaje no puede contener etiquetas HTML (texto como <b> o </p>). WhatsApp envía solo texto; usa *negritas*, _cursivas_ o ~tachado~ para dar formato.');

            return;
        }

        $allowed = self::allowedList();

        preg_match_all('/\{([^{}]*)\}/u', $value, $matches);

        $unknown = array_values(array_unique(array_filter(
            $matches[0],
            fn (string $token): bool => QuickReplyVariable::tryFrom(substr($token, 1, -1)) === null,
        )));

        if ($unknown !== []) {
            $fail((count($unknown) === 1 ? 'La variable '.$unknown[0].' no existe.' : 'Las variables '.implode(', ', $unknown).' no existen.')." Solo puedes usar: {$allowed}.");

            return;
        }

        $withoutVariables = (string) preg_replace('/\{[^{}]*\}/u', '', $value);

        if (str_contains($withoutVariables, '{') || str_contains($withoutVariables, '}')) {
            $fail("Las llaves { } solo pueden usarse para escribir variables completas. Variables disponibles: {$allowed}.");
        }
    }

    /**
     * The general variables, and the request ones summarized: the form
     * lists all of them under «Insertar».
     */
    private static function allowedList(): string
    {
        $general = array_filter(QuickReplyVariable::cases(), fn (QuickReplyVariable $variable): bool => $variable->group() === 'general');

        return implode(', ', array_map(fn (QuickReplyVariable $variable): string => $variable->token(), $general))
            .' y los datos de la solicitud, como '.QuickReplyVariable::RequestClient->token().' o '.QuickReplyVariable::RequestProduct->token().' (todas en «Insertar»)';
    }
}
