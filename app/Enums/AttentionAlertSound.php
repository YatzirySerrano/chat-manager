<?php

namespace App\Enums;

/**
 * Sounds an attention alert may play, from a fixed catalog. They are
 * synthesized in the browser (resources/js/lib/notifications/alerts.ts), so
 * no audio file is ever uploaded, stored or served, and every one of them
 * is distinct from the chime of ordinary notifications.
 */
enum AttentionAlertSound: string
{
    /** The default sound of the alert's priority. */
    case Priority = 'priority';
    case Chime = 'chime';
    case Bell = 'bell';
    case Pulse = 'pulse';
    case Siren = 'siren';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Priority => 'Según la prioridad',
            self::Chime => 'Campanilla breve',
            self::Bell => 'Campana distintiva',
            self::Pulse => 'Pulsos repetidos',
            self::Siren => 'Tono largo (3 segundos)',
            self::None => 'Sin sonido',
        };
    }

    /**
     * The sound actually played for an alert of the given priority.
     */
    public function resolve(AttentionAlertPriority $priority): self
    {
        return $this === self::Priority ? $priority->defaultSound() : $this;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $sound): array => ['value' => $sound->value, 'label' => $sound->label()], self::cases());
    }
}
