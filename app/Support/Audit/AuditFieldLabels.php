<?php

namespace App\Support\Audit;

use Illuminate\Support\Str;

/**
 * Spanish labels of the property keys and model attributes written to the
 * audit log (see every AuditLogger::record call). Stored keys are permanent
 * and never renamed; this dictionary only decides how they are shown. An
 * unknown key never breaks the screen: it falls back to a humanized label.
 */
final class AuditFieldLabels
{
    /**
     * @var array<string, string>
     */
    private const LABELS = [
        // People, roles and departments.
        'name' => 'Nombre',
        'email' => 'Correo electrónico',
        'role' => 'Rol',
        'member' => 'Miembro',
        'member_id' => 'ID del miembro',
        'member_role' => 'Rol del miembro',
        'members_kept' => 'Miembros conservados',
        'departments' => 'Departamentos',
        'department_ids' => 'Departamentos permitidos',
        'default_department_id' => 'Departamento predeterminado',
        'added' => 'Agregados',
        'removed' => 'Retirados',
        'from' => 'Antes',
        'to' => 'Después',
        'scope' => 'Alcance de datos',
        'label' => 'Nombre',
        'description' => 'Descripción',
        'permissions' => 'Permisos',
        'lost_permissions' => 'Permisos retirados',
        'gained_permissions' => 'Permisos otorgados',
        'users_affected' => 'Usuarios afectados',
        'released_conversations' => 'Conversaciones liberadas',
        'sessions_closed' => 'Sesiones cerradas',
        'deleted_at' => 'Eliminado el',
        'max_open_conversations' => 'Límite de conversaciones abiertas',
        'max_open_conversations_per_agent' => 'Límite de conversaciones abiertas por agente',
        'distribution_mode' => 'Modo de distribución',
        'slug' => 'Identificador',
        'color' => 'Color',
        'welcome_message' => 'Mensaje de bienvenida',
        'away_message' => 'Mensaje fuera de horario',
        'farewell_message' => 'Mensaje de despedida',
        'welcome_enabled' => 'Mensaje de bienvenida activo',
        'away_enabled' => 'Mensaje fuera de horario activo',
        'farewell_enabled' => 'Mensaje de despedida activo',
        'uses_custom_hours' => 'Usa horario propio',
        'business_hours' => 'Horario de atención',
        'availability' => 'Disponibilidad',
        'value' => 'Valor',

        // Connections and Meta.
        'channel' => 'Canal',
        'provider' => 'Proveedor',
        'display_phone_number' => 'Número de teléfono',
        'phone_number_id' => 'Phone number ID',
        'business_account_id' => 'ID de la cuenta de WhatsApp Business',
        'meta_app_id' => 'ID de la app de Meta',
        'verified_name' => 'Nombre verificado por Meta',
        'phone_status' => 'Estado del número',
        'quality_rating' => 'Calidad del número',
        'code_verification_status' => 'Verificación del número',
        'platform_type' => 'Plataforma',
        'verification_result' => 'Resultado de la verificación',
        'verification_reset' => 'Verificación reiniciada',
        'meta_code' => 'Código de error de Meta',
        'http_status' => 'Código HTTP',
        'reason' => 'Motivo',
        'saved_fields' => 'Credenciales guardadas',
        'replaced_fields' => 'Credenciales reemplazadas',
        'health' => 'Estado de la conexión',
        'was_active' => 'Estaba activa',
        'conversations_kept' => 'Conversaciones conservadas',
        'contacts_kept' => 'Contactos conservados',
        'reopen_window_minutes' => 'Ventana para reabrir (minutos)',
        'subscribed' => 'App suscrita',
        'apps' => 'Apps suscritas',
        'connection_id' => 'Conexión',
        'connection_ids' => 'Conexiones permitidas',
        'is_active' => 'Activa',
        'total' => 'Total',
        'created' => 'Creados',
        'updated' => 'Actualizados',
        'skipped' => 'Omitidos',
        'failed' => 'Con error',

        // Contacts.
        'source' => 'Origen del registro',
        'automatic' => 'Registro automático',
        'has_phone' => 'Con teléfono',
        'has_email' => 'Con correo',
        'confirmed_duplicate' => 'Duplicado confirmado',
        'phone_e164' => 'Teléfono',
        'profile_name' => 'Nombre de perfil',
        'custom_fields' => 'Campos personalizados',
        'attribution_id' => 'ID de la atribución',
        'origin_type' => 'Origen del contacto',
        'reliability' => 'Confiabilidad',
        'note_id' => 'ID de la nota',
        'length' => 'Longitud (caracteres)',
        'matched_by' => 'Vinculado por',
        'from_contact_id' => 'Contacto de origen',
        'review_case_id' => 'Caso de revisión',
        'candidates' => 'Posibles duplicados',
        'resolution' => 'Resolución',
        'resolved_contact_id' => 'Contacto resultante',
        'saved_at' => 'Guardado el',
        'provisional' => 'Contacto provisional (sin guardar)',
        'via' => 'Guardado desde',
        'details_changed' => 'Datos corregidos al guardar',
        'contact_id' => 'ID del contacto',

        // Conversations and messages.
        'message_id' => 'ID del mensaje',
        'previous_error' => 'Error anterior',
        'error_code' => 'Motivo del error',
        'conversation_id' => 'ID de la conversación',
        'assigned_user_id' => 'ID del agente asignado',
        'duplicate_risk_confirmed' => 'Riesgo de duplicado aceptado',

        // Tags and quick replies.
        'tags' => 'Etiquetas',
        'items' => 'Elementos',
        'title' => 'Título',
        'shortcut' => 'Atajo',
        'is_global' => 'Disponible para todos',
        'body' => 'Texto',
        'personal' => 'Respuesta personal',
        'personal_quick_replies' => 'Respuestas personales existentes',

        // Exports and imports.
        'report' => 'Reporte',
        'report_title' => 'Reporte',
        'format' => 'Formato',
        'filters' => 'Filtros',
        'rows' => 'Filas',
        'mode' => 'Modo de generación',
        'transfer_id' => 'ID de la transferencia',
        'file' => 'Archivo',
        'decision' => 'Contactos existentes',
        'fields' => 'Campos importados',

        // Team chat.
        'participants' => 'Participantes',
        'participant' => 'Participante',

        // System and security.
        'uuid' => 'UUID del trabajo',
        'job' => 'Trabajo',
        'queue' => 'Cola',
        'failed_at' => 'Falló el',

        // API tokens.
        'public_id' => 'ID público',
        'abilities' => 'Permisos del token',
        'allowed_ips' => 'IP permitidas',
        'expires_at' => 'Vence el',

        // Web chat widget.
        'allowed_origins' => 'Sitios permitidos',
        'subtitle' => 'Subtítulo',
        'welcome_text' => 'Texto de bienvenida',
        'offline_text' => 'Texto fuera de horario',
        'position' => 'Posición',
        'accent_color' => 'Color principal',
        'launcher_enabled' => 'Botón flotante activo',
        'launcher_label' => 'Texto del botón flotante',
        'avatar' => 'Imagen',
        'avatar_path' => 'Imagen',
        'identity_mode' => 'Identificación de visitantes',
        'prechat' => 'Formulario previo',
        'availability_mode' => 'Disponibilidad del chat',
        'session_idle_days' => 'Días de inactividad de la sesión',
        'revoked_sessions' => 'Sesiones cerradas',

        // Phase five: supervision of personal items, Meta accounts, on-hold retirement.
        'owner' => 'Propietario',
        'owner_id' => 'ID del propietario',
        'owner_user_id' => 'ID del propietario',
        'item' => 'Elemento',
        'item_type' => 'Tipo de elemento',
        'item_id' => 'ID del elemento',
        'alias' => 'Alias',
        'preset' => 'Estilo predefinido',
        'external_account_id' => 'ID de la cuenta de Meta',
        'external_account_name' => 'Cuenta de Meta',
        'granted_scopes' => 'Permisos otorgados por Meta',
        'token_expires_at' => 'Vencimiento del token',
        'authorized_at' => 'Autorizada el',
        'previous_status' => 'Estado anterior',
        'new_status' => 'Estado nuevo',
        'status' => 'Estado',
        'assignee_id' => 'ID del agente asignado',
        'target' => 'Estado destino',
        'target_status' => 'Estado destino',
        'held_since' => 'En espera desde',
        'on_hold_at' => 'En espera desde',
        'on_hold_reason' => 'Motivo de la espera',
        'account_name' => 'Cuenta de Meta',
        'reauthorized' => 'Reautorización',
        'unsubscribed' => 'App desuscrita',

        // Platform settings (dotted paths).
        'general.platform_name' => 'Nombre de la plataforma',
        'general.timezone' => 'Zona horaria',
        'appearance.palette' => 'Paleta de colores',
        'business_hours.enabled' => 'Horario de atención activo',
        'operations.default_department_id' => 'Departamento predeterminado',
        'operations.max_open_conversations_per_agent' => 'Límite de conversaciones abiertas por agente',
        'operations.auto_close_after_hours' => 'Cierre automático (horas sin actividad)',
        'operations.transfer_reason_required' => 'Motivo obligatorio al transferir',
        'sla.first_response_minutes' => 'Objetivo de primera respuesta (minutos)',
        'sla.resolution_minutes' => 'Objetivo de resolución (minutos)',
    ];

    /**
     * Labels of the segments of dotted paths (settings and business hours).
     *
     * @var array<string, string>
     */
    private const SEGMENTS = [
        'general' => 'General',
        'appearance' => 'Apariencia',
        'business_hours' => 'Horario de atención',
        'operations' => 'Operación',
        'sla' => 'Objetivos de servicio',
        'days' => '',
        'holidays' => 'Día festivo',
        'monday' => 'Lunes',
        'tuesday' => 'Martes',
        'wednesday' => 'Miércoles',
        'thursday' => 'Jueves',
        'friday' => 'Viernes',
        'saturday' => 'Sábado',
        'sunday' => 'Domingo',
        'open' => 'abierto',
        'start' => 'apertura',
        'end' => 'cierre',
        'date' => 'fecha',
        'label' => 'nombre',
        'recurring' => 'se repite cada año',
        'enabled' => 'activo',
    ];

    public function label(string $key): string
    {
        if (isset(self::LABELS[$key])) {
            return self::LABELS[$key];
        }

        if (str_contains($key, '.')) {
            return $this->dotted($key);
        }

        return self::humanize($key);
    }

    /**
     * Whether the key has a dedicated label (not a humanized fallback).
     */
    public function knows(string $key): bool
    {
        return isset(self::LABELS[$key]);
    }

    /**
     * "max_open_conversations" → "Max open conversations": readable and
     * stable for keys added later without a dictionary entry.
     */
    public static function humanize(string $key): string
    {
        $words = trim((string) preg_replace('/[\s_\-.]+/', ' ', Str::snake($key)));

        return $words === '' ? $key : Str::ucfirst($words);
    }

    /**
     * "business_hours.days.monday.start" → "Horario de atención › Lunes › apertura".
     */
    private function dotted(string $key): string
    {
        $parts = [];
        $segments = explode('.', $key);

        foreach ($segments as $index => $segment) {
            if (ctype_digit($segment)) {
                $previous = $segments[$index - 1] ?? null;
                $number = ((int) $segment) + 1;
                $parts[] = $previous === 'holidays' ? "#{$number}" : (string) $number;

                continue;
            }

            $label = self::SEGMENTS[$segment] ?? (self::LABELS[$segment] ?? self::humanize($segment));

            if ($label !== '') {
                $parts[] = $label;
            }
        }

        $label = implode(' › ', $parts);

        return Str::ucfirst(str_replace('Día festivo › #', 'Día festivo #', $label));
    }
}
