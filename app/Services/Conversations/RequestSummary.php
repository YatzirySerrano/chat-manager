<?php

namespace App\Services\Conversations;

use App\Enums\ConversationOutcome;
use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\User;
use App\Support\ProtectedAccounts;
use App\Support\RequestFields\RequestFieldCatalog;

/**
 * One conversation told as the credit request it was, for «Solicitudes
 * anteriores» in the details panel and the separators of «Conversación
 * completa»: whose request it was (the «Cliente» field: the WhatsApp contact
 * is usually the field manager who sends requests for many clients), its
 * type, product and requested amount when they were captured, who attended
 * it and where it ended.
 *
 * The result is one of: `not_request` («No es solicitud», when the column
 * exists and is set), `approved` (with the authorized amount), `rejected`,
 * `completed`, `closed` or `open` (still in attention: its status label).
 * The credit result only counts once the request was completed, as in the
 * conversation's own resource. Needs `department` and `assignee` loaded.
 *
 * @phpstan-type RequestSummaryArray array{id: int, status: string, status_label: string, client: string|null, type: string|null, product: string|null, requested_amount: string|null, result: array{value: string, label: string, amount: string|null}, department: array{id: int, name: string, color: string, is_active: bool}|null, assignee: string|null, created_at: string|null, closed_at: string|null, completed_at: string|null}
 */
class RequestSummary
{
    public function __construct(private ProtectedAccounts $accounts) {}

    /**
     * @return RequestSummaryArray
     */
    public function of(Conversation $conversation, User $viewer): array
    {
        $fields = RequestFieldCatalog::values($conversation->request_fields);

        return [
            'id' => $conversation->id,
            'status' => $conversation->status->value,
            'status_label' => $conversation->status->label(),
            'client' => $this->text($fields['cliente'] ?? null),
            'type' => $this->text($fields['tipo'] ?? null),
            'product' => $this->text($fields['producto'] ?? null),
            'requested_amount' => $this->text($fields['monto_solicitado'] ?? null),
            'result' => $this->result($conversation),
            'department' => $conversation->department === null ? null : [
                'id' => $conversation->department->id,
                'name' => $conversation->department->name,
                'color' => $conversation->department->color->value,
                'is_active' => $conversation->department->is_active,
            ],
            'assignee' => $this->accounts->nameFor($conversation->assignee, $viewer),
            'created_at' => $conversation->created_at?->toIso8601String(),
            'closed_at' => $conversation->closed_at?->toIso8601String(),
            'completed_at' => $conversation->completed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{value: string, label: string, amount: string|null}
     */
    private function result(Conversation $conversation): array
    {
        // «No es solicitud» may not exist yet on every installation: read only when present.
        $notRequest = array_key_exists('not_request_at', $conversation->getAttributes())
            && $conversation->getAttribute('not_request_at') !== null;

        return match (true) {
            $notRequest => ['value' => 'not_request', 'label' => 'No es solicitud', 'amount' => null],
            $conversation->completed_at !== null && $conversation->outcome === ConversationOutcome::Approved => [
                'value' => 'approved',
                'label' => ConversationOutcome::Approved->label(),
                'amount' => $conversation->outcome_amount,
            ],
            $conversation->completed_at !== null && $conversation->outcome === ConversationOutcome::Rejected => [
                'value' => 'rejected',
                'label' => ConversationOutcome::Rejected->label(),
                'amount' => null,
            ],
            $conversation->completed_at !== null => ['value' => 'completed', 'label' => 'Completada', 'amount' => null],
            $conversation->status === ConversationStatus::Closed => ['value' => 'closed', 'label' => 'Cerrada', 'amount' => null],
            default => ['value' => 'open', 'label' => $conversation->status->label(), 'amount' => null],
        };
    }

    private function text(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : $value;
    }
}
