<?php

namespace App\Http\Controllers\Conversations;

use App\Exceptions\ConversationConflict;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use App\Services\Conversations\ConversationAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Shared behavior of the controllers that operate on a conversation.
 */
abstract class ConversationOperationController extends Controller
{
    /**
     * A conversation outside the actor's reach looks exactly like one that
     * does not exist.
     */
    protected function ensureVisible(Conversation $conversation): void
    {
        abort_unless(Gate::allows('view', $conversation), 404);
    }

    /**
     * The agent named as destination of an assignment or transfer. Someone
     * outside the actor's scope is only resolved when they could attend the
     * destination department; anyone else "does not exist", so probing ids
     * never reveals the names of people the actor may not see.
     */
    protected function destinationAgent(int $userId, User $actor, ?Department $department): User
    {
        $agent = User::query()->find($userId);

        if ($agent === null
            || (! User::query()->visibleTo($actor)->whereKey($agent->id)->exists()
                && ! app(ConversationAccess::class)->canAttend($agent, $department))) {
            throw new ConversationConflict('El agente elegido no existe.');
        }

        return $agent;
    }

    /**
     * Back to the page the user was on, or to the list when the operation
     * moved the conversation out of their reach (e.g. after a transfer).
     */
    protected function done(Conversation $conversation, User $actor, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        $conversation->refresh();

        if ($actor->fresh()?->can('view', $conversation) ?? false) {
            return back();
        }

        return redirect()->to(ConversationController::listUrl());
    }
}
