<?php

namespace App\Services\Migration\History;

use App\Enums\HistoryImportFileKind;
use App\Services\Migration\HeaderMatching;

/**
 * Extensible column mapping of the Whaticket exports.
 *
 * NO VERIFICADO CON EXPORTACIÓN AUTÉNTICA. The chats report columns come
 * from Whaticket's help center (help.whaticket.com, article 20776: contactName,
 * contactNumber, user, department, connection, createdAt, resolvedAt,
 * conversationId, chatId, chatLink, tags, conversationTags...). The columns
 * of the messages CSV are NOT documented publicly: the aliases below are
 * the names an export is likely to use (the open-source Whaticket message
 * fields — id, body, fromMe, mediaType, mediaUrl, ticketId, createdAt,
 * quotedMsgId — and their Spanish/Portuguese/English variants). Nothing is
 * invented: a header that matches no alias is reported as unknown, a
 * required logical field without a column is reported as missing, and the
 * admin can always override the mapping of a file (stored per file).
 * New aliases go in self::FIELDS once an authentic export is reviewed.
 */
class HistoryColumns
{
    use HeaderMatching;

    /**
     * Logical fields per kind of file: label and the header aliases
     * (lowercase, without accents, compared with HeaderMatching).
     *
     * @var array<string, array<string, array{label: string, aliases: list<string>}>>
     */
    private const FIELDS = [
        'chats' => [
            'conversation_id' => ['label' => 'Identificador de la conversación', 'aliases' => ['conversationid', 'conversation id', 'ticketid', 'ticket id', 'ticket', 'id de conversacion', 'id conversacion', 'protocolo']],
            'chat_id' => ['label' => 'Identificador del chat', 'aliases' => ['chatid', 'chat id', 'id del chat']],
            'chat_link' => ['label' => 'Enlace del chat', 'aliases' => ['chatlink', 'chat link', 'enlace del chat']],
            'contact_name' => ['label' => 'Nombre del contacto', 'aliases' => ['contactname', 'contact name', 'nombre del contacto', 'nome do contato', 'contacto', 'contato', 'nombre', 'name']],
            'contact_number' => ['label' => 'Número del contacto', 'aliases' => ['contactnumber', 'contact number', 'numero del contacto', 'numero', 'number', 'telefono', 'phone', 'whatsapp del contacto']],
            'user' => ['label' => 'Agente', 'aliases' => ['user', 'usuario', 'agente', 'agent', 'username', 'user name', 'atendente']],
            'department' => ['label' => 'Departamento', 'aliases' => ['department', 'departamento', 'queue', 'fila', 'cola', 'queuename', 'queue name']],
            'connection' => ['label' => 'Conexión', 'aliases' => ['connection', 'conexion', 'conexao', 'whatsapp', 'whatsappname', 'connectionname', 'connection name', 'canal']],
            'created_at' => ['label' => 'Fecha de creación', 'aliases' => ['createdat', 'created at', 'fecha de creacion', 'creado', 'fecha de inicio', 'inicio']],
            'resolved_at' => ['label' => 'Fecha de resolución', 'aliases' => ['resolvedat', 'resolved at', 'fecha de resolucion', 'closedat', 'closed at', 'cerrado', 'fecha de cierre']],
            'status' => ['label' => 'Estado', 'aliases' => ['status', 'estado', 'situacion']],
            'tags' => ['label' => 'Etiquetas', 'aliases' => ['tags', 'etiquetas']],
            'conversation_tags' => ['label' => 'Etiquetas de la conversación', 'aliases' => ['conversationtags', 'conversation tags', 'etiquetas de la conversacion']],
        ],
        'messages' => [
            'message_id' => ['label' => 'Identificador del mensaje', 'aliases' => ['id', 'messageid', 'message id', 'msgid', 'id del mensaje', 'wid']],
            'conversation_id' => ['label' => 'Identificador de la conversación', 'aliases' => ['ticketid', 'ticket id', 'ticket', 'conversationid', 'conversation id', 'chatid', 'chat id', 'id de conversacion']],
            'contact_number' => ['label' => 'Número del contacto', 'aliases' => ['contactnumber', 'contact number', 'number', 'numero', 'phone', 'telefono', 'remotejid', 'contact']],
            'contact_name' => ['label' => 'Nombre del contacto', 'aliases' => ['contactname', 'contact name', 'nombre del contacto', 'nome do contato', 'contacto', 'contato']],
            'from_me' => ['label' => 'Enviado por la empresa', 'aliases' => ['fromme', 'from me', 'enviado por mi', 'sentbyme', 'sent by me', 'direction', 'direccion', 'tipo de remitente']],
            'sender' => ['label' => 'Remitente', 'aliases' => ['sender', 'sendername', 'sender name', 'remitente', 'author', 'autor', 'participant']],
            'user' => ['label' => 'Agente', 'aliases' => ['user', 'usuario', 'username', 'user name', 'agente', 'agent', 'atendente']],
            'body' => ['label' => 'Texto', 'aliases' => ['body', 'message', 'mensaje', 'mensagem', 'text', 'texto', 'content', 'contenido']],
            'media_type' => ['label' => 'Tipo de mensaje', 'aliases' => ['mediatype', 'media type', 'type', 'tipo', 'tipo de mensaje', 'messagetype']],
            'media_name' => ['label' => 'Archivo', 'aliases' => ['mediaurl', 'media url', 'mediapath', 'media', 'archivo', 'arquivo', 'file', 'filename', 'file name', 'attachment', 'adjunto', 'medianame']],
            'created_at' => ['label' => 'Fecha y hora', 'aliases' => ['createdat', 'created at', 'timestamp', 'datetime', 'date', 'fecha', 'fecha y hora', 'data', 'sentat', 'sent at']],
            'time' => ['label' => 'Hora (si viene aparte)', 'aliases' => ['time', 'hora', 'hour']],
            'quoted_id' => ['label' => 'Mensaje citado', 'aliases' => ['quotedmsgid', 'quoted msg id', 'quotedid', 'quoted id', 'reply to', 'respuesta a']],
            'connection' => ['label' => 'Conexión', 'aliases' => ['connection', 'conexion', 'whatsapp', 'whatsappname', 'connectionname', 'connection name']],
            'department' => ['label' => 'Departamento', 'aliases' => ['department', 'departamento', 'queue', 'fila', 'cola', 'queuename']],
            'is_deleted' => ['label' => 'Eliminado', 'aliases' => ['isdeleted', 'is deleted', 'deleted', 'eliminado']],
        ],
    ];

    /**
     * Logical fields that must have a column. Each group is satisfied by any
     * of its fields.
     *
     * @var array<string, list<list<string>>>
     */
    private const REQUIRED = [
        'chats' => [['contact_number', 'conversation_id', 'chat_id']],
        'messages' => [['created_at'], ['body', 'media_name'], ['conversation_id', 'contact_number']],
    ];

    /**
     * @return array<string, array{label: string, aliases: list<string>}>
     */
    public function fields(HistoryImportFileKind $kind): array
    {
        return self::FIELDS[$kind->value] ?? [];
    }

    /**
     * Fields of the kind for the UI.
     *
     * @return list<array{value: string, label: string, required: bool}>
     */
    public function options(HistoryImportFileKind $kind): array
    {
        $required = array_merge(...array_map(fn (array $group): array => count($group) === 1 ? $group : [], self::REQUIRED[$kind->value] ?? [[]]));

        return array_map(fn (string $field, array $definition): array => [
            'value' => $field,
            'label' => $definition['label'],
            'required' => in_array($field, $required, true),
        ], array_keys($this->fields($kind)), $this->fields($kind));
    }

    /**
     * Mapping suggested from the headers: each field takes the first unused
     * column named like one of its aliases, in field order.
     *
     * @param  list<string>  $headers
     * @return array<string, int|null>
     */
    public function suggest(HistoryImportFileKind $kind, array $headers): array
    {
        $normalized = array_map(fn (string $header): string => self::normalizeHeader($header), $headers);
        $mapping = [];
        $taken = [];

        // Exact header names first (e.g. "user" over a later alias), then aliases.
        foreach ($this->fields($kind) as $field => $definition) {
            $mapping[$field] = null;

            foreach ($definition['aliases'] as $alias) {
                foreach ($normalized as $index => $header) {
                    if (! in_array($index, $taken, true) && $header === $alias) {
                        $mapping[$field] = $index;
                        $taken[] = $index;

                        continue 3;
                    }
                }
            }
        }

        return $mapping;
    }

    /**
     * A mapping given by the admin, kept only for known fields and existing
     * columns (a column is used by at most one field).
     *
     * @param  array<string, mixed>  $mapping
     * @param  list<string>  $headers
     * @return array<string, int|null>
     */
    public function sanitize(HistoryImportFileKind $kind, array $mapping, array $headers): array
    {
        $clean = [];
        $taken = [];

        foreach (array_keys($this->fields($kind)) as $field) {
            $value = $mapping[$field] ?? null;
            $index = is_numeric($value) ? (int) $value : null;

            if ($index !== null && ($index < 0 || $index >= count($headers) || in_array($index, $taken, true))) {
                $index = null;
            }

            $clean[$field] = $index;

            if ($index !== null) {
                $taken[] = $index;
            }
        }

        return $clean;
    }

    /**
     * Headers no field uses.
     *
     * @param  array<string, int|null>  $mapping
     * @param  list<string>  $headers
     * @return list<string>
     */
    public function unknownHeaders(array $mapping, array $headers): array
    {
        $used = array_values(array_filter($mapping, fn (?int $index): bool => $index !== null));

        return array_values(array_filter($headers, fn (string $header, int $index): bool => ! in_array($index, $used, true), ARRAY_FILTER_USE_BOTH));
    }

    /**
     * Labels of the required fields without a column ("Fecha y hora", or
     * "Texto o Archivo" for a group).
     *
     * @param  array<string, int|null>  $mapping
     * @return list<string>
     */
    public function missingFields(HistoryImportFileKind $kind, array $mapping): array
    {
        $fields = $this->fields($kind);
        $missing = [];

        foreach (self::REQUIRED[$kind->value] ?? [] as $group) {
            $satisfied = array_filter($group, fn (string $field): bool => ($mapping[$field] ?? null) !== null) !== [];

            if (! $satisfied) {
                $missing[] = implode(' o ', array_map(fn (string $field): string => $fields[$field]['label'] ?? $field, $group));
            }
        }

        return $missing;
    }
}
