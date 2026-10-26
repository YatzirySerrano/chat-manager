<?php

namespace App\Http\Requests\WebChat;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/widget/session/identity: the body is the signed identity.
 * The visitor is authenticated by the 'webchat.session' middleware.
 */
class VisitorIdentityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'external_id' => ['required', 'string', 'min:1', 'max:191'],
            'name' => ['nullable', 'string', 'max:80'],
            'email' => ['nullable', 'string', 'email', 'max:191'],
            'iat' => ['required', 'integer'],
            'nonce' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{16,64}$/'],
            'signature' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'external_id' => 'identificador del usuario en tu sitio',
            'name' => 'nombre',
            'email' => 'correo electrónico',
            'iat' => 'fecha de emisión',
            'nonce' => 'nonce',
            'signature' => 'firma',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function identity(): array
    {
        return VisitorIdentityRules::payload($this->validated());
    }
}
