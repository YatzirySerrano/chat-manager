<?php

namespace App\Http\Requests\Admin;

use App\Models\Department;
use App\Services\PlatformSettings;
use App\Services\ServiceRules;
use App\Support\Features;
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
            'operations.pending_block_enabled' => ['sometimes', 'boolean'],
            'operations.pending_block_after_minutes' => ['sometimes', 'required', 'integer', 'min:0', 'max:1440'],
            // Service rules and automatic SLA alerts: bounds come from their definition.
            // Phase one (FEATURE_PHASE_TWO off): only the two report objectives.
            ...$this->onlyInForce(app(ServiceRules::class)->validationRules(ServiceRules::LEVEL_PLATFORM)),
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
            'operations.pending_block_enabled' => 'control de conversaciones pendientes',
            'operations.pending_block_after_minutes' => 'tolerancia antes de bloquear',
            ...app(ServiceRules::class)->validationAttributes(ServiceRules::LEVEL_PLATFORM),
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

        // Phase one (FEATURE_PHASE_TWO off): the phase-two rules and alert
        // settings are ignored, so their stored values stay as they are.
        $phaseTwo = Features::phaseTwo();

        return [
            ...($phaseTwo ? $this->serviceRuleGroups() : []),
            ...$this->phaseOneGroups($days),
            'sla' => [
                'first_response_minutes' => $this->filled('sla.first_response_minutes') ? $this->integer('sla.first_response_minutes') : null,
                'resolution_minutes' => $this->filled('sla.resolution_minutes') ? $this->integer('sla.resolution_minutes') : null,
                ...($phaseTwo ? $this->sentRules('sla', ['sla.first_response_minutes', 'sla.resolution_minutes']) : []),
            ],
        ];
    }

    /**
     * The groups that exist in both phases (general, business hours and
     * operations), normalized.
     *
     * @param  array<string, array{open: bool, start: string, end: string}>  $days
     * @return array<string, array<string, mixed>>
     */
    private function phaseOneGroups(array $days): array
    {
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
                // Only when sent: an older form that does not know them keeps them.
                ...($this->has('operations.pending_block_enabled') ? ['pending_block_enabled' => $this->boolean('operations.pending_block_enabled')] : []),
                ...($this->filled('operations.pending_block_after_minutes') ? ['pending_block_after_minutes' => $this->integer('operations.pending_block_after_minutes')] : []),
            ],
        ];
    }

    /**
     * The validation rules of the service rules in force: all of them in
     * phase two, only the two report objectives in phase one.
     *
     * @param  array<string, list<string>>  $rules
     * @return array<string, list<string>>
     */
    private function onlyInForce(array $rules): array
    {
        return Features::phaseTwo() ? $rules : array_intersect_key($rules, array_flip(ServiceRules::PHASE_ONE_KEYS));
    }

    /**
     * The groups that only hold service rules or alert settings (`intake`,
     * `queue`, `sla_alerts`), with the keys the form sent.
     *
     * @return array<string, array<string, mixed>>
     */
    private function serviceRuleGroups(): array
    {
        $groups = [];

        foreach (['intake', 'queue', 'sla_alerts'] as $group) {
            $values = $this->sentRules($group);

            if ($values !== []) {
                $groups[$group] = $values;
            }
        }

        return $groups;
    }

    /**
     * Values of the service rules of a settings group that the form sent.
     * Only when sent: an older form that does not know a rule keeps it. An
     * empty nullable rule means "no objective"; an empty required one is
     * left as it is.
     *
     * @param  list<string>  $except  Keys the caller already normalizes.
     * @return array<string, int|bool|null>
     */
    private function sentRules(string $group, array $except = []): array
    {
        $values = [];

        foreach ([...ServiceRules::KEYS, ...ServiceRules::ALERT_KEYS] as $key => $definition) {
            if (! str_starts_with($key, "{$group}.") || in_array($key, $except, true) || ! $this->has($key)) {
                continue;
            }

            $name = substr($key, strlen($group) + 1);

            if ($definition['type'] === 'boolean') {
                $values[$name] = $this->boolean($key);
            } elseif ($this->filled($key)) {
                $values[$name] = $this->integer($key);
            } elseif ($definition['nullable']) {
                $values[$name] = null;
            }
        }

        return $values;
    }
}
