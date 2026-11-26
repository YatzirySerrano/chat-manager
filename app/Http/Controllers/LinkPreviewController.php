<?php

namespace App\Http\Controllers;

use App\Enums\TeamChat\TeamMessageKind;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\LinkPreview;
use App\Models\Message;
use App\Models\TeamChat\TeamMessage;
use App\Models\TeamChat\TeamThread;
use App\Services\LinkPreviews\LinkPreviews;
use App\Support\Attachments\MediaResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The preview card of the first web address written in a message the
 * viewer may see: a customer message or reply, an internal note, or an
 * internal chat message. The address is never taken from the request: the
 * server reads it from the message itself after authorizing the viewer
 * exactly like the chat does (404 for anything outside their reach), so
 * nobody can make the server open an arbitrary address. The image and the
 * icon are served from the media disk through a signed address handed out
 * only in these answers.
 */
class LinkPreviewController extends Controller
{
    public function message(Conversation $conversation, Message $message, LinkPreviews $previews): JsonResponse
    {
        abort_unless(Gate::allows('view', $conversation) && $message->conversation_id === $conversation->id, 404);

        return $this->answer($message->revoked_at === null ? $message->body : null, $previews);
    }

    public function note(Conversation $conversation, ConversationNote $note, LinkPreviews $previews): JsonResponse
    {
        abort_unless(Gate::allows('view', $conversation) && $note->conversation_id === $conversation->id, 404);

        return $this->answer($note->body, $previews);
    }

    public function teamMessage(TeamThread $thread, TeamMessage $message, LinkPreviews $previews): JsonResponse
    {
        Gate::authorize('view', $thread);

        abort_unless($message->team_thread_id === $thread->id, 404);

        return $this->answer($message->isDeleted() || $message->kind === TeamMessageKind::System ? null : $message->body, $previews);
    }

    /**
     * The kept image or icon of a preview (signed address: whoever has it
     * was shown the preview by one of the routes above).
     */
    public function image(Request $request, LinkPreview $linkPreview, string $variant): Response
    {
        [$disk, $path, $mime] = $variant === 'icon'
            ? [$linkPreview->icon_disk, $linkPreview->icon_path, $linkPreview->icon_mime]
            : [$linkPreview->image_disk, $linkPreview->image_path, $linkPreview->image_mime];

        abort_if($disk === null || $path === null || $mime === null || ! in_array($mime, LinkPreviews::ICON_TYPES, true), 404);

        $location = MediaResponse::open($disk, $path);

        try {
            $contents = $location === null ? null : $location['filesystem']->get($location['path']);
        } catch (Throwable $exception) {
            Log::warning('Media storage unavailable while serving a link preview image.', ['link_preview_id' => $linkPreview->id, 'exception' => $exception::class]);

            abort(503);
        }

        abort_if($contents === null, 404);

        $etag = '"'.sha1($linkPreview->id.'|'.$path.'|'.$linkPreview->fetched_at?->getTimestamp()).'"';

        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304, ['ETag' => $etag, 'Cache-Control' => 'private, max-age=604800']);
        }

        return response($contents, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, max-age=604800',
            'ETag' => $etag,
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; sandbox",
        ]);
    }

    private function answer(?string $text, LinkPreviews $previews): JsonResponse
    {
        $url = LinkPreviews::firstUrl($text);

        return response()->json([
            'preview' => $url === null ? null : LinkPreviews::present($previews->forUrl($url)),
        ]);
    }
}
