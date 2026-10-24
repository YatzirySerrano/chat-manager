<?php

namespace App\Enums;

enum PermissionGroup: string
{
    case Dashboard = 'dashboard';
    case Users = 'users';
    case Departments = 'departments';
    case Roles = 'roles';
    case Conversations = 'conversations';
    case Connections = 'connections';
    case Integrations = 'integrations';
    case Templates = 'templates';
    case Contacts = 'contacts';
    case Tags = 'tags';
    case QuickReplies = 'quick-replies';
    case TeamChat = 'team-chat';
    case Reports = 'reports';
    case Settings = 'settings';
    case Appearance = 'appearance';
    case Records = 'records';
    case Audit = 'audit';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Dashboard => 'Panel principal',
            self::Users => 'Usuarios',
            self::Departments => 'Departamentos',
            self::Roles => 'Roles y permisos',
            self::Conversations => 'Conversaciones',
            self::Connections => 'Conexiones',
            self::Integrations => 'Integraciones (API)',
            self::Templates => 'Plantillas de WhatsApp',
            self::Contacts => 'Contactos',
            self::Tags => 'Etiquetas',
            self::QuickReplies => 'Respuestas rápidas',
            self::TeamChat => 'Chat interno',
            self::Reports => 'Reportes',
            self::Settings => 'Configuración',
            self::Appearance => 'Personalización',
            self::Records => 'Registros eliminados',
            self::Audit => 'Auditoría',
            self::System => 'Sistema y operación',
        };
    }
}
