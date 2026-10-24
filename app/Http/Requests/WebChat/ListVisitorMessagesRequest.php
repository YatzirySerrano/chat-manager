<?php

namespace App\Http\Requests\WebChat;

use App\Services\WebChat\VisitorCursor;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/v1/widget/session/messages?after=|before=&limit=. Cursors must
 * be ones the server issued.
 */
class ListVisitorMessagesRequest extends FormRequest
{
    public const MAX_LIMIT = 50;

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
            'after' => ['nullable', 'string', 'max:64', $cursor, 'prohibits:before'],
            'before' => ['nullable', 'string', 'max:64', $cursor],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
        ];
    }

    public function afterCursor(): ?int
    {
        return $this->filled('after') ? app(VisitorCursor::class)->decode((string) $this->query('after')) : null;
    }

    public function beforeCursor(): ?int
    {
        return $this->filled('before') ? app(VisitorCursor::class)->decode((string) $this->query('before')) : null;
    }

    public function limit(): int
    {
        return $this->filled('limit') ? (int) $this->query('limit') : self::MAX_LIMIT;
    }
}
