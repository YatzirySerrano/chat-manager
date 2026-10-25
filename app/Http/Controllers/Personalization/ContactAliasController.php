<?php

namespace App\Http\Controllers\Personalization;

use App\Actions\Personalization\SetContactAlias;
use App\Http\Controllers\Controller;
use App\Http\Requests\Personalization\ContactAliasRequest;
use App\Models\Conversation;
use App\Models\User;
use App\Support\Personalization\ViewerAlias;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The viewer's own private alias of the contact of a conversation they can
 * see. Reaching it through the conversation keeps the alias behind the same
 * access rules as the chat: a contact the viewer cannot reach cannot be
 * named. Own changes are not audited (they only affect the viewer's view).
 */
class ContactAliasController extends Controller
{
    public function update(ContactAliasRequest $request, Conversation $conversation, SetContactAlias $setAlias): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $request->user();

        $record = $setAlias->handle($viewer, $conversation->contact, $request->alias());

        return $this->respond($conversation, $record?->alias);
    }

    public function destroy(Request $request, Conversation $conversation, SetContactAlias $setAlias): JsonResponse
    {
        abort_unless(Gate::allows('view', $conversation), 404);

        /** @var User $viewer */
        $viewer = $request->user();

        $setAlias->handle($viewer, $conversation->contact, null);

        return $this->respond($conversation, null);
    }

    private function respond(Conversation $conversation, ?string $alias): JsonResponse
    {
        return response()->json([
            'contact_id' => $conversation->contact->id,
            'alias' => $alias,
            'display_name' => ViewerAlias::displayName($conversation->contact, $alias),
            'name' => $conversation->contact->name,
        ]);
    }
}
