<?php

namespace App\Notifications;

use App\Models\User;
use App\Models\UserNotificationPreference;

/**
 * Reads and saves the notification preferences of a user, falling back to
 * the defaults of each kind when nothing was saved.
 *
 * @phpstan-type Preference array{in_app: bool, browser: bool, sound: bool}
 */
class NotificationPreferences
{
    /**
     * @var array<int, array<string, Preference>>
     */
    private array $memo = [];

    /**
     * Preferences of every kind for the user.
     *
     * @return array<string, Preference>
     */
    public function for(User $user): array
    {
        if (isset($this->memo[$user->id])) {
            return $this->memo[$user->id];
        }

        $stored = UserNotificationPreference::query()
            ->where('user_id', $user->id)
            ->get()
            ->keyBy('kind');

        $preferences = [];

        foreach (NotificationKind::cases() as $kind) {
            $row = $stored->get($kind->value);

            $preferences[$kind->value] = $row instanceof UserNotificationPreference
                ? ['in_app' => $row->in_app, 'browser' => $row->browser, 'sound' => $row->sound]
                : $kind->defaults();
        }

        return $this->memo[$user->id] = $preferences;
    }

    /**
     * @return Preference
     */
    public function of(User $user, NotificationKind $kind): array
    {
        return $this->for($user)[$kind->value];
    }

    public function allowsInApp(User $user, NotificationKind $kind): bool
    {
        return $this->of($user, $kind)['in_app'];
    }

    /**
     * Save the given kinds and return the ones whose values changed.
     *
     * @param  array<string, Preference>  $preferences  Keyed by kind value.
     * @return list<string>
     */
    public function save(User $user, array $preferences): array
    {
        $current = $this->for($user);
        $changed = [];

        foreach ($preferences as $kind => $values) {
            if (NotificationKind::tryFrom($kind) === null || $current[$kind] === $values) {
                continue;
            }

            UserNotificationPreference::query()->updateOrCreate(
                ['user_id' => $user->id, 'kind' => $kind],
                $values,
            );

            $changed[] = $kind;
        }

        unset($this->memo[$user->id]);

        return $changed;
    }

    /**
     * Forget memoized reads (long-running workers handle many events).
     */
    public function flush(): void
    {
        $this->memo = [];
    }
}
