<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A record of another system (a Whaticket ticket or contact) that a
 * migration turned into a record here (import_source_keys). Written in the
 * same transaction as the record, so a source record is imported once
 * whatever the batch.
 *
 * @property int $id
 * @property string $source_system
 * @property string $source_instance
 * @property string $source_kind
 * @property string $source_id
 * @property string $target_type
 * @property int $target_id
 * @property int $data_transfer_id
 * @property array<string, mixed>|null $details Original identifiers of the source, never content.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class HistoryImportSourceKey extends Model
{
    public const SYSTEM_WHATICKET = 'whaticket';

    public const KIND_CONVERSATION = 'conversation';

    public const KIND_CONTACT = 'contact';

    /**
     * A message of a conversation imported with a key per message: every
     * conversation keyed by contact, and the tickets of the messages CSV
     * (later exports only add the messages it does not have yet).
     */
    public const KIND_MESSAGE = 'message';

    protected $table = 'import_source_keys';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_id' => 'integer',
            'details' => 'array',
        ];
    }

    /**
     * Whether the conversation of this key was imported with a key per
     * message, so a later export of the same ticket adds only what is new
     * instead of being skipped as a duplicate.
     */
    public function extendable(): bool
    {
        return $this->source_kind === self::KIND_CONVERSATION && ($this->details['message_keys'] ?? false) === true;
    }
}
