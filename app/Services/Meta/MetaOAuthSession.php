<?php

namespace App\Services\Meta;

use App\Enums\ConnectionChannel;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Throwable;

/**
 * Server-side state of a Messenger / Instagram authorization in progress.
 *
 * - The OAuth "state" is random (40 characters), kept in the session as its
 *   SHA-256 only, bound to the user and the channel, valid for TTL_SECONDS
 *   and single-use: it is removed when the callback reads it, whether it
 *   matched or not (CSRF protection, compared with hash_equals).
 * - The accounts Meta returned (with their tokens) are kept encrypted in the
 *   cache under a random key referenced only from the session, for
 *   TTL_SECONDS, until the administrator chooses one. Tokens never reach
 *   the browser.
 *
 * PKCE is not documented by Meta for Facebook Login (manual flow) nor for
 * Instagram Login; the confidential client secret stays on the server.
 */
class MetaOAuthSession
{
    public const TTL_SECONDS = 600;

    private const STATE_KEY = 'meta_oauth.state';

    private const SELECTION_KEY = 'meta_oauth.selection';

    public function __construct(private Session $session) {}

    /**
     * Start an authorization and return its state value.
     */
    public function begin(ConnectionChannel $channel, User $user): string
    {
        $state = Str::random(40);

        $this->session->put(self::STATE_KEY, [
            'hash' => hash('sha256', $state),
            'channel' => $channel->value,
            'user_id' => $user->id,
            'expires_at' => now()->addSeconds(self::TTL_SECONDS)->getTimestamp(),
        ]);

        return $state;
    }

    /**
     * Validate the state returned by Meta and consume it (single use).
     */
    public function consume(ConnectionChannel $channel, User $user, mixed $state): bool
    {
        $stored = $this->session->pull(self::STATE_KEY);

        return is_array($stored)
            && is_string($state) && $state !== ''
            && ($stored['channel'] ?? null) === $channel->value
            && ($stored['user_id'] ?? null) === $user->id
            && is_int($stored['expires_at'] ?? null) && $stored['expires_at'] >= now()->getTimestamp()
            && is_string($stored['hash'] ?? null)
            && hash_equals($stored['hash'], hash('sha256', $state));
    }

    /**
     * Keep the accounts Meta returned until one is chosen.
     *
     * @param  list<array<string, mixed>>  $accounts
     */
    public function keepAccounts(ConnectionChannel $channel, User $user, array $accounts): void
    {
        $this->forgetAccounts();
        $key = Str::random(40);

        Cache::put(self::cacheKey($key), Crypt::encryptString((string) json_encode($accounts)), self::TTL_SECONDS);

        $this->session->put(self::SELECTION_KEY, [
            'key' => $key,
            'channel' => $channel->value,
            'user_id' => $user->id,
            'expires_at' => now()->addSeconds(self::TTL_SECONDS)->getTimestamp(),
        ]);
    }

    /**
     * Accounts waiting to be chosen by this user for this channel, or null
     * when there are none (expired, other user or channel).
     *
     * @return list<array<string, mixed>>|null
     */
    public function accounts(ConnectionChannel $channel, User $user): ?array
    {
        $selection = $this->session->get(self::SELECTION_KEY);

        if (! is_array($selection) || ($selection['channel'] ?? null) !== $channel->value || ($selection['user_id'] ?? null) !== $user->id
            || ! is_int($selection['expires_at'] ?? null) || $selection['expires_at'] < now()->getTimestamp() || ! is_string($selection['key'] ?? null)) {
            return null;
        }

        $encrypted = Cache::get(self::cacheKey($selection['key']));

        if (! is_string($encrypted)) {
            return null;
        }

        try {
            $accounts = json_decode(Crypt::decryptString($encrypted), true);
        } catch (Throwable) {
            return null;
        }

        return is_array($accounts) ? array_values(array_filter($accounts, 'is_array')) : null;
    }

    public function forgetAccounts(): void
    {
        $selection = $this->session->pull(self::SELECTION_KEY);

        if (is_array($selection) && is_string($selection['key'] ?? null)) {
            Cache::forget(self::cacheKey($selection['key']));
        }
    }

    private static function cacheKey(string $key): string
    {
        return 'meta-oauth-accounts:'.hash('sha256', $key);
    }
}
