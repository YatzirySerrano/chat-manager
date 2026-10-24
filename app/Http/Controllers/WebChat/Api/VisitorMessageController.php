<?php

namespace App\Http\Controllers\WebChat\Api;

use App\Actions\WebChat\ReceiveVisitorMessage;
use App\Enums\MessageDirection;
use App\Http\Controllers\Controller;
use App\Http\Requests\WebChat\ListVisitorMessagesRequest;
use App\Http\Requests\WebChat\StoreVisitorMessageRequest;
use App\Http\Resources\WebChat\VisitorMessageResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WebChatSession;
use App\Services\WebChat\VisitorCursor;
use App\Services\WebChat\VisitorSessions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The visitor's conversation history and the messages they write.
 *
 * History is read with server-issued cursors: without one, the latest
 * page; with "before", older messages; with "after", what arrived since
 * (the reconnection and polling path). An agent reply that became visible
 * late (queued before, delivered after a later visitor message) is
 * returned again by "after", so the widget deduplicates by cursor. The
 * number of queries is constant, whatever the page size. An ETag over the
 * page and the conversation state lets polling answer 304.
 */
class VisitorMessageController extends Controller
{
    public function __construct(
        private VisitorSessions $sessions,
        private VisitorCursor $cursors,
    ) {}

    public function index(ListVisitorMessagesRequest $request): JsonResponse|Response
    {
        $session = $this->session($request);
        $limit = $request->limit();
        $after = $request->afterCursor();
        $before = $request->beforeCursor();

        $query = $this->sessions->visibleMessages($session)->with(['sender:id,name', 'attachments']);
        $hasOlder = false;

        if ($after !== null) {
            $anchor = Message::query()->whereKey($after)->value('created_at');

            $messages = $query
                ->where(fn (Builder $since) => $since
                    ->where('id', '>', $after)
                    ->when($anchor !== null, fn (Builder $late) => $late->orWhere(fn (Builder $outbound) => $outbound
                        ->where('direction', MessageDirection::Outbound)
                        ->where('id', '<', $after)
                        ->where('accepted_at', '>=', $anchor))))
                ->orderBy('id')
                ->limit($limit)
                ->get();
        } else {
            $messages = $query
                ->when($before !== null, fn (Builder $older) => $older->where('id', '<', $before))
                ->orderByDesc('id')
                ->limit($limit + 1)
                ->get();

            $hasOlder = $messages->count() > $limit;
            $messages = $messages->take($limit)->reverse()->values();
        }

        /** @var Collection<int, Message> $messages */
        $last = $messages->max('id');
        $first = $messages->min('id');
        $nextCursor = $last !== null ? $this->cursors->encode((int) $last) : $request->query('after');

        $etag = '"'.sha1(implode('|', [
            $messages->pluck('id')->implode(','),
            $this->conversationState($session),
        ])).'"';

        if ($request->headers->get('If-None-Match') === $etag) {
            return response()->noContent(304)->setEtag(trim($etag, '"'));
        }

        return VisitorMessageResource::collection($messages)
            ->additional(['meta' => [
                'next_cursor' => is_string($nextCursor) ? $nextCursor : null,
                'prev_cursor' => $hasOlder && $first !== null ? $this->cursors->encode((int) $first) : null,
                'limit' => $limit,
            ]])
            ->response()
            ->setEtag(trim($etag, '"'));
    }

    public function store(StoreVisitorMessageRequest $request, ReceiveVisitorMessage $receive): JsonResponse
    {
        $session = $this->session($request);

        ['message' => $message, 'created' => $created] = $receive->handle(
            $session,
            (string) $request->validated('client_message_id'),
            (string) $request->validated('body'),
        );

        $message->load(['sender:id,name', 'attachments']);

        return (new VisitorMessageResource($message))->response()->setStatusCode($created ? 201 : 200);
    }

    /**
     * Status and attending agent of the latest conversation: a change
     * (accepted, closed) must invalidate the polling ETag.
     */
    private function conversationState(WebChatSession $session): string
    {
        if ($session->contact_id === null) {
            return 'none';
        }

        $conversation = Conversation::query()
            ->where('connection_id', $session->connection_id)
            ->where('contact_id', $session->contact_id)
            ->orderByDesc('id')
            ->first(['id', 'status', 'assigned_user_id']);

        return $conversation === null ? 'none' : $conversation->id.':'.$conversation->status->value.':'.$conversation->assigned_user_id;
    }

    private function session(Request $request): WebChatSession
    {
        /** @var WebChatSession $session */
        $session = $request->attributes->get('webchat_session');

        return $session;
    }
}
