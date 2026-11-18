<?php

namespace App\Http\Requests\Conversations;

use App\Actions\Conversations\SetConversationReadiness;
use App\Concerns\AuthorizesVisibleConversation;
use App\Enums\ConversationReadiness;
use App\Services\Conversations\MissingInformation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Confirming a request as complete or returning it to «Necesita
 * información». Returning it says what is missing: items of the catalog
 * (`missing_items`) and/or a free text (`missing_other`), or, as before the
 * catalog, a plain `reason`. With `send_message` the list is also sent to
 * the customer (`client_reference` makes a retry harmless). The version the
 * user was looking at is optional. Authorization happens in the engine,
 * under lock, with fresh data.
 */
class ConversationReadinessRequest extends FormRequest
{
    use AuthorizesVisibleConversation;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $catalog = app(MissingInformation::class)->items();

        return [
            'readiness' => ['required', Rule::enum(ConversationReadiness::class)],
            'missing_items' => ['nullable', 'array', 'max:'.MissingInformation::MAX_ITEMS],
            'missing_items.*' => ['required', 'string', 'distinct', Rule::in($catalog)],
            'missing_other' => ['nullable', 'string', 'min:'.MissingInformation::OTHER_MIN_LENGTH, 'max:'.MissingInformation::OTHER_MAX_LENGTH],
            'reason' => [
                'nullable',
                Rule::requiredIf(fn (): bool => $this->input('readiness') === ConversationReadiness::NeedsInfo->value
                    && $this->missingList() === []),
                'string',
                'min:'.SetConversationReadiness::REASON_MIN,
                'max:'.ConversationOperationRequest::REASON_MAX_LENGTH,
            ],
            'send_message' => ['sometimes', 'boolean'],
            'client_reference' => ['nullable', 'required_if_accepted:send_message', 'uuid'],
            'version' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if (mb_strlen(app(MissingInformation::class)->reason($this->missingList())) > MissingInformation::REASON_MAX_LENGTH) {
                    $validator->errors()->add('missing_items', 'La lista de información faltante es demasiado larga.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'readiness' => 'estado de la solicitud',
            'reason' => 'motivo',
            'missing_items' => 'información faltante',
            'missing_items.*' => 'información faltante',
            'missing_other' => 'otra información',
            'send_message' => 'enviar mensaje al cliente',
            'client_reference' => 'referencia del mensaje',
            'version' => 'versión',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Indica qué información falta.',
            'missing_items.*.in' => 'Una de las opciones ya no está en el catálogo. Vuelve a abrir la ventana.',
            'missing_other.min' => 'Describe la otra información con al menos '.MissingInformation::OTHER_MIN_LENGTH.' caracteres.',
        ];
    }

    public function readiness(): ConversationReadiness
    {
        return ConversationReadiness::from($this->string('readiness')->value());
    }

    /**
     * What is missing, as picked: the catalog items in the catalog's order,
     * then the free text of «Otro». Empty when only a plain reason was sent.
     *
     * @return list<string>
     */
    public function missingList(): array
    {
        $picked = array_filter((array) $this->input('missing_items', []), 'is_string');
        $items = array_values(array_filter(app(MissingInformation::class)->items(), fn (string $item): bool => in_array($item, $picked, true)));
        $other = $this->string('missing_other')->squish()->value();

        return $other === '' ? $items : [...$items, $other];
    }

    public function reason(): ?string
    {
        $missing = $this->missingList();

        if ($missing !== []) {
            return app(MissingInformation::class)->reason($missing);
        }

        $reason = $this->string('reason')->squish()->value();

        return $reason === '' ? null : $reason;
    }

    /**
     * Whether the list of what is missing should also reach the customer.
     */
    public function sendsMessage(): bool
    {
        return $this->readiness() === ConversationReadiness::NeedsInfo
            && $this->boolean('send_message')
            && $this->missingList() !== [];
    }

    public function clientReference(): string
    {
        return $this->string('client_reference')->value();
    }

    public function version(): ?int
    {
        return $this->filled('version') ? $this->integer('version') : null;
    }
}
