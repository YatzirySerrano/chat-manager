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
    | 'users.assign-roles'
    | 'users.delete'
    | 'departments.view'
    | 'departments.create'
    | 'departments.update'
    | 'departments.manage-members'
    | 'departments.delete'
    | 'roles.view'
    | 'roles.create'
    | 'roles.update'
    | 'roles.delete'
    | 'settings.view'
    | 'settings.update'
    | 'appearance.view'
    | 'appearance.update'
    | 'records.view-deleted'
    | 'audit.view'
    | 'audit.export'
    | 'reports.export'
    | 'connections.view'
    | 'connections.manage'
    | 'contacts.view'
    | 'contacts.create'
    | 'contacts.update'
    | 'contacts.delete'
    | 'contacts.import'
    | 'contacts.notes'
    | 'contacts.classify-origin'
    | 'tags.view'
    | 'tags.manage'
    | 'tags.assign'
    | 'quick-replies.use'
    | 'quick-replies.manage'
    | 'conversations.view'
    | 'conversations.reply'
    | 'conversations.assign'
    | 'conversations.transfer'
    | 'conversations.close'
    | 'conversations.reopen'
    | 'conversations.history'
    | 'conversations.accept'
    | 'conversations.notes';

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
