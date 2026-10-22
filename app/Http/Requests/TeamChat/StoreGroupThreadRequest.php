<?php

namespace App\Http\Requests\TeamChat;

use App\Models\TeamChat\TeamThread;
use App\Models\User;
use App\Services\TeamChat\TeamChatDirectory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

/**
 * Create a group: a name, an optional description and the first
 * participants, every one of them within the creator's reach.
 */
class StoreGroupThreadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('createGroup', TeamThread::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->input('name')) ? Str::squish($this->input('name')) : $this->input('name'),
            'description' => is_string($this->input('description')) ? (trim($this->input('description')) === '' ? null : trim($this->input('description'))) : $this->input('description'),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $max = max(1, (int) config('team-chat.group_max_participants') - 1);

        return [
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
            'participant_ids' => ['required', 'array', 'min:1', 'max:'.$max],
            'participant_ids.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['participant_ids', 'participant_ids.*'])) {
                    return;
                }

                /** @var User $actor */
                $actor = $this->user();
                $requested = array_values(array_diff($this->participantIds(), [$actor->id]));

                if ($requested === []) {
                    $validator->errors()->add('participant_ids', 'Elige al menos a otra persona para el grupo.');

                    return;
                }

                if (array_diff($requested, app(TeamChatDirectory::class)->contactableAmong($actor, $requested)) !== []) {
                    $validator->errors()->add('participant_ids', 'Algunas personas elegidas no están disponibles para el chat interno o están fuera de tu alcance.');
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
            'participant_ids.required' => 'Elige al menos a otra persona para el grupo.',
            'participant_ids.min' => 'Elige al menos a otra persona para el grupo.',
            'participant_ids.max' => 'Un grupo puede tener como máximo '.config('team-chat.group_max_participants').' participantes.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'description' => 'descripción',
            'participant_ids' => 'participantes',
        ];
    }

    /**
     * @return array{name: string, description: string|null, participant_ids: list<int>}
     */
    public function payload(): array
    {
        $description = $this->validated('description');

        return [
            'name' => (string) $this->validated('name'),
            'description' => is_string($description) ? $description : null,
            'participant_ids' => $this->participantIds(),
        ];
    }

    /**
     * @return list<int>
     */
    private function participantIds(): array
    {
        return array_values(array_unique(array_map('intval', (array) $this->input('participant_ids', []))));
    }
}
