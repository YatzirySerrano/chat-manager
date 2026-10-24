<?php

namespace App\Services\WebChat;

use App\Models\WebChatWidget;

/**
 * Short-lived proof that a frame of the widget was loaded by an authorized
 * host page. The frame (GET /widget/v1/frame/{publicKey}) issues it after
 * matching the Referer origin against the widget's allowed origins, and
 * POST sessions requires it, so every visitor session is bound to a frame
 * load whose origin was authorized. Format: "{exp}.{base64url(origin)}.{hmac}",
 * with HMAC-SHA256 (app.key) over "v1|{widget id}|{public key}|{origin}|{exp}":
 * rotating the public key also invalidates the tickets issued before.
 *
 * The hard barrier stays the browser's CSP frame-ancestors; Origin and
 * Referer can be forged outside a browser, so this is an extra layer.
 */
class FrameTicket
{
    public function issue(WebChatWidget $widget, string $hostOrigin): string
    {
        $origin = WebChatWidget::normalizeOrigin($hostOrigin) ?? throw new \InvalidArgumentException('The host origin is not a valid origin.');
        $expires = now()->getTimestamp() + max(1, (int) config('webchat.frame_ticket_ttl_seconds', 600));

        return $expires.'.'.self::base64UrlEncode($origin).'.'.$this->signature($widget, $origin, $expires);
    }

    /**
     * The host origin the ticket was issued for, or null when it is
     * malformed, forged, expired, issued for another widget (or before its
     * public key was rotated) or for an origin the widget no longer allows.
     */
    public function verify(WebChatWidget $widget, string $ticket): ?string
    {
        if (strlen($ticket) > 1024 || preg_match('/^(\d{1,12})\.([A-Za-z0-9_-]{1,700})\.([0-9a-f]{64})$/', $ticket, $parts) !== 1) {
            return null;
        }

        $expires = (int) $parts[1];
        $origin = self::base64UrlDecode($parts[2]);

        if ($origin === null || ! hash_equals($this->signature($widget, $origin, $expires), $parts[3])) {
            return null;
        }

        if ($expires < now()->getTimestamp() || ! $widget->allowsOrigin($origin)) {
            return null;
        }

        return $origin;
    }

    private function signature(WebChatWidget $widget, string $origin, int $expires): string
    {
        return hash_hmac('sha256', "v1|{$widget->id}|{$widget->public_key}|{$origin}|{$expires}", (string) config('app.key'));
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false || $decoded === '' ? null : $decoded;
    }
}
