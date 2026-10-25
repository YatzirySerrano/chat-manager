<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Models\ApiToken;
use App\Models\ChatAppearance;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\ContactAlias;
use App\Models\ContactAttribution;
use App\Models\ContactNote;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\QuickReply;
use App\Models\Role;
use App\Models\Tag;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use App\Models\WebChatSession;
use App\Models\WebChatWidget;
use App\Services\Meta\MetaCloudWhatsAppTransport;
use App\Services\Meta\SimulatedWhatsAppTransport;
use App\Services\Meta\UnavailableWhatsAppTransport;
use App\Services\Meta\WhatsAppTransport;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Outbound WhatsApp transport (config whatsapp.transport): the real Cloud
        // API, the local-only simulation, or the explicit "unavailable" default.
        $this->app->bind(WhatsAppTransport::class, fn ($app): WhatsAppTransport => match (config('whatsapp.transport')) {
            'meta' => $app->make(MetaCloudWhatsAppTransport::class),
            'simulated' => $app->isProduction() ? new UnavailableWhatsAppTransport : new SimulatedWhatsAppTransport,
            default => new UnavailableWhatsAppTransport,
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
    }

    /**
     * Destructive database commands are always blocked in production and,
     * elsewhere, only allowed on the testing database (database.testing_database)
     * or when explicitly enabled through DB_ALLOW_DESTRUCTIVE_COMMANDS.
     */
    protected function destructiveCommandsAreProhibited(): bool
    {
        if (app()->isProduction()) {
            return true;
        }

        $database = (string) config('database.connections.'.config('database.default').'.database');

        return $database !== config('database.testing_database') && ! config('database.allow_destructive_commands');
    }

    /**
     * Register gates that are not tied to a model.
     */
    protected function configureAuthorization(): void
    {
        // WhatsApp webhooks: per webhook URL (connection), so one noisy number
        // never throttles another.
        RateLimiter::for('whatsapp-webhooks', fn (Request $request): Limit => Limit::perMinute(max(1, (int) config('whatsapp.webhooks.rate_limit_per_minute', 1200)))
            ->by('whatsapp-webhook:'.(string) $request->route('webhookKey')));

        // Meta app webhook (Messenger/Instagram): one fixed public URL, so the
        // limits are per source address; invalid signatures are counted apart
        // in MetaWebhookController. The media route is fetched by Meta's few
        // crawler addresses, already protected by the URL signature.
        RateLimiter::for('meta-webhooks', fn (Request $request): Limit => Limit::perMinute(max(1, (int) config('services.meta.webhook_rate_limit_per_minute', 6000)))
            ->by('meta-webhook:'.$request->ip()));
        RateLimiter::for('meta-webhook-verify', fn (Request $request): Limit => Limit::perMinute(30)
            ->by('meta-webhook-verify:'.$request->ip()));
        RateLimiter::for('meta-media', fn (Request $request): Limit => Limit::perMinute(max(1, (int) config('services.meta.media_rate_limit_per_minute', 1200)))
            ->by('meta-media:'.$request->ip()));

        $this->configureApiRateLimiters();

        Gate::define('view-settings', fn (User $user): bool => $user->holdsPermission(Permission::ViewSettings));
        Gate::define('update-settings', fn (User $user): bool => $user->holdsPermission(Permission::UpdateSettings));
        Gate::define('view-appearance', fn (User $user): bool => $user->holdsPermission(Permission::ViewAppearance));
        Gate::define('update-appearance', fn (User $user): bool => $user->holdsPermission(Permission::UpdateAppearance));
    }

    /**
     * Named limiters of the /api/v1 surfaces and the web chat frame. The
     * authentication middleware always runs before them, so the principal
     * (visitor session or API token) is already on the request; the IP is
     * only used through its HMAC.
     */
    protected function configureApiRateLimiters(): void
    {
        $perMinute = fn (string $key, int $default): int => max(1, (int) config($key, $default));
        $ipHash = fn (Request $request): string => (string) WebChatSession::hashIpForLimits($request->ip());
        $session = function (Request $request) use ($ipHash): string {
            $session = $request->attributes->get('webchat_session');

            return $session instanceof WebChatSession ? 'session:'.$session->public_id : 'ip:'.$ipHash($request);
        };
        $publicKey = function (Request $request): string {
            $key = $request->route('publicKey');

            if (is_string($key)) {
                return $key;
            }

            $widget = $request->attributes->get('webchat_widget');

            if ($widget instanceof WebChatWidget) {
                return $widget->public_key;
            }

            $session = $request->attributes->get('webchat_session');

            return $session instanceof WebChatSession ? (string) $session->connection->webChatWidget?->public_key : '';
        };
        $token = function (Request $request) use ($ipHash): string {
            $token = $request->attributes->get('api_token');

            return $token instanceof ApiToken ? 'token:'.$token->public_id : 'ip:'.$ipHash($request);
        };

        RateLimiter::for('webchat-frame', fn (Request $request): Limit => Limit::perMinute($perMinute('webchat.limits.frame_per_minute', 120))
            ->by('webchat-frame:'.$publicKey($request).'|'.$ipHash($request)));

        // Only per address: the per-widget cap counts sessions that proved a
        // valid frame ticket, so it is applied by VisitorSessionController
        // after verifying it (junk requests must never exhaust it).
        RateLimiter::for('webchat-sessions', fn (Request $request): Limit => Limit::perMinute($perMinute('webchat.limits.sessions_per_ip_per_minute', 10))
            ->by('webchat-sessions-ip:'.$publicKey($request).'|'.$ipHash($request)));

        RateLimiter::for('webchat-messages', function (Request $request) use ($perMinute, $ipHash, $session): array {
            $visitor = $request->attributes->get('webchat_session');
            $limits = [
                Limit::perMinute($perMinute('webchat.limits.messages_per_session_per_minute', 20))
                    ->by('webchat-messages-session:'.$session($request)),
                Limit::perMinute($perMinute('webchat.limits.messages_per_ip_per_minute', 60))
                    ->by('webchat-messages-ip:'.$ipHash($request)),
            ];

            // A shared cap every visitor of the connection draws from is a
            // denial-of-service lever, so it is only a high cost ceiling
            // (0 disables it); the per-session and per-address limits are
            // what stops an abuser.
            $perConnection = (int) config('webchat.limits.messages_per_connection_per_minute', 6000);

            if ($perConnection > 0) {
                $limits[] = Limit::perMinute($perConnection)
                    ->by('webchat-messages-connection:'.($visitor instanceof WebChatSession ? $visitor->connection_id : 'none'));
            }

            return $limits;
        });

        RateLimiter::for('webchat-reads', fn (Request $request): Limit => Limit::perMinute($perMinute('webchat.limits.reads_per_minute', 60))
            ->by('webchat-reads:'.$session($request)));

        RateLimiter::for('webchat-broadcasting', fn (Request $request): Limit => Limit::perMinute($perMinute('webchat.limits.broadcasting_per_minute', 30))
            ->by('webchat-broadcasting:'.$session($request)));

        RateLimiter::for('api-v1-read', fn (Request $request): Limit => Limit::perMinute($perMinute('integrations.limits.reads_per_minute', 600))
            ->by('api-v1-read:'.$token($request)));

        RateLimiter::for('api-v1-write', fn (Request $request): Limit => Limit::perMinute($perMinute('integrations.limits.writes_per_minute', 60))
            ->by('api-v1-write:'.$token($request)));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        Model::preventLazyLoading(! app()->isProduction());

        Relation::morphMap([
            'user' => User::class,
            'department' => Department::class,
            'connection' => Connection::class,
            'contact' => Contact::class,
            'contact_note' => ContactNote::class,
            'contact_alias' => ContactAlias::class,
            'chat_appearance' => ChatAppearance::class,
            'contact_attribution' => ContactAttribution::class,
            'conversation' => Conversation::class,
            'message' => Message::class,
            'role' => Role::class,
            'tag' => Tag::class,
            'quick_reply' => QuickReply::class,
            'team_thread' => TeamThread::class,
            'api_token' => ApiToken::class,
            'web_chat_widget' => WebChatWidget::class,
        ]);

        DB::prohibitDestructiveCommands($this->destructiveCommandsAreProhibited());

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
