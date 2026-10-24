<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Api\AuthenticateApiToken;
use App\Http\Requests\Api\V1\ListConversationsRequest;
use App\Http\Resources\Api\V1\ConversationResource;
use App\Models\ApiToken;
use App\Models\Conversation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Conversations the token can reach (its connections and, when listed, its
 * departments). There is no implicit binding: a conversation outside the
 * token's reach answers the same 404 as one that does not exist.
 */
class ConversationController extends Controller
{
    /**
     * Most recently updated first, with an opaque keyset cursor over
     * (updated_at, id); the filters only narrow the token's reach.
     */
    public function index(ListConversationsRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $limit = $request->limit();

        $query = self::reachable($request)
            ->with(self::RELATIONS)
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['connection_id'] ?? null, fn (Builder $query, int|string $id) => $query->where('connection_id', (int) $id))
            ->when($filters['department_id'] ?? null, fn (Builder $query, int|string $id) => $query->where('department_id', (int) $id))
            ->when($filters['updated_since'] ?? null, fn (Builder $query, string $since) => $query->where('updated_at', '>=', Carbon::parse($since)))
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        $page = $query->cursorPaginate($limit, ['*'], 'cursor', self::cursor($filters['cursor'] ?? null));

        return response()->json([
            'data' => ConversationResource::collection($page->items())->resolve($request),
            'meta' => [
                'next_cursor' => $page->nextCursor()?->encode(),
                'prev_cursor' => $page->previousCursor()?->encode(),
                'limit' => $limit,
            ],
        ]);
    }

    public function show(Request $request, string $conversation): JsonResponse
    {
        $model = self::reachable($request)->with(self::RELATIONS)->findOrFail((int) $conversation);

        return ConversationResource::make($model)->response($request);
    }

    /** Relations the resource renders, with only the columns it shows. */
    private const RELATIONS = [
        'channelConnection:id,name,channel',
        'department:id,name',
        'contact:id,name',
        'assignee:id,name',
    ];

    /**
     * Conversations within the reach of the authenticated token.
     *
     * @return Builder<Conversation>
     */
    public static function reachable(Request $request): Builder
    {
        /** @var ApiToken $token */
        $token = $request->attributes->get(AuthenticateApiToken::ATTRIBUTE);

        return $token->scopeConversations(Conversation::query());
    }

    /**
     * Decodes the opaque cursor; one that was not issued by this list is a
     * validation error instead of a server error.
     */
    private static function cursor(?string $encoded): ?Cursor
    {
        if ($encoded === null) {
            return null;
        }

        $cursor = Cursor::fromEncoded($encoded);
        $parameters = $cursor?->toArray() ?? [];

        if ($cursor === null
            || ! is_scalar($parameters['updated_at'] ?? null)
            || ! is_numeric($parameters['id'] ?? null)
            || strtotime((string) $parameters['updated_at']) === false) {
            throw ValidationException::withMessages(['cursor' => 'El cursor no es válido.']);
        }

        return $cursor;
    }
}
