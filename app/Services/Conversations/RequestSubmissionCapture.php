<?php

namespace App\Services\Conversations;

use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Models\Conversation;
use App\Models\Message;
use App\Support\RequestFields\RequestFieldCatalog;
use App\Support\RequestFields\RequestSubmission;

/**
 * Fills «Datos de la solicitud» (conversations.request_fields) from the
 * credit request a field manager writes in the chat (RequestSubmission:
 * «Cliente: …» with its sucursal, amount, product… or a «Solicito
 * autorización …» heading), so the analyst does not have to take it by
 * hand:
 *
 * - only while the conversation has no client yet: the first request
 *   written is the one of the conversation, and nothing stored (typed by
 *   the analyst or captured before) is ever replaced, the request's fields
 *   only complete the missing ones;
 * - a group credit («… crédito grupal», «Nombre del grupo …») where each
 *   message names another member keeps its first client and still records
 *   the group when it was missing.
 *
 * Callers run it inside their transaction with the conversation row
 * locked (ReceiveInboundMessage), as a best effort: it must never stop the
 * receipt. Written through RequestResolutionCapture::merge() as the system,
 * mode «auto», source «inbound_message», with the message id, and signalled
 * in real time (reason "request_fields") once the transaction commits.
 */
class RequestSubmissionCapture
{
    public const SOURCE = 'inbound_message';

    public function __construct(private RequestResolutionCapture $writer) {}

    /**
     * @param  array<string, mixed>  $audit  Extra properties of the audit entry («note» => «backfill»).
     * @param  bool  $signal  Whether open panels are told in real time.
     * @return bool Whether anything changed.
     */
    public function fromMessage(Conversation $conversation, Message $message, array $audit = [], bool $signal = true): bool
    {
        if ($conversation->isImported() || $conversation->isHistoryOnly()) {
            return false;
        }

        $fields = $this->missing($conversation->request_fields, $message);

        if ($fields === []) {
            return false;
        }

        return $this->writer->merge($conversation, [], $fields, $message->id, null, self::SOURCE, $audit, $signal);
    }

    /**
     * The fields the message would add to the stored ones (key => value):
     * every field of the request when no client is stored, only the group
     * of a group credit otherwise; empty when it adds nothing.
     *
     * @return array<string, string>
     */
    public function missing(mixed $stored, Message $message): array
    {
        if ($message->direction !== MessageDirection::Inbound || $message->type !== MessageType::Text) {
            return [];
        }

        $values = RequestFieldCatalog::values($stored);

        // Cheap answer for the usual case: a conversation whose request is
        // already captured only takes the group of a group credit.
        if (isset($values['cliente']) && isset($values['grupo'])) {
            return [];
        }

        $submission = RequestSubmission::read($message->body);

        if ($submission === null) {
            return [];
        }

        $fields = isset($values['cliente'])
            ? ($submission['group'] && isset($submission['fields']['grupo']) ? ['grupo' => $submission['fields']['grupo']] : [])
            : $submission['fields'];

        return array_diff_key($fields, $values);
    }
}
