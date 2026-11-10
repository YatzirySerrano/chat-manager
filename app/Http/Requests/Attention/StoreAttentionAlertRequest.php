<?php

namespace App\Http\Requests\Attention;

use App\Actions\Attention\SendAttentionAlert;
use App\Enums\AttentionAlertAudience;
use App\Enums\AttentionAlertPriority;
use App\Enums\AttentionAlertSound;
use App\Enums\ColorSwatch;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An attention alert as the composer sends it. Title and message are plain
 * text (the screens escape them; no HTML or Markdown is interpreted), and
 * priority, color and sound must come from their closed catalogs.
 *
 * @phpstan-import-type AlertInput from SendAttentionAlert
 */
class StoreAttentionAlertRequest extends FormRequest
{
    public const TITLE_MAX_LENGTH = 120;

    public const BODY_MAX_LENGTH = 1000;

    /** Agents one selection may name at most (everyone uses the "everyone" audience). */
    public const MAX_SELECTED = 500;

    /** Agents a general alert may list for the server to compare. */
    public const MAX_EXPECTED = 2000;

    public function authorize(): bool
    {
        return $this->user()?->can('send-attention-alerts') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'client_key' => ['required', 'uuid'],
            'audience' => ['required', Rule::enum(AttentionAlertAudience::class)],
            'recipient_ids' => ['exclude_if:audience,everyone', 'required', 'array', 'min:1', 'max:'.self::MAX_SELECTED],
            'recipient_ids.*' => ['integer', 'min:1'],
            // «Todos»: the agents the composer listed, compared on the server.
            'expected_recipient_ids' => ['exclude_unless:audience,everyone', 'sometimes', 'array', 'max:'.self::MAX_EXPECTED],
            'expected_recipient_ids.*' => ['integer', 'min:1'],
            'title' => ['required', 'string', 'max:'.self::TITLE_MAX_LENGTH],
            'body' => ['required', 'string', 'max:'.self::BODY_MAX_LENGTH],
            'priority' => ['required', Rule::enum(AttentionAlertPriority::class)],
            'color' => ['required', Rule::enum(ColorSwatch::class)],
            'sound' => ['required', Rule::enum(AttentionAlertSound::class)],
            'link_pending' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'audience' => 'destinatarios',
            'recipient_ids' => 'agentes',
            'title' => 'título',
            'body' => 'mensaje',
            'priority' => 'prioridad',
            'color' => 'color',
            'sound' => 'sonido',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'recipient_ids.required' => 'Elige al menos un agente.',
            'recipient_ids.min' => 'Elige al menos un agente.',
        ];
    }

    /**
     * The validated alert, normalized for SendAttentionAlert.
     *
     * @return AlertInput
     */
    public function alert(): array
    {
        $recipients = $this->input('recipient_ids');
        $expected = $this->input('expected_recipient_ids');

        return [
            'client_key' => (string) $this->input('client_key'),
            'audience' => $this->enum('audience', AttentionAlertAudience::class) ?? AttentionAlertAudience::Individual,
            'recipient_ids' => is_array($recipients) ? array_values(array_map('intval', $recipients)) : [],
            'expected_recipient_ids' => $this->audienceIs(AttentionAlertAudience::Everyone) && is_array($expected) ? array_values(array_map('intval', $expected)) : null,
            'title' => trim((string) $this->input('title')),
            'body' => trim((string) $this->input('body')),
            'priority' => $this->enum('priority', AttentionAlertPriority::class) ?? AttentionAlertPriority::Info,
            'color' => $this->enum('color', ColorSwatch::class) ?? ColorSwatch::Blue,
            'sound' => $this->enum('sound', AttentionAlertSound::class) ?? AttentionAlertSound::Priority,
            'link_pending' => $this->boolean('link_pending'),
        ];
    }

    private function audienceIs(AttentionAlertAudience $audience): bool
    {
        return $this->enum('audience', AttentionAlertAudience::class) === $audience;
    }
}
