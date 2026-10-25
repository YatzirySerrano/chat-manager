<?php

namespace App\Policies;

use App\Models\ContactAlias;
use App\Models\User;
use App\Services\Personalization\PersonalItemSupervision;
use Illuminate\Auth\Access\Response;

/**
 * Another user's alias, as reached from the supervision screen. The owner
 * manages their own aliases from the conversation (authorized by the
 * conversation's visibility). An alias out of reach answers as not found.
 */
class ContactAliasPolicy
{
    public function __construct(private PersonalItemSupervision $supervision) {}

    public function view(User $actor, ContactAlias $alias): Response
    {
        return $this->supervision->canView($actor, $alias->user) ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $actor, ContactAlias $alias): Response
    {
        return $this->manage($actor, $alias);
    }

    public function delete(User $actor, ContactAlias $alias): Response
    {
        return $this->manage($actor, $alias);
    }

    private function manage(User $actor, ContactAlias $alias): Response
    {
        if (! $this->supervision->canView($actor, $alias->user)) {
            return Response::denyAsNotFound();
        }

        return $this->supervision->canManage($actor, $alias->user) ? Response::allow() : Response::deny();
    }
}
