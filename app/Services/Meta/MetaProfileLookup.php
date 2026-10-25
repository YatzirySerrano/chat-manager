<?php

namespace App\Services\Meta;

use App\Enums\ConnectionChannel;
use App\Models\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Name a Messenger or Instagram customer shows publicly, read once per
 * customer (cached for a day, failures included) through the User Profile
 * API. Meta only answers when the app has the needed access (Messenger:
 * Business Asset User Profile Access); any refusal returns null, so the
 * contact keeps the channel's fallback name and no name is ever invented.
 * Profile pictures are not stored: Meta's URLs expire.
 */
class MetaProfileLookup
{
    public const CACHE_SECONDS = 86400;

    public function __construct(private MetaPlatformClient $client) {}

    public function nameFor(Connection $connection, string $customerId): ?string
    {
        $token = (string) $connection->access_token;

        if ($token === '' || ! in_array($connection->channel, [ConnectionChannel::Messenger, ConnectionChannel::Instagram], true)) {
            return null;
        }

        $key = 'meta-profile:'.$connection->channel->value.':'.hash('sha256', $connection->id.'|'.$customerId);
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return is_string($cached['name'] ?? null) ? $cached['name'] : null;
        }

        try {
            $profile = $this->client->profile($connection->channel, $customerId, $token);
            $name = $this->nameOf($connection->channel, $profile);
        } catch (MetaGraphException $exception) {
            Log::info('Meta profile not available for a customer.', ['connection_id' => $connection->id, 'kind' => $exception->kind, 'meta_code' => $exception->metaCode]);
            $name = null;
        }

        Cache::put($key, ['name' => $name], self::CACHE_SECONDS);

        return $name;
    }

    /**
     * @param  array<array-key, mixed>  $profile
     */
    private function nameOf(ConnectionChannel $channel, array $profile): ?string
    {
        if ($channel === ConnectionChannel::Instagram) {
            $name = $profile['name'] ?? null;
            $username = $profile['username'] ?? null;

            return match (true) {
                is_string($name) && trim($name) !== '' => trim($name),
                is_string($username) && trim($username) !== '' => '@'.ltrim(trim($username), '@'),
                default => null,
            };
        }

        $parts = array_filter([
            is_string($profile['first_name'] ?? null) ? trim($profile['first_name']) : '',
            is_string($profile['last_name'] ?? null) ? trim($profile['last_name']) : '',
        ]);

        return $parts === [] ? null : implode(' ', $parts);
    }
}
