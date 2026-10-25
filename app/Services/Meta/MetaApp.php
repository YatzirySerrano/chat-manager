<?php

namespace App\Services\Meta;

use App\Enums\ConnectionChannel;

/**
 * Configuration of the Meta app that authorizes Facebook Messenger pages and
 * Instagram professional accounts (config services.meta, read from the
 * environment, never stored in the database).
 *
 * - Messenger uses Facebook Login with the Facebook app id and secret
 *   (META_APP_ID, META_APP_SECRET; META_LOGIN_CONFIG_ID switches to
 *   Facebook Login for Business).
 * - Instagram uses the Instagram API with Instagram Login, whose app id and
 *   secret are shown separately in the dashboard (META_INSTAGRAM_APP_ID,
 *   META_INSTAGRAM_APP_SECRET); when they are not set the Facebook ones
 *   are used.
 * - The app-level webhook needs META_WEBHOOK_VERIFY_TOKEN.
 */
class MetaApp
{
    /**
     * Facebook Login permissions requested when no Login for Business configuration is set.
     * business_management is deliberately not requested: it may be needed to
     * list pages owned by a Business Portfolio (add it to the Login for
     * Business configuration in that case; docs/meta-messenger-instagram.md).
     *
     * @var list<string>
     */
    public const MESSENGER_SCOPES = ['pages_show_list', 'pages_messaging', 'pages_manage_metadata', 'pages_read_engagement'];

    /**
     * Instagram Login permissions.
     *
     * @var list<string>
     */
    public const INSTAGRAM_SCOPES = ['instagram_business_basic', 'instagram_business_manage_messages'];

    /**
     * Webhook fields each account is subscribed to.
     *
     * @var array<string, list<string>>
     */
    public const SUBSCRIBED_FIELDS = [
        'messenger' => ['messages', 'message_deliveries', 'message_reads', 'message_echoes', 'messaging_postbacks', 'messaging_policy_enforcement'],
        'instagram' => ['messages', 'messaging_seen', 'messaging_postbacks', 'message_reactions'],
    ];

    /**
     * Spanish description of a Meta permission, for administrators.
     */
    public static function scopeLabel(string $scope): string
    {
        return match ($scope) {
            'pages_show_list' => 'Ver la lista de páginas que administras',
            'pages_messaging' => 'Enviar y recibir mensajes de Messenger de la página',
            'pages_manage_metadata' => 'Suscribir la página al webhook y administrar su configuración',
            'pages_read_engagement' => 'Leer el contenido y la información básica de la página',
            'business_management' => 'Acceder a los activos del portafolio comercial',
            'instagram_business_basic' => 'Leer el perfil básico de la cuenta profesional de Instagram',
            'instagram_business_manage_messages' => 'Enviar y recibir mensajes directos de Instagram',
            'instagram_basic' => 'Leer el perfil básico de Instagram',
            'instagram_manage_messages' => 'Administrar los mensajes directos de Instagram',
            default => 'Permiso adicional de Meta ('.$scope.')',
        };
    }

    /**
     * What the administrator needs before authorizing an account of the channel.
     *
     * @return list<string>
     */
    public static function requirements(ConnectionChannel $channel): array
    {
        return $channel === ConnectionChannel::Instagram
            ? [
                'Una cuenta de Instagram profesional (empresa o creador). Las cuentas personales no pueden usar la API de mensajería.',
                'En la app de Instagram: Configuración > Mensajes y respuestas a historias > Controles de mensajes > Herramientas conectadas: activa «Permitir acceso a los mensajes».',
                'La app de Meta con el producto «Instagram» (API con inicio de sesión de Instagram) y esta dirección de retorno registrada.',
                'Para atender a clientes reales: app publicada (modo Live), acceso avanzado a instagram_business_manage_messages aprobado en App Review y verificación del negocio. Sin eso solo funcionan las cuentas con un rol en la app.',
            ]
            : [
                'Una página de Facebook publicada en la que tengas el rol de administrador (o la tarea de mensajes y moderación).',
                'La app de Meta con los productos «Inicio de sesión con Facebook» (o «para empresas») y «Messenger», y esta dirección de retorno registrada en modo estricto.',
                'Para atender a clientes reales: app publicada (modo Live), acceso avanzado a pages_messaging aprobado en App Review y verificación del negocio. Sin eso solo llegan mensajes de personas con un rol en la app.',
                'Para mostrar el nombre de los clientes: la función «Business Asset User Profile Access». Sin ella se usa «Usuario de Messenger» y los últimos dígitos de su identificador.',
            ];
    }

    public function clientId(ConnectionChannel $channel): ?string
    {
        $value = $channel === ConnectionChannel::Instagram
            ? (config('services.meta.instagram_app_id') ?: config('services.meta.app_id'))
            : config('services.meta.app_id');

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    public function clientSecret(ConnectionChannel $channel): ?string
    {
        $value = $channel === ConnectionChannel::Instagram
            ? (config('services.meta.instagram_app_secret') ?: config('services.meta.app_secret'))
            : config('services.meta.app_secret');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function loginConfigId(): ?string
    {
        $value = config('services.meta.login_config_id');

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    public function webhookVerifyToken(): ?string
    {
        $value = config('services.meta.webhook_verify_token');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Every distinct secret that may sign an app-level webhook (the Facebook
     * app secret and, when different, the Instagram app secret).
     *
     * @return list<string>
     */
    public function webhookSecrets(): array
    {
        return array_values(array_unique(array_filter([
            $this->clientSecret(ConnectionChannel::Messenger),
            $this->clientSecret(ConnectionChannel::Instagram),
        ])));
    }

    /**
     * Environment variables still missing to authorize accounts of the channel.
     *
     * @return list<string>
     */
    public function missingFor(ConnectionChannel $channel): array
    {
        $missing = [];

        if ($this->clientId($channel) === null) {
            $missing[] = $channel === ConnectionChannel::Instagram ? 'META_INSTAGRAM_APP_ID (o META_APP_ID)' : 'META_APP_ID';
        }

        if ($this->clientSecret($channel) === null) {
            $missing[] = $channel === ConnectionChannel::Instagram ? 'META_INSTAGRAM_APP_SECRET (o META_APP_SECRET)' : 'META_APP_SECRET';
        }

        if ($this->webhookVerifyToken() === null) {
            $missing[] = 'META_WEBHOOK_VERIFY_TOKEN';
        }

        return $missing;
    }

    public function isConfiguredFor(ConnectionChannel $channel): bool
    {
        return $this->missingFor($channel) === [];
    }

    /**
     * appsecret_proof of a token: HMAC-SHA256 of the token with the app secret.
     */
    public function proof(ConnectionChannel $channel, string $accessToken): ?string
    {
        $secret = $this->clientSecret($channel);

        return $secret === null ? null : hash_hmac('sha256', $accessToken, $secret);
    }

    public static function graphVersion(): string
    {
        return trim((string) config('services.meta.graph_version'), '/');
    }
}
