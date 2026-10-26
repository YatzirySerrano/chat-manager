<?php

namespace App\Services\Migration\History;

/**
 * Counts the rows of a file left out or flagged while reading it, per
 * reason, with the first line numbers (never the content of the row).
 */
class IssueCounter
{
    private const SAMPLE_LINES = 20;

    /**
     * Reasons the admin sees.
     *
     * @var array<string, string>
     */
    public const MESSAGES = [
        'deleted' => 'Mensajes marcados como eliminados en Whaticket (no se importan).',
        'invalid_date' => 'Filas sin fecha válida (no se importan).',
        'empty_message' => 'Filas sin texto ni archivo (no se importan).',
        'no_conversation' => 'Filas sin conversación ni número de contacto (no se importan).',
        'truncated' => 'Textos demasiado largos que se recortaron.',
        'system_line' => 'Avisos del sistema de WhatsApp (no se importan como mensajes).',
        'unparsed_line' => 'Líneas al inicio del chat sin fecha reconocible (no se importan).',
        'invalid_phone' => 'Contactos sin un número válido (se ignoran).',
        'duplicate_message' => 'Mensajes que ya venían en otro archivo de esta importación (se cuentan una sola vez).',
        'duplicate_chat' => 'Este chat ya venía en otro archivo de esta importación (se lee una sola vez).',
    ];

    /**
     * @var array<string, array{count: int, lines: list<int>}>
     */
    private array $issues = [];

    public function add(string $code, int $line, int $count = 1): void
    {
        $this->issues[$code] ??= ['count' => 0, 'lines' => []];
        $this->issues[$code]['count'] += $count;

        if ($line > 0 && count($this->issues[$code]['lines']) < self::SAMPLE_LINES) {
            $this->issues[$code]['lines'][] = $line;
        }
    }

    /**
     * @return array<string, array{count: int, lines: list<int>, message: string}>
     */
    public function toArray(): array
    {
        $result = [];

        foreach ($this->issues as $code => $issue) {
            $result[$code] = [...$issue, 'message' => self::MESSAGES[$code] ?? $code];
        }

        return $result;
    }
}
