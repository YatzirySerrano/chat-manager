<?php

namespace App\Http\Controllers\VideoExports;

use App\Actions\VideoExports\RequestVideoExport;
use App\Enums\VideoExportStatus;
use App\Exceptions\StorageUnavailable;
use App\Http\Controllers\Controller;
use App\Http\Requests\VideoExports\SaveVideoRequest;
use App\Jobs\SaveVideoToNas;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\TeamChat\TeamAttachment;
use App\Models\TeamChat\TeamMessage;
use App\Models\TeamChat\TeamThread;
use App\Models\VideoExport;
use App\Services\VideoExports\VideoExports;
use App\Services\VideoExports\VideoFolders;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * «Guardar archivo» (born as «Guardar video»): JSON endpoints of the save
 * button of every attachment (videos, images, PDFs, documents, audios), in
 * customer conversations and in the internal chat, and of its folder
 * browser over the NAS. Every route requires videos.save-to-nas; saving and
 * reading a copy also require that the user can see the chat of the file
 * (the same policies as the chat and its files: 404 otherwise, as if the
 * file did not exist). With the NAS down the folder routes answer 503 with
 * the reason in Spanish.
 */
class VideoExportController extends Controller
{
    public function __construct(private VideoExports $videoExports, private VideoFolders $folders) {}

    /**
     * The sub folders of a folder (`path`, relative to the root). Without
     * `path` the browser opens where the user saved their last video (the
     * root when that folder no longer exists).
     */
    public function folders(Request $request): JsonResponse
    {
        $this->ensurePermitted($request);

        return $this->onNas(function () use ($request): JsonResponse {
            if ($request->has('path')) {
                $segments = VideoFolders::segments((string) $request->query('path'), 'path');
            } else {
                $segments = $this->lastFolder($request);
            }

            return response()->json($this->listing($segments));
        });
    }

    /**
     * Creates a folder inside `path` and answers the listing of the new folder.
     */
    public function storeFolder(Request $request): JsonResponse
    {
        $this->ensurePermitted($request);

        $request->validate([
            'path' => ['present', 'nullable', 'string', 'max:700'],
            'name' => ['required', 'string', 'max:80'],
        ], [], ['name' => 'nombre de la carpeta']);

        return $this->onNas(function () use ($request): JsonResponse {
            $parent = VideoFolders::segments((string) $request->input('path'), 'path');
            $name = $this->folders->create($parent, (string) $request->input('name'));

            return response()->json($this->listing([...$parent, $name]), 201);
        });
    }

    public function storeForMessage(SaveVideoRequest $request, RequestVideoExport $requestExport, Conversation $conversation, Message $message, MessageAttachment $attachment): JsonResponse
    {
        abort_unless(Gate::allows('view', $conversation), 404);
        abort_unless($message->conversation_id === $conversation->id && $attachment->message_id === $message->id, 404);
        abort_unless($this->videoExports->isSavableMessageAttachment($attachment), 422, 'Solo se pueden guardar archivos disponibles.');

        return $this->onNas(fn (): JsonResponse => $this->save($request, $requestExport, $attachment, $conversation));
    }

    public function storeForTeam(SaveVideoRequest $request, RequestVideoExport $requestExport, TeamThread $thread, TeamMessage $message, TeamAttachment $attachment): JsonResponse
    {
        abort_unless(Gate::allows('view', $thread), 404);
        abort_unless($message->team_thread_id === $thread->id && $attachment->team_message_id === $message->id && ! $message->isDeleted(), 404);
        abort_unless($this->videoExports->isSavableTeamAttachment($attachment), 422, 'Solo se pueden guardar archivos disponibles.');

        return $this->onNas(fn (): JsonResponse => $this->save($request, $requestExport, $attachment, $thread));
    }

    /**
     * The current state of a copy (the bubble polls it while it is on its way).
     */
    public function show(Request $request, VideoExport $export): JsonResponse
    {
        $this->ensureReachable($request, $export);

        return response()->json(['export' => $this->videoExports->present($export)]);
    }

    /**
     * Queues a failed copy again.
     */
    public function retry(Request $request, VideoExport $export): JsonResponse
    {
        $this->ensureReachable($request, $export);
        abort_unless($export->status === VideoExportStatus::Failed, 409, 'Este archivo no tiene un error por reintentar.');

        $export->forceFill(['status' => VideoExportStatus::Queued, 'error' => null, 'attempts' => 0])->save();
        SaveVideoToNas::dispatch($export->id);

        return response()->json(['export' => $this->videoExports->present($export)]);
    }

    private function save(SaveVideoRequest $request, RequestVideoExport $requestExport, MessageAttachment|TeamAttachment $attachment, Conversation|TeamThread $chat): JsonResponse
    {
        $folder = $request->folder();

        // The NAS must be up and the folder must exist before the copy is queued.
        $this->folders->ready();
        $this->folders->assertExists($folder);

        // A typed extension of this same file is dropped (the copy keeps the original's).
        $fileName = $request->fileName($this->videoExports->extensionOf($attachment));
        $fileName = $fileName !== '' ? $fileName : $request->fileName();

        $export = $requestExport->handle($request->user(), $attachment, $chat, $folder, $fileName);

        return response()->json(['export' => $this->videoExports->present($export)], 201);
    }

    /**
     * @param  list<string>  $segments
     * @return array{root: string, path: string, segments: list<string>, folders: list<string>}
     */
    private function listing(array $segments): array
    {
        return [
            'root' => $this->folders->root(),
            'path' => implode('/', $segments),
            'segments' => $segments,
            'folders' => $this->folders->list($segments),
        ];
    }

    /**
     * @return list<string>
     */
    private function lastFolder(Request $request): array
    {
        try {
            $segments = VideoFolders::segments($this->videoExports->lastFolder($request->user()));
        } catch (ValidationException) {
            return [];
        }

        return $segments !== [] && $this->folders->exists($segments) ? $segments : [];
    }

    /**
     * Runs an operation on the NAS; a NAS that is down answers 503 with the reason.
     *
     * @param  Closure(): JsonResponse  $operation
     */
    private function onNas(Closure $operation): JsonResponse
    {
        try {
            $this->folders->ready();

            return $operation();
        } catch (StorageUnavailable $exception) {
            return response()->json(['message' => VideoFolders::unavailableMessage($exception), 'code' => StorageUnavailable::CODE], 503);
        }
    }

    private function ensurePermitted(Request $request): void
    {
        abort_unless($this->videoExports->permits($request->user()), 403);
    }

    private function ensureReachable(Request $request, VideoExport $export): void
    {
        $this->ensurePermitted($request);
        abort_unless($this->videoExports->canAccess($request->user(), $export), 404);
    }
}
