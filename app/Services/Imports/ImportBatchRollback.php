<?php

namespace App\Services\Imports;

use App\Actions\Contacts\DeleteContact;
use App\Actions\Contacts\UpdateContact;
use App\Actions\Tags\SyncContactTags;
use App\Enums\AuditAction;
use App\Enums\DataTransferStatus;
use App\Enums\DataTransferType;
use App\Enums\Permission;
use App\Jobs\RollbackContactImport;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\ContactNote;
use App\Models\Conversation;
use App\Models\DataTransfer;
use App\Models\ImportBatchRecord;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Queues;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Logical rollback of a contacts import batch, only where it is safe:
 *
 * - a contact the batch CREATED is logically deleted (archived through
 *   DeleteContact) only if nothing happened to it afterwards: no
 *   conversations, WhatsApp identities, origin attributions, review cases
 *   or connections, no notes other than the ones the batch added, no audit
 *   entry about it after the batch, no later import touching it, and the
 *   same data and tags it had right after the import;
 * - a field the batch FILLED on an existing contact is restored to its
 *   previous (empty) value only if it still holds the value the batch wrote
 *   (and, for the phone, if no conversation or identity arrived since);
 *   tags the batch added are removed if they are still there. Notes are
 *   history and are always kept.
 *
 * Nothing is physically deleted. Records with later activity are kept and
 * reported with the reason. The rollback runs once per batch, is previewed
 * before confirming, re-checks every rule under a row lock right before
 * writing, is reserved to the owner of the import (contacts.import, root)
 * and is audited (restricted).
 *
 * @phpstan-type RollbackPlan array{
 *     outcome: 'reverted'|'partial'|'kept',
 *     reason: string|null,
 *     archive: bool,
 *     restore: array<string, mixed>,
 *     kept_fields: list<string>,
 *     remove_tag_ids: list<int>,
 *     kept_notes: int,
 * }
 * @phpstan-type RollbackSummary array{
 *     records: int,
 *     contacts_archived: int,
 *     contacts_kept: int,
 *     fields_restored: int,
 *     fields_kept: int,
 *     tags_removed: int,
 *     notes_kept: int,
 *     reverted: int,
 *     partial: int,
 *     kept: int,
 * }
 */
class ImportBatchRollback
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    private const FIELD_LABELS = ['phone_e164' => 'teléfono', 'email' => 'correo electrónico'];

    public function __construct(
        private AuditLogger $audit,
        private ImportBatchTracker $tracker,
        private DeleteContact $deleteContact,
        private UpdateContact $updateContact,
        private SyncContactTags $syncTags,
    ) {}

    /**
     * Why the batch cannot be rolled back now, or null when it can.
     */
    public function unavailableReason(DataTransfer $transfer): ?string
    {
        $state = $this->state($transfer);

        return match (true) {
            ! (bool) config('imports.rollback.enabled', true) => 'La reversión de importaciones está desactivada en esta instalación.',
            $transfer->type !== DataTransferType::Import || $transfer->report !== ContactImporter::REPORT => 'Solo se pueden revertir importaciones de contactos.',
            ! $this->isFinished($transfer) => 'Solo se puede revertir una importación terminada.',
            ($transfer->options['traceability'] ?? false) !== true => 'Esta importación se hizo antes de la trazabilidad por lotes; no se puede revertir automáticamente.',
            in_array($state['status'] ?? null, [self::STATUS_QUEUED, self::STATUS_PROCESSING], true) => 'La reversión de esta importación ya está en proceso.',
            ($state['status'] ?? null) === self::STATUS_COMPLETED => 'Esta importación ya se revirtió.',
            $transfer->finished_at->lt(now()->subDays(max(1, (int) config('imports.rollback.days', 30)))) => 'Solo se pueden revertir importaciones de los últimos '.max(1, (int) config('imports.rollback.days', 30)).' días.',
            ! $this->pendingRecords($transfer)->exists() => 'Esta importación no creó ni completó contactos; no hay nada que revertir.',
            default => null,
        };
    }

    /**
     * Whether the import stopped writing contacts: completed (or completed
     * and expired), or failed after processing part of its rows (those rows
     * stay linked to the batch and can be rolled back too).
     */
    private function isFinished(DataTransfer $transfer): bool
    {
        if ($transfer->finished_at === null) {
            return false;
        }

        return match ($transfer->status) {
            DataTransferStatus::Completed, DataTransferStatus::Expired => $transfer->summary !== null,
            DataTransferStatus::Failed => true,
            default => false,
        };
    }

    /**
     * Rollback state stored with the batch (null when never requested).
     *
     * @return array<string, mixed>|null
     */
    public function state(DataTransfer $transfer): ?array
    {
        $state = $transfer->options['rollback'] ?? null;

        return is_array($state) ? $state : null;
    }

    /**
     * What a rollback would do right now, without writing anything: the
     * counters and the first records that would be kept, with the reason.
     *
     * @return array{summary: RollbackSummary, kept: list<array{line: int|null, contact_id: int, name: string, action: string, outcome: string, reason: string|null}>, limit: int}
     */
    public function preview(DataTransfer $transfer): array
    {
        $limit = (int) config('imports.preview_rows', 50);
        $summary = $this->emptySummary();
        $kept = [];

        $this->pendingRecords($transfer)->orderBy('id')->chunkById(200, function (Collection $records) use (&$summary, &$kept, $limit): void {
            $contacts = $this->contactsOf($records);
            $plans = $this->plan($records, $contacts);

            foreach ($records as $record) {
                $plan = $plans[$record->id];
                $summary = $this->tally($summary, $record, $plan);

                if ($plan['outcome'] !== ImportBatchRecord::OUTCOME_REVERTED && count($kept) < $limit) {
                    $kept[] = [
                        'line' => $record->line,
                        'contact_id' => $record->record_id,
                        'name' => $contacts[$record->record_id]->name ?? 'Contacto #'.$record->record_id,
                        'action' => $record->action,
                        'outcome' => $plan['outcome'],
                        'reason' => $plan['reason'],
                    ];
                }
            }
        });

        return ['summary' => $summary, 'kept' => $kept, 'limit' => $limit];
    }

    /**
     * Queue the rollback of a batch after the user confirmed the preview.
     *
     * @throws ValidationException When the batch cannot be rolled back.
     */
    public function request(DataTransfer $transfer, User $actor): void
    {
        DB::transaction(function () use ($transfer, $actor): void {
            $locked = DataTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            $reason = $this->unavailableReason($locked);

            if ($reason !== null) {
                throw ValidationException::withMessages(['rollback' => $reason]);
            }

            $locked->update(['options' => [...(array) $locked->options, 'rollback' => [
                'status' => self::STATUS_QUEUED,
                'requested_at' => now()->toIso8601String(),
                'requested_by' => $actor->id,
            ]]]);
        });

        $this->audit->record(AuditAction::ImportRollbackRequested, null, [
            'transfer_id' => $transfer->id,
            'file' => $transfer->options['original_name'] ?? null,
        ], $actor);

        try {
            RollbackContactImport::dispatch($transfer->id)->onQueue(Queues::name(Queues::EXPORTS));
        } catch (Throwable $exception) {
            report($exception);
            $this->fail($transfer->refresh(), $actor, 'No se pudo programar la reversión. Inténtalo de nuevo más tarde.');

            throw ValidationException::withMessages(['rollback' => 'No se pudo iniciar la reversión. Inténtalo de nuevo más tarde.']);
        }
    }

    /**
     * Run a queued rollback (called by the RollbackContactImport job).
     */
    public function run(DataTransfer $transfer, User $actor): void
    {
        $claimed = DB::transaction(function () use ($transfer): bool {
            $locked = DataTransfer::query()->lockForUpdate()->findOrFail($transfer->id);

            if (($this->state($locked)['status'] ?? null) !== self::STATUS_QUEUED) {
                return false;
            }

            $this->updateState($locked, ['status' => self::STATUS_PROCESSING, 'started_at' => now()->toIso8601String()]);

            return true;
        });

        if (! $claimed) {
            return;
        }

        $transfer->refresh();

        if (! $actor->holdsPermission(Permission::ImportContacts)) {
            $this->fail($transfer, $actor, 'Ya no tienes permiso para importar contactos.');

            return;
        }

        // A retry after a failure continues the totals of the first attempt.
        $summary = $this->emptySummary();
        $previous = (array) ($this->state($transfer)['summary'] ?? []);

        foreach (array_keys($summary) as $key) {
            $summary[$key] = (int) ($previous[$key] ?? 0);
        }

        try {
            $this->pendingRecords($transfer)->orderBy('id')->chunkById(100, function (Collection $records) use ($actor, &$summary): void {
                foreach ($records as $record) {
                    $plan = $this->rollbackRecord($record, $actor);

                    if ($plan !== null) {
                        $summary = $this->tally($summary, $record, $plan);
                    }
                }
            });
        } catch (Throwable $exception) {
            report($exception);
            $this->updateState($transfer, ['summary' => $summary]);
            $this->fail($transfer, $actor, 'No se pudo terminar la reversión. Lo ya revertido se conserva; puedes intentarlo de nuevo.');

            return;
        }

        $this->updateState($transfer, ['status' => self::STATUS_COMPLETED, 'finished_at' => now()->toIso8601String(), 'summary' => $summary]);

        $this->audit->record(AuditAction::ImportRolledBack, null, [
            'transfer_id' => $transfer->id,
            'file' => $transfer->options['original_name'] ?? null,
            ...$summary,
        ], $actor);
    }

    /**
     * Mark the rollback as failed (it can be requested again: the records
     * already handled are not touched twice).
     */
    public function fail(DataTransfer $transfer, ?User $actor, string $reason): void
    {
        $this->updateState($transfer, ['status' => self::STATUS_FAILED, 'finished_at' => now()->toIso8601String(), 'error' => $reason]);

        $this->audit->record(AuditAction::ImportRollbackFailed, null, [
            'transfer_id' => $transfer->id,
            'reason' => $reason,
        ], $actor);
    }

    /**
     * Records of the batch with the outcome of the rollback, for the result page.
     *
     * @return list<array{line: int|null, contact_id: int, name: string, action: string, outcome: string|null, reason: string|null}>
     */
    public function handledNotReverted(DataTransfer $transfer, int $limit): array
    {
        $records = ImportBatchRecord::query()
            ->where('data_transfer_id', $transfer->id)
            ->whereIn('rollback_outcome', [ImportBatchRecord::OUTCOME_KEPT, ImportBatchRecord::OUTCOME_PARTIAL])
            ->orderBy('id')
            ->limit($limit)
            ->get();
        $names = Contact::query()->whereKey($records->pluck('record_id')->all())->pluck('name', 'id');

        return array_values($records->map(fn (ImportBatchRecord $record): array => [
            'line' => $record->line,
            'contact_id' => $record->record_id,
            'name' => (string) ($names[$record->record_id] ?? 'Contacto #'.$record->record_id),
            'action' => $record->action,
            'outcome' => $record->rollback_outcome,
            'reason' => $record->rollback_reason,
        ])->all());
    }

    /**
     * Roll back one record under a lock of its contact, re-checking the
     * rules with fresh data.
     *
     * @return RollbackPlan|null What was done (null when already handled).
     */
    private function rollbackRecord(ImportBatchRecord $record, User $actor): ?array
    {
        return DB::transaction(function () use ($record, $actor): ?array {
            /** @var ImportBatchRecord $locked */
            $locked = ImportBatchRecord::query()->lockForUpdate()->findOrFail($record->id);

            if ($locked->rolled_back_at !== null) {
                return null;
            }

            $contact = Contact::query()->lockForUpdate()->find($locked->record_id);
            $plan = $contact === null
                ? $this->keep('El contacto ya no existe.')
                : $this->plan(collect([$locked]), [$contact->id => $contact])[$locked->id];

            if ($contact === null) {
                $locked->update(['rolled_back_at' => now(), 'rollback_outcome' => $plan['outcome'], 'rollback_reason' => $plan['reason']]);

                return $plan;
            }

            if ($plan['archive']) {
                $this->deleteContact->handle($contact, $actor);
            }

            if ($plan['restore'] !== []) {
                $this->updateContact->handle($contact, [
                    'name' => $contact->name,
                    'phone_e164' => array_key_exists('phone_e164', $plan['restore']) ? $plan['restore']['phone_e164'] : $contact->phone_e164,
                    'email' => array_key_exists('email', $plan['restore']) ? $plan['restore']['email'] : $contact->email,
                    'custom_fields' => $contact->custom_fields,
                ], $actor);
            }

            if ($plan['remove_tag_ids'] !== []) {
                $current = $contact->tags()->pluck('tags.id')->map(fn (mixed $id): int => (int) $id)->all();
                $this->syncTags->handle($contact, array_values(array_diff($current, $plan['remove_tag_ids'])), $actor);
            }

            $locked->update([
                'rolled_back_at' => now(),
                'rollback_outcome' => $plan['outcome'],
                'rollback_reason' => $plan['reason'] === null ? null : mb_substr($plan['reason'], 0, 255),
            ]);

            return $plan;
        });
    }

    /**
     * Decide what can be undone for each record. Everything needed is
     * loaded per chunk (no query per record).
     *
     * @param  Collection<int, ImportBatchRecord>  $records
     * @param  array<int, Contact>  $contacts
     * @return array<int, RollbackPlan>
     */
    private function plan(Collection $records, array $contacts): array
    {
        $ids = array_keys($contacts);
        $activity = $this->activityOf($ids);
        $plans = [];

        foreach ($records as $record) {
            $contact = $contacts[$record->record_id] ?? null;
            $plans[$record->id] = $contact === null
                ? $this->keep('El contacto ya no existe.')
                : ($record->action === ImportBatchRecord::ACTION_CREATED
                    ? $this->planCreated($record, $contact, $activity)
                    : $this->planFilled($record, $contact, $activity));
        }

        return $plans;
    }

    /**
     * @param  array{conversations: array<int, Carbon>, identities: array<int, Carbon>, attributions: array<int, true>, reviews: array<int, true>, connections: array<int, true>, notes: array<int, list<int>>, tags: array<int, list<int>>, audits: array<int, int>, later: array<int, list<int>>}  $activity
     * @return RollbackPlan
     */
    private function planCreated(ImportBatchRecord $record, Contact $contact, array $activity): array
    {
        $id = $contact->id;
        $ownNotes = $record->added_note_ids ?? [];
        $otherNotes = array_diff($activity['notes'][$id] ?? [], $ownNotes);

        $reason = match (true) {
            $contact->isDeleted() => 'Ya estaba eliminado.',
            isset($activity['conversations'][$id]) => 'Tiene conversaciones.',
            isset($activity['identities'][$id]) => 'Ya escribió por WhatsApp (tiene identidad registrada).',
            isset($activity['attributions'][$id]) || isset($activity['reviews'][$id]) => 'Tiene clasificación de origen o revisiones registradas.',
            isset($activity['connections'][$id]) => 'Está vinculado a una conexión de WhatsApp.',
            $otherNotes !== [] => 'Tiene notas agregadas después de la importación.',
            $this->hasLaterImport($record, $activity) => 'Una importación posterior también lo modificó.',
            ($activity['audits'][$id] ?? 0) > $record->audit_watermark => 'Se modificó después de la importación.',
            $this->tracker->snapshot($contact, $activity['tags'][$id] ?? []) !== ($record->applied_values ?? []) => 'Sus datos o etiquetas cambiaron después de la importación.',
            default => null,
        };

        if ($reason !== null) {
            return $this->keep($reason, count($ownNotes));
        }

        return [
            'outcome' => ImportBatchRecord::OUTCOME_REVERTED,
            'reason' => null,
            'archive' => true,
            'restore' => [],
            'kept_fields' => [],
            'remove_tag_ids' => [],
            'kept_notes' => 0,
        ];
    }

    /**
     * @param  array{conversations: array<int, Carbon>, identities: array<int, Carbon>, attributions: array<int, true>, reviews: array<int, true>, connections: array<int, true>, notes: array<int, list<int>>, tags: array<int, list<int>>, audits: array<int, int>, later: array<int, list<int>>}  $activity
     * @return RollbackPlan
     */
    private function planFilled(ImportBatchRecord $record, Contact $contact, array $activity): array
    {
        $id = $contact->id;
        $notes = count($record->added_note_ids ?? []);

        if ($contact->isDeleted()) {
            return $this->keep('El contacto está eliminado; restáuralo para revertir los campos completados.', $notes);
        }

        $restore = [];
        $kept = [];
        $reasons = [];
        $since = $record->created_at;

        foreach (($record->previous_values ?? []) as $field => $previous) {
            $label = self::FIELD_LABELS[$field] ?? $field;

            if ($contact->getAttribute($field) !== ($record->applied_values[$field] ?? null)) {
                $kept[] = $field;
                $reasons[] = "El {$label} cambió después de la importación.";
            } elseif ($field === 'phone_e164' && $since !== null
                && ((isset($activity['conversations'][$id]) && $activity['conversations'][$id]->gte($since))
                    || (isset($activity['identities'][$id]) && $activity['identities'][$id]->gte($since)))) {
                $kept[] = $field;
                $reasons[] = 'El teléfono ya se usó en conversaciones de WhatsApp.';
            } else {
                $restore[$field] = $previous;
            }
        }

        $phone = array_key_exists('phone_e164', $restore) ? $restore['phone_e164'] : $contact->phone_e164;
        $email = array_key_exists('email', $restore) ? $restore['email'] : $contact->email;

        if ($restore !== [] && $phone === null && $email === null) {
            $kept = [...$kept, ...array_keys($restore)];
            $restore = [];
            $reasons[] = 'Sin los campos completados el contacto quedaría sin teléfono ni correo.';
        }

        $removeTags = array_values(array_intersect($record->added_tag_ids ?? [], $activity['tags'][$id] ?? []));
        $undone = $restore !== [] || $removeTags !== [];

        if ($notes > 0) {
            $reasons[] = 'Las notas agregadas se conservan como historial.';
        }

        $outcome = match (true) {
            $undone && $kept === [] => ImportBatchRecord::OUTCOME_REVERTED,
            $undone => ImportBatchRecord::OUTCOME_PARTIAL,
            default => ImportBatchRecord::OUTCOME_KEPT,
        };

        return [
            'outcome' => $outcome,
            'reason' => $reasons === [] ? ($undone ? null : 'No queda nada por revertir.') : implode(' ', $reasons),
            'archive' => false,
            'restore' => $restore,
            'kept_fields' => $kept,
            'remove_tag_ids' => $removeTags,
            'kept_notes' => $notes,
        ];
    }

    /**
     * @param  array{later: array<int, list<int>>}  $activity
     */
    private function hasLaterImport(ImportBatchRecord $record, array $activity): bool
    {
        foreach ($activity['later'][$record->record_id] ?? [] as $transferId) {
            if ($transferId > $record->data_transfer_id) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return RollbackPlan
     */
    private function keep(string $reason, int $notes = 0): array
    {
        return [
            'outcome' => ImportBatchRecord::OUTCOME_KEPT,
            'reason' => $reason,
            'archive' => false,
            'restore' => [],
            'kept_fields' => [],
            'remove_tag_ids' => [],
            'kept_notes' => $notes,
        ];
    }

    /**
     * Everything that counts as later activity, for a set of contacts.
     *
     * @param  list<int>  $ids
     * @return array{conversations: array<int, Carbon>, identities: array<int, Carbon>, attributions: array<int, true>, reviews: array<int, true>, connections: array<int, true>, notes: array<int, list<int>>, tags: array<int, list<int>>, audits: array<int, int>, later: array<int, list<int>>}
     */
    private function activityOf(array $ids): array
    {
        $activity = ['conversations' => [], 'identities' => [], 'attributions' => [], 'reviews' => [], 'connections' => [], 'notes' => [], 'tags' => [], 'audits' => [], 'later' => []];

        if ($ids === []) {
            return $activity;
        }

        // Conversations and identities count from their last message or sighting, not only from when they appeared.
        $lastActivity = [
            Conversation::class => ['conversations', 'max(coalesce(greatest(created_at, last_message_at), created_at)) as last_at'],
            ContactIdentity::class => ['identities', 'max(coalesce(greatest(created_at, last_seen_at), created_at)) as last_at'],
        ];

        foreach ($lastActivity as $model => [$key, $select]) {
            $rows = $model::query()->whereIn('contact_id', $ids)->groupBy('contact_id')->selectRaw('contact_id, '.$select)->get();

            foreach ($rows as $row) {
                $activity[$key][(int) $row->getAttribute('contact_id')] = Carbon::parse((string) $row->getAttribute('last_at'));
            }
        }

        foreach (['contact_attributions' => 'attributions', 'contact_review_cases' => 'reviews', 'connection_contact' => 'connections'] as $table => $key) {
            foreach (DB::table($table)->whereIn('contact_id', $ids)->distinct()->pluck('contact_id') as $contactId) {
                $activity[$key][(int) $contactId] = true;
            }
        }

        foreach (ContactNote::query()->whereIn('contact_id', $ids)->get(['id', 'contact_id']) as $note) {
            $activity['notes'][$note->contact_id][] = $note->id;
        }

        foreach (DB::table('contact_tag')->whereIn('contact_id', $ids)->get(['contact_id', 'tag_id']) as $row) {
            $activity['tags'][(int) $row->contact_id][] = (int) $row->tag_id;
        }

        $audits = AuditLog::query()
            ->where('auditable_type', (new Contact)->getMorphClass())
            ->whereIn('auditable_id', $ids)
            ->groupBy('auditable_id')
            ->selectRaw('auditable_id, max(id) as last_id')
            ->get();

        foreach ($audits as $row) {
            $activity['audits'][(int) $row->getAttribute('auditable_id')] = (int) $row->getAttribute('last_id');
        }

        $later = ImportBatchRecord::query()
            ->where('record_type', (new Contact)->getMorphClass())
            ->whereIn('record_id', $ids)
            ->where(fn ($query) => $query->whereNull('rollback_outcome')->orWhere('rollback_outcome', '!=', ImportBatchRecord::OUTCOME_REVERTED))
            ->get(['record_id', 'data_transfer_id']);

        foreach ($later as $row) {
            $activity['later'][$row->record_id][] = $row->data_transfer_id;
        }

        return $activity;
    }

    /**
     * @param  Collection<int, ImportBatchRecord>  $records
     * @return array<int, Contact>
     */
    private function contactsOf(Collection $records): array
    {
        return Contact::query()
            ->whereKey($records->pluck('record_id')->unique()->values()->all())
            ->get()
            ->keyBy('id')
            ->all();
    }

    /**
     * @return Builder<ImportBatchRecord>
     */
    private function pendingRecords(DataTransfer $transfer): Builder
    {
        return ImportBatchRecord::query()
            ->where('data_transfer_id', $transfer->id)
            ->where('record_type', (new Contact)->getMorphClass())
            ->pendingRollback();
    }

    /**
     * @param  RollbackSummary  $summary
     * @param  RollbackPlan  $plan
     * @return RollbackSummary
     */
    private function tally(array $summary, ImportBatchRecord $record, array $plan): array
    {
        $summary['records']++;
        $summary[$plan['outcome']]++;

        if ($record->action === ImportBatchRecord::ACTION_CREATED) {
            $plan['archive'] ? $summary['contacts_archived']++ : $summary['contacts_kept']++;
        }

        $summary['fields_restored'] += count($plan['restore']);
        $summary['fields_kept'] += count($plan['kept_fields']);
        $summary['tags_removed'] += count($plan['remove_tag_ids']);
        $summary['notes_kept'] += $record->action === ImportBatchRecord::ACTION_FILLED ? $plan['kept_notes'] : 0;

        return $summary;
    }

    /**
     * @return RollbackSummary
     */
    private function emptySummary(): array
    {
        return [
            'records' => 0,
            'contacts_archived' => 0,
            'contacts_kept' => 0,
            'fields_restored' => 0,
            'fields_kept' => 0,
            'tags_removed' => 0,
            'notes_kept' => 0,
            'reverted' => 0,
            'partial' => 0,
            'kept' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function updateState(DataTransfer $transfer, array $changes): void
    {
        $transfer->update(['options' => [
            ...(array) $transfer->options,
            'rollback' => [...($this->state($transfer) ?? []), ...$changes],
        ]]);
    }
}
