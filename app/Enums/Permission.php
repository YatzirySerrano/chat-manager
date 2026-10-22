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
    case AssignRoles = 'users.assign-roles';
    case DeleteUsers = 'users.delete';

    case ViewDepartments = 'departments.view';
    case CreateDepartments = 'departments.create';
    case UpdateDepartments = 'departments.update';
    case ManageDepartmentMembers = 'departments.manage-members';
    case DeleteDepartments = 'departments.delete';

    case ViewRoles = 'roles.view';
    case CreateRoles = 'roles.create';
    case UpdateRoles = 'roles.update';
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

    case ViewTemplates = 'templates.view';
    case ManageTemplates = 'templates.manage';

    case ViewContacts = 'contacts.view';
    case CreateContacts = 'contacts.create';
    case UpdateContacts = 'contacts.update';
    case DeleteContacts = 'contacts.delete';
    case ImportContacts = 'contacts.import';
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

    case ViewDeletedRecords = 'records.view-deleted';

    case ViewAuditLog = 'audit.view';
    case ExportAuditLog = 'audit.export';

    case ViewSystem = 'system.view';
    case ManageSystem = 'system.manage';

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
            self::AssignRoles => 'Asignar roles a usuarios',
            self::DeleteUsers => 'Eliminar y restaurar usuarios',
            self::ViewDepartments => 'Ver departamentos',
            self::CreateDepartments => 'Crear departamentos',
            self::UpdateDepartments => 'Editar departamentos',
            self::ManageDepartmentMembers => 'Administrar miembros',
            self::DeleteDepartments => 'Eliminar y restaurar departamentos',
            self::ViewRoles => 'Ver roles y permisos',
            self::CreateRoles => 'Crear roles',
            self::UpdateRoles => 'Editar roles y sus permisos',
            self::DeleteRoles => 'Eliminar y restaurar roles sin usuarios',
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
            self::ManageConnections => 'Administrar conexiones y credenciales (incluye eliminar y restaurar)',
            self::ViewTemplates => 'Ver plantillas de WhatsApp',
            self::ManageTemplates => 'Sincronizar plantillas de WhatsApp con Meta',
            self::ViewContacts => 'Ver contactos',
            self::CreateContacts => 'Crear contactos',
            self::UpdateContacts => 'Editar contactos',
            self::DeleteContacts => 'Eliminar y restaurar contactos',
            self::ImportContacts => 'Importar contactos desde Excel/CSV',
            self::ManageContactNotes => 'Registrar notas privadas de contactos',
            self::ClassifyContactOrigin => 'Registrar procedencia declarada (excepcional, solo alcance global)',
            self::ViewTags => 'Ver etiquetas',
            self::ManageTags => 'Administrar etiquetas (crear, editar, eliminar y restaurar)',
            self::AssignTags => 'Asignar etiquetas a contactos y conversaciones',
            self::UseQuickReplies => 'Usar respuestas rápidas',
            self::ManageQuickReplies => 'Administrar respuestas rápidas (crear, editar, eliminar y restaurar)',
            self::UseTeamChat => 'Usar el chat interno',
            self::CreateTeamChatGroups => 'Crear grupos internos',
            self::ManageTeamChatGroups => 'Administrar los grupos internos en los que participa',
            self::UseCrossDepartmentTeamChat => 'Iniciar chats internos con personas de otros departamentos',
            self::ViewReports => 'Ver reportes',
            self::ExportReports => 'Exportar información a Excel y PDF',
            self::ViewSettings => 'Ver configuración',
            self::UpdateSettings => 'Modificar configuración',
            self::ViewAppearance => 'Ver personalización',
            self::UpdateAppearance => 'Modificar la apariencia global',
            self::ViewDeletedRecords => 'Consultar registros eliminados',
            self::ViewAuditLog => 'Ver registros de auditoría',
            self::ExportAuditLog => 'Exportar registros de auditoría',
            self::ViewSystem => 'Ver el estado técnico del sistema (colas, trabajos fallidos, salud)',
            self::ManageSystem => 'Reintentar o descartar trabajos fallidos del sistema',
        };
    }

    /**
     * Whether the code enforcing this permission already exists. Unavailable
     * permissions are shown as "coming later" and cannot be edited.
     *
     * Every catalogued module now ships (reports.view is enforced by the
     * reports module), so all permissions are
     * available; the flag stays for future modules. reports.export is the
     * generic export permission (Excel and PDF) of every module.
     * audit.export exports the audit log (always within what the viewer may
     * read) and contacts.import runs the contacts importer, which stays
     * reserved to the root authority.
     */
    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * Permissions whose removal or grant deserves an explicit warning.
     */
    public function isSensitive(): bool
    {
        return in_array($this, [
            self::CreateUsers,
            self::UpdateUsers,
            self::AssignRoles,
            self::DeleteUsers,
            self::CreateRoles,
            self::UpdateRoles,
            self::DeleteRoles,
            self::DeleteDepartments,
            self::DeleteContacts,
            self::ImportContacts,
            self::ViewDeletedRecords,
            self::ExportAuditLog,
            self::UpdateSettings,
            self::UpdateAppearance,
            self::ManageConnections,
            self::ViewSystem,
            self::ManageSystem,
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
            self::DeleteDepartments,
            self::CreateRoles,
            self::UpdateRoles,
            self::DeleteRoles,
            self::ViewSettings,
            self::UpdateSettings,
            self::ViewAppearance,
            self::UpdateAppearance,
            self::ViewConnections,
            self::ManageConnections,
            self::ManageTemplates,
            self::ManageTags,
            self::ClassifyContactOrigin,
            self::ImportContacts,
            self::ViewSystem,
            self::ManageSystem,
        ], true);
    }

    /**
     * Capabilities only the root authority (Superadministrador) may hold: they
     * are never granted to another role, whoever edits it, and a non-root role
     * never exercises them even if the database says otherwise.
     *
     * - contacts.import: a bulk import rewrites the directory of the whole
     *   organization in one operation (and can merge or overwrite customers
     *   that other departments attend), so it stays with the platform owner.
     *
     * No other existing permission is reserved: the Administrador manages
     * everything else below the root authority, and the privilege guard
     * already keeps the root role and root users out of their reach.
     */
    public function isReservedToRoot(): bool
    {
        return $this === self::ImportContacts;
    }

    /**
     * Capabilities the root authority can never lose: managing users (including
     * restoring deleted ones, which requires seeing them) and roles, so the
     * platform can always be recovered.
     *
     * @return list<self>
     */
    public static function rootCapabilities(): array
    {
        return [
            self::ViewUsers,
            self::CreateUsers,
            self::UpdateUsers,
            self::DeleteUsers,
            self::AssignRoles,
            self::ViewRoles,
            self::UpdateRoles,
            self::ViewDeletedRecords,
        ];
    }

    /**
     * @return list<string>
     */
    public static function reservedToRootValues(): array
    {
        return array_column(array_values(array_filter(self::cases(), fn (self $permission): bool => $permission->isReservedToRoot())), 'value');
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
     * @return list<array{value: string, label: string, permissions: list<array{value: string, label: string, available: bool, sensitive: bool, global_only: bool, reserved: bool}>}>
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
                'reserved' => $permission->isReservedToRoot(),
            ];
        }

        return array_values($groups);
    }
}
