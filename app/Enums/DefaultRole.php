<?php

namespace App\Enums;

/**
 * Roles created on a fresh installation. They are only a starting point:
 * after installation every role, including these, is managed from the
 * interface and the synchronization never overwrites those changes.
 */
enum DefaultRole: string
{
    case Administrator = 'admin';
    case Supervisor = 'supervisor';
    case Agent = 'agent';

    public function label(): string
    {
        return match ($this) {
            self::Administrator => 'Administrador',
            self::Supervisor => 'Supervisor',
            self::Agent => 'Agente',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Administrator => 'Autoridad administrativa de la plataforma: usuarios, roles, departamentos y configuración.',
            self::Supervisor => 'Supervisa las conversaciones y los miembros de sus departamentos.',
            self::Agent => 'Atiende las conversaciones que tiene asignadas.',
        };
    }

    public function scope(): DataScope
    {
        return match ($this) {
            self::Administrator => DataScope::Global,
            self::Supervisor => DataScope::Departments,
            self::Agent => DataScope::Assigned,
        };
    }

    /**
     * The administrator role is the protected root authority.
     */
    public function isRoot(): bool
    {
        return $this === self::Administrator;
    }

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Administrator => Permission::cases(),
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
                Permission::ViewContacts,
                Permission::ViewTags,
                Permission::UseQuickReplies,
                Permission::ViewReports,
            ],
            self::Agent => [
                Permission::ViewDashboard,
                Permission::ViewConversations,
                Permission::ReplyConversations,
                Permission::CloseConversations,
                Permission::ViewConversationHistory,
                Permission::ViewContacts,
                Permission::UseQuickReplies,
            ],
        };
    }
}
