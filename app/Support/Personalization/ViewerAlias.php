<?php

namespace App\Support\Personalization;

use App\Models\Contact;
use App\Models\ContactAlias;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The viewer's private alias of a contact and the name shown to them. The
 * aliases are eager loaded for a whole page in one query, constrained to
 * the viewer (load()); reading them never queries and never trusts the
 * relation alone: rows of another user are ignored even if loaded. The
 * official name stays available for everything shared with others
 * (notifications, reports, exports, the API and quick reply variables).
 */
final class ViewerAlias
{
    /**
     * Loads the viewer's aliases of the contacts of the conversations: one
     * query for the whole page (none when it is empty).
     *
     * @param  Conversation|Collection<int, Conversation>  $conversations
     */
    public static function load(Conversation|Collection $conversations, User $viewer): void
    {
        $conversations->load(['contact.aliases' => function (Relation $query) use ($viewer): void {
            $query->where('user_id', $viewer->id);
        }]);
    }

    public static function of(Contact $contact, ?User $viewer): ?string
    {
        if ($viewer === null || ! $contact->relationLoaded('aliases')) {
            return null;
        }

        /** @var ContactAlias|null $alias */
        $alias = $contact->aliases->first(fn (ContactAlias $alias): bool => $alias->user_id === $viewer->id);

        return $alias?->alias;
    }

    /**
     * Viewer alias, else official name, else the provider's profile name,
     * else the phone.
     */
    public static function displayName(Contact $contact, ?string $alias): string
    {
        foreach ([$alias, $contact->name, $contact->profile_name, $contact->phone_e164] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return $candidate;
            }
        }

        return 'Contacto sin nombre';
    }
}
