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
 * Agent replies. Prepared for the advanced inbox: the engine validates the
 * responsible agent and the customer service window, and the delivery job
 * checks the window again before reaching the provider.
 */
class ConversationMessageController extends ConversationOperationController
{
    public function store(OutboundMessageRequest $request, Conversation $conversation, QueueOutboundMessage $queue): RedirectResponse|JsonResponse
    {
        $this->ensureVisible($conversation);

        $message = $queue->handle($conversation, $request->user(), $request->string('body')->value(), $request->string('client_reference')->value());

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return (new ConversationMessageResource($message))->response()->setStatusCode(202);
        }

        Inertia::flash('toast', ['type' => 'info', 'message' => 'Mensaje en cola de envío.']);

        return back();
    }
}
