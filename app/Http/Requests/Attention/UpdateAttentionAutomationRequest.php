<?php

namespace App\Http\Requests\Attention;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * «Alertas automáticas» (Seguimiento de atención): the escalation steps of
 * the time alerts (Pendientes and En atención) and the «Solicitud completada»
 * broadcast. Normalized into the `time_alerts` settings group.
 */
class UpdateAttentionAutomationRequest extends FormRequest
{
    public const MESSAGE_MAX = 500;

    public const MAX_STEPS = 6;

    /** Allowed urgencies, matching NotificationLevel. */
    private const PRIORITIES = ['urgent', 'informative'];

    public function authorize(): bool
    {
        return $this->user()?->can('update-settings') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $stepRules = fn (string $key): array => [
            $key => ['required', 'array', 'min:1', 'max:'.self::MAX_STEPS],
            "{$key}.*.minutes" => ['required', 'integer', 'min:1', 'max:1440'],
            "{$key}.*.priority" => ['required', Rule::in(self::PRIORITIES)],
            "{$key}.*.message" => ['nullable', 'string', 'max:'.self::MESSAGE_MAX],
        ];

        return [
            'queue_enabled' => ['required', 'boolean'],
            ...$stepRules('queue_steps'),
            'attention_enabled' => ['required', 'boolean'],
            ...$stepRules('attention_steps'),
            'completed_enabled' => ['required', 'boolean'],
            'completed_priority' => ['required', Rule::in(self::PRIORITIES)],
            'completed_message' => ['nullable', 'string', 'max:'.self::MESSAGE_MAX],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'queue_steps' => 'escalones de Pendientes',
            'queue_steps.*.minutes' => 'minutos del escalón',
            'attention_steps' => 'escalones de atención',
            'attention_steps.*.minutes' => 'minutos del escalón',
            'completed_message' => 'mensaje de solicitud completada',
        ];
    }

    /**
     * The normalized `time_alerts` group. Steps are sorted by minutes and kept
     * unique; the first step's minutes stay in `{kind}_minutes` so the simple
     * single-threshold configuration keeps working.
     *
     * @return array<string, array<string, mixed>>
     */
    public function payload(): array
    {
        $queue = $this->steps('queue_steps');
        $attention = $this->steps('attention_steps');
        $message = trim((string) $this->input('completed_message', ''));

        return [
            'time_alerts' => [
                'queue_enabled' => $this->boolean('queue_enabled'),
                'queue_minutes' => $queue[0]['minutes'] ?? 5,
                'queue_steps' => $queue,
                'attention_enabled' => $this->boolean('attention_enabled'),
                'attention_minutes' => $attention[0]['minutes'] ?? 15,
                'attention_steps' => $attention,
                'completed_enabled' => $this->boolean('completed_enabled'),
                'completed_priority' => in_array($this->input('completed_priority'), self::PRIORITIES, true) ? (string) $this->input('completed_priority') : 'informative',
                'completed_message' => $message === '' ? null : $message,
            ],
        ];
    }

    /**
     * @return list<array{minutes: int, priority: string, message: string|null}>
     */
    private function steps(string $key): array
    {
        $steps = [];

        foreach ((array) $this->input($key, []) as $step) {
            if (! is_array($step)) {
                continue;
            }

            $minutes = max(1, (int) ($step['minutes'] ?? 0));
            $priority = in_array($step['priority'] ?? null, self::PRIORITIES, true) ? (string) $step['priority'] : 'urgent';
            $message = trim((string) ($step['message'] ?? ''));
            // One step per minute: a repeated minute keeps the first.
            $steps[$minutes] ??= ['minutes' => $minutes, 'priority' => $priority, 'message' => $message === '' ? null : $message];
        }

        ksort($steps);

        return array_values($steps);
    }
}
