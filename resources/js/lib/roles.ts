import {
    ArchiveRestore,
    Building,
    Building2,
    ChartNoAxesCombined,
    ContactRound,
    FileText,
    History,
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
    UserRoundCheck,
    Users,
    UsersRound,
} from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import type {
    DataScopeValue,
    ManagedRole,
    PermissionGroupCatalog,
} from '@/types';

/**
 * Pure helpers of the roles screen: the executive summary of a role, the
 * icons the catalog names, and the texts that explain scopes and counts.
 */

export type RolePermissionGroupSummary = {
    value: string;
    label: string;
    enabled: number;
    available: number;
};

export type RolePermissionSummary = {
    /** Available permissions the role holds. */
    enabled: number;
    /** Permissions that exist and can be held (in the catalog shown). */
    available: number;
    /** Modules with at least one permission enabled. */
    areas: number;
    /** Modules with at least one available permission. */
    areasTotal: number;
    /** Enabled permissions marked as high impact. */
    sensitive: number;
    groups: RolePermissionGroupSummary[];
};

export function summarizeRole(
    role: Pick<ManagedRole, 'permissions'>,
    catalog: PermissionGroupCatalog[],
): RolePermissionSummary {
    const held = new Set(role.permissions);
    const summary: RolePermissionSummary = {
        enabled: 0,
        available: 0,
        areas: 0,
        areasTotal: 0,
        sensitive: 0,
        groups: [],
    };

    for (const group of catalog) {
        const available = group.permissions.filter(
            (permission) => permission.available,
        );

        if (available.length === 0) {
            continue;
        }

        const enabled = available.filter((permission) =>
            held.has(permission.value),
        );

        summary.available += available.length;
        summary.enabled += enabled.length;
        summary.areasTotal += 1;
        summary.areas += enabled.length > 0 ? 1 : 0;
        summary.sensitive += enabled.filter(
            (permission) => permission.sensitive,
        ).length;
        summary.groups.push({
            value: group.value,
            label: group.label,
            enabled: enabled.length,
            available: available.length,
        });
    }

    return summary;
}

const groupIcons: Record<string, LucideIcon> = {
    'layout-grid': LayoutGrid,
    users: Users,
    'building-2': Building2,
    'shield-check': ShieldCheck,
    'messages-square': MessagesSquare,
    'plug-zap': PlugZap,
    'key-round': KeyRound,
    'file-text': FileText,
    'contact-round': ContactRound,
    tags: Tags,
    'message-square-text': MessageSquareText,
    'message-circle': MessageCircle,
    'chart-no-axes-combined': ChartNoAxesCombined,
    settings: Settings,
    palette: Palette,
    'archive-restore': ArchiveRestore,
    'user-cog': UserCog,
    'scroll-text': ScrollText,
    'server-cog': ServerCog,
    history: History,
};

/** Icon of a permission module, from the name the catalog sends. */
export function groupIcon(name: string): LucideIcon {
    return groupIcons[name] ?? ShieldCheck;
}

const scopeIcons: Record<DataScopeValue, LucideIcon> = {
    global: Building,
    departments: UsersRound,
    assigned: UserRoundCheck,
};

/** Icon of a data scope («Toda la organización», «Sus departamentos»…). */
export function scopeIcon(scope: DataScopeValue): LucideIcon {
    return scopeIcons[scope] ?? ShieldCheck;
}

/**
 * Modules whose permissions act on records limited by the scope; the
 * permission detail tells to which information they apply.
 */
export const SCOPED_GROUPS: ReadonlySet<string> = new Set([
    'conversations',
    'contacts',
    'users',
    'departments',
    'reports',
    'audit',
]);

export function permissionCountLabel(count: number): string {
    return count === 1
        ? '1 permiso habilitado'
        : `${count} permisos habilitados`;
}

export function areaCountLabel(count: number): string {
    return count === 1 ? 'Acceso a 1 área' : `Acceso a ${count} áreas`;
}

export function usersCountLabel(count: number): string {
    return count === 1 ? '1 usuario' : `${count} usuarios`;
}

/** Lowercase text without accents, for searches in Spanish. */
export function normalizeSearch(value: string): string {
    return value
        .toLocaleLowerCase('es')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '');
}

const scopeRank: Record<DataScopeValue, number> = {
    global: 3,
    departments: 2,
    assigned: 1,
};

/** What a person sees with each scope, to complete a sentence. */
const scopeReach: Record<DataScopeValue, string> = {
    global: 'todos los departamentos',
    departments: 'sus departamentos',
    assigned: 'lo que tienen asignado',
};

/**
 * Consequences of changing the scope of an existing role, computed in the
 * client before saving: who stops (or starts) seeing what, and which
 * organization-wide permissions are removed with a narrower scope.
 */
export function scopeChangeNotes(change: {
    from: DataScopeValue;
    to: DataScopeValue;
    activeUsers: number;
    removedGlobalOnly: string[];
}): string[] {
    const { from, to, activeUsers, removedGlobalOnly } = change;

    if (from === to) {
        return [];
    }

    const notes: string[] = [];
    const people =
        activeUsers === 1
            ? 'La persona con este rol'
            : `Las ${activeUsers} personas con este rol`;

    if (activeUsers === 0) {
        notes.push(
            'Ninguna persona activa tiene este rol todavía; el cambio no afecta a nadie por ahora.',
        );
    } else if (scopeRank[to] < scopeRank[from]) {
        notes.push(
            `${people} ${activeUsers === 1 ? 'dejará' : 'dejarán'} de ver la información fuera de ${scopeReach[to]}. Las conversaciones que ya atienden no se cierran ni se reasignan.`,
        );

        if (from === 'global') {
            notes.push(
                'Revisa en Usuarios que pertenezcan a los departamentos correctos: sin departamentos verán muy poca información.',
            );
        }
    } else {
        notes.push(
            `${people} ${activeUsers === 1 ? 'podrá' : 'podrán'} consultar la información de ${scopeReach[to]}.`,
        );
    }

    if (removedGlobalOnly.length > 0) {
        notes.push(
            `Se ${removedGlobalOnly.length === 1 ? 'quitará 1 permiso que solo funciona' : `quitarán ${removedGlobalOnly.length} permisos que solo funcionan`} con «Toda la organización»: ${removedGlobalOnly.join(', ')}.`,
        );
    }

    return notes;
}
