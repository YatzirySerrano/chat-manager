<?php

namespace App\Http\Requests\WebChat;

use App\Services\WebChat\VisitorCursor;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/widget/session/receipts: the visitor's widget reports up to
 * which message it received and read the agents' replies.
 */
class StoreVisitorReceiptRequest extends FormRequest
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
        $cursor = function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || app(VisitorCursor::class)->decode($value) === null) {
                $fail('El cursor no es válido.');
            }
        };

        return [
            'delivered_up_to' => ['nullable', 'string', 'max:64', $cursor, 'required_without:read_up_to'],
            'read_up_to' => ['nullable', 'string', 'max:64', $cursor],
        ];
    }

    public function deliveredUpTo(): ?int
    {
        return $this->filled('delivered_up_to') ? app(VisitorCursor::class)->decode((string) $this->input('delivered_up_to')) : null;
    }

    public function readUpTo(): ?int
    {
        return $this->filled('read_up_to') ? app(VisitorCursor::class)->decode((string) $this->input('read_up_to')) : null;
    }
}
