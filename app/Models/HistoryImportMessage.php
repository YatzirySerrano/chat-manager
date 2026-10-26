<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A parsed message of a history import batch (staging, purged when the
 * batch finishes or expires). The text is plain text.
 *
 * @property int $id
 * @property int $data_transfer_id
 * @property int $history_import_file_id
 * @property int|null $history_import_conversation_id
 * @property string $conversation_key
 * @property string|null $source_message_id
 * @property int $line
 * @property Carbon|null $occurred_at
 * @property bool|null $from_me
 * @property bool $direction_in_file Whether from_me came from the file (otherwise from the participants the admin marked as the company).
 * @property bool $is_system
 * @property string|null $sender_name
 * @property string $type
 * @property string|null $body
 * @property string|null $media_name
 * @property bool $media_available
 * @property string|null $media_disk Media disk of the copy made before the conversation's transaction.
 * @property string|null $media_path
 * @property string|null $media_mime Type detected from the content.
 * @property int|null $media_size
 * @property string|null $media_sha256
 * @property string|null $media_error Why the file could not be copied (historical_missing, historical_ambiguous, zip_entry_invalid, too_large, type_not_allowed).
 * @property string|null $quoted_source_id
 * @property string|null $contact_name
 * @property string|null $contact_phone
 * @property string|null $contact_identifier
 * @property string|null $source_agent
 * @property string|null $source_department
 * @property string|null $source_connection
 * @property Carbon|null $created_at
 */
class HistoryImportMessage extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'line' => 'integer',
            'occurred_at' => 'datetime',
            'from_me' => 'boolean',
            'direction_in_file' => 'boolean',
            'is_system' => 'boolean',
            'media_available' => 'boolean',
            'media_size' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<HistoryImportConversation, $this>
     */
    public function inventory(): BelongsTo
    {
        return $this->belongsTo(HistoryImportConversation::class, 'history_import_conversation_id');
    }

    /**
     * @return BelongsTo<HistoryImportFile, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(HistoryImportFile::class, 'history_import_file_id');
    }
}
