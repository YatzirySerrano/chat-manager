<?php

namespace App\Enums;

/**
 * Actions recorded in the audit log. Stored values are permanent: the only
 * cases that are no longer emitted (user.activated, user.deactivated and
 * department.deactivated, from before logical deletion) are kept because
 * older entries carry them; removing a case breaks reading those rows.
 */
enum AuditAction: string
{
    case UserCreated = 'user.created';
    case UserUpdated = 'user.updated';
    case UserRoleChanged = 'user.role_changed';
    case UserDepartmentsChanged = 'user.departments_changed';
    case UserPasswordReset = 'user.password_reset';
    case UserActivated = 'user.activated';
    case UserDeactivated = 'user.deactivated';
    case UserDeleted = 'user.deleted';
    case UserRestored = 'user.restored';
    case UserAvailabilityChanged = 'user.availability_changed';
    case DepartmentCreated = 'department.created';
    case DepartmentUpdated = 'department.updated';
    case DepartmentDeactivated = 'department.deactivated';
    case DepartmentDeleted = 'department.deleted';
    case DepartmentRestored = 'department.restored';
    case DepartmentMemberAdded = 'department.member_added';
    case DepartmentMemberRemoved = 'department.member_removed';
    case DepartmentOperationsUpdated = 'department.operations_updated';
    case DepartmentHoursUpdated = 'department.hours_updated';
    case RoleCreated = 'role.created';
    case RoleUpdated = 'role.updated';
    case RolePermissionsChanged = 'role.permissions_changed';
    case RoleDeleted = 'role.deleted';
    case RoleRestored = 'role.restored';
    case SettingsUpdated = 'settings.updated';
    case AppearanceUpdated = 'appearance.updated';
    case ConnectionCreated = 'connection.created';
    case ConnectionUpdated = 'connection.updated';
    case ConnectionActivated = 'connection.activated';
    case ConnectionDeactivated = 'connection.deactivated';
    case ConnectionDeleted = 'connection.deleted';
    case ConnectionRestored = 'connection.restored';
    case ConnectionCredentialsUpdated = 'connection.credentials_updated';
    case ConnectionCredentialsVerified = 'connection.credentials_verified';
    case ConnectionCredentialsFailed = 'connection.credentials_failed';
    case ConnectionSubscriptionChecked = 'connection.subscription_checked';
    case ConnectionAppSubscribed = 'connection.app_subscribed';
    case TemplatesSynchronized = 'templates.synchronized';
    case ContactCreated = 'contact.created';
    case ContactUpdated = 'contact.updated';
    case ContactDeleted = 'contact.deleted';
    case ContactRestored = 'contact.restored';
    case ContactOriginClassified = 'contact.origin_classified';
    case ContactNoteAdded = 'contact.note_added';
    case ContactTagsChanged = 'contact.tags_changed';
    case ConversationTagsChanged = 'conversation.tags_changed';
    case MessageRetriedWithDuplicateRisk = 'conversation.message_retried_duplicate_risk';
    case AttachmentLinkRevoked = 'conversation.attachment_link_revoked';
    case ContactIdentityLinked = 'contact.identity_linked';
    case ContactReviewOpened = 'contact.review_opened';
    case ContactReviewResolved = 'contact.review_resolved';
    case TagCreated = 'tag.created';
    case TagUpdated = 'tag.updated';
    case TagDeleted = 'tag.deleted';
    case TagRestored = 'tag.restored';
    case QuickReplyCreated = 'quick_reply.created';
    case QuickReplyUpdated = 'quick_reply.updated';
    case QuickReplyDeleted = 'quick_reply.deleted';
    case QuickReplyRestored = 'quick_reply.restored';
    case ExportRequested = 'export.requested';
    case ExportGenerated = 'export.generated';
    case ExportFailed = 'export.failed';
    case ImportRequested = 'import.requested';
    case ImportCompleted = 'import.completed';
    case ImportFailed = 'import.failed';
    case ImportRollbackRequested = 'import.rollback_requested';
    case ImportRolledBack = 'import.rolled_back';
    case ImportRollbackFailed = 'import.rollback_failed';
    case TeamChatGroupCreated = 'team_chat.group_created';
    case TeamChatGroupUpdated = 'team_chat.group_updated';
    case TeamChatParticipantsAdded = 'team_chat.participants_added';
    case TeamChatParticipantRemoved = 'team_chat.participant_removed';
    case TeamChatParticipantRoleChanged = 'team_chat.participant_role_changed';
    case TeamChatGroupArchived = 'team_chat.group_archived';
    case TeamChatGroupUnarchived = 'team_chat.group_unarchived';
    case SystemJobRetried = 'system.job_retried';
    case SystemJobForgotten = 'system.job_forgotten';
    case AuthPasswordChanged = 'auth.password_changed';
    case AuthPasswordResetByLink = 'auth.password_reset_by_link';
    case AuthTwoFactorEnabled = 'auth.two_factor_enabled';
    case AuthTwoFactorDisabled = 'auth.two_factor_disabled';
    case AuthRecoveryCodesRegenerated = 'auth.recovery_codes_regenerated';
    case AuthLockout = 'auth.lockout';
    case ApiTokenIssued = 'api_token.issued';
    case ApiTokenRevoked = 'api_token.revoked';
    case WebChatWidgetUpdated = 'web_chat.widget_updated';
    case WebChatKeyRotated = 'web_chat.key_rotated';
    case WebChatIdentitySecretRotated = 'web_chat.identity_secret_rotated';
    case ContactSaved = 'contact.saved';
    case SupervisionPersonalItemUpdated = 'supervision.personal_item_updated';
    case SupervisionPersonalItemDeleted = 'supervision.personal_item_deleted';
    case UserPersonalQuickRepliesGranted = 'user.personal_quick_replies_granted';
    case UserPersonalQuickRepliesRevoked = 'user.personal_quick_replies_revoked';
    case ConnectionMetaAuthorized = 'connection.meta_authorized';
    case ConnectionMetaDisconnected = 'connection.meta_disconnected';
    case ConversationOnHoldRetired = 'conversation.on_hold_retired';

    /**
     * Human readable description of the action.
     */
    public function label(): string
    {
        return match ($this) {
            self::UserCreated => 'Usuario creado',
            self::UserUpdated => 'Usuario actualizado',
            self::UserRoleChanged => 'Rol modificado',
            self::UserDepartmentsChanged => 'Departamentos del usuario modificados',
            self::UserPasswordReset => 'Contraseña restablecida por un administrador',
            self::UserActivated => 'Usuario activado',
            self::UserDeactivated => 'Usuario desactivado',
            self::UserDeleted => 'Usuario eliminado',
            self::UserRestored => 'Usuario restaurado',
            self::UserAvailabilityChanged => 'Disponibilidad para atender modificada',
            self::DepartmentCreated => 'Departamento creado',
            self::DepartmentUpdated => 'Departamento actualizado',
            self::DepartmentDeactivated => 'Departamento desactivado',
            self::DepartmentDeleted => 'Departamento eliminado',
            self::DepartmentRestored => 'Departamento restaurado',
            self::DepartmentMemberAdded => 'Miembro agregado al departamento',
            self::DepartmentMemberRemoved => 'Miembro retirado del departamento',
            self::DepartmentOperationsUpdated => 'Configuración operativa del departamento actualizada',
            self::DepartmentHoursUpdated => 'Horario del departamento actualizado',
            self::RoleCreated => 'Rol creado',
            self::RoleUpdated => 'Rol actualizado',
            self::RolePermissionsChanged => 'Permisos del rol modificados',
            self::RoleDeleted => 'Rol eliminado',
            self::RoleRestored => 'Rol restaurado',
            self::SettingsUpdated => 'Configuración actualizada',
            self::AppearanceUpdated => 'Apariencia global modificada',
            self::ConnectionCreated => 'Conexión creada',
            self::ConnectionUpdated => 'Conexión actualizada',
            self::ConnectionActivated => 'Conexión activada',
            self::ConnectionDeactivated => 'Conexión desactivada',
            self::ConnectionDeleted => 'Conexión eliminada',
            self::ConnectionRestored => 'Conexión restaurada',
            self::ConnectionCredentialsUpdated => 'Credenciales de la conexión actualizadas',
            self::ConnectionCredentialsVerified => 'Credenciales de la conexión verificadas',
            self::ConnectionCredentialsFailed => 'Falló la verificación de credenciales',
            self::ConnectionSubscriptionChecked => 'Se revisó si Meta envía los mensajes de esta conexión',
            self::ConnectionAppSubscribed => 'Se activó la recepción de mensajes de WhatsApp desde Meta',
            self::TemplatesSynchronized => 'Plantillas de WhatsApp actualizadas desde Meta',
            self::ContactCreated => 'Contacto creado',
            self::ContactUpdated => 'Contacto actualizado',
            self::ContactDeleted => 'Contacto eliminado',
            self::ContactRestored => 'Contacto restaurado',
            self::ContactOriginClassified => 'Se anotó cómo nos conoció el contacto',
            self::ContactNoteAdded => 'Nota privada agregada al contacto',
            self::ContactTagsChanged => 'Etiquetas del contacto modificadas',
            self::ConversationTagsChanged => 'Etiquetas de la conversación modificadas',
            self::MessageRetriedWithDuplicateRisk => 'Reintento de un mensaje con entrega no confirmada (riesgo de duplicado)',
            self::AttachmentLinkRevoked => 'Revocación del enlace de descarga de un archivo enviado',
            self::ContactIdentityLinked => 'Número de WhatsApp vinculado al contacto',
            self::ContactReviewOpened => 'Posible duplicado enviado a revisión',
            self::ContactReviewResolved => 'Revisión de posible duplicado resuelta',
            self::TagCreated => 'Etiqueta creada',
            self::TagUpdated => 'Etiqueta actualizada',
            self::TagDeleted => 'Etiqueta eliminada',
            self::TagRestored => 'Etiqueta restaurada',
            self::QuickReplyCreated => 'Respuesta rápida creada',
            self::QuickReplyUpdated => 'Respuesta rápida actualizada',
            self::QuickReplyDeleted => 'Respuesta rápida eliminada',
            self::QuickReplyRestored => 'Respuesta rápida restaurada',
            self::ExportRequested => 'Exportación solicitada',
            self::ExportGenerated => 'Exportación generada',
            self::ExportFailed => 'Exportación fallida',
            self::ImportRequested => 'Importación de contactos solicitada',
            self::ImportCompleted => 'Importación de contactos terminada',
            self::ImportFailed => 'Importación de contactos fallida',
            self::ImportRollbackRequested => 'Reversión de importación solicitada',
            self::ImportRolledBack => 'Importación de contactos revertida',
            self::ImportRollbackFailed => 'Reversión de importación fallida',
            self::TeamChatGroupCreated => 'Grupo de chat interno creado',
            self::TeamChatGroupUpdated => 'Grupo de chat interno actualizado',
            self::TeamChatParticipantsAdded => 'Participantes agregados a un grupo interno',
            self::TeamChatParticipantRemoved => 'Participante retirado de un grupo interno',
            self::TeamChatParticipantRoleChanged => 'Administradores de un grupo interno modificados',
            self::TeamChatGroupArchived => 'Grupo de chat interno archivado',
            self::TeamChatGroupUnarchived => 'Grupo de chat interno reactivado',
            self::SystemJobRetried => 'Tarea con error reintentada',
            self::SystemJobForgotten => 'Tarea con error descartada',
            self::AuthPasswordChanged => 'Contraseña cambiada por su titular',
            self::AuthPasswordResetByLink => 'Contraseña restablecida con enlace de recuperación',
            self::AuthTwoFactorEnabled => 'Verificación en dos pasos activada',
            self::AuthTwoFactorDisabled => 'Verificación en dos pasos desactivada',
            self::AuthRecoveryCodesRegenerated => 'Códigos de recuperación regenerados',
            self::AuthLockout => 'Inicio de sesión bloqueado por intentos fallidos',
            self::ApiTokenIssued => 'Clave de integración creada',
            self::ApiTokenRevoked => 'Clave de integración revocada',
            self::WebChatWidgetUpdated => 'Configuración del chat web actualizada',
            self::WebChatKeyRotated => 'Se cambió el código de instalación del chat web',
            self::WebChatIdentitySecretRotated => 'Se cambió la clave secreta de identificación del chat web',
            self::ContactSaved => 'Contacto guardado en el directorio',
            self::SupervisionPersonalItemUpdated => 'Elemento personal de otro usuario modificado',
            self::SupervisionPersonalItemDeleted => 'Elemento personal de otro usuario eliminado',
            self::UserPersonalQuickRepliesGranted => 'Respuestas rápidas personales habilitadas para el usuario',
            self::UserPersonalQuickRepliesRevoked => 'Respuestas rápidas personales retiradas al usuario',
            self::ConnectionMetaAuthorized => 'Cuenta de Meta autorizada para la conexión',
            self::ConnectionMetaDisconnected => 'Cuenta de Meta desconectada de la conexión',
            self::ConversationOnHoldRetired => 'Conversación «En espera» pasada a «Pendiente»',
        };
    }

    /**
     * Visual severity used by the interface.
     */
    public function tone(): string
    {
        return match ($this) {
            self::UserDeleted, self::UserDeactivated, self::DepartmentDeactivated, self::DepartmentMemberRemoved,
            self::RoleDeleted, self::ConnectionDeactivated, self::ConnectionCredentialsFailed,
            self::QuickReplyDeleted,
            self::DepartmentDeleted, self::ConnectionDeleted, self::ContactDeleted, self::TagDeleted,
            self::ExportFailed, self::ImportFailed, self::ImportRollbackFailed,
            self::AuthTwoFactorDisabled, self::AuthLockout, self::ApiTokenRevoked,
            self::SupervisionPersonalItemDeleted, self::ConnectionMetaDisconnected, self::AttachmentLinkRevoked => 'danger',
            self::UserRoleChanged, self::UserPasswordReset, self::SettingsUpdated, self::RolePermissionsChanged,
            self::AppearanceUpdated, self::ConnectionCredentialsUpdated,
            self::DepartmentHoursUpdated, self::ContactOriginClassified, self::ContactIdentityLinked,
            self::ContactReviewOpened, self::TeamChatParticipantRemoved, self::TeamChatParticipantRoleChanged,
            self::TeamChatGroupArchived, self::SystemJobRetried, self::SystemJobForgotten, self::MessageRetriedWithDuplicateRisk,
            self::ImportRollbackRequested, self::ImportRolledBack,
            self::AuthPasswordChanged, self::AuthPasswordResetByLink, self::AuthRecoveryCodesRegenerated,
            self::WebChatKeyRotated, self::WebChatIdentitySecretRotated,
            self::SupervisionPersonalItemUpdated, self::UserPersonalQuickRepliesRevoked => 'warning',
            self::UserCreated, self::UserActivated, self::DepartmentCreated, self::DepartmentMemberAdded,
            self::RoleCreated, self::ConnectionCreated, self::ConnectionActivated,
            self::ConnectionCredentialsVerified, self::ContactCreated, self::ContactRestored, self::TagCreated,
            self::QuickReplyCreated, self::UserRestored,
            self::DepartmentRestored, self::RoleRestored, self::ConnectionRestored, self::TagRestored,
            self::QuickReplyRestored, self::ImportCompleted, self::TeamChatGroupCreated,
            self::TeamChatParticipantsAdded, self::TeamChatGroupUnarchived, self::AuthTwoFactorEnabled,
            self::ApiTokenIssued, self::ContactSaved, self::UserPersonalQuickRepliesGranted,
            self::ConnectionMetaAuthorized => 'success',
            default => 'neutral',
        };
    }

    /**
     * Entries only the root authority may read whoever performed them: the
     * contacts importer is reserved to the Superadministrador.
     */
    public function isAlwaysRestricted(): bool
    {
        return in_array($this, [
            self::ImportRequested, self::ImportCompleted, self::ImportFailed,
            self::ImportRollbackRequested, self::ImportRolledBack, self::ImportRollbackFailed,
        ], true);
    }

    /**
     * Options for select inputs.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $action): array => [
            'value' => $action->value,
            'label' => $action->label(),
        ], self::cases());
    }
}
