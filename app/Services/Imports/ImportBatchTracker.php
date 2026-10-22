<?php

namespace App\Services\Imports;

use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\DataTransfer;
use App\Models\ImportBatchRecord;
use BackedEnum;

/**
 * Links every contact an import creates or completes to its batch (the
 * DataTransfer of the import) in import_batch_records, with what the batch
 * changed, so the batch stays traceable and can be rolled back logically
 * when it is safe (ImportBatchRollback). Called inside the transaction that
 * writes the contact, so a record exists if and only if the change does.
 */
class ImportBatchTracker
{
    /**
     * Contact attributes kept in the snapshot of a created contact: any later
     * difference in them means somebody (or something) changed it.
     *
     * @var list<string>
     */
    public const SNAPSHOT_FIELDS = ['name', 'phone_e164', 'email', 'profile_name', 'custom_fields', 'status'];

    /**
     * A contact the batch registered.
     *
     * @param  list<int>  $tagIds
     */
    public function created(DataTransfer $transfer, Contact $contact, int $line, array $tagIds, ?int $noteId): void
    {
        $record = $this->recordFor($transfer, $contact, $line, ImportBatchRecord::ACTION_CREATED);

        $record->fill([
            'applied_values' => $this->snapshot($contact),
            'added_tag_ids' => $this->merge($record->added_tag_ids, $tagIds),
            'added_note_ids' => $this->merge($record->added_note_ids, $noteId === null ? [] : [$noteId]),
            'audit_watermark' => $this->watermark(),
        ])->save();
    }

    /**
     * Empty fields the batch completed on an existing contact (with the
     * values it replaced), tags it added and the note it left.
     *
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $applied
     * @param  list<int>  $tagIds
     */
    public function filled(DataTransfer $transfer, Contact $contact, int $line, array $previous, array $applied, array $tagIds, ?int $noteId): void
    {
        $record = $this->recordFor($transfer, $contact, $line, ImportBatchRecord::ACTION_FILLED);

        if ($record->action === ImportBatchRecord::ACTION_CREATED) {
            // Created earlier in this same batch: the snapshot just moves forward.
            $record->applied_values = $this->snapshot($contact);
        } else {
            // The first value replaced is the one to restore.
            $record->previous_values = [...$previous, ...($record->previous_values ?? [])];
            $record->applied_values = [...($record->applied_values ?? []), ...$applied];
        }

        $record->fill([
            'added_tag_ids' => $this->merge($record->added_tag_ids, $tagIds),
            'added_note_ids' => $this->merge($record->added_note_ids, $noteId === null ? [] : [$noteId]),
            'audit_watermark' => $this->watermark(),
        ])->save();
    }

    /**
     * Comparable state of a contact (attributes and tags).
     *
     * @param  list<int>|null  $tagIds  Current tags when already known.
     * @return array<string, mixed>
     */
    public function snapshot(Contact $contact, ?array $tagIds = null): array
    {
        $tagIds ??= $contact->tags()->pluck('tags.id')->map(fn (mixed $id): int => (int) $id)->all();
        sort($tagIds);

        $snapshot = [];

        foreach (self::SNAPSHOT_FIELDS as $field) {
            $value = $contact->getAttribute($field);
            $snapshot[$field] = $value instanceof BackedEnum ? $value->value : $value;
        }

        $snapshot['tag_ids'] = $tagIds;

        return $snapshot;
    }

    /**
     * Last audit entry written so far.
     */
    public function watermark(): int
    {
        return (int) AuditLog::query()->max('id');
    }

    private function recordFor(DataTransfer $transfer, Contact $contact, int $line, string $action): ImportBatchRecord
    {
        return ImportBatchRecord::query()->firstOrNew(
            ['data_transfer_id' => $transfer->id, 'record_type' => $contact->getMorphClass(), 'record_id' => $contact->id],
            ['line' => $line, 'action' => $action],
        );
    }

    /**
     * @param  list<int>|null  $current
     * @param  list<int>  $added
     * @return list<int>|null
     */
    private function merge(?array $current, array $added): ?array
    {
        $merged = array_values(array_unique([...($current ?? []), ...$added]));

        return $merged === [] ? null : $merged;
    }
}
