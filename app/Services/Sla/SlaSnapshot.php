<?php

namespace App\Services\Sla;

use App\Models\Conversation;
use App\Models\ResponseCycle;
use App\Support\Features;
use Illuminate\Database\Eloquent\Collection;

/**
 * The SLA of a conversation as the interface shows it (`sla` key of
 * ConversationResource): the open response cycle that has an objective, or
 * null. The browser classifies it («En tiempo», «Por vencer», «Vencida»)
 * from `warn_at` and `due_at` against the shared server clock, so nothing is
 * polled for a countdown.
 *
 * Null while phase two is off (App\Support\Features).
 *
 * Lists call load() once for the page (one query); of() falls back to one
 * query for a single conversation.
 *
 * @phpstan-type SlaPayload array{kind: string, kind_label: string, started_at: string, warn_at: string|null, due_at: string|null, target_seconds: int, business_hours_only: bool, breached: bool}
 */
class SlaSnapshot
{
    /**
     * Load the open cycle of every conversation of a page in one query.
     *
     * @param  Collection<int, Conversation>  $conversations
     */
    public static function load(Collection $conversations): void
    {
        if (! Features::phaseTwo()) {
            return;
        }

        $conversations->loadMissing('openResponseCycle');
    }

    /**
     * @return SlaPayload|null
     */
    public static function of(Conversation $conversation): ?array
    {
        // SLA badges are phase two (FEATURE_PHASE_TWO): null while it is off.
        if (! Features::phaseTwo()) {
            return null;
        }

        if (! $conversation->relationLoaded('openResponseCycle')) {
            $conversation->setRelation('openResponseCycle', $conversation->openResponseCycle()->first());
        }

        return self::present($conversation->getRelation('openResponseCycle'));
    }

    /**
     * @return SlaPayload|null
     */
    public static function present(?ResponseCycle $cycle): ?array
    {
        if ($cycle === null || $cycle->target_seconds === null || ! $cycle->isActive()) {
            return null;
        }

        return [
            'kind' => $cycle->kind->value,
            'kind_label' => $cycle->kind->label(),
            'started_at' => $cycle->started_at->toIso8601String(),
            'warn_at' => $cycle->warn_at?->toIso8601String(),
            'due_at' => $cycle->due_at?->toIso8601String(),
            'target_seconds' => $cycle->target_seconds,
            'business_hours_only' => $cycle->business_hours_only,
            'breached' => $cycle->breached_at !== null,
        ];
    }
}
