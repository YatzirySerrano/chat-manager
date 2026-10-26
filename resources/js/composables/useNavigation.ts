import {
    Building2,
    ChartNoAxesCombined,
    ContactRound,
    Download,
    FileText,
    KeyRound,
    LayoutGrid,
    MessageCircle,
    MessageSquareText,
    MessagesSquare,
    Palette,
    PlugZap,
    ScrollText,
    ServerCog,
    Settings,
    ShieldCheck,
    Tags,
    UserCog,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import { usePermissions } from '@/composables/usePermissions';
import { useTeamChatUnread } from '@/composables/useTeamChatUnread';
import { dashboard } from '@/routes';
import { edit as appearanceEdit } from '@/routes/admin/appearance';
import { index as auditIndex } from '@/routes/admin/audit';
import { index as personalItemsIndex } from '@/routes/admin/personal-items';
import { index as rolesIndex } from '@/routes/admin/roles';
import { edit as platformSettings } from '@/routes/admin/settings';
import { index as systemIndex } from '@/routes/admin/system';
import { index as usersIndex } from '@/routes/admin/users';
import { index as connectionsIndex } from '@/routes/connections';
import { index as contactsIndex } from '@/routes/contacts';
import { index as conversationsIndex } from '@/routes/conversations';
import { index as departmentsIndex } from '@/routes/departments';
import { index as exportsIndex } from '@/routes/exports';
import { index as integrationTokensIndex } from '@/routes/integrations/tokens';
import { index as quickRepliesIndex } from '@/routes/quick-replies';
import { index as reportsIndex } from '@/routes/reports';
import { index as tagsIndex } from '@/routes/tags';
import { index as teamChatIndex } from '@/routes/team-chat';
import { index as templatesIndex } from '@/routes/templates';
import type { NavItem } from '@/types';

export type NavSection = {
    label: string;
    items: (NavItem & { description: string })[];
};

/**
 * Navigation derived from the effective permissions of the current user, so
 * the sidebar never offers anything unauthorized (the backend still enforces
 * every access).
 */
export function useNavigation() {
    const { can, canAny } = usePermissions();
    const teamChatUnread = useTeamChatUnread();

    const sections = computed<NavSection[]>(() => {
        const result: NavSection[] = [
            {
                label: 'General',
                items: [
                    {
                        title: 'Panel principal',
                        description: 'Resumen de la operación',
                        href: dashboard(),
                        icon: LayoutGrid,
                    },
                ],
            },
        ];

        if (can('reports.view')) {
            result[0].items.push({
                title: 'Reportes',
                description: 'Indicadores, tiempos y tendencias',
                href: reportsIndex(),
                icon: ChartNoAxesCombined,
            });
        }

        if (canAny('reports.export', 'audit.export')) {
            result[0].items.push({
                title: 'Exportaciones',
                description: 'Archivos de Excel y PDF generados',
                href: exportsIndex(),
                icon: Download,
            });
        }

        const attention: NavSection['items'] = [];

        if (can('conversations.view')) {
            attention.push({
                title: 'Conversaciones',
                description: 'Pendientes, atención y seguimiento',
                href: conversationsIndex(),
                icon: MessagesSquare,
            });
        }

        if (can('contacts.view')) {
            attention.push({
                title: 'Contactos',
                description: 'Personas, procedencia e historial',
                href: contactsIndex(),
                icon: ContactRound,
            });
        }

        if (canAny('tags.view', 'tags.manage')) {
            attention.push({
                title: 'Etiquetas',
                description: 'Clasifica contactos y conversaciones',
                href: tagsIndex(),
                icon: Tags,
            });
        }

        if (
            canAny(
                'quick-replies.use',
                'quick-replies.manage',
                'quick-replies.personal',
                'supervision.view-personal',
            )
        ) {
            attention.push({
                title: 'Respuestas rápidas',
                description: 'Textos para insertar con «/»',
                href: quickRepliesIndex(),
                icon: MessageSquareText,
            });
        }

        if (attention.length > 0) {
            result.push({ label: 'Atención', items: attention });
        }

        const team: NavSection['items'] = [];

        if (can('team-chat.use')) {
            team.push({
                title: 'Chat interno',
                description: 'Mensajes privados y grupos del equipo',
                href: teamChatIndex(),
                icon: MessageCircle,
                badge: teamChatUnread.value,
            });
        }

        if (can('departments.view')) {
            team.push({
                title: 'Departamentos',
                description: 'Equipos y sus miembros',
                href: departmentsIndex(),
                icon: Building2,
            });
        }

        if (can('users.view')) {
            team.push({
                title: 'Usuarios',
                description: 'Cuentas, roles y estado',
                href: usersIndex(),
                icon: Users,
            });
        }

        if (team.length > 0) {
            result.push({ label: 'Equipo', items: team });
        }

        const admin: NavSection['items'] = [];

        if (canAny('connections.view', 'connections.manage')) {
            admin.push({
                title: 'Conexiones',
                description: 'Canales de atención y su estado',
                href: connectionsIndex(),
                icon: PlugZap,
            });
        }

        if (canAny('templates.view', 'templates.manage')) {
            admin.push({
                title: 'Plantillas de WhatsApp',
                description: 'Plantillas aprobadas por Meta',
                href: templatesIndex(),
                icon: FileText,
            });
        }

        if (canAny('integrations.view', 'integrations.manage')) {
            admin.push({
                title: 'Integraciones',
                description: 'Claves de acceso para otros sistemas',
                href: integrationTokensIndex(),
                icon: KeyRound,
            });
        }

        if (can('roles.view')) {
            admin.push({
                title: 'Roles y permisos',
                description: 'Qué puede hacer y ver cada persona',
                href: rolesIndex(),
                icon: ShieldCheck,
            });
        }

        if (can('audit.view')) {
            admin.push({
                title: 'Auditoría',
                description: 'Historial de operaciones',
                href: auditIndex(),
                icon: ScrollText,
            });
        }

        if (can('supervision.view-personal')) {
            admin.push({
                title: 'Elementos personales',
                description:
                    'Alias, colores y respuestas personales del equipo',
                href: personalItemsIndex(),
                icon: UserCog,
            });
        }

        if (can('system.view')) {
            admin.push({
                title: 'Sistema',
                description: 'Estado técnico y tareas con error',
                href: systemIndex(),
                icon: ServerCog,
            });
        }

        if (can('settings.view')) {
            admin.push({
                title: 'Configuración',
                description: 'Horarios y parámetros',
                href: platformSettings(),
                icon: Settings,
            });
        }

        if (can('appearance.view')) {
            admin.push({
                title: 'Personalización',
                description: 'Color de la plataforma',
                href: appearanceEdit(),
                icon: Palette,
            });
        }

        if (admin.length > 0) {
            result.push({ label: 'Administración', items: admin });
        }

        return result;
    });

    return { sections };
}
