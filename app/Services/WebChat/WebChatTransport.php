<?php

namespace App\Services\WebChat;

use App\Models\Message;
use App\Services\Messaging\MessageTransport;
use App\Services\Meta\SendResult;
use Illuminate\Support\Str;

/**
 * Outbound transport of the web chat. The message already lives in the
 * database, where the visitor reads it, so "sending" only assigns a provider
 * id ('web.out.{ulid}') and the message becomes accepted. Nothing goes over
 * the network and the outcome is never ambiguous; the visitor is signalled
 * when the message status changes (after commit).
 */
class WebChatTransport implements MessageTransport
{
    public function send(Message $message): SendResult
    {
        return new SendResult('web.out.'.strtolower((string) Str::ulid()));
    }

    public function name(): string
    {
        return 'web_chat';
    }
}
