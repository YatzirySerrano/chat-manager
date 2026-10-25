<?php

namespace App\Services\Meta;

use App\Console\Commands\RefreshInstagramTokens;
use App\Enums\ConnectionChannel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Official authorization flows of the Meta accounts a connection operates:
 *
 * - Facebook Messenger: Facebook Login, manual flow
 *   (https://www.facebook.com/{version}/dialog/oauth). With
 *   META_LOGIN_CONFIG_ID it uses Facebook Login for Business (config_id);
 *   otherwise it asks for the Messenger permissions (MetaApp::MESSENGER_SCOPES).
 *   The code is exchanged on the server for a user token, then a
 *   long-lived one (tolerated to fail with Login for Business, whose token
 *   does not expire), which lists the pages with their page tokens (those
 *   do not expire). The user token is discarded afterwards. Pages owned by
 *   a Business Portfolio may additionally need business_management, which
 *   is not requested by default (with config_id it is chosen in the
 *   configuration; docs/meta-messenger-instagram.md).
 * - Instagram: Instagram API with Instagram Login
 *   (https://www.instagram.com/oauth/authorize), code exchange at
 *   api.instagram.com, long-lived token (60 days, refreshed by
 *   meta:refresh-instagram-tokens). No Facebook page is required.
 *
 * Accounts are returned with their token for MetaOAuthSession; nothing is
 * stored here.
 */
class MetaAccountAuthorization
{
    public function __construct(private MetaApp $app, private MetaPlatformClient $client) {}

    public function authorizationUrl(ConnectionChannel $channel, string $state, string $redirectUri): string
    {
        if ($channel === ConnectionChannel::Instagram) {
            return 'https://www.instagram.com/oauth/authorize?'.http_build_query([
                'client_id' => $this->app->clientId($channel),
                'redirect_uri' => $redirectUri,
                'response_type' => 'code',
                'scope' => implode(',', MetaApp::INSTAGRAM_SCOPES),
                'state' => $state,
                'enable_fb_login' => 0,
                'force_reauth' => 'true',
            ]);
        }

        $configId = $this->app->loginConfigId();

        return 'https://www.facebook.com/'.MetaApp::graphVersion().'/dialog/oauth?'.http_build_query([
            'client_id' => $this->app->clientId($channel),
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'response_type' => 'code',
            ...($configId !== null
                ? ['config_id' => $configId, 'override_default_response_type' => 'true']
                : ['scope' => implode(',', MetaApp::MESSENGER_SCOPES)]),
        ]);
    }

    /**
     * Exchange the code and return the accounts the person can connect.
     *
     * @return list<array{id: string, name: string, username: string|null, picture: string|null, access_token: string, scopes: list<string>, expires_at: int|null, meta_user_id: string|null, can_message: bool}>
     *
     * @throws MetaGraphException
     */
    public function accounts(ConnectionChannel $channel, string $code, string $redirectUri): array
    {
        return $channel === ConnectionChannel::Instagram
            ? $this->instagramAccounts($code, $redirectUri)
            : $this->facebookPages($code, $redirectUri);
    }

    /**
     * @return list<array{id: string, name: string, username: string|null, picture: string|null, access_token: string, scopes: list<string>, expires_at: int|null, meta_user_id: string|null, can_message: bool}>
     *
     * @throws MetaGraphException
     */
    private function facebookPages(string $code, string $redirectUri): array
    {
        $short = $this->client->exchangeFacebookCode($code, $redirectUri);
        $userToken = $this->facebookUserToken($short);
        $scopes = $this->client->facebookPermissions($userToken);
        $userId = $this->client->facebookUserId($userToken);

        return array_map(fn (array $page): array => [
            'id' => $page['id'],
            'name' => $page['name'],
            'username' => null,
            'picture' => $page['picture'],
            'access_token' => $page['access_token'],
            'scopes' => $scopes,
            'expires_at' => null,
            'meta_user_id' => $userId,
            // Sending messages and managing the subscription require the MESSAGING/MODERATE task.
            'can_message' => $page['tasks'] === [] || array_intersect(['MESSAGING', 'MODERATE', 'MANAGE'], $page['tasks']) !== [],
        ], $this->client->pages($userToken));
    }

    /**
     * The user token that lists the pages. Facebook Login for Business
     * (config_id) usually returns a Business Integration System User token
     * that does not expire and for which Meta documents no fb_exchange_token:
     * a refused exchange keeps the code-exchanged token. Classic Facebook
     * Login always exchanges it for the long-lived one.
     *
     * @throws MetaGraphException
     */
    private function facebookUserToken(string $short): string
    {
        if ($this->app->loginConfigId() === null) {
            return $this->client->longLivedFacebookToken($short);
        }

        try {
            return $this->client->longLivedFacebookToken($short);
        } catch (MetaGraphException $exception) {
            Log::info('Meta Login for Business: long-lived exchange refused, the code token is kept.', ['kind' => $exception->kind]);

            return $short;
        }
    }

    /**
     * @return list<array{id: string, name: string, username: string|null, picture: string|null, access_token: string, scopes: list<string>, expires_at: int|null, meta_user_id: string|null, can_message: bool}>
     *
     * @throws MetaGraphException
     */
    private function instagramAccounts(string $code, string $redirectUri): array
    {
        $short = $this->client->exchangeInstagramCode($code, $redirectUri);
        $long = $this->client->longLivedInstagramToken($short['access_token']);
        $account = $this->client->instagramAccount($long['access_token']);

        return [[
            'id' => $account['id'],
            'name' => $account['name'] ?? ($account['username'] !== null ? '@'.$account['username'] : 'Cuenta de Instagram'),
            'username' => $account['username'],
            'picture' => $account['picture'],
            'access_token' => $long['access_token'],
            'scopes' => $short['permissions'] !== [] ? $short['permissions'] : MetaApp::INSTAGRAM_SCOPES,
            // Long-lived Instagram Login tokens last 60 days when Meta omits expires_in.
            'expires_at' => now()->addSeconds($long['expires_in'] ?? RefreshInstagramTokens::DEFAULT_LIFETIME_DAYS * 86400)->getTimestamp(),
            'meta_user_id' => $short['user_id'] === null ? null : Str::limit($short['user_id'], 64, ''),
            'can_message' => true,
        ]];
    }
}
