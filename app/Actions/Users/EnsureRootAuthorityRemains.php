<?php

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Guarantees the platform never loses its last root authority: an active
 * user holding the active root role, able to manage users and permissions.
 *
 * Must be called inside a database transaction. Every root user row is locked
 * in primary key order, so concurrent attempts are serialized (instead of
 * deadlocking) and each one decides on the data committed by the previous.
 */
class EnsureRootAuthorityRemains
{
    /**
     * @throws ValidationException
     */
    public function handle(User $userLosingAuthority, string $errorKey = 'user'): void
    {
        $rootUserIds = User::query()
            ->withRootAuthority()
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');

        if (! $rootUserIds->contains($userLosingAuthority->id)) {
            return;
        }

        if ($rootUserIds->count() === 1) {
            throw ValidationException::withMessages([
                $errorKey => 'Es la última autoridad administrativa activa. Asigna el rol administrador a otra persona antes de continuar.',
            ]);
        }
    }
}
