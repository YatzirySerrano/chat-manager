<?php

namespace App\Services\Imports;

use App\Actions\Contacts\AddContactNote;
use App\Actions\Contacts\CreateContact;
use App\Actions\Contacts\FindDuplicateContacts;
use App\Actions\Contacts\SaveContactToDirectory;
use App\Actions\Contacts\UpdateContact;
use App\Actions\Tags\SyncContactTags;
use App\Enums\AuditAction;
use App\Enums\ContactSource;
use App\Enums\DataTransferStatus;
use App\Enums\DataTransferType;
use App\Enums\ExportFormat;
use App\Enums\Permission;
use App\Jobs\ImportContacts;
use App\Models\Contact;
use App\Models\DataTransfer;
use App\Models\ImportBatchRecord;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Migration\GenericContactsAdapter;
use App\Services\Migration\MigrationSourceAdapter;
use App\Services\Migration\MigrationSourceRegistry;
use App\Support\Queues;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Contacts importer (Excel .xlsx / .csv), reserved to the Superadministrador
 * (contacts.import). Flow, all on one DataTransfer of type "import" and
 * report "contacts-import":
 *
 * 1. upload(): the file is checked (ImportFileReader), stored on the private
 *    disk and registered as a draft with the suggested column mapping;
 * 2. preview(): rows are validated with the contact form rules and checked
 *    for duplicates inside the file and against existing contacts; the user
 *    adjusts the mapping and the duplicate decision ("skip" by default, or
 *    "fill_empty", which only completes empty fields and never overwrites);
 * 3. confirm(): the import is queued (ImportContacts job), processed in
 *    batches through CreateContact / UpdateContact / SyncContactTags /
 *    AddContactNote, so business rules and per-contact audit apply, and a
 *    result workbook (created / updated / skipped / failed with reasons) is
 *    left for the owner to download. The source file is then deleted.
 *
 * Idempotency: the SHA-256 of the file is stored; the same file cannot be
 * queued twice at the same time, an earlier completed import of it is
 * announced in the preview, and every row is matched again against the
 * existing contacts (normalized phone / lowercased email) right before it is
 * written, so re-running a file never duplicates contacts (already imported
 * rows end up as "omitted" or "no new data").
 *
 * Source adapters (App\Services\Migration): the file is read as coming
 * from a source ("generic" or "whaticket-contacts", options.source) that
 * suggests the mapping and normalizes the values before validation. The
 * mapping always stays editable.
 *
 * Traceability: every contact created or completed is linked to the batch
 * (this DataTransfer) in import_batch_records (ImportBatchTracker), which
 * allows a logical rollback of the batch when it is safe
 * (ImportBatchRollback). Historical Whaticket conversations and messages are
 * NOT imported: see docs/migracion-whaticket.md for what that would need.
 *
 * @phpstan-import-type AnalyzedRow from ContactImportAnalyzer
 * @phpstan-import-type ImportMapping from ContactImportAnalyzer
 */
class ContactImporter
{
    public const REPORT = 'contacts-import';

    public const DECISION_SKIP = 'skip';

    public const DECISION_FILL_EMPTY = 'fill_empty';

    public const DECISIONS = [
        self::DECISION_SKIP => 'Omitir los contactos que ya existen',
        self::DECISION_FILL_EMPTY => 'Completar solo los campos vacíos de los contactos que ya existen',
    ];

    public function __construct(
        private ImportFileReader $reader,
        private ContactImportAnalyzer $analyzer,
        private AuditLogger $audit,
        private CreateContact $createContact,
        private UpdateContact $updateContact,
        private FindDuplicateContacts $findDuplicates,
        private SyncContactTags $syncTags,
        private AddContactNote $addNote,
        private ContactImportReport $report,
        private MigrationSourceRegistry $sources,
        private ImportBatchTracker $tracker,
        private SaveContactToDirectory $saveContact,
    ) {}

    /**
     * Check, store and register an uploaded file as a draft import.
     *
     * @throws ValidationException When the file is unsafe or invalid.
     */
    public function upload(UploadedFile $file, User $actor, string $source = GenericContactsAdapter::KEY): DataTransfer
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $sheet = $this->reader->read((string) $file->getRealPath(), $extension);
        $adapter = $this->sources->find($source);

        $disk = (string) config('imports.disk', 'local');
        $directory = trim((string) config('imports.directory', 'imports'), '/')."/{$actor->id}";
        $stored = $file->storeAs($directory, Str::uuid()->toString().'.'.$extension, ['disk' => $disk]);

        if ($stored === false) {
            throw new RuntimeException('The import file could not be stored.');
        }

        return DataTransfer::query()->create([
            'type' => DataTransferType::Import,
            'report' => self::REPORT,
            'format' => ExportFormat::Xlsx,
            'status' => DataTransferStatus::Draft,
            'user_id' => $actor->id,
            'disk' => $disk,
            'file_name' => null,
            'rows_total' => count($sheet['rows']),
            'options' => [
                'source_path' => $stored,
                'source_extension' => $extension,
                'original_name' => Str::limit($this->safeName($file->getClientOriginalName()), 120, ''),
                'hash' => (string) hash_file('sha256', (string) $file->getRealPath()),
                'headers' => $sheet['headers'],
                'source' => $adapter->key(),
                'detection' => $this->sources->detect($sheet['headers']),
                'mapping' => $adapter->suggestMapping($sheet['headers']),
                'decision' => self::DECISION_SKIP,
            ],
        ]);
    }

    /**
     * Validation of every row with the current mapping.
     *
     * @return array{rows: list<AnalyzedRow>, summary: array{total: int, valid: int, new: int, existing: int, duplicates_in_file: int, errors: int, warnings: int}, mapping_errors: list<string>}
     */
    public function preview(DataTransfer $transfer, User $actor): array
    {
        $mapping = $this->mapping($transfer);
        $mappingErrors = $this->analyzer->mappingErrors($mapping, $this->headers($transfer));
        $rows = $mappingErrors === [] ? $this->analyze($transfer, $actor) : [];

        return [
            'rows' => $rows,
            'summary' => $this->analyzer->summarize($rows),
            'mapping_errors' => $mappingErrors,
        ];
    }

    /**
     * Save the mapping, the duplicate decision and (optionally) the source
     * adapter of a draft.
     *
     * @param  ImportMapping  $mapping
     *
     * @throws ValidationException
     */
    public function configure(DataTransfer $transfer, array $mapping, string $decision, ?string $source = null): void
    {
        $this->ensureDraft($transfer);

        $mapping = array_merge(array_fill_keys(array_keys(ContactImportAnalyzer::FIELDS), null), array_intersect_key($mapping, ContactImportAnalyzer::FIELDS));
        $errors = $this->analyzer->mappingErrors($mapping, $this->headers($transfer));

        if ($errors !== []) {
            throw ValidationException::withMessages(['mapping' => $errors[0]]);
        }

        $transfer->update(['options' => [
            ...(array) $transfer->options,
            'mapping' => $mapping,
            'decision' => $decision,
            'source' => $source === null ? $this->source($transfer)->key() : $this->sources->find($source)->key(),
        ]]);
    }

    /**
     * Queue a configured draft.
     *
     * @throws ValidationException
     */
    public function confirm(DataTransfer $transfer, User $actor): void
    {
        DB::transaction(function () use ($transfer): void {
            $locked = DataTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            $this->ensureDraft($locked);

            $errors = $this->analyzer->mappingErrors($this->mapping($locked), $this->headers($locked));

            if ($errors !== []) {
                throw ValidationException::withMessages(['mapping' => $errors[0]]);
            }

            $inProgress = DataTransfer::query()
                ->where('type', DataTransferType::Import)
                ->whereKeyNot($locked->id)
                ->whereIn('status', [DataTransferStatus::Queued, DataTransferStatus::Processing])
                ->where('options->hash', $locked->options['hash'] ?? '')
                ->exists();

            if ($inProgress) {
                throw ValidationException::withMessages(['file' => 'Este mismo archivo ya se está importando. Espera a que termine.']);
            }

            $locked->update(['status' => DataTransferStatus::Queued, 'rows_processed' => 0, 'rows_failed' => 0]);
        });

        $transfer->refresh();

        $this->audit->record(AuditAction::ImportRequested, null, [
            'transfer_id' => $transfer->id,
            'file' => $transfer->options['original_name'] ?? null,
            'source' => $this->source($transfer)->key(),
            'rows' => $transfer->rows_total,
            'decision' => $transfer->options['decision'] ?? self::DECISION_SKIP,
            'fields' => array_keys(array_filter($this->mapping($transfer), fn (?int $column): bool => $column !== null)),
        ], $actor);

        ImportContacts::dispatch($transfer->id)->onQueue(Queues::name(Queues::EXPORTS));
    }

    /**
     * Cancel a draft and delete its file.
     */
    public function discard(DataTransfer $transfer): void
    {
        $this->ensureDraft($transfer);
        $this->deleteSource($transfer);
        $transfer->update(['status' => DataTransferStatus::Expired, 'error' => 'Importación cancelada.', 'finished_at' => now()]);
    }

    /**
     * A completed import of the same file, if there was one.
     */
    public function previousImportOf(DataTransfer $transfer): ?DataTransfer
    {
        return DataTransfer::query()
            ->where('type', DataTransferType::Import)
            ->whereKeyNot($transfer->id)
            ->where('status', DataTransferStatus::Completed)
            ->where('options->hash', $transfer->options['hash'] ?? '')
            ->latest('finished_at')
            ->first();
    }

    /**
     * Process a queued import (called by the ImportContacts job).
     */
    public function process(DataTransfer $transfer, User $actor): void
    {
        if (! $actor->holdsPermission(Permission::ImportContacts)) {
            $this->fail($transfer, $actor, 'Ya no tienes permiso para importar contactos.');

            return;
        }

        try {
            $rows = $this->analyze($transfer, $actor);
        } catch (ValidationException $exception) {
            $this->fail($transfer, $actor, $exception->validator->errors()->first());

            return;
        }

        $decision = (string) ($transfer->options['decision'] ?? self::DECISION_SKIP);
        $transfer->update(['options' => [...(array) $transfer->options, 'traceability' => true]]);
        $outcomes = [];
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];

        foreach (array_chunk($rows, max(1, (int) config('imports.batch_size', 200))) as $batch) {
            foreach ($batch as $row) {
                $outcome = $this->processRow($transfer, $row, $decision, $actor);
                $counts[$outcome['result']]++;
                $outcomes[] = $outcome;
            }

            $transfer->update([
                'rows_processed' => count($outcomes),
                'rows_failed' => $counts['failed'],
            ]);
        }

        $reportPath = $this->report->write($transfer, $outcomes, $counts);
        $this->deleteSource($transfer);

        $transfer->update([
            'status' => DataTransferStatus::Completed,
            'path' => $reportPath,
            'file_name' => 'resultado-importacion-contactos-'.now()->format('Y-m-d-Hi').'.xlsx',
            'rows_processed' => count($outcomes),
            'rows_failed' => $counts['failed'],
            'summary' => $counts,
            'finished_at' => now(),
            'expires_at' => now()->addHours(max(1, (int) config('exports.retention_hours', 24))),
        ]);

        $this->audit->record(AuditAction::ImportCompleted, null, [
            'transfer_id' => $transfer->id,
            'file' => $transfer->options['original_name'] ?? null,
            'source' => $this->source($transfer)->key(),
            ...$counts,
        ], $actor);
    }

    /**
     * Mark an import as failed and audit it (restricted).
     */
    public function fail(DataTransfer $transfer, ?User $actor, string $reason): void
    {
        $this->deleteSource($transfer);

        $transfer->update(['status' => DataTransferStatus::Failed, 'error' => $reason, 'finished_at' => now()]);

        $this->audit->record(AuditAction::ImportFailed, null, [
            'transfer_id' => $transfer->id,
            'file' => $transfer->options['original_name'] ?? null,
            'reason' => $reason,
        ], $actor);
    }

    /**
     * @return ImportMapping
     */
    public function mapping(DataTransfer $transfer): array
    {
        $stored = (array) ($transfer->options['mapping'] ?? []);
        $mapping = [];

        foreach (array_keys(ContactImportAnalyzer::FIELDS) as $field) {
            $mapping[$field] = isset($stored[$field]) && is_numeric($stored[$field]) ? (int) $stored[$field] : null;
        }

        return $mapping;
    }

    /**
     * @return list<string>
     */
    public function headers(DataTransfer $transfer): array
    {
        return array_values(array_map('strval', (array) ($transfer->options['headers'] ?? [])));
    }

    /**
     * Source adapter of the import (generic for imports older than adapters).
     */
    public function source(DataTransfer $transfer): MigrationSourceAdapter
    {
        $key = $transfer->options['source'] ?? null;

        return $this->sources->resolve(is_string($key) ? $key : null);
    }

    /**
     * Rows of the source file analyzed with the current mapping and source.
     *
     * @return list<AnalyzedRow>
     *
     * @throws ValidationException
     */
    private function analyze(DataTransfer $transfer, User $actor): array
    {
        return $this->analyzer->analyze(
            $this->readSource($transfer)['rows'],
            $this->mapping($transfer),
            $actor,
            $this->source($transfer),
            $this->headers($transfer),
        );
    }

    /**
     * @param  AnalyzedRow  $row
     * @return array{line: int, result: 'created'|'updated'|'skipped'|'failed', name: string, phone: string|null, email: string|null, contact_id: int|null, reason: string|null}
     */
    private function processRow(DataTransfer $transfer, array $row, string $decision, User $actor): array
    {
        if ($row['status'] === 'error') {
            return $this->outcome($row, 'failed', implode(' ', $row['errors']));
        }

        if ($row['status'] === 'duplicate') {
            return $this->outcome($row, 'skipped', "Repetido en el archivo: es el mismo contacto de la fila {$row['duplicate_of_line']}.");
        }

        $data = $row['data'];

        try {
            // Checked again right before writing: the directory may have changed since the preview.
            $matches = $this->findDuplicates->handle($data['phone_e164'], $data['email'], $actor);

            if ($matches === []) {
                $contact = DB::transaction(function () use ($transfer, $row, $data, $actor): Contact {
                    $contact = $this->createContact->handle([
                        'name' => $data['name'],
                        'phone_e164' => $data['phone_e164'],
                        'email' => $data['email'],
                        'custom_fields' => null,
                    ], $actor, false, ContactSource::Import);

                    $added = $this->applyTagsAndNote($contact, $data, $actor);
                    $this->tracker->created($transfer, $contact, $row['line'], $added['tag_ids'], $added['note_id']);

                    return $contact;
                });

                return $this->outcome($row, 'created', null, $contact->id);
            }

            if (count($matches) > 1) {
                return $this->outcome($row, 'skipped', 'Coincide con varios contactos existentes (#'.implode(', #', array_column($matches, 'id')).'); revísalos a mano.');
            }

            $existing = Contact::query()->findOrFail($matches[0]['id']);

            if ($existing->isDeleted() && ($rolledBackBy = $this->rolledBackBatchOf($existing)) !== null) {
                return $this->outcome($row, 'skipped', "Ya existe como «{$existing->name}» (#{$existing->id}), eliminado al revertir la importación #{$rolledBackBy}; restáuralo desde Contactos si quieres conservarlo.", $existing->id);
            }

            if ($decision !== self::DECISION_FILL_EMPTY) {
                return $this->outcome($row, 'skipped', $existing->isSaved()
                    ? "Ya existe como «{$existing->name}» (#{$existing->id})."
                    : "Ya existe como «{$existing->name}» (#{$existing->id}), registrado por un mensaje y sin guardar en el directorio; elige completar campos vacíos para guardarlo.", $existing->id);
            }

            if ($existing->isDeleted()) {
                return $this->outcome($row, 'skipped', "Ya existe como «{$existing->name}» (#{$existing->id}), pero está eliminado; restáuralo antes de completarlo.", $existing->id);
            }

            // Completing a provisional match (an unknown sender registered by
            // an inbound message) also saves it into the directory: the import
            // declares these people as contacts. A later rollback of the batch
            // reverts the completed fields but keeps it saved.
            $changed = DB::transaction(function () use ($transfer, $row, $existing, $data, $actor): bool {
                $previous = [];
                $applied = [];
                $promoted = $this->saveContact->promote($existing, $actor, 'import');

                foreach (['phone_e164', 'email'] as $field) {
                    if ($existing->getAttribute($field) === null && $data[$field] !== null) {
                        $previous[$field] = null;
                        $applied[$field] = $data[$field];
                    }
                }

                if ($applied !== []) {
                    $this->updateContact->handle($existing, [
                        'name' => $existing->name,
                        'phone_e164' => $existing->phone_e164 ?? $data['phone_e164'],
                        'email' => $existing->email ?? $data['email'],
                        'custom_fields' => $existing->custom_fields,
                    ], $actor);
                }

                $added = $this->applyTagsAndNote($existing, $data, $actor);

                if (! $promoted && $applied === [] && $added['tag_ids'] === [] && $added['note_id'] === null) {
                    return false;
                }

                $this->tracker->filled($transfer, $existing, $row['line'], $previous, $applied, $added['tag_ids'], $added['note_id']);

                return true;
            });

            return $changed
                ? $this->outcome($row, 'updated', 'Se completaron campos vacíos del contacto existente; no se sobrescribió ningún dato.', $existing->id)
                : $this->outcome($row, 'skipped', "Ya existe como «{$existing->name}» (#{$existing->id}) y no hay datos nuevos para completar.", $existing->id);
        } catch (ValidationException $exception) {
            return $this->outcome($row, 'failed', $exception->validator->errors()->first());
        } catch (Throwable $exception) {
            report($exception);

            return $this->outcome($row, 'failed', 'No se pudo guardar esta fila por un error inesperado.');
        }
    }

    /**
     * @param  AnalyzedRow  $row
     * @param  'created'|'updated'|'skipped'|'failed'  $result
     * @return array{line: int, result: 'created'|'updated'|'skipped'|'failed', name: string, phone: string|null, email: string|null, contact_id: int|null, reason: string|null}
     */
    private function outcome(array $row, string $result, ?string $reason = null, ?int $contactId = null): array
    {
        return [
            'line' => $row['line'],
            'result' => $result,
            'name' => $row['data']['name'] !== '' ? $row['data']['name'] : $row['values']['name'],
            'phone' => $row['data']['phone_e164'] ?? ($row['values']['phone'] !== '' ? $row['values']['phone'] : null),
            'email' => $row['data']['email'] ?? ($row['values']['email'] !== '' ? $row['values']['email'] : null),
            'contact_id' => $contactId,
            'reason' => $reason,
        ];
    }

    /**
     * Adds the row's tags (never removes the ones the contact has) and its
     * note, unless the contact already has the same note.
     *
     * @param  array{name: string, phone_e164: string|null, email: string|null, tag_ids: list<int>, tag_names: list<string>, notes: string|null}  $data
     * @return array{tag_ids: list<int>, note_id: int|null} What was added.
     */
    private function applyTagsAndNote(Contact $contact, array $data, User $actor): array
    {
        $added = ['tag_ids' => [], 'note_id' => null];

        if ($data['tag_ids'] !== [] && $actor->holdsPermission(Permission::AssignTags)) {
            $current = $contact->tags()->pluck('tags.id')->map(fn (mixed $id): int => (int) $id)->all();
            $missing = array_values(array_diff($data['tag_ids'], $current));

            if ($missing !== []) {
                $this->syncTags->handle($contact, array_values([...$current, ...$missing]), $actor);
                $added['tag_ids'] = $missing;
            }
        }

        if ($data['notes'] !== null && Gate::forUser($actor)->allows('manageNotes', $contact)
            && ! $contact->notes()->where('body', $data['notes'])->exists()) {
            $added['note_id'] = $this->addNote->handle($contact, $data['notes'], $actor)->id;
        }

        return $added;
    }

    /**
     * The import whose rollback archived this contact, if that is why it is
     * deleted.
     */
    private function rolledBackBatchOf(Contact $contact): ?int
    {
        $transferId = ImportBatchRecord::query()
            ->where('record_type', $contact->getMorphClass())
            ->where('record_id', $contact->id)
            ->where('action', ImportBatchRecord::ACTION_CREATED)
            ->where('rollback_outcome', ImportBatchRecord::OUTCOME_REVERTED)
            ->latest('rolled_back_at')
            ->value('data_transfer_id');

        return $transferId === null ? null : (int) $transferId;
    }

    /**
     * @return array{headers: list<string>, rows: list<array{line: int, cells: list<string>}>}
     *
     * @throws ValidationException
     */
    private function readSource(DataTransfer $transfer): array
    {
        $path = $transfer->options['source_path'] ?? null;
        $disk = Storage::disk((string) $transfer->disk);

        if (! is_string($path) || ! $disk->exists($path)) {
            throw ValidationException::withMessages(['file' => 'El archivo de la importación ya no está disponible. Súbelo de nuevo.']);
        }

        return $this->reader->read($disk->path($path), (string) ($transfer->options['source_extension'] ?? ''));
    }

    private function deleteSource(DataTransfer $transfer): void
    {
        $path = $transfer->options['source_path'] ?? null;

        if (is_string($path) && $path !== '') {
            Storage::disk((string) $transfer->disk)->delete($path);
            $transfer->options = [...(array) $transfer->options, 'source_path' => null];
            $transfer->save();
        }
    }

    /**
     * @throws ValidationException
     */
    private function ensureDraft(DataTransfer $transfer): void
    {
        if ($transfer->status !== DataTransferStatus::Draft) {
            throw ValidationException::withMessages(['file' => 'Esta importación ya fue confirmada o cancelada.']);
        }
    }

    /**
     * Original file name reduced to safe characters (it is only displayed).
     */
    private function safeName(string $name): string
    {
        return trim((string) preg_replace('/[^\pL\pN ._()\-]+/u', '_', $name)) ?: 'archivo';
    }
}
