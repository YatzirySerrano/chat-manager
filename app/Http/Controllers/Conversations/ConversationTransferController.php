<?php

namespace App\Http\Controllers\Conversations;

use App\Actions\Conversations\TransferConversation;
use App\Exceptions\ConversationConflict;
use App\Http\Requests\Conversations\TransferConversationRequest;
use App\Models\Conversation;
use App\Models\Department;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class ConversationTransferController extends ConversationOperationController
{
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

        $transfer->handle($conversation, $department, $agent, $request->user(), $request->reason(), $request->version(), $request->confirmsUnavailable());

        $destination = $agent->name ?? "la cola de {$department?->name}";

        return $this->done($conversation, $request->user(), "Conversación transferida a {$destination}.");
    }
}
