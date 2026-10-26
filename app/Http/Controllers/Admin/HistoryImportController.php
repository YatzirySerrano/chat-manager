<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DataTransferType;
use App\Enums\HistoryImportDecision;
use App\Enums\HistoryImportFileKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\HistoryImportBatchRequest;
use App\Http\Requests\Admin\HistoryImportExecuteRequest;
use App\Http\Requests\Admin\HistoryImportMappingsRequest;
use App\Models\Connection;
use App\Models\DataTransfer;
use App\Models\Department;
use App\Models\Tag;
use App\Models\User;
use App\Policies\HistoryImportPolicy;
use App\Services\Migration\History\HistoryFileStore;
use App\Services\Migration\History\HistoryImportBatches;
use App\Services\Migration\History\HistoryImportReport;
use App\Services\Migration\History\HistoryImportSummary;
use App\Services\Migration\History\HistoryInventory;
use App\Services\PlatformSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Whaticket history importer (admin area): batches, their summary,
 * mappings, confirmation, resume and report. Files, inventory and rules
 * have their own controllers. Reserved to the root authority through the
 * history-import.* permissions (HistoryImportPolicy).
 */
class HistoryImportController extends Controller
{
    public function __construct(private HistoryImportPolicy $policy) {}

    public function index(Request $request, HistoryImportBatches $batches): Response
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->policy->viewAny($actor)->authorize();

        return Inertia::render('admin/history-import/Index', [
            'batches' => DataTransfer::query()
                ->where('type', DataTransferType::HistoryImport)
                ->with('user:id,name')
                ->latest()
                ->orderByDesc('id')
                ->limit(50)
                ->get()
                ->map(function (DataTransfer $batch) use ($batches): array {
                    $presented = $batches->present($batch);

                    return [...array_diff_key($presented, ['files' => true]), 'files_count' => count($presented['files'])];
                })
                ->values(),
            'can' => [
                'create' => $this->policy->create($actor)->allowed(),
                'archive' => $this->policy->viewArchive($actor)->allowed(),
            ],
            'defaults' => [
                'timezone' => (string) app(PlatformSettings::class)->get('general.timezone', 'America/Mexico_City'),
                'source_instance' => (string) config('history-import.default_source_instance', 'whaticket'),
            ],
            'formatNote' => self::formatNote(),
        ]);
    }

    public function store(HistoryImportBatchRequest $request, HistoryImportBatches $batches): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $batch = $batches->create($actor, $request->input('source_instance'), $request->input('timezone'), $request->input('label'));

        return to_route('admin.history-import.show', $batch);
    }

    public function show(Request $request, DataTransfer $transfer, HistoryImportBatches $batches, HistoryInventory $inventory, HistoryImportSummary $summary): Response
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->policy->view($actor, $transfer)->authorize();

        return Inertia::render('admin/history-import/Show', [
            'batch' => $batches->present($transfer),
            'summary' => fn (): array => $summary->for($transfer),
            'facets' => fn (): array => $inventory->facets($transfer),
            'mappings' => fn (): array => $batches->mappingRows($transfer),
            'rules' => fn (): array => HistoryImportRuleController::presentAll($transfer),
            'options' => fn (): array => self::options(),
            'can' => [
                'classify' => $this->policy->classify($actor, $transfer)->allowed(),
                'execute' => $this->policy->execute($actor, $transfer)->allowed(),
                'archive' => $this->policy->viewArchive($actor)->allowed(),
            ],
            'limits' => [
                'max_kilobytes' => (int) config('history-import.max_kilobytes', 512000),
                'per_page' => (int) config('history-import.per_page', 50),
            ],
            'formatNote' => self::formatNote(),
        ]);
    }

    /**
     * Progress of a batch (polled while it is read or executed).
     */
    public function progress(Request $request, DataTransfer $transfer, HistoryImportBatches $batches): JsonResponse
    {
        $this->policy->view($request->user(), $transfer)->authorize();

        return response()->json(['batch' => $batches->present($transfer->refresh())]);
    }

    public function summary(Request $request, DataTransfer $transfer, HistoryImportSummary $summary): JsonResponse
    {
        $this->policy->view($request->user(), $transfer)->authorize();

        return response()->json(['summary' => $summary->for($transfer)]);
    }

    public function mappings(HistoryImportMappingsRequest $request, DataTransfer $transfer, HistoryImportBatches $batches, HistoryImportSummary $summary): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $batches->updateMappings($transfer, $request->mappings(), $actor);

        return response()->json(['mappings' => $batches->mappingRows($transfer), 'summary' => $summary->for($transfer)]);
    }

    public function execute(HistoryImportExecuteRequest $request, DataTransfer $transfer, HistoryImportBatches $batches): RedirectResponse|JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $batches->confirm($transfer, $actor, $request->string('confirmation_token')->value());

        return $this->done($request, $transfer, $batches, 'Importación en proceso. Puedes seguir trabajando; el avance aparecerá aquí.', 202);
    }

    public function resume(Request $request, DataTransfer $transfer, HistoryImportBatches $batches): RedirectResponse|JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->policy->execute($actor, $transfer)->authorize();

        // Runs as whoever resumes it (the original confirmer may have lost the permission).
        $batches->resume($transfer, $actor);

        return $this->done($request, $transfer, $batches, 'La importación continúa. Lo que ya se importó no se repetirá.', 202);
    }

    public function destroy(Request $request, DataTransfer $transfer, HistoryImportBatches $batches, HistoryFileStore $store): RedirectResponse|JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->policy->classify($actor, $transfer)->authorize();

        $batches->discard($transfer, $actor, $store);

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['batch' => $batches->present($transfer->refresh())]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Importación cancelada. Sus archivos se eliminaron y no se importó nada.']);

        return to_route('admin.history-import.index');
    }

    public function report(Request $request, DataTransfer $transfer, HistoryImportReport $report): StreamedResponse
    {
        $this->policy->view($request->user(), $transfer)->authorize();

        return $report->download($transfer);
    }

    /**
     * Records the admin can map to.
     *
     * @return array{connections: list<array{id: int, name: string, channel: string, active: bool}>, departments: list<array{id: int, name: string}>, agents: list<array{id: int, name: string}>, tags: list<array{id: int, name: string}>, decisions: list<array{value: string, label: string}>, kinds: list<array{value: string, label: string, extensions: list<string>}>}
     */
    public static function options(): array
    {
        return [
            'connections' => array_values(Connection::query()->notDeleted()->orderBy('name')->get(['id', 'name', 'channel', 'is_active'])
                ->map(fn (Connection $connection): array => ['id' => $connection->id, 'name' => $connection->name, 'channel' => $connection->channel->value, 'active' => $connection->is_active])->all()),
            'departments' => array_values(Department::query()->notDeleted()->orderBy('name')->get(['id', 'name'])->map(fn (Department $department): array => ['id' => $department->id, 'name' => $department->name])->all()),
            'agents' => array_values(User::query()->notDeleted()->orderBy('name')->limit(1000)->get(['id', 'name'])->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])->all()),
            'tags' => array_values(Tag::query()->notDeleted()->orderBy('name')->get(['id', 'name'])->map(fn (Tag $tag): array => ['id' => $tag->id, 'name' => $tag->name])->all()),
            'decisions' => HistoryImportDecision::options(),
            'kinds' => HistoryImportFileKind::options(),
        ];
    }

    /**
     * The formats were not verified against an authentic export.
     */
    public static function formatNote(): string
    {
        return 'Formato no verificado con una exportación auténtica de Whaticket: las columnas se reconocen por su nombre y puedes corregir el mapeo de cada archivo. Revisa el inventario antes de confirmar.';
    }

    private function done(Request $request, DataTransfer $transfer, HistoryImportBatches $batches, string $message, int $status): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['batch' => $batches->present($transfer->refresh()), 'message' => $message], $status);
        }

        Inertia::flash('toast', ['type' => 'info', 'message' => $message]);

        return to_route('admin.history-import.show', $transfer);
    }
}
