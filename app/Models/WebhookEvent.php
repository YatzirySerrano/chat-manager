<?php

namespace App\Models;

use App\Enums\WebhookEventStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One authenticated WhatsApp webhook delivery, stored as received before it
 * is processed asynchronously (see ProcessWhatsAppWebhook).
 *
 * @property int $id
 * @property int $connection_id
 * @property string $payload_hash
 * @property string|null $fields Comma separated change fields of the body (messages, statuses...).
 * @property string $payload Raw body, exactly as signed by Meta.
 * @property WebhookEventStatus $status
 * @property int $attempts
 * @property string|null $error
 * @property array<string, mixed>|null $summary
 * @property Carbon $received_at
 * @property Carbon|null $processed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class WebhookEvent extends Model
{
    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @var list<string>
     */
    protected $hidden = ['payload'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WebhookEventStatus::class,
            'attempts' => 'integer',
            'summary' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Connection, $this>
     */
    public function channelConnection(): BelongsTo
    {
        return $this->belongsTo(Connection::class, 'connection_id');
    }

    /**
     * Decoded body.
     *
     * @return array<array-key, mixed>
     */
    public function decodedPayload(): array
    {
        $decoded = json_decode($this->payload, true);

        return is_array($decoded) ? $decoded : [];
    }
}
