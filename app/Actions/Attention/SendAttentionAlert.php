<?php

namespace App\Actions\Attention;

use App\Broadcasting\RealtimeSignals;
use App\Enums\AttentionAlertAudience;
use App\Enums\AttentionAlertPriority;
use App\Enums\AttentionAlertSound;
use App\Enums\AuditAction;
use App\Enums\ColorSwatch;
use App\Models\AttentionAlert;
use App\Models\User;
use App\Services\Attention\AttentionAudience;
use App\Services\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An administrator sends an attention alert to one agent, a selection or
 * every agent within their reach (AttentionAudience):
 *
 * 1. the recipients are resolved again on the server from that reach, so a
 *    forged id never reaches anyone else; for «everyone», when the composer
 *    sends the agents it listed, a list that changed meanwhile (an agent
 *    added, deactivated or moved) is refused, so the sender always knows
 *    exactly who gets it;
 * 2. the alert and one row per recipient are stored in one transaction and
 *    the sending is audited;
 * 3. after commit, each recipient's personal channel gets a signal with the
 *    alert id only (the content is fetched by the recipient, authorized,
 *    from AttentionInbox), so people offline find it pending when they
 *    come back.
 *
 * Idempotent per sender: the client key of a double click or a retried
 * request answers the alert already stored. The same text sent again by
 * the same person within DUPLICATE_SECONDS is refused as an accidental
 * repetition.
 *
 * @phpstan-type AlertInput array{
 *     client_key: string,
 *     audience: AttentionAlertAudience,
 *     recipient_ids: list<int>,
 *     expected_recipient_ids?: list<int>|null,
 *     title: string,
 *     body: string,
 *     priority: AttentionAlertPriority,
 *     color: ColorSwatch,
 *     sound: AttentionAlertSound,
 *     link_pending: bool,
 * }
 */
class SendAttentionAlert
{
    /** An identical alert by the same sender within this time is a repetition. */
    public const DUPLICATE_SECONDS = 60;

    public function __construct(
        private AttentionAudience $audience,
        private AuditLogger $audit,
        private RealtimeSignals $signals,
    ) {}

    /**
     * @param  AlertInput  $input
     */
    public function handle(User $sender, array $input): AttentionAlert
    {
        $existing = $this->stored($sender, $input['client_key']);

        if ($existing !== null) {
            return $existing;
        }

        $recipientIds = $this->recipients($sender, $input['audience'], $input['recipient_ids'], $input['expected_recipient_ids'] ?? null);

        try {
            $alert = DB::transaction(function () use ($sender, $input, $recipientIds): AttentionAlert {
                $this->refuseRepetition($sender, $input);

                $alert = new AttentionAlert;
                $alert->forceFill([
                    'sender_id' => $sender->id,
                    'client_key' => $input['client_key'],
                    'audience' => $input['audience'],
                    'title' => $input['title'],
                    'body' => $input['body'],
                    'priority' => $input['priority'],
                    'color' => $input['color'],
                    'sound' => $input['sound'],
                    'action' => $input['link_pending'] ? AttentionAlert::ACTION_PENDING_CONVERSATIONS : null,
                    'recipients_count' => count($recipientIds),
                ])->save();

                $now = now();

                foreach (array_chunk($recipientIds, 500) as $chunk) {
                    DB::table('attention_alert_recipients')->insert(array_map(fn (int $userId): array => [
                        'attention_alert_id' => $alert->id,
                        'user_id' => $userId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ], $chunk));
                }

                $this->audit->record(AuditAction::AttentionAlertSent, $alert, [
                    'alert_id' => $alert->id,
                    'title' => $alert->title,
                    'alert_priority' => $alert->priority->label(),
                    'alert_audience' => $alert->audience->label(),
                    'recipients_count' => $alert->recipients_count,
                ], $sender);

                DB::afterCommit(fn () => $this->signals->toUsers($recipientIds, 'attention.alert', ['alert_id' => $alert->id]));

                return $alert;
            });
        } catch (UniqueConstraintViolationException) {
            // The same client key was stored meanwhile by a parallel request.
            $alert = $this->stored($sender, $input['client_key']);

            if ($alert === null) {
                throw ValidationException::withMessages(['title' => 'No se pudo enviar la alerta. Inténtalo de nuevo.']);
            }
        }

        return $alert;
    }

    private function stored(User $sender, string $clientKey): ?AttentionAlert
    {
        return AttentionAlert::query()->where('sender_id', $sender->id)->where('client_key', $clientKey)->first();
    }

    /**
     * The recipients, always within the sender's reach.
     *
     * @param  list<int>  $requested
     * @param  list<int>|null  $expected  the agents the composer listed for «everyone»
     * @return list<int>
     */
    private function recipients(User $sender, AttentionAlertAudience $audience, array $requested, ?array $expected = null): array
    {
        $eligible = $this->audience->agents($sender);

        if ($audience === AttentionAlertAudience::Everyone) {
            $ids = array_values($eligible->orderBy('users.id')->pluck('users.id')->map(fn (mixed $id): int => (int) $id)->all());

            if ($ids === []) {
                throw ValidationException::withMessages(['recipient_ids' => 'No hay agentes que puedan recibir alertas.']);
            }

            if ($expected !== null) {
                $listed = array_values(array_unique($expected));
                sort($listed);

                if ($listed !== $ids) {
                    throw ValidationException::withMessages(['recipient_ids' => count($ids) === 1
                        ? 'La lista de destinatarios cambió mientras redactabas la alerta: ahora es 1 agente. Revísala y vuelve a enviarla.'
                        : 'La lista de destinatarios cambió mientras redactabas la alerta: ahora son '.count($ids).' agentes. Revísala y vuelve a enviarla.']);
                }
            }

            return $ids;
        }

        $requested = array_values(array_unique($requested));

        if ($requested === []) {
            throw ValidationException::withMessages(['recipient_ids' => 'Elige al menos un agente.']);
        }

        if ($audience === AttentionAlertAudience::Individual && count($requested) !== 1) {
            throw ValidationException::withMessages(['recipient_ids' => 'Una alerta individual va dirigida a un solo agente.']);
        }

        $ids = array_values($eligible->whereKey($requested)->orderBy('users.id')->pluck('users.id')->map(fn (mixed $id): int => (int) $id)->all());

        if (count($ids) !== count($requested)) {
            throw ValidationException::withMessages(['recipient_ids' => 'Algunos de los agentes elegidos ya no pueden recibir alertas. Actualiza la página y vuelve a elegirlos.']);
        }

        return $ids;
    }

    /**
     * @param  AlertInput  $input
     */
    private function refuseRepetition(User $sender, array $input): void
    {
        // Serializes the sender's own requests (two tabs, a double click with
        // two client keys), so the check below sees the other one.
        User::query()->whereKey($sender->id)->lockForUpdate()->value('id');

        $repeated = AttentionAlert::query()
            ->where('sender_id', $sender->id)
            ->where('title', $input['title'])
            ->where('body', $input['body'])
            ->where('created_at', '>=', now()->subSeconds(self::DUPLICATE_SECONDS))
            ->exists();

        if ($repeated) {
            throw ValidationException::withMessages(['body' => 'Acabas de enviar esta misma alerta. Espera un minuto antes de repetirla.']);
        }
    }
}
