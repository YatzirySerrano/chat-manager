<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Conversations\QueueOutboundMessage;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Api\AuthenticateApiToken;
use App\Http\Requests\Api\V1\ListMessagesRequest;
use App\Http\Requests\Api\V1\StoreMessageRequest;
use App\Http\Resources\Api\V1\MessageResource;
use App\Models\ApiToken;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Messages of a conversation the token can reach (otherwise the uniform
 * 404). Internal notes live in another table and are never listed.
 */
class ConversationMessageController extends Controller
{
    /**
     * Oldest first. "after" returns the messages following that id and
     * "before" the latest ones preceding it; next_cursor (use as "after")
     * and prev_cursor (use as "before") are null when nothing remains in
     * that direction.
     */
    public function index(ListMessagesRequest $request, string $conversation): JsonResponse
    {
        $model = $this->conversation($request, $conversation);
        $limit = $request->limit();
        $after = $request->validated('after');
        $before = $request->validated('before');

        $query = $model->messages()->with('attachments');

        if ($before !== null) {
            $rows = $query->where('id', '<', (int) $before)->orderByDesc('id')->limit($limit + 1)->get();
            $hasOlder = $rows->count() > $limit;
            $items = $rows->take($limit)->reverse()->values();
            $hasNewer = $items->isNotEmpty() && $model->messages()->where('id', '>', $items->last()->id)->exists();
        } else {
            $rows = $query->when($after !== null, fn ($query) => $query->where('id', '>', (int) $after))->orderBy('id')->limit($limit + 1)->get();
            $hasNewer = $rows->count() > $limit;
            $items = $rows->take($limit)->values();
            $hasOlder = $items->isNotEmpty() && $after !== null && $model->messages()->where('id', '<', $items->first()->id)->exists();
        }

        /** @var Message|null $first */
        $first = $items->first();
        /** @var Message|null $last */
        $last = $items->last();

        return response()->json([
            'data' => MessageResource::collection($items)->resolve($request),
            'meta' => [
                'next_cursor' => $hasNewer && $last !== null ? (string) $last->id : null,
                'prev_cursor' => $hasOlder && $first !== null ? (string) $first->id : null,
                'limit' => $limit,
            ],
        ]);
    }

    /**
     * 201 with the queued message, or 200 with the same message when the
     * request is a retry (same Idempotency-Key and text).
     */
    public function store(StoreMessageRequest $request, string $conversation, QueueOutboundMessage $queue): JsonResponse
    {
        $model = $this->conversation($request, $conversation);

        /** @var ApiToken $token */
        $token = $request->attributes->get(AuthenticateApiToken::ATTRIBUTE);

        $result = $queue->handleForIntegration($model, $token, (string) $request->validated('text'), (string) $request->validated('idempotency_key'));

        return MessageResource::make($result['message']->load('attachments'))
            ->response($request)
            ->setStatusCode($result['created'] ? 201 : 200);
    }

    private function conversation(Request $request, string $id): Conversation
    {
        return ConversationController::reachable($request)->findOrFail((int) $id);
    }
}
