<?php

namespace App\Http\Controllers\Conversations;

use App\Actions\Conversations\RecordConversationOutcome;
use App\Enums\ConversationOutcome;
use App\Http\Requests\Conversations\CloseConversationRequest;
use App\Models\Conversation;
use App\Models\User;
use App\Policies\ConversationPolicy;
use App\Services\Conversations\ConversationLifecycle;
use App\Services\Conversations\RequestOutcomeSuggestions;
use App\Support\OutcomeText;
use App\Support\SaveToast;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The credit result of a request («Crédito aprobado» / «Rechazada»): the
 * suggestion read from the conversation when it is completed, and its
 * correction afterwards from the details panel.
 */
class ConversationOutcomeController extends ConversationOperationController
{
    /**
     * What to pre-select in the completion dialog (or the correction): for
     * whoever may complete the request now or record its result.
     */
    public function suggestion(Request $request, Conversation $conversation, RequestOutcomeSuggestions $suggestions, ConversationPolicy $policy): JsonResponse
    {
        $this->ensureVisible($conversation);

        /** @var User $actor */
        $actor = $request->user();

        if (! $policy->permits($actor, 'close', $conversation) && ! $policy->permitsOutcome($actor, $conversation)) {
            throw ConversationLifecycle::forbidden($conversation);
        }

        return response()->json([
            'suggestion' => $suggestions->for($conversation),
            'options' => ConversationOutcome::options(),
        ]);
    }

    public function update(Request $request, Conversation $conversation, RecordConversationOutcome $record, ConversationPolicy $policy): RedirectResponse
    {
        $this->ensureVisible($conversation);

        /** @var User $actor */
        $actor = $request->user();

        // Permission before validation: a refusal is a 403, never a 422.
        if (! $policy->permitsOutcome($actor, $conversation)) {
            throw ConversationLifecycle::forbidden($conversation);
        }

        $request->validate(
            CloseConversationRequest::outcomeRules(),
            CloseConversationRequest::outcomeMessages(),
            CloseConversationRequest::outcomeAttributes(),
        );

        $outcome = $request->filled('outcome') ? ConversationOutcome::tryFrom($request->string('outcome')->value()) : null;
        $amount = $request->filled('outcome_amount') ? $request->string('outcome_amount')->value() : null;

        $changed = $record->handle($conversation, $actor, $outcome, $amount);

        SaveToast::flash($changed, 'Resultado de la solicitud: '.(OutcomeText::of($conversation->refresh()) ?? 'sin resultado').'.');

        return back();
    }
}
