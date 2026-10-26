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

    /** A message of a contact-keyed conversation (later exports only add the missing ones). */
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
}
