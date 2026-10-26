<?php

namespace App\Enums;

/**
 * Destination the admin chose for a conversation detected in a history
 * import batch.
 *
 * - Import: a closed conversation visible under "Cerradas", search and
 *   exports to whoever may read closed conversations; outside metrics, the
 *   dashboard, the 24 hour window, notifications and automations.
 * - Archive: read-only history, only visible from the archived-history
 *   screen (history-import.archive-view).
 * - Exclude: nothing is created; it only stays as a minimal reference in
 *   the report of the batch.
 */
enum HistoryImportDecision: string
{
    case Import = 'import';
    case Archive = 'archive';
    case Exclude = 'exclude';

    public function label(): string
    {
        return match ($this) {
            self::Import => 'Importar como historial operativo',
            self::Archive => 'Archivar como historial de solo lectura',
            self::Exclude => 'No importar',
        };
    }

    /**
     * Whether the destination creates a conversation.
     */
    public function createsConversation(): bool
    {
        return $this !== self::Exclude;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $decision): array => ['value' => $decision->value, 'label' => $decision->label()], self::cases());
    }
}
