<?php

namespace App\Http\Requests\QuickReplies;

use App\Enums\DataScope;
use App\Enums\RecordState;
use App\Models\Department;
use App\Models\QuickReply;
use App\Models\User;
use App\Rules\QuickReplyBody;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validation for creating and updating quick replies. Shortcut uniqueness in
 * its scope is checked under a lock when saving (EnsureShortcutIsAvailable).
 *
 * A new reply is personal when `type` is "personal" (it then needs
 * quick-replies.personal and takes no availability); an existing reply keeps
 * its type, so a personal reply never becomes corporate or vice versa.
 */
class QuickReplyRequest extends FormRequest
{
    /**
     * WhatsApp's maximum length for a text message.
     */
    public const BODY_MAX_LENGTH = 4096;

    public const SHORTCUT_PATTERN = '/^[a-z0-9][a-z0-9_-]*$/';

    public function authorize(): Response
    {
        $quickReply = $this->route('quickReply');

        if ($quickReply instanceof QuickReply) {
            return Gate::inspect('update', $quickReply);
        }

        return Gate::inspect($this->isPersonal() ? 'createPersonal' : 'create', QuickReply::class);
    }

    /**
     * Whether the reply being created or updated is personal.
     */
    public function isPersonal(): bool
    {
        $quickReply = $this->route('quickReply');

        return $quickReply instanceof QuickReply
            ? $quickReply->isPersonal()
            : $this->input('type') === 'personal';
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
            'type' => ['sometimes', 'nullable', Rule::in(['corporate', 'personal'])],
            'is_global' => [Rule::excludeIf(fn (): bool => $this->isPersonal()), 'required', 'boolean'],
            'department_ids' => [Rule::excludeIf(fn (): bool => $this->isPersonal() || $this->boolean('is_global')), 'required', 'array', 'min:1', 'max:100'],
            'department_ids.*' => ['integer', 'distinct', Rule::exists(Department::class, 'id')],
        ];
    }

    /**
     * Scope checks that depend on the actor: only global users can make a
     * reply available to everyone, others only choose their own departments;
     * deleted departments cannot be newly added.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->isPersonal() || $validator->errors()->hasAny(['is_global', 'department_ids', 'department_ids.*'])) {
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

                $deleted = Department::query()
                    ->whereKey(array_diff($departmentIds, $currentIds))
                    ->inRecordState(RecordState::Deleted)
                    ->orderBy('name')
                    ->pluck('name');

                if ($deleted->isNotEmpty()) {
                    $validator->errors()->add('department_ids', 'No puedes agregar departamentos eliminados: '.$deleted->join(', ').'.');
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
            'type' => 'tipo',
            'is_global' => 'disponibilidad',
            'department_ids' => 'departamentos',
            'department_ids.*' => 'departamento',
        ];
    }

    /**
     * Departments of the reply that are deleted are kept (they are not offered
     * in the form): they become effective again when restored.
     *
     * @return array{title: string, shortcut: string, body: string, is_global: bool, department_ids: list<int>, personal: bool}
     */
    public function payload(): array
    {
        if ($this->isPersonal()) {
            return [
                'title' => (string) $this->validated('title'),
                'shortcut' => (string) $this->validated('shortcut'),
                'body' => (string) $this->validated('body'),
                'is_global' => false,
                'department_ids' => [],
                'personal' => true,
            ];
        }

        $isGlobal = $this->boolean('is_global');
        $quickReply = $this->route('quickReply');
        $keptDeleted = $quickReply instanceof QuickReply && ! $isGlobal
            ? array_map('intval', $quickReply->departments()->inRecordState(RecordState::Deleted)->pluck('departments.id')->all())
            : [];

        return [
            'title' => (string) $this->validated('title'),
            'shortcut' => (string) $this->validated('shortcut'),
            'body' => (string) $this->validated('body'),
            'is_global' => $isGlobal,
            'department_ids' => $isGlobal ? [] : array_values(array_unique([...$this->departmentIds(), ...$keptDeleted])),
            'personal' => false,
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
