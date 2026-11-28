<?php

namespace App\Services\Conversations;

use App\Enums\AuditAction;
use App\Enums\ConversationOutcome;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Analytics\AnalyticsCache;
use App\Services\AuditLogger;
use App\Support\RequestFields\RequestFieldCatalog;
use App\Support\RequestFields\RequestResolution;
use App\Support\RequestOutcomeEvidence;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Completes, for the requests attended before the automatic captures
 * existed, what those captures would have recorded (`requests:backfill`):
 *
 * a. «Datos de la solicitud»: a conversation without a client takes the
 *    request its field manager wrote (RequestSubmissionCapture), reading
 *    its inbound texts oldest first; the first one that reads as a
 *    request decides it (a group credit may still add its group later);
 * b. the result of a completed request without one (and not «No es
 *    solicitud»): the result the team announced (RequestOutcomeSuggestions:
 *    «Se autoriza …» with its amount, the «Monto autorizado» of the request
 *    data, «Solicitud rechazada»), dated at the completion (`outcome_at` =
 *    `completed_at`) and attributed to whoever completed it
 *    (`outcome_by_user_id` = `closed_by_user_id`), so the reports place it
 *    where it belongs; the resolution data of that message (monto
 *    autorizado, desembolso, cantidad a pagar…) completes the missing ones.
 *
 * Safe by construction: only live conversations (never imported history
 * nor one absorbed by the request next to it) with activity since the
 * given date, nothing stored is ever replaced (a
 * value is only added where there is none, a result only where there is
 * none), so running it again changes nothing. Each conversation is written
 * in its own short transaction on its locked row, re-checked there; the
 * conversations are walked by id in chunks and each one reads a bounded
 * number of messages. Every change is audited as the system with the note
 * «backfill»; nothing is signalled in real time (a bulk run). Without
 * $apply nothing is written: the same figures are computed as a preview.
 *
 * The results report (RequestOutcomes) reads the conversations directly,
 * so only its short-lived cache is invalidated after applying.
 *
 * @phpstan-type Stats array{reviewed: int, fields: int, field_values: int, groups: int, approved: int, approved_with_amount: int, rejected: int, resolution: int}
 * @phpstan-type Sample array{conversation_id: int, change: string, detail: string}
 */
class RequestBackfill
{
    public const NOTE = 'backfill';

    /** Conversations per chunk. */
    public const CHUNK = 200;

    /** Inbound texts read per conversation looking for its request. */
    public const MESSAGES = 100;

    /** Examples kept of each kind of change. */
    public const SAMPLES = 10;

    public function __construct(
        private RequestSubmissionCapture $submissions,
        private RequestResolutionCapture $writer,
        private RequestOutcomeSuggestions $suggestions,
        private AuditLogger $audit,
        private AnalyticsCache $cache,
    ) {}

    /**
     * @param  Closure(int): void|null  $progress  Called with the size of every chunk processed.
     * @return array{stats: Stats, samples: list<Sample>}
     */
    public function run(CarbonInterface $since, bool $apply, ?Closure $progress = null): array
    {
        $stats = ['reviewed' => 0, 'fields' => 0, 'field_values' => 0, 'groups' => 0, 'approved' => 0, 'approved_with_amount' => 0, 'rejected' => 0, 'resolution' => 0];
        $samples = [];

        $this->candidates($since)->chunkById(self::CHUNK, function (Collection $conversations) use ($apply, &$stats, &$samples, $progress): void {
            foreach ($conversations as $conversation) {
                /** @var Conversation $conversation */
                $stats['reviewed']++;
                $this->requestFields($conversation, $apply, $stats, $samples);
                $this->outcome($conversation, $apply, $stats, $samples);
            }

            if ($progress !== null) {
                $progress($conversations->count());
            }
        });

        if ($apply && $stats['approved'] + $stats['rejected'] > 0) {
            $this->cache->flush();
        }

        return ['stats' => $stats, 'samples' => $samples];
    }

    /**
     * Conversations the backfill looks at.
     *
     * @return Builder<Conversation>
     */
    public function candidates(CarbonInterface $since): Builder
    {
        return Conversation::query()
            ->live()
            ->notHistoryOnly()
            ->whereNull('not_request_at')
            // Absorbed by the request next to it (where that column exists): nothing of its own to complete.
            ->when(Schema::hasColumn('conversations', 'merged_at'), fn (Builder $query) => $query->whereNull('merged_at'))
            ->where('last_message_at', '>=', $since)
            ->select(['id', 'origin', 'history_only_at', 'not_request_at', 'completed_at', 'closed_by_user_id', 'outcome', 'outcome_amount', 'request_fields', 'last_message_at']);
    }

    /**
     * a. The request written by the field manager, for a conversation
     * without a client.
     *
     * @param  Stats  $stats
     * @param  list<Sample>  $samples
     */
    private function requestFields(Conversation $conversation, bool $apply, array &$stats, array &$samples): void
    {
        $stored = $conversation->request_fields;

        if (isset(RequestFieldCatalog::values($stored)['cliente'])) {
            return;
        }

        $messages = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', MessageDirection::Inbound->value)
            ->where('type', MessageType::Text->value)
            ->whereNotNull('body')
            ->orderBy('id')
            ->limit(self::MESSAGES)
            ->get(['id', 'conversation_id', 'direction', 'type', 'body']);

        foreach ($messages as $message) {
            $fields = $this->submissions->missing($stored, $message);

            if ($fields === []) {
                continue;
            }

            $hadClient = isset(RequestFieldCatalog::values($stored)['cliente']);

            if ($apply) {
                $changed = DB::transaction(function () use ($conversation, $message): ?Conversation {
                    $locked = Conversation::query()->lockForUpdate()->find($conversation->id);

                    if ($locked === null || $locked->not_request_at !== null) {
                        return null;
                    }

                    return $this->submissions->fromMessage($locked, $message, ['note' => self::NOTE], signal: false) ? $locked : null;
                });

                if ($changed === null) {
                    return;
                }

                $stored = $changed->request_fields;
            } else {
                $stored = [...RequestFieldCatalog::fromStored($stored), ...array_map(
                    fn (string $key, string $value): array => ['key' => $key, 'label' => RequestFieldCatalog::labelOf($key) ?? $key, 'value' => $value],
                    array_keys($fields),
                    $fields,
                )];
            }

            $stats[$hadClient ? 'groups' : 'fields']++;
            $stats['field_values'] += count($fields);
            $this->sample($samples, $conversation->id, $hadClient ? 'grupo' : 'datos', implode(' · ', array_map(
                fn (string $key, string $value): string => (RequestFieldCatalog::labelOf($key) ?? $key).': '.$value,
                array_keys($fields),
                $fields,
            )));

            // What the result step below reads (and the next message compares with).
            $conversation->setAttribute('request_fields', $stored);
        }
    }

    /**
     * b. The result of a completed request that has none.
     *
     * @param  Stats  $stats
     * @param  list<Sample>  $samples
     */
    private function outcome(Conversation $conversation, bool $apply, array &$stats, array &$samples): void
    {
        if ($conversation->completed_at === null || $conversation->outcome !== null) {
            return;
        }

        $suggestion = $this->suggestions->for($conversation);

        if ($suggestion === null) {
            return;
        }

        $outcome = ConversationOutcome::from($suggestion['outcome']);
        $amount = $outcome === ConversationOutcome::Approved ? $suggestion['amount'] : null;
        $message = $this->announcingMessage($conversation);
        $resolution = $message === null ? null : RequestResolution::read($message->body);
        // Never replaced: the resolution data only completes what is missing.
        $resolutionFields = $resolution === null ? [] : array_diff_key(
            [...$resolution['latest'], ...$resolution['if_missing']],
            RequestFieldCatalog::values($conversation->request_fields),
        );

        if ($apply) {
            $done = DB::transaction(function () use ($conversation, $outcome, $amount, $message, $resolutionFields): bool {
                $locked = Conversation::query()->lockForUpdate()->find($conversation->id);

                if ($locked === null || $locked->completed_at === null || $locked->outcome !== null || $locked->not_request_at !== null || $locked->isImported()) {
                    return false;
                }

                $locked->forceFill([
                    'outcome' => $outcome,
                    'outcome_amount' => $amount,
                    'outcome_at' => $locked->completed_at,
                    'outcome_by_user_id' => $locked->closed_by_user_id,
                ])->save();

                $this->audit->record(AuditAction::ConversationOutcomeRecorded, $locked, [
                    'outcome' => $outcome->value,
                    'outcome_label' => $outcome->label(),
                    'outcome_amount' => $amount,
                    'previous_outcome' => null,
                    'previous_outcome_amount' => null,
                    'on_completion' => false,
                    'mode' => 'auto',
                    'source' => $message === null ? 'request_fields' : 'outbound_message',
                    'message_id' => $message?->id,
                    'note' => self::NOTE,
                ]);

                if ($resolutionFields !== []) {
                    $this->writer->merge($locked, [], $resolutionFields, $message?->id, null, 'outbound_message', ['note' => self::NOTE], signal: false);
                }

                return true;
            });

            if (! $done) {
                return;
            }
        }

        $stats[$outcome === ConversationOutcome::Approved ? 'approved' : 'rejected']++;

        if ($amount !== null) {
            $stats['approved_with_amount']++;
        }

        if ($resolutionFields !== []) {
            $stats['resolution']++;
        }

        $this->sample($samples, $conversation->id, 'resultado', $outcome->label()
            .($amount === null ? '' : ' '.RequestOutcomeEvidence::display($amount))
            .($resolutionFields === [] ? '' : ' (+ '.implode(', ', array_map(fn (string $key): string => RequestFieldCatalog::labelOf($key) ?? $key, array_keys($resolutionFields))).')'));
    }

    /**
     * The latest text a person of the team sent that announces a result,
     * among the last RequestOutcomeSuggestions::MESSAGES replies.
     */
    private function announcingMessage(Conversation $conversation): ?Message
    {
        $replies = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', MessageDirection::Outbound->value)
            ->where('type', MessageType::Text->value)
            ->where('status', '!=', MessageStatus::Failed->value)
            ->whereNull('revoked_at')
            ->whereNotNull('body')
            ->orderByDesc('id')
            ->limit(RequestOutcomeSuggestions::MESSAGES)
            ->get(['id', 'body', 'metadata']);

        foreach ($replies as $reply) {
            if (is_array($reply->metadata) && ($reply->metadata['automatic'] ?? false) === true) {
                continue;
            }

            if (RequestResolution::read($reply->body) !== null) {
                return $reply;
            }
        }

        return null;
    }

    /**
     * @param  list<Sample>  $samples
     */
    private function sample(array &$samples, int $conversationId, string $change, string $detail): void
    {
        $kept = count(array_filter($samples, fn (array $sample): bool => $sample['change'] === $change));

        if ($kept < self::SAMPLES) {
            $samples[] = ['conversation_id' => $conversationId, 'change' => $change, 'detail' => mb_strimwidth($detail, 0, 160, '…')];
        }
    }
}
