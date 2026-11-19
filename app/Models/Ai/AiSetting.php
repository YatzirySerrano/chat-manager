<?php

namespace App\Models\Ai;

use App\Enums\AiVendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The one configuration of «Inteligencia artificial» (a single row, see
 * current()). The API key is encrypted at rest and hidden from every
 * serialization: the browser only ever sees its last four characters.
 *
 * @property int $id
 * @property AiVendor $provider
 * @property string|null $api_key
 * @property string|null $model
 * @property string|null $business_info
 * @property list<array{question: string, answer: string}>|null $catalog
 * @property string|null $instructions
 * @property string|null $assistant_name
 * @property string|null $handoff_message
 * @property int $max_replies Replies per conversation (and cycle) before handing off.
 * @property string|null $last_error_type Last failure of the provider (AiProviderException::$type), cleared by a reply.
 * @property Carbon|null $last_error_at
 * @property Carbon|null $last_reply_at
 * @property int|null $updated_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AiSetting extends Model
{
    public const DEFAULT_ASSISTANT_NAME = 'Asistente virtual';

    public const DEFAULT_HANDOFF_MESSAGE = 'Te comunico con un asesor, en un momento te atienden.';

    public const DEFAULT_MAX_REPLIES = 10;

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = ['api_key'];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'provider' => 'anthropic',
        'max_replies' => self::DEFAULT_MAX_REPLIES,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => AiVendor::class,
            'api_key' => 'encrypted',
            'catalog' => 'array',
            'max_replies' => 'integer',
            'last_error_at' => 'datetime',
            'last_reply_at' => 'datetime',
        ];
    }

    /**
     * The stored configuration, or an unsaved one with the defaults.
     */
    public static function current(): self
    {
        return self::query()->orderBy('id')->first() ?? new self;
    }

    public function hasApiKey(): bool
    {
        return is_string($this->api_key) && $this->api_key !== '';
    }

    /** «••••1234»: what the page shows of a saved key. */
    public function maskedApiKey(): ?string
    {
        return $this->hasApiKey() ? '••••'.mb_substr((string) $this->api_key, -4) : null;
    }

    public function resolvedModel(): string
    {
        $model = trim((string) $this->model);

        return $model !== '' ? $model : $this->provider->defaultModel();
    }

    public function assistantName(): string
    {
        $name = trim((string) $this->assistant_name);

        return $name !== '' ? $name : self::DEFAULT_ASSISTANT_NAME;
    }

    public function handoffMessage(): string
    {
        $message = trim((string) $this->handoff_message);

        return $message !== '' ? $message : self::DEFAULT_HANDOFF_MESSAGE;
    }

    /**
     * The catalog without blank entries.
     *
     * @return list<array{question: string, answer: string}>
     */
    public function catalogEntries(): array
    {
        return array_values(array_filter(
            $this->catalog ?? [],
            fn (array $entry): bool => trim($entry['question']) !== '' && trim($entry['answer']) !== '',
        ));
    }

    /** Whether the assistant has what it needs to answer anyone. */
    public function isReady(): bool
    {
        return $this->exists && $this->hasApiKey()
            && (trim((string) $this->business_info) !== '' || $this->catalogEntries() !== []);
    }
}
