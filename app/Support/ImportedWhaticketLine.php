<?php

namespace App\Support;

/**
 * Reads who did what in a message imported from Whaticket. Whaticket keeps
 * no author per message: it signs each agent message inside the text
 * («*Jennifer:*» and a line break) and writes its own operations as plain
 * lines («Jennifer resuelto la conversación»). The stored body is never
 * changed; this only interprets it for display.
 */
final class ImportedWhaticketLine
{
    /** Whaticket participle => what the person did. */
    private const VERBS = [
        'aceptado' => 'aceptó',
        'resuelto' => 'resolvió',
        'devuelto' => 'devolvió',
        'transferido' => 'transfirió',
        'reabierto' => 'reabrió',
        'cerrado' => 'cerró',
        'finalizado' => 'finalizó',
        'asignado' => 'asignó',
    ];

    /**
     * The agent signature at the start of the text, and the text without it.
     * The name must be followed by a colon AND a line break, so ordinary
     * text such as «Producto: S12» is never taken for a signature.
     *
     * @return array{name: string, body: string}|null
     */
    public static function signature(?string $body): ?array
    {
        if ($body === null || preg_match('/\A\s*\*?\s*([^\n:*]{1,60}?)\s*:\s*\*?[ \t]*\R(.*)\z/su', $body, $match) !== 1) {
            return null;
        }

        $name = trim($match[1]);
        $text = trim($match[2]);

        return $name === '' || $text === '' ? null : ['name' => $name, 'body' => $text];
    }

    /**
     * A Whaticket operation line: «Angeles S devuelto la conversación para
     * esperar» => actor «Angeles S», action «devolvió la conversación para
     * esperar».
     *
     * @return array{actor: string, action: string}|null
     */
    public static function event(?string $body): ?array
    {
        if ($body === null || mb_strlen($body) > 200) {
            return null;
        }

        $verbs = implode('|', array_keys(self::VERBS));

        if (preg_match('/\A\s*(.{1,60}?)\s+('.$verbs.')\s+(la conversaci[oó]n.*?)\s*\z/siu', $body, $match) !== 1) {
            return null;
        }

        return [
            'actor' => trim($match[1]),
            'action' => self::VERBS[mb_strtolower($match[2])].' '.$match[3],
        ];
    }
}
