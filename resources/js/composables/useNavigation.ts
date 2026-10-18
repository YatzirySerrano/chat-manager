import {
    Building2,
    LayoutGrid,
    Palette,
    ScrollText,
    ShieldCheck,
    SlidersHorizontal,
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
import { index as departmentsIndex } from '@/routes/departments';
import type { NavItem } from '@/types';

export type NavSection = {
    label: string;
    items: (NavItem & { description: string })[];
};

/**
 * Navigation derived from the effective permissions of the current user. It
 * feeds both the sidebar and the quick search, so nothing unauthorized is
 * ever offered (the backend still enforces every access).
 */
export function useNavigation() {
    const { can } = usePermissions();

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
