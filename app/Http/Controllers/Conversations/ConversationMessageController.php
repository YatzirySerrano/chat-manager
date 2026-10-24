<?php

namespace App\Http\Controllers\Conversations;

use App\Actions\Conversations\QueueOutboundMessage;
use App\Http\Requests\Conversations\OutboundMessageRequest;
use App\Http\Resources\ConversationMessageResource;
use App\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Agent replies from the inbox composer. The engine validates the
 * responsible agent, the customer service window and the attachment, and
 * the delivery job checks the window again before reaching the provider.
 */
class ConversationMessageController extends ConversationOperationController
{
    public function store(OutboundMessageRequest $request, Conversation $conversation, QueueOutboundMessage $queue): RedirectResponse|JsonResponse
    {
        $this->ensureVisible($conversation);

        $message = $queue->handle(
            $conversation,
            $request->user(),
            $request->filled('body') ? $request->string('body')->value() : null,
            $request->string('client_reference')->value(),
            $request->file('attachment'),
            $request->filled('reply_to_message_id') ? $request->integer('reply_to_message_id') : null,
        );

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            $message->load(['sender', 'attachments', 'replyTo']);

            return (new ConversationMessageResource($message))->response()->setStatusCode(202);
        }

        Inertia::flash('toast', ['type' => 'info', 'message' => 'Enviando mensaje…']);

        return back();
    }
}
