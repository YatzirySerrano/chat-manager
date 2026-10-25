<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Connections\ConnectMetaAccount;
use App\Actions\Connections\DisconnectMetaAccount;
use App\Enums\ConnectionChannel;
use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Models\User;
use App\Services\Meta\MetaAccountAuthorization;
use App\Services\Meta\MetaApp;
use App\Services\Meta\MetaGraphException;
use App\Services\Meta\MetaOAuthSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Connecting a Facebook Messenger page or an Instagram professional account
 * with Meta's official authorization (connections.manage, organization-wide
 * scope, like every connection):
 *
 * 1. show(): requirements of the channel and whether the Meta app is configured;
 * 2. start(): redirects to Meta with a single-use state (MetaOAuthSession);
 * 3. callback(): validates and consumes the state, handles a refusal,
 *    exchanges the code on the server and keeps the accounts encrypted;
 * 4. accounts() / connect(): the administrator chooses the page or account,
 *    which becomes (or re-authorizes) a connection (ConnectMetaAccount);
 * 5. disconnect(): cancels the subscription and forgets the token.
 */
class MetaAuthorizationController extends Controller
{
    public function show(Request $request, string $channel, MetaApp $app): Response
    {
        Gate::authorize('create', Connection::class);
        $channel = $this->channel($channel);

        return Inertia::render('connections/MetaAuthorize', [
            'channel' => ['value' => $channel->value, 'label' => $channel->label()],
            'configured' => $app->isConfiguredFor($channel),
            'missing' => $app->missingFor($channel),
            'requirements' => MetaApp::requirements($channel),
            'scopes' => array_map(fn (string $scope): array => ['value' => $scope, 'label' => MetaApp::scopeLabel($scope)], $channel === ConnectionChannel::Instagram ? MetaApp::INSTAGRAM_SCOPES : MetaApp::MESSENGER_SCOPES),
            'usesLoginConfiguration' => $channel === ConnectionChannel::Messenger && $app->loginConfigId() !== null,
            'redirectUri' => $this->redirectUri($channel),
            'webhookUrl' => route('webhooks.meta.receive'),
            'subscribedFields' => MetaApp::SUBSCRIBED_FIELDS[$channel->value] ?? [],
        ]);
    }

    public function start(Request $request, string $channel, MetaApp $app, MetaOAuthSession $session, MetaAccountAuthorization $authorization): SymfonyResponse
    {
        Gate::authorize('create', Connection::class);
        $channel = $this->channel($channel);

        if (! $app->isConfiguredFor($channel)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'La aplicación de Meta no está configurada en el servidor ('.implode(', ', $app->missingFor($channel)).'). Solicítalo al administrador del servidor.']);

            return to_route('connections.meta.show', $channel->value);
        }

        /** @var User $actor */
        $actor = $request->user();
        $state = $session->begin($channel, $actor);

        return Inertia::location($authorization->authorizationUrl($channel, $state, $this->redirectUri($channel)));
    }

    public function callback(Request $request, string $channel, MetaApp $app, MetaOAuthSession $session, MetaAccountAuthorization $authorization): RedirectResponse
    {
        Gate::authorize('create', Connection::class);
        $channel = $this->channel($channel);

        /** @var User $actor */
        $actor = $request->user();

        // Consumed before anything else: a state is never accepted twice.
        if (! $session->consume($channel, $actor, $request->query('state'))) {
            return $this->failed($channel, 'La solicitud de autorización no es válida o venció (10 minutos). Vuelve a intentarlo desde Chat Manager.');
        }

        if ($request->filled('error') || $request->filled('error_reason')) {
            return $this->failed($channel, "Se canceló la autorización en {$channel->label()} o no se concedieron los permisos. No se creó ninguna conexión.", 'warning');
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '' || strlen($code) > 2048 || ! $app->isConfiguredFor($channel)) {
            return $this->failed($channel, 'Meta no devolvió un código de autorización válido. Vuelve a intentarlo.');
        }

        try {
            $accounts = $authorization->accounts($channel, $code, $this->redirectUri($channel));
        } catch (MetaGraphException $exception) {
            return $this->failed($channel, $exception->getMessage());
        }

        if ($accounts === []) {
            return $this->failed($channel, $channel === ConnectionChannel::Instagram
                ? 'Meta no devolvió ninguna cuenta profesional de Instagram.'
                : 'Meta no devolvió ninguna página. Verifica que eres administrador de la página y que la seleccionaste al autorizar.');
        }

        $session->keepAccounts($channel, $actor, $accounts);

        return to_route('connections.meta.accounts', $channel->value);
    }

    public function accounts(Request $request, string $channel, MetaOAuthSession $session): Response|RedirectResponse
    {
        Gate::authorize('create', Connection::class);
        $channel = $this->channel($channel);

        /** @var User $actor */
        $actor = $request->user();
        $accounts = $session->accounts($channel, $actor);

        if ($accounts === null) {
            return $this->failed($channel, 'La selección de cuentas venció. Vuelve a autorizar el acceso.');
        }

        $existing = Connection::query()
            ->where('channel', $channel)
            ->whereIn('external_account_id', array_column($accounts, 'id'))
            ->get(['id', 'name', 'external_account_id', 'deleted_at'])
            ->keyBy('external_account_id');

        return Inertia::render('connections/MetaAccounts', [
            'channel' => ['value' => $channel->value, 'label' => $channel->label()],
            'accounts' => array_map(fn (array $account): array => [
                'id' => (string) $account['id'],
                'name' => (string) $account['name'],
                'username' => $account['username'] ?? null,
                'picture' => $account['picture'] ?? null,
                'can_message' => (bool) ($account['can_message'] ?? true),
                'scopes' => array_map(fn (string $scope): array => ['value' => $scope, 'label' => MetaApp::scopeLabel($scope)], array_values(array_filter((array) ($account['scopes'] ?? []), 'is_string'))),
                'existing' => ($connection = $existing->get((string) $account['id'])) === null ? null : [
                    'id' => $connection->id,
                    'name' => $connection->name,
                    'is_deleted' => $connection->isDeleted(),
                ],
            ], $accounts),
        ]);
    }

    public function connect(Request $request, string $channel, MetaOAuthSession $session, ConnectMetaAccount $connect): RedirectResponse
    {
        Gate::authorize('create', Connection::class);
        $channel = $this->channel($channel);

        /** @var User $actor */
        $actor = $request->user();
        $validated = $request->validate(['account_id' => ['required', 'string', 'max:64']]);
        $accounts = $session->accounts($channel, $actor);

        if ($accounts === null) {
            return $this->failed($channel, 'La selección de cuentas venció. Vuelve a autorizar el acceso.');
        }

        // Only the offered accounts of this channel that can send messages
        // (a page without the MESSAGING/MODERATE task is shown but disabled).
        $account = collect($accounts)
            ->map(fn (array $candidate): ?array => ($candidate['can_message'] ?? true) === false ? null : self::account($candidate))
            ->first(fn (?array $candidate): bool => $candidate !== null && $candidate['id'] === $validated['account_id']);

        if ($account === null) {
            throw ValidationException::withMessages(['account_id' => 'Elige una de las cuentas que Meta autorizó.']);
        }

        $connection = $connect->handle($channel, $account, $actor);
        $session->forgetAccounts();

        Inertia::flash('toast', $connection->app_subscribed
            ? ['type' => 'success', 'message' => "{$channel->label()}: «{$connection->external_account_name}» quedó autorizada. Revisa sus departamentos y actívala para empezar a recibir mensajes."]
            : ['type' => 'warning', 'message' => "La cuenta «{$connection->external_account_name}» quedó autorizada, pero Meta no confirmó la suscripción al webhook. Revisa los permisos de la app y vuelve a autorizar."]);

        return to_route('connections.show', $connection);
    }

    public function disconnect(Request $request, Connection $connection, DisconnectMetaAccount $disconnect): RedirectResponse
    {
        Gate::authorize('update', $connection);
        abort_unless($connection->usesMetaAuthorization(), 404);

        $disconnect->handle($connection, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Conexión {$connection->name} desconectada de Meta. Sus conversaciones e historial se conservan; puedes volver a autorizarla cuando quieras."]);

        return back();
    }

    /**
     * An account kept by MetaOAuthSession, read back with its expected shape
     * (null when it is incomplete).
     *
     * @param  array<array-key, mixed>  $candidate
     * @return array{id: string, name: string, username: string|null, picture: string|null, access_token: string, scopes: list<string>, expires_at: int|null, meta_user_id: string|null}|null
     */
    private static function account(array $candidate): ?array
    {
        if (! is_scalar($candidate['id'] ?? null) || ! is_string($candidate['access_token'] ?? null) || $candidate['access_token'] === '') {
            return null;
        }

        return [
            'id' => (string) $candidate['id'],
            'name' => is_string($candidate['name'] ?? null) ? $candidate['name'] : (string) $candidate['id'],
            'username' => is_string($candidate['username'] ?? null) ? $candidate['username'] : null,
            'picture' => is_string($candidate['picture'] ?? null) ? $candidate['picture'] : null,
            'access_token' => $candidate['access_token'],
            'scopes' => array_values(array_filter((array) ($candidate['scopes'] ?? []), 'is_string')),
            'expires_at' => is_int($candidate['expires_at'] ?? null) ? $candidate['expires_at'] : null,
            'meta_user_id' => is_scalar($candidate['meta_user_id'] ?? null) ? (string) $candidate['meta_user_id'] : null,
        ];
    }

    private function channel(string $value): ConnectionChannel
    {
        $channel = ConnectionChannel::tryFrom($value);

        abort_unless(in_array($channel, [ConnectionChannel::Messenger, ConnectionChannel::Instagram], true), 404);

        return $channel;
    }

    private function redirectUri(ConnectionChannel $channel): string
    {
        return route('connections.meta.callback', $channel->value);
    }

    private function failed(ConnectionChannel $channel, string $message, string $type = 'error'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return to_route('connections.meta.show', $channel->value);
    }
}
