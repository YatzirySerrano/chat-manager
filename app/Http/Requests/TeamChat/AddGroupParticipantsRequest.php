<?php

namespace App\Http\Requests\TeamChat;

use App\Models\User;
use App\Services\TeamChat\TeamChatDirectory;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * Add people to a group: only active accounts that can use the internal
 * chat and are within the actor's reach (deleted accounts never).
 */
class AddGroupParticipantsRequest extends FormRequest
{
    /**
     * Non-participants get a 404, exactly as for a thread that does not exist.
     */
    public function authorize(): Response
    {
        return Gate::forUser($this->user())->inspect('manage', $this->route('thread'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_ids' => ['required', 'array', 'min:1', 'max:'.max(1, (int) config('team-chat.group_max_participants'))],
            'user_ids.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['user_ids', 'user_ids.*'])) {
                    return;
                }

                /** @var User $actor */
                $actor = $this->user();
                $requested = $this->userIds();

                if (array_diff($requested, app(TeamChatDirectory::class)->contactableAmong($actor, $requested)) !== []) {
                    $validator->errors()->add('user_ids', 'Algunas personas elegidas no están disponibles para el chat interno o están fuera de tu alcance.');
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
            'user_ids.required' => 'Elige al menos a una persona.',
            'user_ids.min' => 'Elige al menos a una persona.',
        ];
    }

    /**
     * @return list<int>
     */
    public function userIds(): array
    {
        return array_values(array_unique(array_map('intval', (array) $this->input('user_ids', []))));
    }
}
