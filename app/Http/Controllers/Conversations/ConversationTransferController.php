<?php

namespace App\Http\Controllers\Conversations;

use App\Actions\Conversations\TransferConversation;
use App\Actions\Conversations\TransferDestinations;
use App\Exceptions\ConversationConflict;
use App\Http\Requests\Conversations\TransferConversationRequest;
use App\Models\Conversation;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ConversationTransferController extends ConversationOperationController
{
    /**
     * Who may receive this conversation (JSON for the assign and transfer
     * dialogs). Authorized by the assign or transfer policy of this very
     * conversation; anyone else gets the usual "not found" or "forbidden".
     */
    public function destinations(Request $request, Conversation $conversation, TransferDestinations $destinations): JsonResponse
    {
        $this->ensureVisible($conversation);
        abort_unless(Gate::any(['assign', 'transfer'], $conversation), 403);

        return response()->json($destinations->for($conversation, $request->user()));
    }

    public function store(TransferConversationRequest $request, Conversation $conversation, TransferConversation $transfer): RedirectResponse
    {
        $this->ensureVisible($conversation);
        Gate::authorize('transfer', $conversation);

        $department = $request->filled('department_id')
            ? (Department::query()->find($request->integer('department_id')) ?? throw new ConversationConflict('El departamento elegido no existe.'))
            : null;
        $agent = $request->filled('user_id')
            ? $this->destinationAgent($request->integer('user_id'), $request->user(), $department ?? $conversation->department)
            : null;

        $transfer->handle($conversation, $department, $agent, $request->user(), $request->reason(), $request->version(), $request->confirmsUnavailable(), $request->confirmsOverCapacity());

        $destination = $agent->name ?? "la cola de {$department?->name}";

        return $this->done($conversation, $request->user(), "Conversación transferida a {$destination}.");
    }
}
