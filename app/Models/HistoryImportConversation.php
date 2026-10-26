<?php

namespace App\Models;

use App\Enums\HistoryImportDecision;
use App\Enums\HistoryImportRowState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A conversation detected in the files of a history import batch
 * (inventory), with the admin's decision and, once executed, its result.
 * It never holds message content.
 *
 * @property int $id
 * @property int $data_transfer_id
 * @property string $source_key
 * @property string $source_kind ticket | contact | chat_txt
 * @property string|null $source_ticket_id
 * @property string|null $contact_name
 * @property string|null $contact_phone
 * @property string|null $contact_identifier
 * @property bool $is_group
 * @property list<string>|null $participants
 * @property list<string>|null $business_participants
 * @property Carbon|null $opened_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $first_message_at
 * @property Carbon|null $last_message_at
 * @property int $message_count
 * @property int $inbound_count
 * @property int $outbound_count
 * @property int $attachments_total
 * @property int $attachments_available
 * @property int $media_bytes
 * @property string|null $source_agent
 * @property string|null $source_department
 * @property string|null $source_connection
 * @property list<string>|null $source_tags
 * @property string|null $source_status
 * @property array<string, string>|null $source_details Original identifiers (conversation id, chat id, chat link, number as written).
 * @property bool $in_chats_report
 * @property bool $already_imported
 * @property list<array{code: string, severity: string, message: string}>|null $issues
 * @property string|null $recommendation
 * @property string|null $recommendation_reason
 * @property HistoryImportDecision|null $decision
 * @property string|null $decision_source manual | bulk | rule
 * @property int|null $decided_by_user_id
 * @property Carbon|null $decided_at
 * @property int|null $applied_rule_id
 * @property bool $save_contact
 * @property HistoryImportRowState $state
 * @property int|null $result_conversation_id
 * @property int|null $result_contact_id
 * @property string|null $error
 * @property int $attempts Failed executions (retried at most history-import.max_attempts times).
 * @property Carbon|null $processed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class HistoryImportConversation extends Model
{
    public const KIND_TICKET = 'ticket';

    public const KIND_CONTACT = 'contact';

    public const KIND_CHAT_TEXT = 'chat_txt';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'state' => 'pending',
        'is_group' => false,
        'already_imported' => false,
        'in_chats_report' => false,
        'save_contact' => false,
        'attempts' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_group' => 'boolean',
            'participants' => 'array',
            'business_participants' => 'array',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'first_message_at' => 'datetime',
            'last_message_at' => 'datetime',
            'message_count' => 'integer',
            'inbound_count' => 'integer',
            'outbound_count' => 'integer',
            'attachments_total' => 'integer',
            'attachments_available' => 'integer',
            'media_bytes' => 'integer',
            'source_tags' => 'array',
            'source_details' => 'array',
            'in_chats_report' => 'boolean',
            'already_imported' => 'boolean',
            'issues' => 'array',
            'decision' => HistoryImportDecision::class,
            'decided_at' => 'datetime',
            'save_contact' => 'boolean',
            'state' => HistoryImportRowState::class,
            'processed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<DataTransfer, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(DataTransfer::class, 'data_transfer_id');
    }

    /**
     * @return HasMany<HistoryImportMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(HistoryImportMessage::class);
    }

    /**
     * @return BelongsTo<HistoryImportRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(HistoryImportRule::class, 'applied_rule_id');
    }

    public function attachmentsMissing(): int
    {
        return max(0, $this->attachments_total - $this->attachments_available);
    }

    /**
     * Issues that prevent creating a conversation from this row.
     *
     * @return list<array{code: string, severity: string, message: string}>
     */
    public function blockingIssues(): array
    {
        return array_values(array_filter($this->issues ?? [], fn (array $issue): bool => $issue['severity'] === 'error'));
    }

    /**
     * Destinations the admin may choose for this row: a group chat or a
     * conversation whose participants are still unidentified can only be
     * archived or excluded, an empty one only excluded.
     *
     * @return list<HistoryImportDecision>
     */
    public function allowedDecisions(): array
    {
        if ($this->message_count === 0) {
            return [HistoryImportDecision::Exclude];
        }

        if ($this->is_group) {
            return [HistoryImportDecision::Archive, HistoryImportDecision::Exclude];
        }

        return HistoryImportDecision::cases();
    }

    public function allows(HistoryImportDecision $decision): bool
    {
        return in_array($decision, $this->allowedDecisions(), true);
    }
}
