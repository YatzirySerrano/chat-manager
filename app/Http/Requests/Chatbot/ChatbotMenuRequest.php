<?php

namespace App\Http\Requests\Chatbot;

use App\Enums\ChatbotOptionAction;
use App\Http\Requests\Admin\ConnectionRequest;
use App\Models\ChatbotMenu;
use App\Models\Connection;
use App\Models\Department;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The chatbot menu of one connection, as a tree: every option has an
 * action (ChatbotOptionAction) and a submenu holds its own options
 * (`children`), up to MAX_DEPTH levels. A menu that is on needs at least
 * one option.
 *
 * - transfer: needs a department; one newly added must be active and
 *   authorized for the connection (one already saved keeps it even if it
 *   was deactivated since: it is skipped while inactive, see ChatbotMenus);
 * - reply / reply_stay: need the answer and the text shown in the menu;
 * - submenu: needs the text shown in the menu and at least one option.
 */
class ChatbotMenuRequest extends FormRequest
{
    public const MAX_OPTIONS = 9;

    /** Levels of the menu, the main one included. */
    public const MAX_DEPTH = 5;

    /** Options of the whole menu, every level together. */
    public const MAX_TOTAL_OPTIONS = 150;

    public const LABEL_MAX_LENGTH = 80;

    public const RETRY_MAX_LENGTH = 500;

    public const NAVIGATION_MAX_LENGTH = 300;

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
            'navigation_hint' => ['nullable', 'string', 'max:'.self::NAVIGATION_MAX_LENGTH],
            'max_attempts' => ['required', 'integer', 'min:0', 'max:'.ChatbotMenu::MAX_ATTEMPTS_LIMIT],
            'timeout_minutes' => ['required', 'integer', 'min:0', 'max:'.ChatbotMenu::MAX_TIMEOUT_MINUTES],
            'options' => ['present', 'array', 'max:'.self::MAX_OPTIONS],
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
                // Options are optional: a menu without them only sends its
                // welcome and the conversation goes on to the queue.
                $options = $this->options();

                $saved = array_map('intval', ChatbotMenu::query()
                    ->where('connection_id', $connection->id)
                    ->first()?->allOptions()->whereNotNull('department_id')->pluck('department_id')->all() ?? []);
                $authorized = array_map('intval', $connection->departments()->pluck('departments.id')->all());
                $active = array_map('intval', Department::query()->active()->pluck('id')->all());
                $existing = array_map('intval', Department::query()->pluck('id')->all());
                $total = 0;

                $check = function (array $level, string $prefix, int $depth) use (&$check, &$total, $validator, $saved, $authorized, $active, $existing): void {
                    if (count($level) > self::MAX_OPTIONS) {
                        $validator->errors()->add(rtrim($prefix, '.') ?: 'options', 'Cada menú admite hasta '.self::MAX_OPTIONS.' opciones.');
                    }

                    foreach ($level as $index => $option) {
                        $total++;
                        $key = "{$prefix}{$index}";
                        $action = $option['action'];

                        if ($action === null) {
                            $validator->errors()->add("{$key}.action", 'Elige qué hace esta opción.');

                            continue;
                        }

                        if ($option['label'] !== null && mb_strlen($option['label']) > self::LABEL_MAX_LENGTH) {
                            $validator->errors()->add("{$key}.label", 'El texto de la opción admite hasta '.self::LABEL_MAX_LENGTH.' caracteres.');
                        }

                        if ($option['reply_message'] !== null && mb_strlen($option['reply_message']) > ConnectionRequest::MESSAGE_MAX_LENGTH) {
                            $validator->errors()->add("{$key}.reply_message", 'El mensaje admite hasta '.ConnectionRequest::MESSAGE_MAX_LENGTH.' caracteres.');
                        }

                        if ($action !== ChatbotOptionAction::Transfer && $option['label'] === null) {
                            $validator->errors()->add("{$key}.label", 'Escribe el texto que ve el cliente en esta opción.');
                        }

                        if ($action->needsReply() && $option['reply_message'] === null) {
                            $validator->errors()->add("{$key}.reply_message", 'Escribe lo que contesta el bot.');
                        }

                        if ($action === ChatbotOptionAction::Transfer) {
                            $department = $option['department_id'];

                            if ($department === null || ! in_array($department, $existing, true)) {
                                $validator->errors()->add("{$key}.department_id", 'Elige el departamento de la opción.');
                            } elseif (! in_array($department, $saved, true)) {
                                if (! in_array($department, $active, true)) {
                                    $validator->errors()->add("{$key}.department_id", 'Elige un departamento activo.');
                                } elseif ($authorized !== [] && ! in_array($department, $authorized, true)) {
                                    $validator->errors()->add("{$key}.department_id", 'Este departamento no está autorizado para recibir conversaciones de esta conexión.');
                                }
                            }
                        }

                        if ($action === ChatbotOptionAction::Submenu) {
                            if ($option['children'] === []) {
                                $validator->errors()->add("{$key}.children", 'Agrega al menos una opción a este submenú.');
                            } elseif ($depth >= self::MAX_DEPTH) {
                                $validator->errors()->add("{$key}.children", 'El menú admite hasta '.self::MAX_DEPTH.' niveles.');
                            } else {
                                $check($option['children'], "{$key}.children.", $depth + 1);
                            }
                        }
                    }
                };

                $check($options, 'options.', 1);

                if ($total > self::MAX_TOTAL_OPTIONS) {
                    $validator->errors()->add('options', 'El menú admite hasta '.self::MAX_TOTAL_OPTIONS.' opciones en total.');
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
            'navigation_hint' => 'instrucciones para regresar',
            'max_attempts' => 'reintentos',
            'timeout_minutes' => 'tiempo de espera',
            'options' => 'opciones',
        ];
    }

    /**
     * The options as a clean tree (depth bounded, whatever is sent).
     *
     * @return list<array{action: ChatbotOptionAction|null, department_id: int|null, label: string|null, reply_message: string|null, children: list<mixed>}>
     */
    public function options(): array
    {
        return self::tree($this->input('options', []), 1);
    }

    /**
     * @return array{is_enabled: bool, greeting: string|null, retry_message: string|null, navigation_hint: string|null, max_attempts: int, timeout_minutes: int, options: list<array<string, mixed>>}
     */
    public function payload(): array
    {
        return [
            'is_enabled' => $this->boolean('is_enabled'),
            'greeting' => self::text($this->input('greeting')),
            'retry_message' => self::text($this->input('retry_message')),
            'navigation_hint' => self::text($this->input('navigation_hint')),
            'max_attempts' => $this->integer('max_attempts'),
            'timeout_minutes' => $this->integer('timeout_minutes'),
            'options' => $this->options(),
        ];
    }

    /**
     * @return list<array{action: ChatbotOptionAction|null, department_id: int|null, label: string|null, reply_message: string|null, children: list<mixed>}>
     */
    private static function tree(mixed $level, int $depth): array
    {
        if (! is_array($level) || $depth > self::MAX_DEPTH + 1) {
            return [];
        }

        $options = [];

        foreach ($level as $option) {
            if (! is_array($option)) {
                continue;
            }

            $action = is_string($option['action'] ?? null)
                ? ChatbotOptionAction::tryFrom($option['action'])
                // Menus saved before actions existed only transferred.
                : (isset($option['department_id']) ? ChatbotOptionAction::Transfer : null);
            $department = $option['department_id'] ?? null;

            $options[] = [
                'action' => $action,
                'department_id' => $action === ChatbotOptionAction::Transfer && is_numeric($department) ? (int) $department : null,
                'label' => self::text($option['label'] ?? null),
                'reply_message' => self::text($option['reply_message'] ?? null),
                'children' => $action === ChatbotOptionAction::Submenu ? self::tree($option['children'] ?? [], $depth + 1) : [],
            ];
        }

        return $options;
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
