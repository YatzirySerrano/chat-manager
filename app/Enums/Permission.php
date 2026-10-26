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
    case SuperviseConversations = 'conversations.supervise';

    case ViewConnections = 'connections.view';
    case ManageConnections = 'connections.manage';

    case ViewIntegrations = 'integrations.view';
    case ManageIntegrations = 'integrations.manage';

    case ViewTemplates = 'templates.view';
    case ManageTemplates = 'templates.manage';
    case SendTemplates = 'templates.send';

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
    case CreatePersonalQuickReplies = 'quick-replies.personal';

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

    case ViewPersonalItems = 'supervision.view-personal';
    case ManagePersonalItems = 'supervision.manage-personal';

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
            self::ViewConversations => 'Ver las conversaciones que le corresponden',
            self::ReplyConversations => 'Atender y responder sus conversaciones',
            self::AssignConversations => 'Asignar conversaciones a otras personas',
            self::TransferConversations => 'Transferir conversaciones',
            self::CloseConversations => 'Cerrar conversaciones',
            self::ReopenConversations => 'Reabrir conversaciones cerradas',
            self::ViewConversationHistory => 'Consultar conversaciones cerradas y su historial',
            self::AcceptConversations => 'Aceptar conversaciones pendientes de sus departamentos',
            self::AddConversationNotes => 'Agregar notas internas',
            self::SuperviseConversations => 'Supervisar conversaciones (solo lectura)',
            self::ViewConnections => 'Ver conexiones',
            self::ManageConnections => 'Administrar conexiones y credenciales (incluye eliminar y restaurar)',
            self::ViewIntegrations => 'Ver las claves de acceso de integraciones',
            self::ManageIntegrations => 'Crear y revocar claves de acceso de integraciones',
            self::ViewTemplates => 'Ver plantillas de WhatsApp',
            self::ManageTemplates => 'Actualizar plantillas de WhatsApp desde Meta',
            self::SendTemplates => 'Enviar plantillas de WhatsApp (administrativo)',
            self::ViewContacts => 'Ver contactos',
            self::CreateContacts => 'Crear contactos',
            self::UpdateContacts => 'Editar contactos',
            self::DeleteContacts => 'Eliminar y restaurar contactos',
            self::ImportContacts => 'Importar contactos desde Excel/CSV',
            self::ManageContactNotes => 'Registrar notas privadas de contactos',
            self::ClassifyContactOrigin => 'Anotar a mano cómo nos conoció un contacto',
            self::ViewTags => 'Ver etiquetas',
            self::ManageTags => 'Administrar etiquetas (crear, editar, eliminar y restaurar)',
            self::AssignTags => 'Asignar etiquetas a contactos y conversaciones',
            self::UseQuickReplies => 'Usar respuestas rápidas',
            self::ManageQuickReplies => 'Administrar respuestas rápidas (crear, editar, eliminar y restaurar)',
            self::CreatePersonalQuickReplies => 'Crear y administrar sus respuestas rápidas personales',
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
            self::ViewPersonalItems => 'Ver alias, colores de chat y respuestas personales de otros usuarios',
            self::ManagePersonalItems => 'Editar o eliminar alias, colores de chat y respuestas personales de otros usuarios',
            self::ViewAuditLog => 'Ver registros de auditoría',
            self::ExportAuditLog => 'Exportar registros de auditoría',
            self::ViewSystem => 'Ver el estado técnico de la plataforma',
            self::ManageSystem => 'Reintentar o descartar tareas con error',
        };
    }

    /**
     * What the permission allows and its limits, in one plain sentence (no
     * technical names). The role editor shows it under the label and the
     * permission detail of a role lists it.
     */
    public function description(): string
    {
        return match ($this) {
            self::ViewDashboard => 'Entrar al panel principal con el resumen de la actividad que le corresponde.',
            self::ViewUsers => 'Consultar las personas del equipo que le corresponden, con su rol, departamentos y estado.',
            self::CreateUsers => 'Dar de alta cuentas del equipo. Solo puede asignar roles que no den más acceso que el suyo.',
            self::UpdateUsers => 'Cambiar los datos, departamentos y estado de las cuentas que administra.',
            self::AssignRoles => 'Cambiar el rol de una persona. Nunca puede otorgar más acceso del que tiene.',
            self::DeleteUsers => 'Eliminar cuentas del equipo y restaurarlas después. Su historial se conserva.',
            self::ViewDepartments => 'Consultar los departamentos y quién pertenece a cada uno.',
            self::CreateDepartments => 'Crear nuevos departamentos de atención.',
            self::UpdateDepartments => 'Cambiar el nombre y la configuración de los departamentos.',
            self::ManageDepartmentMembers => 'Agregar o quitar personas de los departamentos que le corresponden.',
            self::DeleteDepartments => 'Eliminar departamentos y restaurarlos después.',
            self::ViewRoles => 'Consultar los roles, sus permisos y qué información ve cada uno.',
            self::CreateRoles => 'Crear roles nuevos, solo con permisos que la persona también tiene.',
            self::UpdateRoles => 'Cambiar los permisos y el alcance de los roles. Se aplica de inmediato a quienes los tienen.',
            self::DeleteRoles => 'Eliminar roles que nadie tiene asignado y restaurarlos después.',
            self::ViewConversations => 'Entrar a la bandeja y abrir las conversaciones que le corresponden.',
            self::ReplyConversations => 'Escribir a los clientes en las conversaciones que atiende.',
            self::AssignConversations => 'Asignar conversaciones a otras personas y actuar en las que atienden sus compañeros.',
            self::TransferConversations => 'Pasar una conversación a otro departamento o agente. Conserva el historial y no avisa al cliente.',
            self::CloseConversations => 'Dar por terminada una conversación cuando el cliente ya fue atendido.',
            self::ReopenConversations => 'Volver a abrir una conversación cerrada para seguir atendiéndola.',
            self::ViewConversationHistory => 'Consultar las conversaciones cerradas y todo su historial de mensajes.',
            self::AcceptConversations => 'Tomar conversaciones pendientes de sus departamentos para atenderlas.',
            self::AddConversationNotes => 'Dejar notas internas en una conversación. El cliente nunca las ve.',
            self::SuperviseConversations => 'Consultar todas las conversaciones que su rol puede ver, aunque las atienda otra persona. No permite responder.',
            self::ViewConnections => 'Consultar los canales conectados, como números de WhatsApp, y su estado.',
            self::ManageConnections => 'Conectar, configurar, eliminar y restaurar canales, incluidas sus credenciales.',
            self::ViewIntegrations => 'Consultar las claves de acceso que usan otros sistemas para conectarse.',
            self::ManageIntegrations => 'Crear y revocar claves de acceso para que otros sistemas se conecten.',
            self::ViewTemplates => 'Consultar las plantillas de WhatsApp y su estado de aprobación.',
            self::ManageTemplates => 'Actualizar desde Meta la lista de plantillas de WhatsApp.',
            self::SendTemplates => 'Enviar plantillas aprobadas de WhatsApp, por ejemplo para retomar contacto fuera de las 24 horas.',
            self::ViewContacts => 'Consultar los clientes y su información de contacto.',
            self::CreateContacts => 'Agregar clientes nuevos al directorio.',
            self::UpdateContacts => 'Cambiar los datos de un cliente, como su nombre o su correo.',
            self::DeleteContacts => 'Eliminar clientes del directorio y restaurarlos después.',
            self::ImportContacts => 'Cargar muchos clientes a la vez desde un archivo de Excel o CSV.',
            self::ManageContactNotes => 'Escribir notas privadas sobre un cliente. El cliente nunca las ve.',
            self::ClassifyContactOrigin => 'Anotar a mano cómo nos conoció un cliente cuando Meta no lo informó.',
            self::ViewTags => 'Consultar las etiquetas disponibles.',
            self::ManageTags => 'Crear, editar, eliminar y restaurar etiquetas para todo el equipo.',
            self::AssignTags => 'Poner o quitar etiquetas en clientes y en las conversaciones que atiende.',
            self::UseQuickReplies => 'Insertar respuestas guardadas al escribir a un cliente.',
            self::ManageQuickReplies => 'Crear, editar, eliminar y restaurar las respuestas rápidas compartidas.',
            self::CreatePersonalQuickReplies => 'Guardar sus propias respuestas rápidas, que solo usa esa persona.',
            self::UseTeamChat => 'Enviar mensajes a compañeros del equipo dentro de la plataforma.',
            self::CreateTeamChatGroups => 'Crear grupos de chat interno con varias personas.',
            self::ManageTeamChatGroups => 'Cambiar el nombre y los miembros de los grupos internos en los que participa.',
            self::UseCrossDepartmentTeamChat => 'Escribir por chat interno a personas de otros departamentos.',
            self::ViewReports => 'Consultar los indicadores de atención de la información que le corresponde.',
            self::ExportReports => 'Descargar en Excel o PDF la información de las pantallas que puede consultar.',
            self::ViewSettings => 'Consultar la configuración general de la plataforma.',
            self::UpdateSettings => 'Cambiar la configuración general. Afecta a todo el equipo.',
            self::ViewAppearance => 'Consultar los colores y el aspecto definidos para la plataforma.',
            self::UpdateAppearance => 'Cambiar los colores y el aspecto de la plataforma para todo el equipo.',
            self::ViewDeletedRecords => 'Ver la información eliminada en las pantallas que ya puede consultar, para revisarla o restaurarla.',
            self::ViewPersonalItems => 'Ver alias, colores de chat y respuestas personales de otras personas del equipo.',
            self::ManagePersonalItems => 'Corregir o eliminar alias, colores y respuestas personales de otras personas.',
            self::ViewAuditLog => 'Consultar el historial de los cambios importantes y quién los hizo.',
            self::ExportAuditLog => 'Descargar el historial de cambios en Excel o PDF.',
            self::ViewSystem => 'Consultar el estado técnico de la plataforma y las tareas automáticas con error.',
            self::ManageSystem => 'Reintentar o descartar tareas automáticas que terminaron con error.',
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
            self::ManagePersonalItems,
            self::SendTemplates,
            self::ExportAuditLog,
            self::UpdateSettings,
            self::UpdateAppearance,
            self::ManageConnections,
            self::ManageIntegrations,
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
            self::ViewIntegrations,
            self::ManageIntegrations,
            self::ManageTemplates,
            self::SendTemplates,
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
     * @return list<array{value: string, label: string, description: string, icon: string, permissions: list<array{value: string, label: string, description: string, available: bool, sensitive: bool, global_only: bool, reserved: bool}>}>
     */
    public static function catalog(): array
    {
        $groups = [];

        foreach (self::cases() as $permission) {
            $group = $permission->group();
            $groups[$group->value] ??= [
                'value' => $group->value,
                'label' => $group->label(),
                'description' => $group->description(),
                'icon' => $group->icon(),
                'permissions' => [],
            ];
            $groups[$group->value]['permissions'][] = [
                'value' => $permission->value,
                'label' => $permission->label(),
                'description' => $permission->description(),
                'available' => $permission->isAvailable(),
                'sensitive' => $permission->isSensitive(),
                'global_only' => $permission->requiresGlobalScope(),
                'reserved' => $permission->isReservedToRoot(),
            ];
        }

        return array_values($groups);
    }
}
