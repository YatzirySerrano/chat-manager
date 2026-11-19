<?php

namespace App\Http\Controllers\WebChat;

use App\Actions\WebChat\UpdateWidgetSettings;
use App\Http\Controllers\Controller;
use App\Http\Middleware\SecurityHeaders;
use App\Models\WebChatWidget;
use App\Services\WebChat\FrameTicket;
use App\Services\WebChat\VisitorFiles;
use App\Services\WebChat\WebChatAvailability;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The page the loader embeds in an iframe on the host site (GET
 * /widget/v1/frame/{publicKey}). It is the only response of the
 * application that other origins may frame: it lifts X-Frame-Options and
 * sends its own CSP whose frame-ancestors are 'self' plus the widget's
 * allowed origins, always (not only in production).
 *
 * The host page's origin comes from the Referer (the loader creates the
 * iframe with referrerpolicy="origin"). Only when it is one of the allowed
 * origins does the page carry a frame ticket, the proof POST sessions
 * requires; otherwise it renders the "not authorized" state with no ticket.
 * ?preview=1 is only honored when the Referer is the application itself
 * (the live preview of the connection form): no ticket, no session.
 *
 * The embedded configuration is public by design: appearance (texts,
 * colors, the floating button the loader draws and the window size),
 * endpoints, allowed origins, availability and what files a visitor may
 * send. Never the identity secret or a token.
 */
class WidgetFrameController extends Controller
{
    public function __invoke(Request $request, string $publicKey, FrameTicket $tickets, WebChatAvailability $availability, SecurityHeaders $headers, VisitorFiles $files): Response
    {
        $widget = WebChatWidget::query()
            ->with('connection')
            ->where('public_key', $publicKey)
            ->first();

        if ($widget === null || $widget->connection === null || $widget->connection->isDeleted()) {
            throw new NotFoundHttpException;
        }

        $connection = $widget->connection;
        $connection->setRelation('webChatWidget', $widget);

        $request->attributes->set(SecurityHeaders::ALLOW_FRAMING, true);

        $appOrigin = (string) WebChatWidget::normalizeOrigin($request->getSchemeAndHttpHost());
        $hostOrigin = $this->refererOrigin($request);
        $allowedOrigins = $this->allowedOrigins($widget);
        // Behind a proxy that does not pass the scheme on, the request may
        // look like http:// while the page is https://: APP_URL's origin
        // counts as the application itself too.
        $configuredOrigin = WebChatWidget::normalizeOrigin((string) config('app.url'));
        $ownOrigins = array_values(array_unique(array_filter([$appOrigin, $configuredOrigin])));
        $preview = $request->boolean('preview') && $hostOrigin !== null && in_array($hostOrigin, $ownOrigins, true);

        if ($preview) {
            $appOrigin = $hostOrigin;
        }
        $authorized = ! $preview && $hostOrigin !== null && $widget->allowsOrigin($hostOrigin);
        $available = $connection->isOperational() && $connection->isConfigured();

        $state = match (true) {
            $preview => 'preview',
            ! $authorized => 'unauthorized',
            ! $available => 'offline',
            default => 'ready',
        };

        $nonce = Vite::cspNonce() ?? Vite::useCspNonce();

        $config = [
            'v' => 1,
            'state' => $state,
            'public_key' => $widget->public_key,
            'host_origin' => $preview ? $appOrigin : ($authorized ? $hostOrigin : null),
            'allowed_origins' => $preview ? [$appOrigin] : $allowedOrigins,
            'ticket' => $state === 'ready' ? $tickets->issue($widget, (string) $hostOrigin) : null,
            'appearance' => [
                'title' => $widget->title,
                'subtitle' => $widget->subtitle,
                'welcome_text' => $widget->welcome_text,
                'offline_text' => $widget->offline_text,
                'accent_color' => $widget->accent_color,
                'position' => $widget->position->value,
                'launcher_enabled' => $widget->launcher_enabled,
                'launcher_label' => $widget->launcher_label,
                ...$widget->launcherStyle(),
                'background_color' => $widget->background_color,
                'visitor_bubble_color' => $widget->visitor_bubble_color,
                'visitor_text_color' => $widget->visitor_text_color,
                'agent_bubble_color' => $widget->agent_bubble_color,
                'agent_text_color' => $widget->agent_text_color,
                'online_text' => $widget->online_text,
                'offline_status_text' => $widget->offline_status_text,
                'composer_placeholder' => $widget->composer_placeholder,
                'avatar' => $state === 'unauthorized' ? null : $this->avatar($widget),
                // The button's own image, inlined (the loader draws it on the host page).
                'launcher_image' => $state === 'unauthorized' ? null : UpdateWidgetSettings::imageDataUri($widget->launcher_icon_path),
            ],
            'visitor_files' => $widget->allow_visitor_files
                ? ['enabled' => true, ...$files->summary()]
                : ['enabled' => false],
            'identity_mode' => $widget->identity_mode->value,
            'prechat' => [
                'fields' => array_values(array_intersect(['name', 'email'], (array) ($widget->prechat['fields'] ?? []))),
                'required' => (bool) ($widget->prechat['required'] ?? false),
                'privacy_notice' => $widget->prechat['privacy_notice'] ?? null,
            ],
            'availability' => $state === 'unauthorized' ? null : $availability->for($widget),
            'message_max_length' => (int) config('webchat.message_max_length', 2000),
            'poll' => [
                'visible_seconds' => (int) config('webchat.poll.visible_seconds', 4),
                'hidden_seconds' => (int) config('webchat.poll.hidden_seconds', 20),
            ],
            'endpoints' => [
                'sessions' => route('api.v1.widget.sessions.store', $widget->public_key, false),
                'session' => route('api.v1.widget.session.show', absolute: false),
                'identity' => route('api.v1.widget.session.identity.store', absolute: false),
                'messages' => route('api.v1.widget.session.messages.index', absolute: false),
                'receipts' => route('api.v1.widget.session.receipts.store', absolute: false),
                'files' => route('api.v1.widget.session.files.store', absolute: false),
            ],
        ];

        return response()
            ->view('widget.frame', ['config' => $config, 'title' => $widget->title])
            ->header('Content-Security-Policy', $this->policy($nonce, $preview ? [] : $allowedOrigins, $headers))
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * Normalized origin of the Referer, or null when there is none or it
     * is not an http(s) URL.
     */
    private function refererOrigin(Request $request): ?string
    {
        $referer = (string) $request->headers->get('referer', '');
        $parts = $referer === '' ? false : parse_url($referer);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return WebChatWidget::normalizeOrigin($parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : ''));
    }

    /**
     * @return list<string>
     */
    private function allowedOrigins(WebChatWidget $widget): array
    {
        $origins = array_map(fn (string $origin): ?string => WebChatWidget::normalizeOrigin($origin), $widget->allowed_origins);

        return array_values(array_unique(array_filter($origins)));
    }

    /**
     * The frame's own policy: the application's (scripts with the nonce,
     * connections to itself and Reverb) except frame-ancestors, which also
     * lists the widget's allowed origins. While the Vite development server
     * runs, its origin is allowed for scripts, styles and its socket.
     *
     * @param  list<string>  $allowedOrigins
     */
    private function policy(string $nonce, array $allowedOrigins, SecurityHeaders $headers): string
    {
        $dev = $this->viteDevSources();
        $connect = array_unique(["'self'", ...$headers->realtimeOrigins(), ...$dev['http'], ...$dev['ws']]);

        return implode('; ', [
            "default-src 'self'",
            'script-src '.implode(' ', ["'self'", "'nonce-{$nonce}'", ...$dev['http']]),
            'style-src '.implode(' ', ["'self'", "'unsafe-inline'", ...$dev['http']]),
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "media-src 'self' blob:",
            'connect-src '.implode(' ', $connect),
            "worker-src 'none'",
            "frame-src 'none'",
            'frame-ancestors '.implode(' ', ["'self'", ...$allowedOrigins]),
            "form-action 'self'",
            "base-uri 'none'",
            "object-src 'none'",
        ]);
    }

    /**
     * Sources of the Vite development server while it runs (public/hot),
     * for its scripts, styles and HMR socket. CSP cannot express IPv6
     * literals such as [::1], so those fall back to the scheme alone; this
     * only ever happens on a developer's machine.
     *
     * @return array{http: list<string>, ws: list<string>}
     */
    private function viteDevSources(): array
    {
        $origin = Vite::isRunningHot() ? WebChatWidget::normalizeOrigin(trim((string) @file_get_contents(public_path('hot')))) : null;

        if ($origin === null) {
            return ['http' => [], 'ws' => []];
        }

        $secure = str_starts_with($origin, 'https://');

        if (str_contains($origin, '[')) {
            return ['http' => [$secure ? 'https:' : 'http:'], 'ws' => [$secure ? 'wss:' : 'ws:']];
        }

        return ['http' => [$origin], 'ws' => [(string) preg_replace('/^http/', 'ws', $origin)]];
    }

    /**
     * The avatar inlined as a data URI (it lives in the private disk).
     */
    private function avatar(WebChatWidget $widget): ?string
    {
        // From whichever disk holds it (it stays readable after MEDIA_DISK changes).
        return UpdateWidgetSettings::imageDataUri($widget->avatar_path);
    }
}
