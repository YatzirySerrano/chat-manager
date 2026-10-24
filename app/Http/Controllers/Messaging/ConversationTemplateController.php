<?php

namespace App\Http\Controllers\Messaging;

use App\Actions\Templates\QueueTemplateMessage;
use App\Http\Controllers\Controller;
use App\Http\Resources\MessageTemplateResource;
use App\Models\Conversation;
use App\Models\MessageTemplate;
use App\Services\Conversations\CustomerServiceWindow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Templates from the conversation composer: the approved templates of the
 * conversation's connection (only for whoever can reply in it) and sending
 * one, also outside the customer service window.
 */
class ConversationTemplateController extends Controller
{
    public function index(Request $request, Conversation $conversation, CustomerServiceWindow $window): JsonResponse
    {
        abort_unless(Gate::allows('view', $conversation), 404);
        Gate::authorize('reply', $conversation);

        $templates = MessageTemplate::query()
            ->approved()
            ->where('connection_id', $conversation->connection_id)
            ->orderBy('name')
            ->orderBy('language')
            ->get();

        return response()->json([
            'data' => MessageTemplateResource::collection($templates->filter(fn (MessageTemplate $template): bool => $template->isUsable())->values())->resolve($request),
            'window' => $window->for($conversation)->toArray(),
        ]);
    }

    public function store(Request $request, Conversation $conversation, QueueTemplateMessage $queue): RedirectResponse|JsonResponse
    {
        abort_unless(Gate::allows('view', $conversation), 404);

        $data = $request->validate([
            'template_id' => ['required', 'integer', Rule::exists('message_templates', 'id')->where('connection_id', $conversation->connection_id)],
            'client_reference' => ['required', 'uuid'],
            'parameters' => ['nullable', 'array', 'max:40'],
            'parameters.*' => ['nullable', 'string', 'max:2048'],
        ], [
            'template_id.exists' => 'La plantilla no existe para la conexión de esta conversación.',
        ]);

        $message = $queue->handle(
            $conversation,
            $request->user(),
            MessageTemplate::query()->findOrFail((int) $data['template_id']),
            $data['parameters'] ?? [],
            $data['client_reference'],
        );

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['data' => [
                'id' => $message->id,
                'type' => $message->type->value,
                'status' => $message->status->value,
                'body' => $message->body,
                'client_reference' => $message->client_reference,
            ]], 202);
        }

        Inertia::flash('toast', ['type' => 'info', 'message' => 'Enviando plantilla…']);

        return back();
    }
}
