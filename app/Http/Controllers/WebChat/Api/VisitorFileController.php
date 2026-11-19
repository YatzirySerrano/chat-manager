<?php

namespace App\Http\Controllers\WebChat\Api;

use App\Actions\WebChat\ReceiveVisitorMessage;
use App\Http\Controllers\Controller;
use App\Http\Requests\WebChat\StoreVisitorFileRequest;
use App\Http\Resources\WebChat\VisitorMessageResource;
use App\Models\WebChatSession;
use Illuminate\Http\JsonResponse;

/**
 * A file the visitor sends from the widget (POST session/files, multipart),
 * idempotent by client_message_id like a text message. The answer is the
 * stored message, whose attachment the widget then shows through its signed
 * URL. A media disk that cannot keep the file answers 503 (retryable with
 * the same id); a widget that does not accept files, 409 files_disabled.
 */
class VisitorFileController extends Controller
{
    public function store(StoreVisitorFileRequest $request, ReceiveVisitorMessage $receive): JsonResponse
    {
        /** @var WebChatSession $session */
        $session = $request->attributes->get('webchat_session');

        ['message' => $message, 'created' => $created] = $receive->handleFile(
            $session,
            (string) $request->validated('client_message_id'),
            $request->uploadedFile(),
            $request->inspected(),
            $request->caption(),
        );

        $message->load(['sender:id,name,avatar_disk,avatar_path,avatar_version', 'attachments']);

        return (new VisitorMessageResource($message))->response()->setStatusCode($created ? 201 : 200);
    }
}
