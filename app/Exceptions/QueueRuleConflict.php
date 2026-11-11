<?php

namespace App\Exceptions;

use App\Services\Conversations\QueueBlock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A queue rule (request completeness, minimum wait or chronological order,
 * QueueRules) does not let the actor take the conversation right now. Like
 * every operational conflict it is a controlled 409, with its own stable
 * code and what the interface needs to explain it:
 *
 * - JSON: {code, message, available_at, blocking_conversation_id, overridable};
 * - Inertia: the message under `errors.conversation`, the code under
 *   `errors.conversation_conflict`, and `errors.conversation_available_at`,
 *   `errors.conversation_blocking_id` and `errors.conversation_overridable`
 *   when they apply (error bags only carry strings).
 *
 * `overridable` tells whether the actor holds the permission to make a
 * justified exception; the server checks it again when the exception is sent.
 */
class QueueRuleConflict extends ConversationConflict
{
    public function __construct(public readonly QueueBlock $block, public readonly bool $overridable = false)
    {
        parent::__construct($block->message);
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        $availableAt = $this->block->availableAt?->toIso8601String();

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'code' => $this->block->code,
                'message' => $this->getMessage(),
                'available_at' => $availableAt,
                'blocking_conversation_id' => $this->block->blockingConversationId,
                'overridable' => $this->overridable,
            ], 409);
        }

        return $this->destination($request)->withErrors(array_filter([
            'conversation' => $this->getMessage(),
            ConversationStateConflict::CODE_ERROR_KEY => $this->block->code,
            'conversation_available_at' => $availableAt,
            'conversation_blocking_id' => $this->block->blockingConversationId === null ? null : (string) $this->block->blockingConversationId,
            'conversation_overridable' => $this->overridable ? '1' : null,
        ], fn (?string $value): bool => $value !== null));
    }
}
