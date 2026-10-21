<?php

namespace App\Enums;

/**
 * Central catalog of capabilities. Roles are managed from the interface, but a
 * permission only exists here when there is code that enforces it; permissions
 * of future modules are catalogued as unavailable until that module ships.
 */
enum Permission: string
{
    case ViewDashboard = 'dashboard.view';

    case ViewUsers = 'users.view';
    case CreateUsers = 'users.create';
    case UpdateUsers = 'users.update';
    case ChangeUserStatus = 'users.change-status';
    case AssignRoles = 'users.assign-roles';
    case DeleteUsers = 'users.delete';

    case ViewDepartments = 'departments.view';
    case CreateDepartments = 'departments.create';
    case UpdateDepartments = 'departments.update';
    case ManageDepartmentMembers = 'departments.manage-members';
    case ChangeDepartmentStatus = 'departments.change-status';

    case ViewRoles = 'roles.view';
    case CreateRoles = 'roles.create';
    case UpdateRoles = 'roles.update';
    case ChangeRoleStatus = 'roles.change-status';
    case DeleteRoles = 'roles.delete';

    case ViewConversations = 'conversations.view';
    case ReplyConversations = 'conversations.reply';
    case AssignConversations = 'conversations.assign';
    case TransferConversations = 'conversations.transfer';
    case CloseConversations = 'conversations.close';
    case ReopenConversations = 'conversations.reopen';
    case ViewConversationHistory = 'conversations.history';

    case AcceptConversations = 'conversations.accept';
    case AddConversationNotes = 'conversations.notes';

    case ViewConnections = 'connections.view';
    case ManageConnections = 'connections.manage';

    case ViewContacts = 'contacts.view';
    case CreateContacts = 'contacts.create';
    case UpdateContacts = 'contacts.update';
    case ArchiveContacts = 'contacts.archive';
    case ManageContactNotes = 'contacts.notes';
    case ClassifyContactOrigin = 'contacts.classify-origin';

    case ViewTags = 'tags.view';
    case ManageTags = 'tags.manage';
    case AssignTags = 'tags.assign';

    case UseQuickReplies = 'quick-replies.use';
    case ManageQuickReplies = 'quick-replies.manage';

    case UseTeamChat = 'team-chat.use';
    case CreateTeamChatGroups = 'team-chat.create-groups';
    case ManageTeamChatGroups = 'team-chat.manage-groups';
    case UseCrossDepartmentTeamChat = 'team-chat.cross-department';

    case ViewReports = 'reports.view';
    case ExportReports = 'reports.export';

    case ViewSettings = 'settings.view';
    case UpdateSettings = 'settings.update';

    case ViewAppearance = 'appearance.view';
    case UpdateAppearance = 'appearance.update';

    case ViewAuditLog = 'audit.view';
    case ExportAuditLog = 'audit.export';

    public function group(): PermissionGroup
    {
        return PermissionGroup::from(explode('.', $this->value)[0]);
    }

    public function label(): string
    {
        return match ($this) {
            self::ViewDashboard => 'Ver el panel principal',
            self::ViewUsers => 'Ver usuarios',
            self::CreateUsers => 'Crear usuarios',
            self::UpdateUsers => 'Editar usuarios',
            self::ChangeUserStatus => 'Activar y desactivar usuarios',
            self::AssignRoles => 'Asignar roles a usuarios',
            self::DeleteUsers => 'Eliminar usuarios sin historial',
            self::ViewDepartments => 'Ver departamentos',
            self::CreateDepartments => 'Crear departamentos',
            self::UpdateDepartments => 'Editar departamentos',
            self::ManageDepartmentMembers => 'Administrar miembros',
            self::ChangeDepartmentStatus => 'Activar y desactivar departamentos',
            self::ViewRoles => 'Ver roles y permisos',
            self::CreateRoles => 'Crear roles',
            self::UpdateRoles => 'Editar roles y sus permisos',
            self::ChangeRoleStatus => 'Activar y desactivar roles',
            self::DeleteRoles => 'Eliminar roles sin usuarios',
            self::ViewConversations => 'Ver conversaciones de su alcance',
            self::ReplyConversations => 'Atender y responder sus conversaciones',
            self::AssignConversations => 'Asignar conversaciones y gestionar las de su alcance',
            self::TransferConversations => 'Transferir conversaciones',
            self::CloseConversations => 'Cerrar conversaciones',
            self::ReopenConversations => 'Reabrir conversaciones cerradas',
            self::ViewConversationHistory => 'Consultar conversaciones cerradas y su historial',
            self::AcceptConversations => 'Aceptar conversaciones pendientes de sus departamentos',
            self::AddConversationNotes => 'Agregar notas internas',
            self::ViewConnections => 'Ver conexiones',
            self::ManageConnections => 'Administrar conexiones y credenciales',
            self::ViewContacts => 'Ver contactos',
            self::CreateContacts => 'Crear contactos',
            self::UpdateContacts => 'Editar contactos',
            self::ArchiveContacts => 'Archivar y restaurar contactos',
            self::ManageContactNotes => 'Registrar notas privadas de contactos',
            self::ClassifyContactOrigin => 'Registrar procedencia declarada (excepcional, solo alcance global)',
            self::ViewTags => 'Ver etiquetas',
            self::ManageTags => 'Administrar etiquetas',
            self::AssignTags => 'Asignar etiquetas a contactos',
            self::UseQuickReplies => 'Usar respuestas rápidas',
            self::ManageQuickReplies => 'Administrar respuestas rápidas',
            self::UseTeamChat => 'Usar el chat interno',
            self::CreateTeamChatGroups => 'Crear grupos internos',
            self::ManageTeamChatGroups => 'Administrar grupos internos',
            self::UseCrossDepartmentTeamChat => 'Comunicarse con otros departamentos',
            self::ViewReports => 'Ver reportes',
            self::ExportReports => 'Exportar reportes',
            self::ViewSettings => 'Ver configuración',
            self::UpdateSettings => 'Modificar configuración',
            self::ViewAppearance => 'Ver personalización',
            self::UpdateAppearance => 'Modificar la apariencia global',
            self::ViewAuditLog => 'Ver registros de auditoría',
            self::ExportAuditLog => 'Exportar registros de auditoría',
        };
    }

    /**
     * Whether the code enforcing this permission already exists. Unavailable
     * permissions are shown as "coming later" and cannot be edited.
     */
    public function isAvailable(): bool
    {
        return ! in_array($this->group(), [
            PermissionGroup::Reports,
            PermissionGroup::TeamChat,
        ], true) && $this !== self::ExportAuditLog;
    }

    /**
     * Permissions whose removal or grant deserves an explicit warning.
     */
    public function isSensitive(): bool
    {
        return in_array($this, [
            self::CreateUsers,
            self::UpdateUsers,
            self::ChangeUserStatus,
            self::AssignRoles,
            self::DeleteUsers,
            self::CreateRoles,
            self::UpdateRoles,
            self::ChangeRoleStatus,
            self::DeleteRoles,
            self::ChangeDepartmentStatus,
            self::UpdateSettings,
            self::UpdateAppearance,
            self::ManageConnections,
        ], true);
    }

    /**
     * Organization-wide capabilities. A role with a narrower scope never
     * exercises them, so a custom role cannot gain global reach by accident.
     */
    public function requiresGlobalScope(): bool
    {
        return in_array($this, [
            self::CreateDepartments,
            self::ChangeDepartmentStatus,
            self::CreateRoles,
            self::UpdateRoles,
            self::ChangeRoleStatus,
            self::DeleteRoles,
            self::ViewSettings,
            self::UpdateSettings,
            self::ViewAppearance,
            self::UpdateAppearance,
            self::ViewConnections,
            self::ManageConnections,
            self::ManageTags,
            self::ClassifyContactOrigin,
        ], true);
    }

    /**
     * Capabilities the root authority can never lose: managing users and roles,
     * so the platform can always be recovered.
     *
     * @return list<self>
     */
    public static function rootCapabilities(): array
    {
        return [
            self::ViewUsers,
            self::CreateUsers,
            self::UpdateUsers,
            self::ChangeUserStatus,
            self::AssignRoles,
            self::ViewRoles,
            self::UpdateRoles,
        ];
    }

    /**
     * @return list<self>
     */
    public static function available(): array
    {
        return array_values(array_filter(self::cases(), fn (self $permission): bool => $permission->isAvailable()));
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Catalog grouped by module for the permission matrix.
     *
     * @return list<array{value: string, label: string, permissions: list<array{value: string, label: string, available: bool, sensitive: bool, global_only: bool}>}>
     */
    public static function catalog(): array
    {
        $groups = [];

        foreach (self::cases() as $permission) {
            $group = $permission->group();
            $groups[$group->value] ??= ['value' => $group->value, 'label' => $group->label(), 'permissions' => []];
            $groups[$group->value]['permissions'][] = [
                'value' => $permission->value,
                'label' => $permission->label(),
                'available' => $permission->isAvailable(),
                'sensitive' => $permission->isSensitive(),
                'global_only' => $permission->requiresGlobalScope(),
            ];
        }

        return array_values($groups);
    }
}
