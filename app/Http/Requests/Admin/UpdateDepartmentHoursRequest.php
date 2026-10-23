<?php

namespace App\Http\Requests\Admin;

use App\Models\Department;
use App\Services\PlatformSettings;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * Validation for the business hours of a department. The per-day structure
 * and rules mirror the platform business hours; the days are only validated
 * (and stored) when the department uses its own schedule.
 */
class UpdateDepartmentHoursRequest extends FormRequest
{
    public function authorize(): Response
    {
        $department = $this->route('department');

        return $department instanceof Department ? Gate::inspect('update', $department) : Response::deny();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'uses_custom_hours' => ['required', 'boolean'],
            'business_hours' => ['exclude_unless:uses_custom_hours,true,1', 'required', 'array:days'],
            'business_hours.days' => ['exclude_unless:uses_custom_hours,true,1', 'required', 'array:'.implode(',', PlatformSettings::WEEKDAYS)],
        ];

        foreach (PlatformSettings::WEEKDAYS as $day) {
            $rules["business_hours.days.{$day}"] = ['exclude_unless:uses_custom_hours,true,1', 'required', 'array'];
            $rules["business_hours.days.{$day}.open"] = ['exclude_unless:uses_custom_hours,true,1', 'required', 'boolean'];
            $rules["business_hours.days.{$day}.start"] = ['exclude_unless:uses_custom_hours,true,1', 'required', 'date_format:H:i'];
            $rules["business_hours.days.{$day}.end"] = ['exclude_unless:uses_custom_hours,true,1', 'required', 'date_format:H:i'];
        }

        return $rules;
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->boolean('uses_custom_hours')) {
                    return;
                }

                foreach (PlatformSettings::WEEKDAYS as $day) {
                    if ($validator->errors()->has("business_hours.days.{$day}.*")) {
                        continue;
                    }

                    $start = (string) $this->input("business_hours.days.{$day}.start");
                    $end = (string) $this->input("business_hours.days.{$day}.end");

                    if ($this->boolean("business_hours.days.{$day}.open") && $end <= $start) {
                        $validator->errors()->add("business_hours.days.{$day}.end", 'La hora de cierre debe ser posterior a la hora de apertura.');
                    }
                }
            },
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [
            'uses_custom_hours' => 'horario propio',
            'business_hours' => 'horario',
            'business_hours.days' => 'días del horario',
        ];

        foreach (PlatformSettings::WEEKDAYS as $day) {
            $attributes["business_hours.days.{$day}.start"] = 'hora de apertura';
            $attributes["business_hours.days.{$day}.end"] = 'hora de cierre';
        }

        return $attributes;
    }

    /**
     * Normalized schedule. `business_hours` is null when the department follows
     * the platform hours, in which case the stored custom schedule is kept.
     *
     * @return array{uses_custom_hours: bool, business_hours: array{days: array<string, array{open: bool, start: string, end: string}>}|null}
     */
    public function payload(): array
    {
        if (! $this->boolean('uses_custom_hours')) {
            return ['uses_custom_hours' => false, 'business_hours' => null];
        }

        $days = [];

        foreach (PlatformSettings::WEEKDAYS as $day) {
            $days[$day] = [
                'open' => $this->boolean("business_hours.days.{$day}.open"),
                'start' => (string) $this->input("business_hours.days.{$day}.start"),
                'end' => (string) $this->input("business_hours.days.{$day}.end"),
            ];
        }

        return ['uses_custom_hours' => true, 'business_hours' => ['days' => $days]];
    }
}
