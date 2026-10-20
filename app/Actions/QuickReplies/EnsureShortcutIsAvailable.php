<?php

namespace App\Actions\QuickReplies;

use App\Models\Department;
use App\Models\QuickReply;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * A shortcut is unique within its scope: two replies conflict when they share
 * the shortcut and either of them is global or they share a department.
 *
 * Must run inside the transaction that saves the reply: the replies with the
 * same shortcut are locked (and, through the shortcut index, the gap where a
 * new one would be inserted), so concurrent saves are serialized.
 *
 * The conflicting reply is only named when the actor can see it; otherwise the
 * message stays generic so shortcuts cannot be used to discover other scopes.
 */
class EnsureShortcutIsAvailable
{
    /**
     * @param  list<int>  $departmentIds
     *
     * @throws ValidationException
     */
    public function handle(User $actor, string $shortcut, bool $isGlobal, array $departmentIds, ?int $ignoreQuickReplyId = null): void
    {
        $sameShortcut = QuickReply::query()
            ->where('shortcut', $shortcut)
            ->when($ignoreQuickReplyId !== null, fn ($query) => $query->whereKeyNot($ignoreQuickReplyId))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($sameShortcut->isEmpty()) {
            return;
        }

        $sameShortcut->load(['departments' => fn ($query) => $query->select('departments.id', 'departments.name')->orderBy('departments.name')]);

        $visibleIds = QuickReply::query()->visibleTo($actor)->whereKey($sameShortcut->modelKeys())->pluck('id')->all();

        foreach ($sameShortcut as $existing) {
            $shared = $existing->departments->whereIn('id', $departmentIds);

            if (! $existing->is_global && ! $isGlobal && $shared->isEmpty()) {
                continue;
            }

            if (! in_array($existing->id, $visibleIds, true)) {
                throw $this->conflict($shortcut, 'otra respuesta rápida de un ámbito que se superpone con el elegido');
            }

            if ($existing->is_global) {
                throw $this->conflict($shortcut, "«{$existing->title}», que está disponible para todos los departamentos");
            }

            if ($isGlobal) {
                throw $this->conflict($shortcut, "«{$existing->title}». Una respuesta disponible para todos los departamentos necesita un atajo que no use ninguna otra respuesta");
            }

            $names = $shared->map(fn (Department $department): string => $department->name)->join(', ', ' y ');

            throw $this->conflict($shortcut, "«{$existing->title}» en {$names}");
        }
    }

    private function conflict(string $shortcut, string $detail): ValidationException
    {
        return ValidationException::withMessages([
            'shortcut' => "El atajo /{$shortcut} ya se usa en {$detail}.",
        ]);
    }
}
