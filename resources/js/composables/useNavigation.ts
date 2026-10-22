import {
    Building2,
    Contact,
    FileDown,
    LayoutGrid,
    MessageSquareText,
    MessagesSquare,
    Palette,
    PlugZap,
    ScrollText,
    ShieldCheck,
    SlidersHorizontal,
    Tags,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import { usePermissions } from '@/composables/usePermissions';
import { dashboard } from '@/routes';
import { edit as appearanceEdit } from '@/routes/admin/appearance';
import { index as auditIndex } from '@/routes/admin/audit';
import { index as rolesIndex } from '@/routes/admin/roles';
import { edit as platformSettings } from '@/routes/admin/settings';
import { index as usersIndex } from '@/routes/admin/users';
import { index as connectionsIndex } from '@/routes/connections';
import { index as contactsIndex } from '@/routes/contacts';
import { index as conversationsIndex } from '@/routes/conversations';
import { index as departmentsIndex } from '@/routes/departments';
import { index as exportsIndex } from '@/routes/exports';
import { index as quickRepliesIndex } from '@/routes/quick-replies';
import { index as tagsIndex } from '@/routes/tags';
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

        if (canAny('reports.export', 'audit.export')) {
            result[0].items.push({
                title: 'Exportaciones',
                description: 'Archivos de Excel y PDF generados',
                href: exportsIndex(),
                icon: FileDown,
            });
        }

        const attention: NavSection['items'] = [];

        if (can('conversations.view')) {
            attention.push({
                title: 'Conversaciones',
                description: 'Cola, atención y seguimiento',
                href: conversationsIndex(),
                icon: MessagesSquare,
            });
        }

        if (can('contacts.view')) {
            attention.push({
                title: 'Contactos',
                description: 'Personas, origen e historial',
                href: contactsIndex(),
                icon: Contact,
            });
        }

        if (canAny('tags.view', 'tags.manage')) {
            attention.push({
                title: 'Etiquetas',
                description: 'Clasificación de contactos',
                href: tagsIndex(),
                icon: Tags,
            });
        }

        if (canAny('quick-replies.use', 'quick-replies.manage')) {
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
                description: 'Números de WhatsApp y su estado',
                href: connectionsIndex(),
                icon: PlugZap,
            });
        }

        if (can('roles.view')) {
            admin.push({
                title: 'Roles y permisos',
                description: 'Autorizaciones y alcance',
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

        if (can('settings.view')) {
            admin.push({
                title: 'Configuración',
                description: 'Horarios y parámetros',
                href: platformSettings(),
                icon: SlidersHorizontal,
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
