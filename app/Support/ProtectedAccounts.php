<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Presentation of protected accounts (holders of the root role) to everyone
 * else. Lists, selectors and filters leave them out (User::withoutProtectedFor);
 * the records they took part in (messages, notes, assignments, events, audit
 * entries, team chats) are kept and shown, but their name is replaced by
 * «Administración del sistema» for any viewer without the root authority, so
 * nobody learns that the account exists. Stored facts are never rewritten.
 *
 * The ids of protected accounts are read once per request (or queued job)
 * and reused for every record presented.
 */
#[Scoped]
class ProtectedAccounts
{
    public const SYSTEM_NAME = 'Administración del sistema';

    /** @var list<int>|null */
    private ?array $ids = null;

    private ?Request $resolvedFor = null;

    /**
     * Ids of every protected account (active or not).
     *
     * @return list<int>
     */
    public function ids(): array
    {
        $request = app()->bound('request') ? app('request') : null;

        if ($this->ids === null || $this->resolvedFor !== $request) {
            $this->resolvedFor = $request;
            $this->ids = array_values(array_map('intval', User::query()
                ->whereHas('roles', fn (Builder $roles) => $roles->where('is_root', true))
                ->pluck('id')
                ->all()));
        }

        return $this->ids;
    }

    /**
     * Whether the account must stay hidden from the viewer. A viewer that is
     * not a user (integration token, web chat visitor) never sees it.
     */
    public function hides(User|int|null $account, ?User $viewer): bool
    {
        if ($account === null || ($viewer !== null && $viewer->hasRootAuthority())) {
            return false;
        }

        return in_array($account instanceof User ? $account->id : $account, $this->ids(), true);
    }

    /**
     * The name of the account as the viewer may see it, or null without one.
     */
    public function nameFor(?User $account, ?User $viewer): ?string
    {
        if ($account === null) {
            return null;
        }

        return $this->hides($account, $viewer) ? self::SYSTEM_NAME : $account->name;
    }

    /**
     * The id of the account as the viewer may see it: null for a protected
     * account, so «Administración del sistema» cannot be told apart as one
     * concrete account or correlated across screens.
     */
    public function idFor(?User $account, ?User $viewer): ?int
    {
        return $account === null || $this->hides($account, $viewer) ? null : $account->id;
    }

    /**
     * The id of the account as the person behind the request may see it.
     */
    public function idForRequest(?User $account, Request $request): ?int
    {
        $viewer = $request->user();

        return $this->idFor($account, $viewer instanceof User ? $viewer : null);
    }

    /**
     * The name of the account as the person behind the request may see it.
     */
    public function nameForRequest(?User $account, Request $request): ?string
    {
        $viewer = $request->user();

        return $this->nameFor($account, $viewer instanceof User ? $viewer : null);
    }

    /**
     * Narrow a query on a user id column (e.g. conversations.assigned_user_id)
     * so that it never matches protected accounts for the viewer.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function excludeFrom(Builder $query, string $column, ?User $viewer): Builder
    {
        if ($viewer !== null && $viewer->hasRootAuthority()) {
            return $query;
        }

        $ids = $this->ids();

        return $ids === [] ? $query : $query->where(fn (Builder $nullable) => $nullable
            ->whereNull($column)
            ->orWhereNotIn($column, $ids));
    }
}
