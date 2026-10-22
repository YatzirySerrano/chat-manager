<?php

namespace App\Http\Requests\Automation;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Non-working days of the platform: a date, a label and whether it repeats
 * every year. Dates must be unique.
 */
class UpdateHolidaysRequest extends FormRequest
{
    public const MAX_HOLIDAYS = 100;

    public function authorize(): bool
    {
        return $this->user()?->can('update-settings') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'holidays' => ['present', 'array', 'max:'.self::MAX_HOLIDAYS],
            'holidays.*' => ['required', 'array:date,label,recurring'],
            'holidays.*.date' => ['required', 'date_format:Y-m-d', 'distinct'],
            'holidays.*.label' => ['required', 'string', 'max:80'],
            'holidays.*.recurring' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'holidays.*.date.distinct' => 'Esta fecha ya está en la lista.',
            'holidays.*.date.required' => 'Indica la fecha.',
            'holidays.*.date.date_format' => 'Usa una fecha válida.',
            'holidays.*.label.required' => 'Escribe un nombre para el día.',
            'holidays.*.label.max' => 'El nombre no puede tener más de 80 caracteres.',
        ];
    }

    /**
     * Sorted by date.
     *
     * @return list<array{date: string, label: string, recurring: bool}>
     */
    public function payload(): array
    {
        $holidays = [];

        foreach (array_keys((array) $this->input('holidays', [])) as $index) {
            $holidays[] = [
                'date' => (string) $this->input("holidays.{$index}.date"),
                'label' => $this->string("holidays.{$index}.label")->trim()->value(),
                'recurring' => $this->boolean("holidays.{$index}.recurring"),
            ];
        }

        usort($holidays, fn (array $a, array $b): int => strcmp($a['date'], $b['date']));

        return $holidays;
    }
}
