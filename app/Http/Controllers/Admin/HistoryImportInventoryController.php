<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\HistoryImportBulkRequest;
use App\Http\Requests\Admin\HistoryImportFilters;
use App\Http\Requests\Admin\HistoryImportRowRequest;
use App\Models\DataTransfer;
use App\Models\HistoryImportConversation;
use App\Models\HistoryImportMessage;
use App\Models\User;
use App\Policies\HistoryImportPolicy;
use App\Services\Migration\History\HistoryClassifier;
use App\Services\Migration\History\HistoryImportSummary;
use App\Services\Migration\History\HistoryInventory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The inventory of a batch (JSON for the classification screen):
 * paginated and filtered list, one conversation with a preview of its
 * first messages, its decision, and bulk decisions.
 */
class HistoryImportInventoryController extends Controller
{
    /** Messages shown in the preview of one conversation. */
    private const PREVIEW_MESSAGES = 20;

    public function __construct(private HistoryImportPolicy $policy) {}

    public function index(Request $request, DataTransfer $transfer, HistoryInventory $inventory): JsonResponse
    {
        $this->policy->view($request->user(), $transfer)->authorize();

        $validated = $request->validate([
            ...HistoryImportFilters::rules(),
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:200'],
        ]);

        $page = $inventory->query($transfer, HistoryImportFilters::normalize($validated))
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? config('history-import.per_page', 50)));

        return response()->json([
            'data' => collect($page->items())->map(fn (HistoryImportConversation $row): array => $inventory->present($row))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(Request $request, DataTransfer $transfer, HistoryImportConversation $row, HistoryInventory $inventory): JsonResponse
    {
        $this->policy->view($request->user(), $transfer)->authorize();
        abort_unless($row->data_transfer_id === $transfer->id, 404);

        $messages = HistoryImportMessage::query()
            ->where('history_import_conversation_id', $row->id)
            ->where('is_system', false)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->limit(self::PREVIEW_MESSAGES)
            ->get(['id', 'occurred_at', 'from_me', 'sender_name', 'type', 'body', 'media_name', 'media_available']);

        return response()->json([
            'conversation' => $inventory->present($row),
            'preview' => $messages->map(fn (HistoryImportMessage $message): array => [
                'id' => $message->id,
                'at' => $message->occurred_at?->toIso8601String(),
                'from_me' => $message->from_me,
                'sender' => $message->sender_name,
                'type' => $message->type,
                'body' => $message->body,
                'file' => $message->media_name === null ? null : ['name' => $message->media_name, 'available' => $message->media_available],
            ])->values(),
            'preview_limit' => self::PREVIEW_MESSAGES,
        ]);
    }

    public function update(HistoryImportRowRequest $request, DataTransfer $transfer, HistoryImportConversation $row, HistoryClassifier $classifier, HistoryInventory $inventory, HistoryImportSummary $summary): JsonResponse
    {
        abort_unless($row->data_transfer_id === $transfer->id, 404);

        /** @var User $actor */
        $actor = $request->user();

        $row = $classifier->update($transfer, $row, $request->changes(), $actor);

        return response()->json(['conversation' => $inventory->present($row), 'summary' => $summary->for($transfer)]);
    }

    public function bulk(HistoryImportBulkRequest $request, DataTransfer $transfer, HistoryClassifier $classifier, HistoryImportSummary $summary): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $result = $classifier->bulk($transfer, $request->hasDecision(), $request->decision(), $request->ids(), $request->filters(), $actor, $request->saveContact());

        return response()->json(['result' => $result, 'summary' => $summary->for($transfer)]);
    }
}
