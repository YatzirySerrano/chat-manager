<?php

namespace App\Http\Controllers\Conversations;

use App\Actions\Conversations\TransferConversation;
use App\Actions\Conversations\TransferDestinations;
use App\Exceptions\ConversationConflict;
use App\Http\Requests\Conversations\TransferConversationRequest;
use App\Models\Conversation;
use App\Models\Department;
use App\Policies\ConversationPolicy;
use App\Services\Conversations\ConversationStateGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ConversationTransferController extends ConversationOperationController
{
    /**
     * Who may receive this conversation (JSON for the assign and transfer
     * dialogs). Authorized by the assign or transfer permission on this very
     * conversation (anyone else gets the usual "not found" or "forbidden");
     * a conversation closed or taken meanwhile answers the 409 conflict
     * (the dialog sends the version it was opened on).
     */
    public function destinations(Request $request, Conversation $conversation, TransferDestinations $destinations, ConversationStateGuard $state): JsonResponse
    {
        $this->ensureVisible($conversation);
        $ability = app(ConversationPolicy::class)->permits($request->user(), 'assign', $conversation) ? 'assign' : 'transfer';
        $this->ensurePermitted($request->user(), $ability, $conversation);
        $state->ensure($request->user(), $ability, $conversation, staleVersion: $request->filled('version') && $request->integer('version') !== $conversation->version);

        return response()->json($destinations->for($conversation, $request->user()));
    }

    public function store(TransferConversationRequest $request, Conversation $conversation, TransferConversation $transfer): RedirectResponse
    {
        $this->ensureVisible($conversation);
        $this->ensurePermitted($request->user(), 'transfer', $conversation);

        $department = $request->filled('department_id')
            ? (Department::query()->find($request->integer('department_id')) ?? throw new ConversationConflict('El departamento elegido no existe.'))
            : null;
        $agent = $request->filled('user_id')
            ? $this->destinationAgent($request->integer('user_id'), $request->user(), $department ?? $conversation->department)
            : null;

        $transfer->handle($conversation, $department, $agent, $request->user(), $request->reason(), $request->version(), $request->confirmsOverCapacity());

        $destination = $agent->name ?? "«Pendientes» de {$department?->name}";

        return $this->done($conversation, $request->user(), "Conversación transferida a {$destination}.");
    }
}
