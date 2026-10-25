<?php

namespace App\Enums;

/**
 * Roles the platform starts with. They are only a starting point: the
 * synchronization creates the ones that do not exist yet and never touches
 * an existing role, so every role is then managed from the interface. An
 * existing installation brings its default roles up to these definitions
 * only on request (app:sync-permissions --upgrade-default-roles), which adds
 * what is missing and never removes a customization.
 *
 * The Superadministrador keeps the historical name 'admin' because existing
 * installations already store it; the Administrador is a regular (non-root)
 * role with organization-wide reach.
 */
enum DefaultRole: string
{
    case SuperAdministrator = 'admin';
    case Administrator = 'administrator';
    case Supervisor = 'supervisor';
    case Agent = 'agent';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdministrator => 'Superadministrador',
            self::Administrator => 'Administrador',
            self::Supervisor => 'Supervisor',
            self::Agent => 'Agente',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SuperAdministrator => 'Autoridad raíz de la plataforma: controla todo, incluidas las capacidades reservadas y los demás superadministradores.',
            self::Administrator => 'Administra la operación de toda la organización por debajo del superadministrador.',
            self::Supervisor => 'Supervisa las conversaciones y los miembros de sus departamentos.',
            self::Agent => 'Atiende las conversaciones que tiene asignadas.',
        };
    }

    public function scope(): DataScope
    {
        return match ($this) {
            self::SuperAdministrator, self::Administrator => DataScope::Global,
            self::Supervisor => DataScope::Departments,
            self::Agent => DataScope::Assigned,
        };
    }

    /**
     * The superadministrator role is the protected root authority.
     */
    public function isRoot(): bool
    {
        return $this === self::SuperAdministrator;
    }

    /**
     * The agent classifies the conversations it attends (tags.assign only
     * reaches conversations it is responsible for) and sees its own figures
     * (reports.view is personal for the assigned scope).
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::SuperAdministrator => Permission::cases(),
            self::Administrator => array_values(array_filter(
                Permission::cases(),
                fn (Permission $permission): bool => ! $permission->isReservedToRoot(),
            )),
            self::Supervisor => [
                Permission::ViewDashboard,
                Permission::ViewDepartments,
                Permission::ManageDepartmentMembers,
                Permission::ViewConversations,
                Permission::ReplyConversations,
                Permission::AssignConversations,
                Permission::TransferConversations,
                Permission::CloseConversations,
                Permission::ReopenConversations,
                Permission::ViewConversationHistory,
                Permission::AcceptConversations,
                Permission::AddConversationNotes,
                Permission::SuperviseConversations,
                Permission::ViewContacts,
                Permission::ViewTags,
                Permission::AssignTags,
                Permission::UseQuickReplies,
                Permission::ViewReports,
                Permission::UseTeamChat,
                Permission::CreateTeamChatGroups,
                Permission::ManageTeamChatGroups,
                Permission::UseCrossDepartmentTeamChat,
            ],
            self::Agent => [
                Permission::ViewDashboard,
                Permission::ViewConversations,
                Permission::ReplyConversations,
                Permission::AcceptConversations,
                Permission::TransferConversations,
                Permission::CloseConversations,
                Permission::ViewConversationHistory,
                Permission::AddConversationNotes,
                Permission::ViewContacts,
                Permission::ViewTags,
                Permission::AssignTags,
                Permission::UseQuickReplies,
                Permission::ViewReports,
                Permission::UseTeamChat,
            ],
        };
    }
}
