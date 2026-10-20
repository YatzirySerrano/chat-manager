<?php

namespace App\Http\Requests\Contacts;

use App\Models\Contact;
use App\Support\PhoneNumber;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

/**
 * Validation for creating and updating contacts. The phone is normalized to
 * E.164 with App\Support\PhoneNumber; custom fields are a controlled list of
 * up to ten plain-text label/value pairs.
 */
class ContactRequest extends FormRequest
{
    public const MAX_CUSTOM_FIELDS = 10;

    /**
     * A contact outside the actor's scope answers 404 (see ContactPolicy).
     */
    public function authorize(): Response
    {
        $contact = $this->route('contact');
        $gate = Gate::forUser($this->user());

        return $contact instanceof Contact
            ? $gate->inspect('update', $contact)
            : $gate->inspect('create', Contact::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'phone' => [
                'nullable',
                'string',
                'max:32',
                'required_without:email',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && trim($value) !== '' && ! PhoneNumber::isValid($value)) {
                        $fail('El teléfono no es válido. Escribe 10 dígitos para México o el número completo con lada internacional (por ejemplo +1 415 555 2671).');
                    }
                },
            ],
            'email' => ['nullable', 'string', 'email', 'max:255', 'required_without:phone'],
            'custom_fields' => ['nullable', 'array', 'max:'.self::MAX_CUSTOM_FIELDS],
            'custom_fields.*' => ['array:label,value'],
            'custom_fields.*.label' => ['required', 'string', 'max:60'],
            'custom_fields.*.value' => ['required', 'string', 'max:500'],
            'confirm_duplicate' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Labels must be unique ignoring case and surrounding spaces.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if (self::clean($this->string('name')->value()) === '') {
                    $validator->errors()->add('name', 'El nombre no puede quedar vacío.');
                }

                $seen = [];

                foreach ((array) $this->input('custom_fields', []) as $index => $field) {
                    $label = self::clean($field['label']);
                    $key = mb_strtolower($label);

                    if ($label === '') {
                        $validator->errors()->add("custom_fields.{$index}.label", 'El nombre del campo no puede quedar vacío.');
                    } elseif (isset($seen[$key])) {
                        $validator->errors()->add("custom_fields.{$index}.label", 'Cada campo personalizado debe tener un nombre distinto.');
                    }

                    if (self::clean($field['value']) === '') {
                        $validator->errors()->add("custom_fields.{$index}.value", 'El valor del campo no puede quedar vacío.');
                    }

                    $seen[$key] = true;
                }
            },
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
            'name' => 'nombre',
            'phone' => 'teléfono',
            'email' => 'correo electrónico',
            'custom_fields' => 'campos personalizados',
            'custom_fields.*.label' => 'nombre del campo',
            'custom_fields.*.value' => 'valor del campo',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.required_without' => 'Captura al menos un teléfono o un correo electrónico.',
            'email.required_without' => 'Captura al menos un teléfono o un correo electrónico.',
            'custom_fields.max' => 'Puedes registrar hasta '.self::MAX_CUSTOM_FIELDS.' campos personalizados.',
        ];
    }

    /**
     * @return array{name: string, phone_e164: string|null, email: string|null, custom_fields: array<string, string>|null}
     */
    public function payload(): array
    {
        $customFields = [];

        foreach ((array) $this->input('custom_fields', []) as $field) {
            $customFields[self::clean($field['label'])] = self::clean($field['value']);
        }

        return [
            'name' => self::clean($this->string('name')->value()),
            'phone_e164' => PhoneNumber::normalize($this->input('phone')),
            'email' => $this->filled('email') ? mb_strtolower($this->string('email')->trim()->value()) : null,
            'custom_fields' => $customFields === [] ? null : $customFields,
        ];
    }

    public function confirmsDuplicate(): bool
    {
        return $this->boolean('confirm_duplicate');
    }

    /**
     * Plain single-line text: no control characters or markup, collapsed spaces.
     */
    private static function clean(string $value): string
    {
        $value = preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', strip_tags($value)) ?? '';

        return Str::squish($value);
    }
}
