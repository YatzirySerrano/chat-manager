<?php

namespace App\Enums;

/**
 * Files a history import batch accepts.
 */
enum HistoryImportFileKind: string
{
    /** Whaticket "Informes" export of chats (XLSX or CSV, one row per chat). */
    case Chats = 'chats';

    /** Whaticket "Exportar mensajes" CSV (one row per message). */
    case Messages = 'messages';

    /** Whaticket contacts CSV (names for the numbers). */
    case Contacts = 'contacts';

    /** "Whaticket-medias.zip" or a WhatsApp export ZIP: media files and, optionally, a Chat.txt. */
    case Media = 'media';

    public function label(): string
    {
        return match ($this) {
            self::Chats => 'Reporte de chats (Excel o CSV)',
            self::Messages => 'Mensajes (CSV)',
            self::Contacts => 'Contactos (CSV)',
            self::Media => 'Archivos y Chat.txt (ZIP)',
        };
    }

    /**
     * @return list<string>
     */
    public function extensions(): array
    {
        return match ($this) {
            self::Chats => ['xlsx', 'csv'],
            self::Messages, self::Contacts => ['csv'],
            self::Media => ['zip', 'txt'],
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return list<array{value: string, label: string, extensions: list<string>}>
     */
    public static function options(): array
    {
        return array_map(fn (self $kind): array => ['value' => $kind->value, 'label' => $kind->label(), 'extensions' => $kind->extensions()], self::cases());
    }
}
