<?php

namespace App\Http\Requests\Messaging;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * The six-digit PIN used to register a WhatsApp number with the Cloud API.
 * It is a secret of the number: a failed validation never flashes it back
 * to the session.
 */
class RegisterPhoneNumberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('verify', $this->route('connection'));
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'pin' => ['required', 'string', 'regex:/^\d{6}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pin.required' => 'Escribe el PIN de seis dígitos.',
            'pin.string' => 'El PIN debe tener exactamente seis dígitos.',
            'pin.regex' => 'El PIN debe tener exactamente seis dígitos.',
        ];
    }

    public function pin(): string
    {
        return (string) $this->validated('pin');
    }

    /**
     * Remove the PIN from the request. The exception handler flashes the
     * input of the request it is rendering (the one bound in the container,
     * not this form request) when it redirects back with errors: the PIN
     * must not travel to the session store.
     */
    public function forgetPin(): void
    {
        foreach ([$this, app('request')] as $request) {
            $request->replace($request->except('pin'));
        }
    }

    protected function failedValidation(Validator $validator): void
    {
        $this->forgetPin();

        parent::failedValidation($validator);
    }
}
