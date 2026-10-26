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
    case Supervision = 'supervision';
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
            self::Supervision => 'Alias, colores y respuestas personales del equipo',
            self::Audit => 'Auditoría',
            self::System => 'Sistema y operación',
        };
    }

    /**
     * What the module covers, in one plain sentence.
     */
    public function description(): string
    {
        return match ($this) {
            self::Dashboard => 'Resumen de la actividad al entrar a la plataforma.',
            self::Users => 'Cuentas del equipo, con su rol, departamentos y estado.',
            self::Departments => 'Áreas de atención y las personas que pertenecen a cada una.',
            self::Roles => 'Qué puede hacer cada rol y qué información ve.',
            self::Conversations => 'Atención a los clientes: responder, asignar, transferir y cerrar conversaciones.',
            self::Connections => 'Canales conectados, como números de WhatsApp y el chat web.',
            self::Integrations => 'Claves de acceso para que otros sistemas se conecten con la plataforma.',
            self::Templates => 'Mensajes aprobados por WhatsApp para iniciar o retomar el contacto.',
            self::Contacts => 'Directorio de clientes y su información.',
            self::Tags => 'Etiquetas para clasificar clientes y conversaciones.',
            self::QuickReplies => 'Textos guardados para responder más rápido.',
            self::TeamChat => 'Mensajes entre las personas del equipo.',
            self::Reports => 'Indicadores de la atención y descarga de información.',
            self::Settings => 'Reglas generales de funcionamiento de la plataforma.',
            self::Appearance => 'Colores y aspecto de la plataforma para todo el equipo.',
            self::Records => 'Información eliminada que todavía puede consultarse o restaurarse.',
            self::Supervision => 'Alias, colores de chat y respuestas personales de otras personas.',
            self::Audit => 'Historial de los cambios importantes y de quién los hizo.',
            self::System => 'Estado técnico de la plataforma y tareas automáticas.',
        };
    }

    /**
     * Lucide icon name (kebab case) the interface maps to a component.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Dashboard => 'layout-grid',
            self::Users => 'users',
            self::Departments => 'building-2',
            self::Roles => 'shield-check',
            self::Conversations => 'messages-square',
            self::Connections => 'plug-zap',
            self::Integrations => 'key-round',
            self::Templates => 'file-text',
            self::Contacts => 'contact-round',
            self::Tags => 'tags',
            self::QuickReplies => 'message-square-text',
            self::TeamChat => 'message-circle',
            self::Reports => 'chart-no-axes-combined',
            self::Settings => 'settings',
            self::Appearance => 'palette',
            self::Records => 'archive-restore',
            self::Supervision => 'user-cog',
            self::Audit => 'scroll-text',
            self::System => 'server-cog',
        };
    }
}
