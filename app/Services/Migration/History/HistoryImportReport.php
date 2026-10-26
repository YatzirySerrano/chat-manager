<?php

namespace App\Services\Migration\History;

use App\Models\DataTransfer;
use App\Models\HistoryImportConversation;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Report of a history import batch as CSV (UTF-8 with BOM): one row per
 * detected conversation with its destination and result, never message
 * content. Cells that a spreadsheet would read as a formula ("=", "+",
 * "-", "@", their full-width forms, also after leading whitespace, tab,
 * carriage return) are prefixed with an apostrophe, so a
 * contact named "=HYPERLINK(...)" in Whaticket is shown as text.
 */
class HistoryImportReport
{
    private const HEADERS = [
        'Fila', 'Clave de origen', 'Tipo', 'Conversación en Whaticket', 'Contacto', 'Número', 'Agente', 'Departamento',
        'Conexión', 'Primer mensaje', 'Último mensaje', 'Mensajes', 'Archivos disponibles', 'Archivos faltantes',
        'Destino', 'Resultado', 'Conversación creada', 'Motivo',
    ];

    public function download(DataTransfer $batch): StreamedResponse
    {
        return response()->streamDownload(function () use ($batch): void {
            $out = fopen('php://output', 'wb');

            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, self::HEADERS, ',', '"', '');

            HistoryImportConversation::query()
                ->where('data_transfer_id', $batch->id)
                ->orderBy('id')
                ->chunkById(1000, function (Collection $rows) use ($out): void {
                    foreach ($rows as $row) {
                        fputcsv($out, array_map(fn (mixed $value): string => self::cell($value), [
                            $row->id,
                            $row->source_key,
                            $row->source_kind,
                            $row->source_ticket_id,
                            $row->contact_name,
                            $row->contact_phone ?? $row->contact_identifier,
                            $row->source_agent,
                            $row->source_department,
                            $row->source_connection,
                            $row->first_message_at?->toIso8601String(),
                            $row->last_message_at?->toIso8601String(),
                            $row->message_count,
                            $row->attachments_available,
                            $row->attachmentsMissing(),
                            $row->decision?->label() ?? 'Sin decidir',
                            $row->state->label(),
                            $row->result_conversation_id,
                            $row->error,
                        ]), ',', '"', '');
                    }
                });

            fclose($out);
        }, "importacion-historial-{$batch->id}.csv", ['Content-Type' => 'text/csv; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff']);
    }

    /**
     * Text of a cell, neutralized against spreadsheet formula injection.
     */
    public static function cell(mixed $value): string
    {
        $text = $value === null ? '' : (is_scalar($value) ? (string) $value : '');
        // Leading spaces, tabs, line breaks and full-width signs (＝＋－＠) are
        // ignored by some spreadsheet programs before they evaluate a cell.
        $start = ltrim($text, " \t\r\n\u{00A0}\u{3000}");

        return $start !== '' && (in_array($start[0], ['=', '+', '-', '@'], true) || preg_match('/^[\x{FF1D}\x{FF0B}\x{FF0D}\x{FF20}]/u', $start) === 1)
            || ($text !== '' && in_array($text[0], ["\t", "\r"], true))
            ? "'".$text
            : $text;
    }
}
