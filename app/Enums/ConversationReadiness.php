<?php

namespace App\Enums;

/**
 * Whether a request has everything the team needs to attend it. Only
 * meaningful where the rule «Solicitud completa / Lista para atención»
 * (intake.confirmation_required) applies: a request that needs information
 * cannot be taken from the queue until someone confirms it is complete.
 */
enum ConversationReadiness: string
{
    case Ready = 'ready';
    case NeedsInfo = 'needs_info';

    public function label(): string
    {
        return match ($this) {
            self::Ready => 'Lista para atención',
            self::NeedsInfo => 'Necesita información',
        };
    }

    /**
     * Options for select inputs.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $readiness): array => [
            'value' => $readiness->value,
            'label' => $readiness->label(),
        ], self::cases());
    }
}
