<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ApiToken;
use App\Models\User;

/**
 * Integration API tokens reach conversations of the whole organization, so
 * both permissions require the organization-wide scope (enforced by
 * User::holdsPermission). Managing implies seeing.
 */
class ApiTokenPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->holdsPermission(Permission::ViewIntegrations)
            || $actor->holdsPermission(Permission::ManageIntegrations);
    }

    public function create(User $actor): bool
    {
        return $actor->holdsPermission(Permission::ManageIntegrations);
    }

    /**
     * Revoking (tokens are never physically deleted).
     */
    public function delete(User $actor, ApiToken $token): bool
    {
        return $actor->holdsPermission(Permission::ManageIntegrations);
    }
}
