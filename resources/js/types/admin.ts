import type { RecordStateValue } from './ui';

export type DataScopeValue = 'global' | 'departments' | 'assigned';

export type ThemePaletteValue =
    | 'emerald'
    | 'teal'
    | 'sky'
    | 'indigo'
    | 'violet'
    | 'rose'
    | 'amber'
    | 'graphite';

export type ColorSwatch =
    | 'slate'
    | 'blue'
    | 'teal'
    | 'emerald'
    | 'amber'
    | 'orange'
    | 'rose'
    | 'violet';

/** Custom color chosen by the user, stored as lowercase "#rrggbb". */
export type HexColor = `#${string}`;

/** Department or tag color: a palette key or a custom hex color. */
export type AppColor = ColorSwatch | HexColor;

export type RoleOption = {
    id: number;
    label: string;
    description: string | null;
    scope: DataScopeValue;
    scope_label: string;
    is_active: boolean;
    is_root: boolean;
    assignable: boolean;
    allows_departments: boolean;
};

export type RoleSummary = {
    id: number;
    label: string;
    is_active?: boolean;
    is_root?: boolean;
};

export type ManagedRole = {
    id: number;
    name: string;
    label: string;
    description: string | null;
    scope: DataScopeValue;
    scope_label: string;
    is_active: boolean;
    is_deleted: boolean;
    is_root: boolean;
    users_count: number;
    permissions: string[];
    is_current_user_role: boolean;
    can: { update: boolean; delete: boolean; restore: boolean };
    updated_at: string | null;
};

export type CatalogPermission = {
    value: string;
    label: string;
    available: boolean;
    sensitive: boolean;
    global_only: boolean;
    /** Only the root role (Superadministrador) can ever hold it. */
    reserved: boolean;
};

export type PermissionGroupCatalog = {
    value: string;
    label: string;
    permissions: CatalogPermission[];
};

export type ScopeOption = {
    value: DataScopeValue;
    label: string;
    description: string;
    grantable: boolean;
};

export type RoleChangeImpact = {
    from: RoleImpactRole | null;
    to: RoleImpactRole;
    lost_permissions: ImpactPermission[];
    gained_permissions: ImpactPermission[];
    scope_changed: boolean;
    current_departments: { id: number; name: string }[];
    departments_to_leave: { id: number; name: string }[];
    conversations_to_release: number;
    blockers: string[];
    significant: boolean;
    fingerprint: string;
};

export type RoleImpactRole = {
    id: number;
    label: string;
    scope: DataScopeValue;
    scope_label: string;
    is_root: boolean;
};

export type ImpactPermission = {
    value: string;
    label: string;
    group: string;
    sensitive: boolean;
};

export type Highlight = { id: number; name?: string; email?: string };

export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

export type PaginationMeta = {
    current_page: number;
    from: number | null;
    last_page: number;
    per_page: number;
    to: number | null;
    total: number;
    links: PaginationLink[];
};

export type Paginated<T> = {
    data: T[];
    meta: PaginationMeta;
};

export type DepartmentOption = {
    id: number;
    name: string;
    color: AppColor;
    is_active: boolean;
};

export type AdminUser = {
    id: number;
    name: string;
    email: string;
    role: RoleSummary | null;
    is_active: boolean;
    is_deleted: boolean;
    two_factor_enabled: boolean;
    last_login_at: string | null;
    deleted_at: string | null;
    created_at: string | null;
    departments: DepartmentOption[];
    is_current_user: boolean;
    can: {
        update: boolean;
        assignRole: boolean;
        delete: boolean;
        restore: boolean;
    };
};

export type UserSort = 'name' | 'newest' | 'last_login';

export type UserFilters = {
    search: string;
    role: number | null;
    department: number | null;
    record: RecordStateValue;
    sort: UserSort;
};

export type UserStats = {
    active: number;
    two_factor: number;
    /** Null when the viewer cannot consult deleted records. */
    deleted: number | null;
};

export type DistributionModeValue = 'shared' | 'manual' | 'automatic';

export type DepartmentSummary = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    color: AppColor;
    is_active: boolean;
    is_deleted: boolean;
    welcome_message: string | null;
    /** How pending conversations reach an agent (see App\\Enums\\DistributionMode). */
    distribution_mode: DistributionModeValue;
    uses_custom_hours: boolean;
    /** Custom schedule; only applies while `uses_custom_hours` is true. */
    business_hours: { days: Record<Weekday, BusinessDay> } | null;
    members_count?: number;
    leaders_count?: number;
    connections_count?: number;
    leaders?: { id: number; name: string }[];
    created_at: string | null;
    /** What the viewer may do with this department (resolved by the server). */
    can?: { view: boolean; update: boolean; delete: boolean; restore: boolean };
};

/** Real conversation counts of a department (never estimated). */
export type DepartmentMetrics = {
    total: number;
    pending: number;
    open: number;
    unassigned: number;
    closed_today: number;
    closed_total: number;
};

export type DepartmentConnection = {
    id: number;
    name: string;
    display_phone_number: string | null;
    is_active: boolean;
    is_deleted: boolean;
    is_default: boolean;
    health: {
        value: string;
        label: string;
        tone: 'success' | 'primary' | 'warning' | 'danger' | 'neutral';
    };
    /** Null when the viewer cannot open connections. */
    url: string | null;
};

export type DepartmentQuickReplies = {
    items: {
        id: number;
        title: string;
        shortcut: string;
        is_active: boolean;
    }[];
    global_count: number;
};

export type DepartmentMember = {
    id: number;
    name: string;
    email: string;
    role: RoleSummary | null;
    is_active: boolean;
    is_deleted: boolean;
    last_login_at: string | null;
};

export type MemberCandidate = {
    id: number;
    name: string;
    email: string;
    role: RoleSummary;
};

export type AuditTone = 'success' | 'warning' | 'danger' | 'neutral';

export type AuditLogEntry = {
    id: number;
    action: { value: string; label: string; tone: AuditTone };
    actor: { id: number | null; name: string };
    subject: { type: string | null; id: number | null; label: string | null };
    properties: Record<string, unknown>;
    ip_address: string | null;
    user_agent: string | null;
    /** Entry about the root authority, only visible to superadministrators. */
    restricted: boolean;
    created_at: string;
};

export type AuditFilters = {
    action: string | null;
    actor: number | null;
    from: string | null;
    to: string | null;
};

export type Weekday =
    | 'monday'
    | 'tuesday'
    | 'wednesday'
    | 'thursday'
    | 'friday'
    | 'saturday'
    | 'sunday';

export type BusinessDay = {
    open: boolean;
    start: string;
    end: string;
};

export type PlatformSettings = {
    general: { platform_name: string; timezone: string };
    business_hours: { enabled: boolean; days: Record<Weekday, BusinessDay> };
    operations: {
        default_department_id: number | null;
        max_open_conversations_per_agent: number | null;
        auto_close_after_hours: number | null;
        transfer_reason_required: boolean;
    };
};

/**
 * Dashboard contract. `null` means the metric source is not available yet
 * and must be rendered as an empty state, never as zero.
 */
export type DashboardOverview = {
    scope: 'all' | 'departments' | 'own';
    messaging_connected: boolean;
    conversations: {
        active: number | null;
        pending: number | null;
        closed_today: number | null;
    };
    average_first_response_seconds: number | null;
    agents: { registered: number | null; available: number | null };
    departments: { active: number; total: number } | null;
};
