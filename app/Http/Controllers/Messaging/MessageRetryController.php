<?php

namespace App\Http\Controllers\Messaging;

use App\Actions\Messaging\RetryOutboundMessage;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Manual retry of a failed outbound message, authorized like a reply.
 */
class MessageRetryController extends Controller
{
    public function store(Request $request, Conversation $conversation, Message $message, RetryOutboundMessage $retry): RedirectResponse|JsonResponse
    {
        abort_unless(Gate::allows('view', $conversation), 404);
        abort_unless($message->conversation_id === $conversation->id, 404);

        $request->validate(['confirm' => ['sometimes', 'boolean']]);

        $message = $retry->handle($conversation, $message, $request->user(), $request->boolean('confirm'));

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['data' => ['id' => $message->id, 'status' => $message->status->value]], 202);
        }

        Inertia::flash('toast', ['type' => 'info', 'message' => 'Reintentando el envío…']);

        return back();
    }
}
