<?php

namespace App\Http\Requests\Chatbot;

use App\Http\Requests\Admin\ConnectionRequest;
use App\Models\ChatbotMenu;
use App\Models\Connection;
use App\Models\Department;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The welcome menu of one connection. A menu that is on needs at least one
 * option. A department newly added as an option must be active and
 * authorized for the connection; options already saved keep their
 * department even if it was deactivated since (they are skipped while
 * inactive, see ChatbotMenus), so the administrator decides when to remove
 * them.
 */
class ChatbotMenuRequest extends FormRequest
{
    public const MAX_OPTIONS = 9;

    public const LABEL_MAX_LENGTH = 80;

    public const RETRY_MAX_LENGTH = 500;

    public function authorize(): bool
    {
        $connection = $this->route('connection');

        return $connection instanceof Connection && ($this->user()?->can('configureChatbot', $connection) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'is_enabled' => ['boolean'],
            'greeting' => ['nullable', 'string', 'max:'.ConnectionRequest::MESSAGE_MAX_LENGTH, 'required_if_accepted:is_enabled'],
            'retry_message' => ['nullable', 'string', 'max:'.self::RETRY_MAX_LENGTH],
            'max_attempts' => ['required', 'integer', 'min:0', 'max:'.ChatbotMenu::MAX_ATTEMPTS_LIMIT],
            'options' => ['present', 'array', 'max:'.self::MAX_OPTIONS],
            'options.*.department_id' => ['required', 'integer', 'distinct', 'exists:departments,id'],
            'options.*.label' => ['nullable', 'string', 'max:'.self::LABEL_MAX_LENGTH],
            'options.*.reply_message' => ['nullable', 'string', 'max:'.ConnectionRequest::MESSAGE_MAX_LENGTH],
        ];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var Connection $connection */
                $connection = $this->route('connection');
                $options = $this->options();

                if ($this->boolean('is_enabled') && $options === []) {
                    $validator->errors()->add('options', 'Agrega al menos una opción para activar el menú.');

                    return;
                }

                $saved = array_map('intval', ChatbotMenu::query()
                    ->where('connection_id', $connection->id)
                    ->first()?->options()->pluck('department_id')->all() ?? []);
                $authorized = array_map('intval', $connection->departments()->pluck('departments.id')->all());
                $active = Department::query()->active()->whereKey(array_column($options, 'department_id'))->pluck('id')->map(fn ($id): int => (int) $id)->all();

                foreach ($options as $index => $option) {
                    if (in_array($option['department_id'], $saved, true)) {
                        continue;
                    }

                    if (! in_array($option['department_id'], $active, true)) {
                        $validator->errors()->add("options.{$index}.department_id", 'Elige un departamento activo.');
                    } elseif ($authorized !== [] && ! in_array($option['department_id'], $authorized, true)) {
                        $validator->errors()->add("options.{$index}.department_id", 'Este departamento no está autorizado para recibir conversaciones de esta conexión.');
                    }
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'greeting.required_if_accepted' => 'Escribe el saludo para activar el menú.',
            'options.max' => 'El menú admite hasta '.self::MAX_OPTIONS.' opciones.',
            'options.*.department_id.distinct' => 'Cada departamento puede aparecer una sola vez en el menú.',
            'options.*.department_id.required' => 'Elige el departamento de la opción.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'greeting' => 'saludo',
            'retry_message' => 'mensaje de reintento',
            'max_attempts' => 'reintentos',
            'options' => 'opciones',
            'options.*.department_id' => 'departamento',
            'options.*.label' => 'texto de la opción',
            'options.*.reply_message' => 'respuesta de la opción',
        ];
    }

    /**
     * @return list<array{department_id: int, label: string|null, reply_message: string|null}>
     */
    public function options(): array
    {
        $options = [];

        foreach ((array) $this->input('options', []) as $option) {
            if (! is_array($option)) {
                continue;
            }

            $options[] = [
                'department_id' => (int) ($option['department_id'] ?? 0),
                'label' => self::text($option['label'] ?? null),
                'reply_message' => self::text($option['reply_message'] ?? null),
            ];
        }

        return $options;
    }

    /**
     * @return array{is_enabled: bool, greeting: string|null, retry_message: string|null, max_attempts: int, options: list<array{department_id: int, label: string|null, reply_message: string|null}>}
     */
    public function payload(): array
    {
        return [
            'is_enabled' => $this->boolean('is_enabled'),
            'greeting' => self::text($this->input('greeting')),
            'retry_message' => self::text($this->input('retry_message')),
            'max_attempts' => $this->integer('max_attempts'),
            'options' => $this->options(),
        ];
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim(str_replace("\r\n", "\n", $value));

        return $value === '' ? null : $value;
    }
}
