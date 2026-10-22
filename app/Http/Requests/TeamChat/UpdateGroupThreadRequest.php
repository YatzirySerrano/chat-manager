<?php

namespace App\Http\Requests\TeamChat;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Rename a group or change its description.
 */
class UpdateGroupThreadRequest extends FormRequest
{
    /**
     * Non-participants get a 404, exactly as for a thread that does not exist.
     */
    public function authorize(): Response
    {
        return Gate::forUser($this->user())->inspect('manage', $this->route('thread'));
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
        return [
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => 'nombre', 'description' => 'descripción'];
    }

    /**
     * @return array{name: string, description: string|null}
     */
    public function payload(): array
    {
        $description = $this->validated('description');

        return [
            'name' => (string) $this->validated('name'),
            'description' => is_string($description) ? $description : null,
        ];
    }
}
