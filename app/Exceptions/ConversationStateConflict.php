<?php

namespace App\Exceptions;

use App\Enums\ConversationConflict as Reason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The actor may perform the operation, but the conversation is no longer in
 * a state that allows it (someone else accepted, closed, moved or reopened
 * it first). Always emitted after the actor was shown to see the
 * conversation, so it never tells anything to someone outside its reach.
 *
 * JSON clients receive 409 {code, message}; Inertia visits return to the
 * page (or the list) with the message under `errors.conversation` and the
 * code under `errors.conversation_conflict`, so the interface can show the
 * explanation and the fresh state of the conversation.
 */
class ConversationStateConflict extends ConversationConflict
{
    /** Error bag key carrying the conflict code on Inertia visits. */
    public const CODE_ERROR_KEY = 'conversation_conflict';

    public function __construct(public readonly Reason $reason)
    {
        parent::__construct($reason->message());
    }

    public static function because(Reason $reason): self
    {
        return new self($reason);
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['code' => $this->reason->value, 'message' => $this->getMessage()], 409);
        }

        return $this->destination($request)->withErrors([
            'conversation' => $this->getMessage(),
            self::CODE_ERROR_KEY => $this->reason->value,
        ]);
    }
}
