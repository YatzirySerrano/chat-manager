<?php

namespace App\Enums;

enum PermissionGroup: string
{
    case Dashboard = 'dashboard';
    case Users = 'users';
    case Departments = 'departments';
    case Roles = 'roles';
    case Conversations = 'conversations';
    case Contacts = 'contacts';
    case QuickReplies = 'quick-replies';
    case Reports = 'reports';
    case Settings = 'settings';
    case Appearance = 'appearance';
    case Audit = 'audit';

    public function label(): string
    {
        return match ($this) {
            self::Dashboard => 'Panel principal',
            self::Users => 'Usuarios',
            self::Departments => 'Departamentos',
            self::Roles => 'Roles y permisos',
            self::Conversations => 'Conversaciones',
            self::Contacts => 'Contactos',
            self::QuickReplies => 'Respuestas rápidas',
            self::Reports => 'Reportes',
            self::Settings => 'Configuración',
            self::Appearance => 'Personalización',
            self::Audit => 'Auditoría',
        };
    }
}
