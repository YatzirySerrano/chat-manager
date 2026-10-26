<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Audit\AuditPresenter;
use App\Support\ProtectedAccounts;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    /**
     * The entry in plain Spanish (AuditPresenter). Raw properties and the
     * browser only travel inside "technical", for viewers allowed to read
     * them (AuditPresenter::canViewTechnical).
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $presentation = app(AuditPresenter::class)->present(
            $this->resource,
            $viewer instanceof User ? $viewer : null,
            $this->relationLoaded('auditable') ? $this->auditable : null,
        );

        return [
            'id' => $this->id,
            'action' => [
                'value' => $this->action->value,
                'label' => $this->action->label(),
                'tone' => $this->action->tone(),
            ],
            'actor' => [
                'id' => app(ProtectedAccounts::class)->hides($this->user_id, $viewer instanceof User ? $viewer : null) ? null : $this->user_id,
                'name' => app(AuditPresenter::class)->actorName($this->resource, $viewer instanceof User ? $viewer : null),
            ],
            'subject' => $presentation['subject'],
            'summary' => $presentation['summary'],
            'changes' => $presentation['changes'],
            'details' => $presentation['details'],
            'result' => $presentation['result'],
            'error' => $presentation['error'],
            'technical' => $presentation['technical'],
            'ip_address' => $this->ip_address,
            'restricted' => $this->restricted,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
