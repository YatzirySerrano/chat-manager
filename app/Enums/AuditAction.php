<?php

namespace App\Enums;

/**
 * Actions recorded in the audit log. Stored values are permanent: cases that
 * are no longer emitted (the activation and deactivation of catalogs that
 * now use logical deletion, archiving contacts) are kept so older entries
 * keep their meaning.
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
    case DepartmentActivated = 'department.activated';
    case DepartmentDeactivated = 'department.deactivated';
    case DepartmentDeleted = 'department.deleted';
    case DepartmentRestored = 'department.restored';
    case DepartmentMemberAdded = 'department.member_added';
    case DepartmentMemberRemoved = 'department.member_removed';
    case DepartmentOperationsUpdated = 'department.operations_updated';
    case DepartmentConnectionsChanged = 'department.connections_changed';
    case DepartmentHoursUpdated = 'department.hours_updated';
    case RoleCreated = 'role.created';
    case RoleUpdated = 'role.updated';
    case RolePermissionsChanged = 'role.permissions_changed';
    case RoleActivated = 'role.activated';
    case RoleDeactivated = 'role.deactivated';
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
    case ContactArchived = 'contact.archived';
    case ContactDeleted = 'contact.deleted';
    case ContactRestored = 'contact.restored';
    case ContactOriginClassified = 'contact.origin_classified';
    case ContactNoteAdded = 'contact.note_added';
    case ContactTagsChanged = 'contact.tags_changed';
    case ConversationTagsChanged = 'conversation.tags_changed';
    case ContactIdentityLinked = 'contact.identity_linked';
    case ContactReviewOpened = 'contact.review_opened';
    case ContactReviewResolved = 'contact.review_resolved';
    case TagCreated = 'tag.created';
    case TagUpdated = 'tag.updated';
    case TagActivated = 'tag.activated';
    case TagDeactivated = 'tag.deactivated';
    case TagDeleted = 'tag.deleted';
    case TagRestored = 'tag.restored';
    case QuickReplyCreated = 'quick_reply.created';
    case QuickReplyUpdated = 'quick_reply.updated';
    case QuickReplyActivated = 'quick_reply.activated';
    case QuickReplyDeactivated = 'quick_reply.deactivated';
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
            self::DepartmentActivated => 'Departamento activado',
            self::DepartmentDeactivated => 'Departamento desactivado',
            self::DepartmentDeleted => 'Departamento eliminado',
            self::DepartmentRestored => 'Departamento restaurado',
            self::DepartmentMemberAdded => 'Miembro agregado al departamento',
            self::DepartmentMemberRemoved => 'Miembro retirado del departamento',
            self::DepartmentOperationsUpdated => 'Configuración operativa del departamento actualizada',
            self::DepartmentConnectionsChanged => 'Conexiones del departamento modificadas',
            self::DepartmentHoursUpdated => 'Horario del departamento actualizado',
            self::RoleCreated => 'Rol creado',
            self::RoleUpdated => 'Rol actualizado',
            self::RolePermissionsChanged => 'Permisos del rol modificados',
            self::RoleActivated => 'Rol activado',
            self::RoleDeactivated => 'Rol desactivado',
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
            self::ConnectionSubscriptionChecked => 'Suscripción del webhook de la conexión consultada',
            self::ConnectionAppSubscribed => 'App suscrita a los webhooks de la cuenta de WhatsApp',
            self::TemplatesSynchronized => 'Plantillas de WhatsApp sincronizadas con Meta',
            self::ContactCreated => 'Contacto creado',
            self::ContactUpdated => 'Contacto actualizado',
            self::ContactArchived => 'Contacto archivado',
            self::ContactDeleted => 'Contacto eliminado',
            self::ContactRestored => 'Contacto restaurado',
            self::ContactOriginClassified => 'Origen del contacto clasificado',
            self::ContactNoteAdded => 'Nota privada agregada al contacto',
            self::ContactTagsChanged => 'Etiquetas del contacto modificadas',
            self::ConversationTagsChanged => 'Etiquetas de la conversación modificadas',
            self::ContactIdentityLinked => 'Identidad de WhatsApp vinculada al contacto',
            self::ContactReviewOpened => 'Posible duplicado enviado a revisión',
            self::ContactReviewResolved => 'Revisión de posible duplicado resuelta',
            self::TagCreated => 'Etiqueta creada',
            self::TagUpdated => 'Etiqueta actualizada',
            self::TagActivated => 'Etiqueta activada',
            self::TagDeactivated => 'Etiqueta desactivada',
            self::TagDeleted => 'Etiqueta eliminada',
            self::TagRestored => 'Etiqueta restaurada',
            self::QuickReplyCreated => 'Respuesta rápida creada',
            self::QuickReplyUpdated => 'Respuesta rápida actualizada',
            self::QuickReplyActivated => 'Respuesta rápida activada',
            self::QuickReplyDeactivated => 'Respuesta rápida desactivada',
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
            self::SystemJobRetried => 'Trabajo fallido reenviado a la cola',
            self::SystemJobForgotten => 'Trabajo fallido descartado',
            self::AuthPasswordChanged => 'Contraseña cambiada por su titular',
            self::AuthPasswordResetByLink => 'Contraseña restablecida con enlace de recuperación',
            self::AuthTwoFactorEnabled => 'Verificación en dos pasos activada',
            self::AuthTwoFactorDisabled => 'Verificación en dos pasos desactivada',
            self::AuthRecoveryCodesRegenerated => 'Códigos de recuperación regenerados',
            self::AuthLockout => 'Inicio de sesión bloqueado por intentos fallidos',
        };
    }

    /**
     * Visual severity used by the interface.
     */
    public function tone(): string
    {
        return match ($this) {
            self::UserDeleted, self::UserDeactivated, self::DepartmentDeactivated, self::DepartmentMemberRemoved,
            self::RoleDeactivated, self::RoleDeleted, self::ConnectionDeactivated, self::ConnectionCredentialsFailed,
            self::ContactArchived, self::TagDeactivated, self::QuickReplyDeactivated, self::QuickReplyDeleted,
            self::DepartmentDeleted, self::ConnectionDeleted, self::ContactDeleted, self::TagDeleted,
            self::ExportFailed, self::ImportFailed, self::ImportRollbackFailed,
            self::AuthTwoFactorDisabled, self::AuthLockout => 'danger',
            self::UserRoleChanged, self::UserPasswordReset, self::SettingsUpdated, self::RolePermissionsChanged,
            self::AppearanceUpdated, self::ConnectionCredentialsUpdated, self::DepartmentConnectionsChanged,
            self::DepartmentHoursUpdated, self::ContactOriginClassified, self::ContactIdentityLinked,
            self::ContactReviewOpened, self::TeamChatParticipantRemoved, self::TeamChatParticipantRoleChanged,
            self::TeamChatGroupArchived, self::SystemJobRetried, self::SystemJobForgotten,
            self::ImportRollbackRequested, self::ImportRolledBack,
            self::AuthPasswordChanged, self::AuthPasswordResetByLink, self::AuthRecoveryCodesRegenerated => 'warning',
            self::UserCreated, self::UserActivated, self::DepartmentCreated, self::DepartmentActivated, self::DepartmentMemberAdded,
            self::RoleCreated, self::RoleActivated, self::ConnectionCreated, self::ConnectionActivated,
            self::ConnectionCredentialsVerified, self::ContactCreated, self::ContactRestored, self::TagCreated,
            self::TagActivated, self::QuickReplyCreated, self::QuickReplyActivated, self::UserRestored,
            self::DepartmentRestored, self::RoleRestored, self::ConnectionRestored, self::TagRestored,
            self::QuickReplyRestored, self::ImportCompleted, self::TeamChatGroupCreated,
            self::TeamChatParticipantsAdded, self::TeamChatGroupUnarchived, self::AuthTwoFactorEnabled => 'success',
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
