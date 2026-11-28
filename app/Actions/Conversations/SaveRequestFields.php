<?php

namespace App\Actions\Conversations;

use App\Enums\AuditAction;
use App\Enums\ConversationStatus;
use App\Events\Conversations\ConversationChanged;
use App\Models\Conversation;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Conversations\ConversationLifecycle;
use App\Support\RequestFields\RequestFieldCatalog;
use App\Support\RequestFields\RequestResolution;
use App\Support\RequestOutcomeEvidence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Saves the request data the team picked out of the messages («Datos de la
 * solicitud»: Cliente, Producto, Monto solicitado...) on the conversation
 * (conversations.request_fields). Optional: nothing requires it, and it
 * never changes the status, the version, the queue or the readiness.
 *
 * Two ways:
 * - merge: the fields taken from a message («Tomar datos de la solicitud»)
 *   are added; one with the same key replaces the stored one, the rest
 *   are kept.
 * - replace: the list edited by hand (a value corrected, a field removed,
 *   «Quitar todo») becomes the whole list. A field kept with the same value
 *   keeps when and from which message it was taken.
 *
 * The key always comes from the label (RequestFieldCatalog::keyFor): a known
 * label gets its canonical name, any other is a custom field. The source
 * message must belong to the conversation. Authorized like the request
 * category (the responsible agent, or whoever assigns conversations or
 * confirms requests within their reach), again under lock. Recorded in the
 * audit log and signalled in real time so other viewers refresh.
 *
 * On a completed request the resolution amounts («Monto autorizado»,
 * «Desembolso», «Cantidad a pagar») stay correctable by whoever may record
 * its result (ConversationPolicy::correctResolution()), even when the rest
 * of the data no longer is; and «Monto autorizado» of an approved request
 * is the result's amount: a correction moves both in the same transaction
 * (RecordConversationOutcome::followAuthorizedAmount()).
 */
class SaveRequestFields
{
    public const MERGE = 'merge';

    public const REPLACE = 'replace';

    public const RESOLUTION_ONLY_MESSAGE = 'En una solicitud completada solo se corrigen los montos de la resolución (monto autorizado, desembolso y cantidad a pagar).';

    public function __construct(
        private ConversationLifecycle $lifecycle,
        private AuditLogger $audit,
        private RecordConversationOutcome $outcome,
    ) {}

    /**
     * @param  list<array{label: string, value: string|null, message_id?: int|null}>  $fields
     * @return bool Whether anything changed.
     *
     * @throws ValidationException
     */
    public function handle(Conversation $conversation, array $fields, string $mode, User $actor): bool
    {
        return DB::transaction(function () use ($conversation, $fields, $mode, $actor): bool {
            $conversation = $this->lifecycle->lock($conversation);
            $correcting = $this->correctsResolution($conversation, $actor);
            $actor = $correcting
                ? $this->lifecycle->freshUser($actor->id, lock: false)
                : $this->lifecycle->authorize($actor, 'categorize', $conversation);

            $current = RequestFieldCatalog::fromStored($conversation->request_fields);
            $currentByKey = [];

            foreach ($current as $field) {
                $currentByKey[$field['key']] = $field;
            }

            $incoming = $this->incoming($conversation, $fields, $currentByKey);

            $next = $mode === self::REPLACE ? $incoming : $this->merge($current, $incoming);

            if (count($next) > RequestFieldCatalog::FIELDS_MAX) {
                throw ValidationException::withMessages([
                    'fields' => 'Se guardan como máximo '.RequestFieldCatalog::FIELDS_MAX.' datos por solicitud. Quita alguno antes de agregar más.',
                ]);
            }

            $changes = $this->changes($currentByKey, $next);

            if ($changes === []) {
                return false;
            }

            $changedKeys = $this->changedKeys($currentByKey, $next);

            if ($correcting && array_diff($changedKeys, RequestResolution::MONEY) !== []) {
                throw ValidationException::withMessages(['fields' => self::RESOLUTION_ONLY_MESSAGE]);
            }

            $conversation->forceFill(['request_fields' => $next === [] ? null : $next])->save();

            $this->audit->record(AuditAction::ConversationRequestFieldsChanged, $conversation, [
                'mode' => $mode,
                'changes' => $changes,
            ], $actor);

            if (in_array('monto_autorizado', $changedKeys, true)) {
                $this->followAuthorizedAmount($conversation, $next, $actor);
            }

            ConversationChanged::dispatch($conversation->id, 'request_fields', $actor->id);

            return true;
        });
    }

    /**
     * Whether this save is a correction of the resolution of a completed
     * request by someone who may record its result but not keep the rest
     * of its data now (the row is locked).
     */
    private function correctsResolution(Conversation $conversation, User $actor): bool
    {
        if ($conversation->status !== ConversationStatus::Closed) {
            return false;
        }

        $gate = Gate::forUser($this->lifecycle->freshUser($actor->id, lock: false));

        return $gate->denies('categorize', $conversation) && $gate->allows('correctResolution', $conversation);
    }

    /**
     * The approved result takes the new «Monto autorizado» (removed: no
     * amount). A value that does not read as money leaves the result alone.
     *
     * @param  list<array{key: string, label: string, value: string, message_id: int|null, captured_at: string|null}>  $next
     */
    private function followAuthorizedAmount(Conversation $conversation, array $next, User $actor): void
    {
        $value = null;

        foreach ($next as $field) {
            if ($field['key'] === 'monto_autorizado') {
                $value = $field['value'];
            }
        }

        $amount = $value !== null ? RequestOutcomeEvidence::amount($value) : null;

        if ($value !== null && $amount === null) {
            return;
        }

        $this->outcome->followAuthorizedAmount($conversation, $actor, $amount);
    }

    /**
     * Keys added, changed or removed.
     *
     * @param  array<string, array{key: string, label: string, value: string, message_id: int|null, captured_at: string|null}>  $currentByKey
     * @param  list<array{key: string, label: string, value: string, message_id: int|null, captured_at: string|null}>  $next
     * @return list<string>
     */
    private function changedKeys(array $currentByKey, array $next): array
    {
        $keys = [];
        $nextKeys = [];

        foreach ($next as $field) {
            $nextKeys[$field['key']] = true;
            $previous = $currentByKey[$field['key']] ?? null;

            if ($previous === null || $previous['value'] !== $field['value'] || $previous['label'] !== $field['label']) {
                $keys[] = $field['key'];
            }
        }

        foreach (array_keys($currentByKey) as $key) {
            if (! isset($nextKeys[$key])) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * The submitted fields in their stored shape, keyed by key (a repeated
     * key keeps the last one). Empty values are dropped.
     *
     * @param  list<array{label: string, value: string|null, message_id?: int|null}>  $fields
     * @param  array<string, array{key: string, label: string, value: string, message_id: int|null, captured_at: string|null}>  $currentByKey
     * @return list<array{key: string, label: string, value: string, message_id: int|null, captured_at: string|null}>
     */
    private function incoming(Conversation $conversation, array $fields, array $currentByKey): array
    {
        $messageIds = array_values(array_unique(array_filter(array_map(
            fn (array $field): ?int => isset($field['message_id']) ? (int) $field['message_id'] : null,
            $fields,
        ))));

        if ($messageIds !== [] && $conversation->messages()->whereKey($messageIds)->count() !== count($messageIds)) {
            throw ValidationException::withMessages(['fields' => 'El mensaje de donde se tomaron los datos no pertenece a esta conversación.']);
        }

        $byKey = [];

        foreach ($fields as $index => $field) {
            $label = RequestFieldCatalog::stripDecorations((string) $field['label']);
            $value = trim((string) preg_replace('/\s+/u', ' ', (string) ($field['value'] ?? '')));
            $key = RequestFieldCatalog::keyFor($label);

            if ($value === '') {
                continue;
            }

            if ($key === '') {
                throw ValidationException::withMessages(["fields.{$index}.label" => 'Escribe un nombre para el dato (por ejemplo «Producto»).']);
            }

            $messageId = isset($field['message_id']) ? (int) $field['message_id'] : null;
            $previous = $currentByKey[$key] ?? null;
            // Untouched (same value, no new source): it keeps its origin.
            $kept = $previous !== null && $previous['value'] === $value && ($messageId === null || $messageId === $previous['message_id']);

            unset($byKey[$key]);
            $byKey[$key] = [
                'key' => $key,
                'label' => RequestFieldCatalog::labelOf($key) ?? $label,
                'value' => $value,
                'message_id' => $kept ? $previous['message_id'] : $messageId,
                'captured_at' => $kept ? $previous['captured_at'] : now()->toIso8601String(),
            ];
        }

        return array_values($byKey);
    }

    /**
     * Stored fields with the incoming ones: same key replaced in place, new
     * ones appended.
     *
     * @param  list<array{key: string, label: string, value: string, message_id: int|null, captured_at: string|null}>  $current
     * @param  list<array{key: string, label: string, value: string, message_id: int|null, captured_at: string|null}>  $incoming
     * @return list<array{key: string, label: string, value: string, message_id: int|null, captured_at: string|null}>
     */
    private function merge(array $current, array $incoming): array
    {
        $merged = [];

        foreach ($current as $field) {
            $merged[$field['key']] = $field;
        }

        foreach ($incoming as $field) {
            $merged[$field['key']] = $field;
        }

        return array_values($merged);
    }

    /**
     * What changed, for the audit log: one entry per added, changed or
     * removed field.
     *
     * @param  array<string, array{key: string, label: string, value: string, message_id: int|null, captured_at: string|null}>  $currentByKey
     * @param  list<array{key: string, label: string, value: string, message_id: int|null, captured_at: string|null}>  $next
     * @return list<array{label: string, previous: string|null, value: string|null}>
     */
    private function changes(array $currentByKey, array $next): array
    {
        $changes = [];
        $nextKeys = [];

        foreach ($next as $field) {
            $nextKeys[$field['key']] = true;
            $previous = $currentByKey[$field['key']] ?? null;

            if ($previous === null || $previous['value'] !== $field['value'] || $previous['label'] !== $field['label']) {
                $changes[] = ['label' => $field['label'], 'previous' => $previous['value'] ?? null, 'value' => $field['value']];
            }
        }

        foreach ($currentByKey as $key => $previous) {
            if (! isset($nextKeys[$key])) {
                $changes[] = ['label' => $previous['label'], 'previous' => $previous['value'], 'value' => null];
            }
        }

        return $changes;
    }
}
