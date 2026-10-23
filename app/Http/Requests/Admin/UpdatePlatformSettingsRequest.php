<?php

namespace App\Http\Requests\Admin;

use App\Models\Department;
use App\Services\PlatformSettings;
use DateTimeZone;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdatePlatformSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update-settings') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'general.platform_name' => ['required', 'string', 'max:60'],
            'general.timezone' => ['required', 'string', Rule::in(DateTimeZone::listIdentifiers())],
            'business_hours.enabled' => ['required', 'boolean'],
            'business_hours.days' => ['required', 'array:'.implode(',', PlatformSettings::WEEKDAYS)],
            'operations.default_department_id' => ['nullable', 'integer', Rule::exists(Department::class, 'id')->where('is_active', true)],
            'operations.max_open_conversations_per_agent' => ['nullable', 'integer', 'min:1', 'max:200'],
            'operations.auto_close_after_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
            'operations.transfer_reason_required' => ['sometimes', 'boolean'],
            'sla.first_response_minutes' => ['nullable', 'integer', 'min:1', 'max:43200'],
            'sla.resolution_minutes' => ['nullable', 'integer', 'min:1', 'max:43200'],
        ];

        foreach (PlatformSettings::WEEKDAYS as $day) {
            $rules["business_hours.days.{$day}"] = ['required', 'array'];
            $rules["business_hours.days.{$day}.open"] = ['required', 'boolean'];
            $rules["business_hours.days.{$day}.start"] = ['required', 'date_format:H:i'];
            $rules["business_hours.days.{$day}.end"] = ['required', 'date_format:H:i'];
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
        return [
            'general.platform_name' => 'nombre de la plataforma',
            'general.timezone' => 'zona horaria',
            'operations.default_department_id' => 'departamento predeterminado',
            'operations.max_open_conversations_per_agent' => 'máximo de conversaciones por agente',
            'operations.auto_close_after_hours' => 'cierre automático por inactividad',
            'operations.transfer_reason_required' => 'motivo obligatorio al transferir',
            'sla.first_response_minutes' => 'objetivo de primera respuesta',
            'sla.resolution_minutes' => 'objetivo de resolución',
        ];
    }

    /**
     * Normalized settings groups.
     *
     * @return array<string, array<string, mixed>>
     */
    public function payload(): array
    {
        $days = [];

        foreach (PlatformSettings::WEEKDAYS as $day) {
            $days[$day] = [
                'open' => $this->boolean("business_hours.days.{$day}.open"),
                'start' => (string) $this->input("business_hours.days.{$day}.start"),
                'end' => (string) $this->input("business_hours.days.{$day}.end"),
            ];
        }

        return [
            'general' => [
                'platform_name' => $this->string('general.platform_name')->trim()->value(),
                'timezone' => (string) $this->input('general.timezone'),
            ],
            'business_hours' => [
                'enabled' => $this->boolean('business_hours.enabled'),
                'days' => $days,
            ],
            'operations' => [
                'default_department_id' => $this->filled('operations.default_department_id') ? $this->integer('operations.default_department_id') : null,
                'max_open_conversations_per_agent' => $this->filled('operations.max_open_conversations_per_agent') ? $this->integer('operations.max_open_conversations_per_agent') : null,
                'auto_close_after_hours' => $this->filled('operations.auto_close_after_hours') ? $this->integer('operations.auto_close_after_hours') : null,
                'transfer_reason_required' => $this->boolean('operations.transfer_reason_required'),
            ],
            'sla' => [
                'first_response_minutes' => $this->filled('sla.first_response_minutes') ? $this->integer('sla.first_response_minutes') : null,
                'resolution_minutes' => $this->filled('sla.resolution_minutes') ? $this->integer('sla.resolution_minutes') : null,
            ],
        ];
    }
}
