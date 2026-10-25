<?php

namespace App\Policies;

use App\Models\ChatAppearance;
use App\Models\User;
use App\Services\Personalization\PersonalItemSupervision;
use Illuminate\Auth\Access\Response;

/**
 * Another user's chat appearance, as reached from the supervision screen. The owner
 * manages their own appearance from the conversation (authorized by the
 * conversation's visibility). An appearance out of reach answers as not found.
 */
class ChatAppearancePolicy
{
    public function __construct(private PersonalItemSupervision $supervision) {}

    public function view(User $actor, ChatAppearance $appearance): Response
    {
        return $this->supervision->canView($actor, $appearance->user) ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $actor, ChatAppearance $appearance): Response
    {
        return $this->manage($actor, $appearance);
    }

    public function delete(User $actor, ChatAppearance $appearance): Response
    {
        return $this->manage($actor, $appearance);
    }

    private function manage(User $actor, ChatAppearance $appearance): Response
    {
        if (! $this->supervision->canView($actor, $appearance->user)) {
            return Response::denyAsNotFound();
        }

        return $this->supervision->canManage($actor, $appearance->user) ? Response::allow() : Response::deny();
    }
}
