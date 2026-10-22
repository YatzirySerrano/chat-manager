<?php

namespace App\Http\Requests\TeamChat;

use App\Models\TeamChat\TeamMessage;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * Correct the text of one's own message. The text may only be emptied when
 * the message still has files.
 */
class UpdateTeamMessageRequest extends FormRequest
{
    /**
     * Non-participants get a 404, exactly as for a thread that does not exist.
     */
    public function authorize(): Response
    {
        return Gate::forUser($this->user())->inspect('updateMessage', [$this->route('thread'), $this->route('message')]);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('body'))) {
            $body = trim(str_replace(["\r\n", "\r"], "\n", $this->input('body')));
            $this->merge(['body' => $body === '' ? null : $body]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:'.(int) config('team-chat.message_max_length')],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var TeamMessage $message */
                $message = $this->route('message');

                if ($this->input('body') === null && ! $message->attachments()->whereNull('purged_at')->exists()) {
                    $validator->errors()->add('body', 'El mensaje no puede quedar vacío. Si ya no lo necesitas, elimínalo.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['body.max' => 'El mensaje no debe tener más de :max caracteres.'];
    }

    public function body(): ?string
    {
        $body = $this->validated('body');

        return is_string($body) ? $body : null;
    }
}
