import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Permission names mirrored from App\Enums\Permission. They only adapt the
 * interface (what to show); the backend always enforces access and scope.
 */
export type PermissionName =
    | 'dashboard.view'
    | 'users.view'
    | 'users.create'
    | 'users.update'
    | 'users.change-status'
    | 'users.assign-roles'
    | 'users.delete'
    | 'departments.view'
    | 'departments.create'
    | 'departments.update'
    | 'departments.manage-members'
    | 'departments.change-status'
    | 'roles.view'
    | 'roles.create'
    | 'roles.update'
    | 'roles.change-status'
    | 'roles.delete'
    | 'settings.view'
    | 'settings.update'
    | 'appearance.view'
    | 'appearance.update'
    | 'audit.view';

export function usePermissions() {
    const page = usePage();

    const permissions = computed(
        () => new Set(page.props.auth?.permissions ?? []),
    );
    const role = computed(() => page.props.auth?.role ?? null);

    function can(permission: PermissionName): boolean {
        return permissions.value.has(permission);
    }

    function canAny(...names: PermissionName[]): boolean {
        return names.some((name) => permissions.value.has(name));
    }

    return { can, canAny, role };
}
