<?php

namespace App\Http\Controllers\Admin;

use App\Concerns\ServesStoredAttachments;
use App\Enums\AttachmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\HistoryImportArchiveRequest;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\DataTransfer;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Policies\HistoryImportPolicy;
use App\Services\Conversations\ConversationTimeline;
use App\Services\Migration\History\HistoryArchive;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * @phpstan-import-type ArchiveFilters from HistoryArchive
 *
 * Read-only consultation of the history imported from Whaticket (admin
 * area, history-import.archive-view): search, the timeline of one
 * conversation (ConversationTimeline, the same chat the inbox renders,
 * with each message marked as imported) and its files. Nothing here can
 * reply, reopen, assign or change anything.
 */
class HistoryImportArchiveController extends Controller
{
    use ServesStoredAttachments;

    private const PER_PAGE = 25;

    /**
     * Files that may be shown inline (anything else is downloaded).
     */
    private const INLINE_PREFIXES = ['image/', 'audio/', 'video/'];

    public function __construct(private HistoryImportPolicy $policy) {}

    public function index(HistoryImportArchiveRequest $request, HistoryArchive $archive): Response
    {
        /** @var User $viewer */
        $viewer = $request->user();

        return Inertia::render('admin/history/Index', [
            'filters' => (object) $request->filters(),
            'conversations' => fn (): array => $this->page($archive, $request->filters(), $viewer),
            'options' => fn (): array => [
                ...array_intersect_key(HistoryImportController::options(), array_flip(['departments', 'tags'])),
                // Root accounts never appear to whoever may not see them.
                'agents' => array_values(User::query()->notDeleted()->withoutProtectedFor($viewer)->orderBy('name')->limit(1000)->get(['id', 'name'])
                    ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])->all()),
                'connections' => Connection::query()->whereIn('id', Conversation::query()->imported()->select('connection_id'))->orderBy('name')->get(['id', 'name'])
                    ->map(fn (Connection $connection): array => ['id' => $connection->id, 'name' => $connection->name])->values()->all(),
                'batches' => DataTransfer::query()->whereIn('id', Conversation::query()->imported()->select('imported_by_transfer_id'))->orderByDesc('id')->get(['id', 'options', 'finished_at'])
                    ->map(fn (DataTransfer $batch): array => ['id' => $batch->id, 'label' => $batch->options['label'] ?? null, 'finished_at' => $batch->finished_at?->toIso8601String()])->values()->all(),
                'destinations' => [
                    ['value' => HistoryArchive::DESTINATION_ARCHIVE, 'label' => 'Archivadas como historial'],
                    ['value' => HistoryArchive::DESTINATION_IMPORT, 'label' => 'Importadas como historial operativo'],
                    ['value' => HistoryArchive::DESTINATION_ALL, 'label' => 'Todas las importadas'],
                ],
            ],
        ]);
    }

    public function list(HistoryImportArchiveRequest $request, HistoryArchive $archive): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $request->user();

        return response()->json($this->page($archive, $request->filters(), $viewer));
    }

    /**
     * The imported conversations of one contact (for an explicit "Ver
     * historial de Whaticket" link from the contact or a live conversation).
     */
    public function contact(Request $request, int $contact, HistoryArchive $archive): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $request->user();

        // Same 404 for a missing contact and for a viewer without the permission: ids reveal nothing.
        abort_unless($this->policy->viewArchive($viewer)->allowed() && Contact::query()->whereKey($contact)->exists(), 404);

        return response()->json($this->page($archive, ['destination' => HistoryArchive::DESTINATION_ALL, 'contact_id' => $contact], $viewer));
    }

    public function show(Request $request, Conversation $conversation, HistoryArchive $archive, ConversationTimeline $timeline): Response
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->policy->viewArchived($actor, $conversation)->authorize();

        $conversation->load(['contact:id,name,phone_e164,saved_at', 'department:id,name', 'assignee:id,name', 'channelConnection:id,name,channel,deleted_at', 'tags:id,name'])->loadCount('messages');

        return Inertia::render('admin/history/Show', [
            'conversation' => $archive->present($conversation, $actor),
            'timeline' => $this->decorate($conversation, $timeline->page($conversation, $request)),
        ]);
    }

    /**
     * Older pages of the timeline (?before=<message id>&until=<cursor>).
     */
    public function messages(Request $request, Conversation $conversation, ConversationTimeline $timeline): JsonResponse
    {
        $this->policy->viewArchived($request->user(), $conversation)->authorize();

        $validated = $request->validate([
            'before' => ['nullable', 'integer', 'min:1'],
            'until' => ['nullable', 'string', 'max:64'],
        ]);

        return response()->json($this->decorate($conversation, $timeline->page(
            $conversation,
            $request,
            isset($validated['before']) ? (int) $validated['before'] : null,
            $validated['until'] ?? null,
        )));
    }

    public function attachment(Request $request, Conversation $conversation, MessageAttachment $attachment): HttpResponse
    {
        $this->policy->viewArchived($request->user(), $conversation)->authorize();

        $belongs = Message::query()->whereKey($attachment->message_id)->where('conversation_id', $conversation->id)->exists();
        abort_unless($belongs, 404);
        abort_unless($attachment->status === AttachmentStatus::Available && is_string($attachment->path) && $attachment->path !== '', 404, 'Archivo histórico no disponible.');

        $disk = Storage::disk($attachment->disk);
        abort_unless($this->storedFileExists($disk, $attachment), 404, 'Archivo histórico no disponible.');

        $mime = strtolower($attachment->mime_type);
        $inline = ! $request->boolean('download') && Str::startsWith($mime, self::INLINE_PREFIXES);
        $name = $attachment->file_name ?? 'archivo';
        $fallback = str_replace(['%', '/', '\\'], '', Str::ascii($name)) ?: 'archivo';

        return $this->storedFileResponse($attachment, fn (): HttpResponse => $disk->response((string) $attachment->path, $name, [
            'Content-Type' => $inline ? $mime : 'application/octet-stream',
            'Content-Disposition' => HeaderUtils::makeDisposition($inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT, $name, $fallback),
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'private, no-cache',
        ], $inline ? 'inline' : 'attachment'));
    }

    /**
     * @param  ArchiveFilters  $filters
     * @return array{data: list<array<string, mixed>>, meta: array{current_page: int, last_page: int, per_page: int, total: int}}
     */
    private function page(HistoryArchive $archive, array $filters, User $viewer): array
    {
        $page = $archive->query($filters, $viewer)->paginate(self::PER_PAGE);
        $details = $archive->sourceDetails(array_values(array_map(fn (Conversation $conversation): int => $conversation->id, $page->items())));

        return [
            'data' => array_values(array_map(fn (Conversation $conversation): array => $archive->present($conversation, $viewer, $details[$conversation->id] ?? null), $page->items())),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ];
    }

    /**
     * Every message of the page marked as imported (who wrote it in
     * Whaticket, whether it came from the company), and its files served
     * by this screen instead of the inbox route.
     *
     * @param  array{messages: list<array<string, mixed>>, notes: list<array<string, mixed>>, events: list<array<string, mixed>>, has_more: bool, until?: string|null}  $page
     * @return array{messages: list<array<string, mixed>>, notes: list<array<string, mixed>>, events: list<array<string, mixed>>, has_more: bool, until?: string|null}
     */
    private function decorate(Conversation $conversation, array $page): array
    {
        $metadata = Message::query()->whereKey(array_column($page['messages'], 'id'))->pluck('metadata', 'id');

        $page['messages'] = array_map(function (array $message) use ($conversation, $metadata): array {
            $stored = $metadata[$message['id']] ?? null;
            $import = is_array($stored) && is_array($stored['import'] ?? null) ? $stored['import'] : [];
            $message['imported'] = [
                'source' => 'whaticket',
                'sender_name' => is_string($import['sender_name'] ?? null) ? $import['sender_name'] : null,
                'from_me' => ($import['from_me'] ?? false) === true,
            ];
            $message['attachments'] = array_map(function (array $attachment) use ($conversation): array {
                $url = route('admin.history.conversations.attachments.show', ['conversation' => $conversation->id, 'attachment' => $attachment['id']]);

                return [...$attachment, 'url' => $url, 'download_url' => $url.'?download=1', 'link_revoke_url' => null];
            }, (array) ($message['attachments'] ?? []));

            return $message;
        }, $page['messages']);

        return $page;
    }
}
