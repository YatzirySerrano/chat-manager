<?php

namespace App\Enums;

/**
 * How pressing an attention alert is. Each level is told apart by its name
 * and icon, never by color alone, and has its own default sound. «Urgente»
 * is meant for exceptions: it asks for «Entendido» before the alert leaves
 * the screen and plays the longest sound.
 */
enum AttentionAlertPriority: string
{
    case Info = 'info';
    case Important = 'important';
    case Urgent = 'urgent';

    public function label(): string
    {
        return match ($this) {
            self::Info => 'Informativa',
            self::Important => 'Importante',
            self::Urgent => 'Urgente',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Info => 'Un aviso para tener en cuenta. Sonido breve.',
            self::Important => 'Pide revisar algo pronto. Sonido distintivo.',
            self::Urgent => 'Requiere atención inmediata. Tono de unos tres segundos; úsala solo cuando de verdad sea urgente.',
        };
    }

    /**
     * The sound played when the sender keeps «Según la prioridad».
     */
    public function defaultSound(): AttentionAlertSound
    {
        return match ($this) {
            self::Info => AttentionAlertSound::Chime,
            self::Important => AttentionAlertSound::Bell,
            self::Urgent => AttentionAlertSound::Siren,
        };
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $priority): array => [
            'value' => $priority->value,
            'label' => $priority->label(),
            'description' => $priority->description(),
        ], self::cases());
    }
}
