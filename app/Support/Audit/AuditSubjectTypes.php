<?php

namespace App\Support\Audit;

/**
 * Spanish names of the audited entity types, keyed by the morph alias stored
 * in audit_logs.auditable_type (AppServiceProvider morph map), and the page
 * that shows each one when there is one.
 */
final class AuditSubjectTypes
{
    /**
     * @var array<string, string>
     */
    public const LABELS = [
        'user' => 'Usuario',
        'department' => 'Departamento',
        'connection' => 'Conexión',
        'contact' => 'Contacto',
        'contact_note' => 'Nota de contacto',
        'contact_attribution' => 'Atribución de contacto',
        'contact_alias' => 'Alias de contacto',
        'chat_appearance' => 'Apariencia del chat',
        'conversation' => 'Conversación',
        'message' => 'Mensaje',
        'role' => 'Rol',
        'tag' => 'Etiqueta',
        'quick_reply' => 'Respuesta rápida',
        'team_thread' => 'Chat interno',
        'api_token' => 'Token de API',
        'web_chat_widget' => 'Chat web',
        'setting' => 'Configuración',
    ];

    /**
     * Named route of the entity's page (one route parameter: the id).
     *
     * @var array<string, string>
     */
    public const ROUTES = [
        'connection' => 'connections.show',
        'contact' => 'contacts.show',
        'conversation' => 'conversations.show',
        'department' => 'departments.show',
    ];

    public static function label(?string $type): ?string
    {
        if ($type === null) {
            return null;
        }

        return self::LABELS[$type] ?? AuditFieldLabels::humanize(class_basename($type));
    }
}
