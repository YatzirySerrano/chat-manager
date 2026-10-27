<?php

namespace App\Exceptions;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * A file that cannot be sent, with a stable reason code next to the usual
 * validation errors: JSON clients receive 422 `{message, code, errors}`;
 * pages go back with the errors as any validation failure.
 *
 * The code also travels in the errors, under `upload_code`, so Inertia
 * visits and useHttp (which only hand over the errors of a 422) can tell a
 * temporary failure (ServerUploadLimits::RETRYABLE: send the same file
 * again) from a definitive refusal.
 *
 * Codes: server_upload_limit, upload_interrupted, server_upload_failure,
 * upload_invalid (ServerUploadLimits: PHP refused the upload), unsupported_type,
 * extension_mismatch, channel_size_limit and unsupported_audio_codec
 * (ChannelAttachmentRules: the channel cannot carry the file).
 */
final class AttachmentRejected extends ValidationException
{
    /** Error bag key that carries the code. */
    public const CODE_FIELD = 'upload_code';

    public string $reason = '';

    public static function because(string $reason, string $message, string $field = 'attachment'): static
    {
        $exception = self::withMessages([$field => $message]);
        $exception->reason = $reason;

        return $exception;
    }

    public static function fromValidator(Validator $validator, string $reason): static
    {
        $exception = new self($validator);
        $exception->reason = $reason;

        return $exception;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function errors(): array
    {
        $errors = parent::errors();

        if ($this->reason !== '') {
            $errors[self::CODE_FIELD] = [$this->reason];
        }

        return $errors;
    }

    public function render(Request $request): ?JsonResponse
    {
        if (! $request->expectsJson() || $request->header('X-Inertia')) {
            return null;
        }

        return response()->json([
            'message' => collect(parent::errors())->flatten()->first() ?? $this->getMessage(),
            'code' => $this->reason,
            'errors' => $this->errors(),
        ], $this->status);
    }
}
