<?php

namespace App\Http\Controllers\Contacts;

use App\Enums\DataTransferStatus;
use App\Enums\DataTransferType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Imports\ConfigureContactImportRequest;
use App\Http\Requests\Imports\RollbackContactImportRequest;
use App\Http\Requests\Imports\UploadContactImportRequest;
use App\Models\DataTransfer;
use App\Models\User;
use App\Services\Imports\ContactImportAnalyzer;
use App\Services\Imports\ContactImporter;
use App\Services\Imports\ContactImportReport;
use App\Services\Imports\ImportBatchRollback;
use App\Services\Migration\GenericContactsAdapter;
use App\Services\Migration\MigrationSourceAdapter;
use App\Services\Migration\MigrationSourceRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Contacts importer and migration wizard, reserved to the
 * Superadministrador (contacts.import): source and upload → mapping and
 * validation preview → result, plus the batch history and the logical
 * rollback of a batch (preview, then confirmation). See ContactImporter and
 * ImportBatchRollback.
 *
 * @phpstan-import-type AnalyzedRow from ContactImportAnalyzer
 */
class ContactImportController extends Controller
{
    /**
     * Upload step, with the actor's recent imports.
     */
    public function create(Request $request, MigrationSourceRegistry $sources, ImportBatchRollback $rollback): Response
    {
        Gate::authorize('createImport', DataTransfer::class);

        /** @var User $actor */
        $actor = $request->user();

        return Inertia::render('contacts/Import', [
            'step' => 'upload',
            'transfer' => null,
            'preview' => null,
            'sources' => $sources->options(),
            'recent' => DataTransfer::query()
                ->ownedBy($actor)
                ->where('type', DataTransferType::Import)
                ->latest()
                ->orderByDesc('id')
                ->limit(20)
                ->get()
                ->map(fn (DataTransfer $transfer): array => $this->present($transfer, $sources, $rollback))
                ->values(),
            ...$this->limits(),
        ]);
    }

    public function template(ContactImportReport $report): BinaryFileResponse
    {
        Gate::authorize('createImport', DataTransfer::class);

        $path = storage_path('framework/cache/plantilla-'.Str::uuid()->toString().'.xlsx');
        $report->template($path);

        return response()
            ->download($path, 'plantilla-importacion-contactos.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
            ->deleteFileAfterSend();
    }

    public function store(UploadContactImportRequest $request, ContactImporter $importer): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $transfer = $importer->upload($request->file('file'), $actor, $request->string('source', GenericContactsAdapter::KEY)->value() ?: GenericContactsAdapter::KEY);

        return to_route('contacts.import.show', $transfer);
    }

    /**
     * Mapping and preview while the import is a draft; progress and result
     * once it is confirmed.
     */
    public function show(Request $request, DataTransfer $transfer, ContactImporter $importer, MigrationSourceRegistry $sources, ImportBatchRollback $rollback): Response
    {
        Gate::authorize('manageImport', $transfer);

        /** @var User $actor */
        $actor = $request->user();
        $isDraft = $transfer->status === DataTransferStatus::Draft;
        $previous = $isDraft ? $importer->previousImportOf($transfer) : null;
        $headers = $importer->headers($transfer);
        $source = $importer->source($transfer);

        return Inertia::render('contacts/Import', [
            'step' => $isDraft ? 'mapping' : 'result',
            'transfer' => $this->present($transfer, $sources, $rollback),
            'sources' => $sources->options(),
            'source' => [
                'current' => $source->key(),
                'detection' => array_map(fn (array $result): array => [
                    'key' => $result['key'],
                    'label' => $result['label'],
                    'score' => $result['score'],
                    'signals' => $result['signals'],
                ], $isDraft ? $sources->detect($headers) : []),
                'suggested_mappings' => $isDraft
                    ? array_map(fn (MigrationSourceAdapter $adapter): array => $adapter->suggestMapping($headers), $sources->all())
                    : (object) [],
            ],
            'rollbackPreview' => Inertia::optional(fn (): ?array => $isDraft || $rollback->unavailableReason($transfer) !== null ? null : $rollback->preview($transfer)),
            'rollbackKept' => $isDraft || $rollback->state($transfer) === null ? [] : $rollback->handledNotReverted($transfer, (int) config('imports.preview_rows', 50)),
            'preview' => $isDraft ? fn (): array => $this->previewPayload($importer->preview($transfer, $actor)) : null,
            'mapping' => [
                'headers' => $importer->headers($transfer),
                'fields' => array_map(fn (string $value, string $label): array => ['value' => $value, 'label' => $label], array_keys(ContactImportAnalyzer::FIELDS), ContactImportAnalyzer::FIELDS),
                'current' => $importer->mapping($transfer),
                'decision' => (string) ($transfer->options['decision'] ?? ContactImporter::DECISION_SKIP),
                'decisions' => array_map(fn (string $value, string $label): array => ['value' => $value, 'label' => $label], array_keys(ContactImporter::DECISIONS), ContactImporter::DECISIONS),
            ],
            'previousImport' => $previous === null ? null : [
                'finished_at' => $previous->finished_at?->toIso8601String(),
                'summary' => $previous->summary,
            ],
            'recent' => [],
            ...$this->limits(),
        ]);
    }

    public function update(ConfigureContactImportRequest $request, DataTransfer $transfer, ContactImporter $importer): RedirectResponse
    {
        $source = $request->input('source');

        $importer->configure($transfer, $request->mapping(), $request->string('decision')->value(), is_string($source) && $source !== '' ? $source : null);

        return to_route('contacts.import.show', $transfer);
    }

    public function confirm(Request $request, DataTransfer $transfer, ContactImporter $importer): RedirectResponse
    {
        Gate::authorize('manageImport', $transfer);

        /** @var User $actor */
        $actor = $request->user();

        $importer->confirm($transfer, $actor);

        Inertia::flash('toast', ['type' => 'info', 'message' => 'Importación en proceso. Puedes seguir trabajando; el resultado aparecerá aquí.']);

        return to_route('contacts.import.show', $transfer);
    }

    public function destroy(DataTransfer $transfer, ContactImporter $importer): RedirectResponse
    {
        Gate::authorize('manageImport', $transfer);

        $importer->discard($transfer);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Importación cancelada. El archivo se eliminó.']);

        return to_route('contacts.import.create');
    }

    /**
     * Queue the logical rollback of a finished batch, once the user has
     * seen its preview and confirmed it.
     */
    public function rollback(RollbackContactImportRequest $request, DataTransfer $transfer, ImportBatchRollback $rollback): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $rollback->request($transfer, $actor);

        Inertia::flash('toast', ['type' => 'info', 'message' => 'Reversión en proceso. Al terminar verás qué se revirtió y qué se conservó.']);

        return to_route('contacts.import.show', $transfer);
    }

    public function report(DataTransfer $transfer): StreamedResponse
    {
        Gate::authorize('manageImport', $transfer);

        abort_unless($transfer->isDownloadable(), 410, 'El reporte de esta importación ya no está disponible.');

        return Storage::disk((string) $transfer->disk)->download(
            (string) $transfer->path,
            $transfer->file_name ?? 'resultado-importacion-contactos.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function present(DataTransfer $transfer, MigrationSourceRegistry $sources, ImportBatchRollback $rollback): array
    {
        $source = $sources->resolve(is_string($transfer->options['source'] ?? null) ? $transfer->options['source'] : null);
        $state = $rollback->state($transfer);
        $unavailable = $transfer->status === DataTransferStatus::Draft ? null : $rollback->unavailableReason($transfer);

        return [
            'id' => $transfer->id,
            'status' => ['value' => $transfer->status->value, 'label' => $transfer->status->label()],
            'file' => $transfer->options['original_name'] ?? null,
            'rows_total' => $transfer->rows_total,
            'rows_processed' => $transfer->rows_processed,
            'rows_failed' => $transfer->rows_failed,
            'summary' => $transfer->summary,
            'error' => $transfer->error,
            'created_at' => $transfer->created_at?->toIso8601String(),
            'finished_at' => $transfer->finished_at?->toIso8601String(),
            'expires_at' => $transfer->expires_at?->toIso8601String(),
            'report_url' => $transfer->isDownloadable() ? route('contacts.import.report', $transfer) : null,
            'url' => route('contacts.import.show', $transfer),
            'source' => ['value' => $source->key(), 'label' => $source->label(), 'validated' => $source->isValidated()],
            'rollback' => [
                'status' => $state['status'] ?? null,
                'requested_at' => $state['requested_at'] ?? null,
                'finished_at' => $state['finished_at'] ?? null,
                'summary' => $state['summary'] ?? null,
                'error' => $state['error'] ?? null,
                'available' => $transfer->status !== DataTransferStatus::Draft && $unavailable === null,
                'unavailable_reason' => $unavailable,
            ],
        ];
    }

    /**
     * The summary and a bounded sample: the first rows and the first rows
     * that need attention.
     *
     * @param  array{rows: list<AnalyzedRow>, summary: array<string, int>, mapping_errors: list<string>}  $preview
     * @return array<string, mixed>
     */
    private function previewPayload(array $preview): array
    {
        $limit = (int) config('imports.preview_rows', 50);
        $shape = fn (array $row): array => [
            'line' => $row['line'],
            'status' => $row['status'],
            'name' => $row['data']['name'],
            'phone' => $row['data']['phone_e164'] ?? $row['values']['phone'],
            'email' => $row['data']['email'] ?? $row['values']['email'],
            'tags' => $row['data']['tag_names'],
            'notes' => $row['data']['notes'] === null ? null : Str::limit($row['data']['notes'], 120),
            'errors' => $row['errors'],
            'warnings' => $row['warnings'],
            'duplicate_of_line' => $row['duplicate_of_line'],
            'existing' => $row['existing'],
        ];

        $issues = array_filter($preview['rows'], fn (array $row): bool => $row['status'] !== 'valid' || $row['warnings'] !== [] || $row['existing'] !== []);

        return [
            'summary' => $preview['summary'],
            'mapping_errors' => $preview['mapping_errors'],
            'rows' => array_map($shape, array_slice($preview['rows'], 0, $limit)),
            'issues' => array_map($shape, array_slice($issues, 0, $limit)),
            'limit' => $limit,
        ];
    }

    /**
     * @return array{limits: array{max_megabytes: float, max_rows: int, max_columns: int}}
     */
    private function limits(): array
    {
        return [
            'limits' => [
                'max_megabytes' => round((int) config('imports.max_kilobytes', 5120) / 1024, 1),
                'max_rows' => (int) config('imports.max_rows', 5000),
                'max_columns' => (int) config('imports.max_columns', 30),
            ],
        ];
    }
}
