<?php

namespace App\Concerns;

use App\Models\Conversation;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Form requests of conversation operations first make sure the viewer can
 * see the conversation of the route, before any input is validated. A
 * conversation out of reach answers exactly like one that does not exist
 * (404), so validation errors can never be used to tell existing ids apart.
 * The operation itself is still authorized by the engine, under lock.
 */
trait AuthorizesVisibleConversation
{
    public function authorize(): Response
    {
        $conversation = $this->route('conversation');

        return $conversation instanceof Conversation && Gate::forUser($this->user())->allows('view', $conversation)
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
