<?php

namespace App\Http\Controllers\WebChat\Api;

use App\Actions\Messaging\RecordMessageStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\WebChat\StoreVisitorReceiptRequest;
use App\Models\WebChatSession;
use App\Services\Meta\ProviderStatus;
use App\Services\WebChat\VisitorSessions;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;

/**
 * Delivery and read receipts of the agents' replies, reported by the
 * visitor's widget. They go through the same RecordMessageStatus as
 * Meta's status webhooks (forward only, idempotent, MessageStatusChanged),
 * and only for outbound web chat messages the session can see.
 */
class VisitorReceiptController extends Controller
{
    /** Messages advanced per receipt; older ones were acknowledged before. */
    private const MAX_MESSAGES = 100;

    public function store(StoreVisitorReceiptRequest $request, VisitorSessions $sessions, RecordMessageStatus $recordStatus): Response
    {
        /** @var WebChatSession $session */
        $session = $request->attributes->get('webchat_session');
        $now = CarbonImmutable::now();

        $steps = array_filter([
            'delivered' => [$request->deliveredUpTo(), [MessageStatus::Accepted, MessageStatus::Sent]],
            'read' => [$request->readUpTo(), [MessageStatus::Accepted, MessageStatus::Sent, MessageStatus::Delivered]],
        ], fn (array $step): bool => $step[0] !== null);

        // A reply read is also delivered (statuses only move forward), so the
        // delivered step skips what the read step advances: one status write,
        // event and signal per reply instead of two.
        $readUpTo = $request->readUpTo();

        foreach ($steps as $status => [$upTo, $from]) {
            $providerIds = $sessions->visibleMessages($session)
                ->where('direction', MessageDirection::Outbound)
                ->where('id', '<=', $upTo)
                ->when($status === 'delivered' && $readUpTo !== null, fn ($query) => $query->where('id', '>', $readUpTo))
                ->whereIn('status', $from)
                ->where('whatsapp_message_id', 'like', 'web.out.%')
                ->orderByDesc('id')
                ->limit(self::MAX_MESSAGES)
                ->pluck('whatsapp_message_id');

            foreach ($providerIds as $providerId) {
                $recordStatus->handle($session->connection, new ProviderStatus('', (string) $providerId, $status, $now));
            }
        }

        return response()->noContent();
    }
}
