<?php

namespace App\Http\Requests\QuickReplies;

use App\Enums\DataScope;
use App\Models\Department;
use App\Models\QuickReply;
use App\Models\User;
use App\Rules\QuickReplyBody;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validation for creating and updating quick replies. Shortcut uniqueness in
 * its scope is checked under a lock when saving (EnsureShortcutIsAvailable).
 */
class QuickReplyRequest extends FormRequest
{
    /**
     * WhatsApp's maximum length for a text message.
     */
    public const BODY_MAX_LENGTH = 4096;

    public const SHORTCUT_PATTERN = '/^[a-z0-9][a-z0-9_-]*$/';

    public function authorize(): bool
    {
        $quickReply = $this->route('quickReply');

        return $quickReply instanceof QuickReply
            ? ($this->user()?->can('update', $quickReply) ?? false)
            : ($this->user()?->can('create', QuickReply::class) ?? false);
    }

    /**
     * Shortcuts are stored lowercase and without the "/" typed in the composer.
     */
    protected function prepareForValidation(): void
    {
        $values = [];

        if (is_string($this->input('shortcut'))) {
            $values['shortcut'] = Str::lower(ltrim(trim($this->input('shortcut')), '/'));
        }

        if (is_string($this->input('title'))) {
            $values['title'] = Str::squish($this->input('title'));
        }

        if (is_string($this->input('body'))) {
            $values['body'] = trim(str_replace(["\r\n", "\r"], "\n", $this->input('body')));
        }

        $this->merge($values);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'shortcut' => ['required', 'string', 'max:32', 'regex:'.self::SHORTCUT_PATTERN],
            'body' => ['required', 'string', 'max:'.self::BODY_MAX_LENGTH, new QuickReplyBody],
            'is_global' => ['required', 'boolean'],
            'department_ids' => [Rule::excludeIf(fn (): bool => $this->boolean('is_global')), 'required', 'array', 'min:1', 'max:100'],
            'department_ids.*' => ['integer', 'distinct', Rule::exists(Department::class, 'id')],
        ];
    }

    /**
     * Scope checks that depend on the actor: only global users can make a
     * reply available to everyone, others only choose their own departments;
     * inactive departments cannot be newly added.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['is_global', 'department_ids', 'department_ids.*'])) {
                    return;
                }

                /** @var User $actor */
                $actor = $this->user();

                if ($this->boolean('is_global')) {
                    if ($actor->dataScope() !== DataScope::Global) {
                        $validator->errors()->add('is_global', 'Solo los usuarios con alcance global pueden crear respuestas disponibles para todos los departamentos. Elige tus departamentos.');
                    }

                    return;
                }

                $departmentIds = $this->departmentIds();

                if ($actor->dataScope() !== DataScope::Global && array_diff($departmentIds, $actor->departmentIds()) !== []) {
                    $validator->errors()->add('department_ids', 'Solo puedes elegir departamentos a los que perteneces.');

                    return;
                }

                $quickReply = $this->route('quickReply');
                $currentIds = $quickReply instanceof QuickReply
                    ? array_map('intval', $quickReply->departments()->pluck('departments.id')->all())
                    : [];

                $inactive = Department::query()
                    ->whereKey(array_diff($departmentIds, $currentIds))
                    ->where('is_active', false)
                    ->orderBy('name')
                    ->pluck('name');

                if ($inactive->isNotEmpty()) {
                    $validator->errors()->add('department_ids', 'No puedes agregar departamentos inactivos: '.$inactive->join(', ').'.');
                }
            },
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'shortcut.regex' => 'El atajo solo puede tener letras minúsculas sin acentos, números, guion (-) y guion bajo (_), y debe empezar con una letra o un número.',
            'body.max' => 'El mensaje no debe tener más de :max caracteres (límite de WhatsApp para un mensaje de texto).',
            'department_ids.required' => 'Elige al menos un departamento o márcala como disponible para todos.',
            'department_ids.min' => 'Elige al menos un departamento o márcala como disponible para todos.',
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
            'title' => 'título',
            'shortcut' => 'atajo',
            'body' => 'mensaje',
            'is_global' => 'disponibilidad',
            'department_ids' => 'departamentos',
            'department_ids.*' => 'departamento',
        ];
    }

    /**
     * @return array{title: string, shortcut: string, body: string, is_global: bool, department_ids: list<int>}
     */
    public function payload(): array
    {
        $isGlobal = $this->boolean('is_global');

        return [
            'title' => (string) $this->validated('title'),
            'shortcut' => (string) $this->validated('shortcut'),
            'body' => (string) $this->validated('body'),
            'is_global' => $isGlobal,
            'department_ids' => $isGlobal ? [] : $this->departmentIds(),
        ];
    }

    /**
     * @return list<int>
     */
    private function departmentIds(): array
    {
        return array_values(array_unique(array_map('intval', (array) $this->input('department_ids', []))));
    }
}
