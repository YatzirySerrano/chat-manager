<?php

namespace App\Services\WebChat;

/**
 * Opaque position in a visitor's message history: base64url of
 * "{message id}|{hmac16}", so the widget never sees raw ids and a forged
 * cursor is rejected. A cursor only marks a position; what the session may
 * read is always decided by the query.
 */
class VisitorCursor
{
    public function encode(int $messageId): string
    {
        return rtrim(strtr(base64_encode($messageId.'|'.$this->mac($messageId)), '+/', '-_'), '=');
    }

    public function decode(string $cursor): ?int
    {
        if ($cursor === '' || strlen($cursor) > 64 || preg_match('/^[A-Za-z0-9_-]+$/', $cursor) !== 1) {
            return null;
        }

        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);

        if ($decoded === false || preg_match('/^([1-9]\d{0,18})\|([0-9a-f]{16})$/', $decoded, $parts) !== 1) {
            return null;
        }

        $messageId = (int) $parts[1];

        return hash_equals($this->mac($messageId), $parts[2]) ? $messageId : null;
    }

    private function mac(int $messageId): string
    {
        return substr(hash_hmac('sha256', 'webchat-cursor|'.$messageId, (string) config('app.key')), 0, 16);
    }
}
