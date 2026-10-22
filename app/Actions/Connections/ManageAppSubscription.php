<?php

namespace App\Actions\Connections;

use App\Enums\AuditAction;
use App\Models\Connection;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Meta\ConnectionMonitor;
use App\Services\Meta\MetaGraphClient;
use App\Services\Meta\MetaGraphException;
use Illuminate\Validation\ValidationException;

/**
 * Whether the Meta app is subscribed to the webhooks of the connection's
 * WhatsApp Business Account (GET /{waba-id}/subscribed_apps), and the
 * subscription itself (POST /{waba-id}/subscribed_apps). Without it Meta
 * sends no message or status webhooks even if the callback URL is verified.
 *
 * When the connection stores its Meta App ID, only that app counts as
 * subscribed; otherwise any subscribed app is reported, with that caveat.
 */
class ManageAppSubscription
{
    public function __construct(
        private MetaGraphClient $graph,
        private AuditLogger $audit,
        private ConnectionMonitor $monitor,
    ) {}

    /**
     * @return array{subscribed: bool, apps: list<string>, message: string}
     *
     * @throws ValidationException|MetaGraphException
     */
    public function check(Connection $connection, User $actor): array
    {
        $this->ensureConfigured($connection);

        try {
            $response = $this->graph->get((string) $connection->access_token, rawurlencode((string) $connection->business_account_id).'/subscribed_apps', [], 'la consulta de suscripción');
        } catch (MetaGraphException $exception) {
            $this->recordFailure($connection, $exception);

            throw $exception;
        }

        $apps = [];

        foreach (is_array($response['data'] ?? null) ? $response['data'] : [] as $app) {
            $data = is_array($app) ? ($app['whatsapp_business_api_data'] ?? $app) : null;

            if (is_array($data) && isset($data['id']) && is_scalar($data['id'])) {
                $apps[(string) $data['id']] = is_string($data['name'] ?? null) ? $data['name'] : (string) $data['id'];
            }
        }

        $appId = (string) $connection->meta_app_id;
        $subscribed = $appId !== '' ? array_key_exists($appId, $apps) : $apps !== [];

        $connection->forceFill(['app_subscribed' => $subscribed, 'subscription_checked_at' => now()])->save();

        $this->audit->record(AuditAction::ConnectionSubscriptionChecked, $connection, [
            'subscribed' => $subscribed,
            'apps' => array_values($apps),
        ], $actor);

        $message = match (true) {
            $subscribed && $appId !== '' => 'La app de Meta está suscrita a los webhooks de la cuenta de WhatsApp Business.',
            $subscribed => 'Hay una app suscrita a la cuenta de WhatsApp Business ('.implode(', ', $apps).'). Guarda el Meta App ID para comprobar que sea la tuya.',
            $apps !== [] => 'La cuenta tiene otras apps suscritas, pero no la app configurada en esta conexión.',
            default => 'Ninguna app está suscrita a los webhooks de esta cuenta de WhatsApp Business.',
        };

        return ['subscribed' => $subscribed, 'apps' => array_values($apps), 'message' => $message];
    }

    /**
     * @throws ValidationException|MetaGraphException
     */
    public function subscribe(Connection $connection, User $actor): void
    {
        $this->ensureConfigured($connection);

        try {
            $response = $this->graph->post((string) $connection->access_token, rawurlencode((string) $connection->business_account_id).'/subscribed_apps', [], 'la suscripción de la app');
        } catch (MetaGraphException $exception) {
            $this->recordFailure($connection, $exception);

            throw $exception;
        }

        if (($response['success'] ?? false) !== true) {
            throw new MetaGraphException('Meta no confirmó la suscripción de la app.', rejected: false, kind: MetaGraphException::AMBIGUOUS);
        }

        $connection->forceFill(['app_subscribed' => true, 'subscription_checked_at' => now()])->save();
        $this->audit->record(AuditAction::ConnectionAppSubscribed, $connection, [], $actor);
    }

    /**
     * @throws ValidationException
     */
    private function ensureConfigured(Connection $connection): void
    {
        if (blank($connection->business_account_id) || blank($connection->access_token)) {
            throw ValidationException::withMessages([
                'connection' => 'Guarda el WhatsApp Business Account ID y el token de acceso antes de revisar la suscripción.',
            ]);
        }
    }

    private function recordFailure(Connection $connection, MetaGraphException $exception): void
    {
        if ($exception->kind === MetaGraphException::TOKEN_INVALID) {
            $this->monitor->credentialsRejected($connection, $exception->getMessage());
        }
    }
}
