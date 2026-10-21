import type { RecordAction } from '@/types';

/**
 * Builds the action list of a record, skipping the entries whose condition
 * is false. Conditions must come from the server (per-record `can` flags),
 * never from role names.
 *
 * @example
 * recordActions(
 *     user.can.update && { key: 'edit', label: 'Editar', icon: Pencil, onSelect: () => openEdit(user) },
 * )
 */
export function recordActions(
    ...items: (RecordAction | false | null | undefined)[]
): RecordAction[] {
    return items.filter((item): item is RecordAction => Boolean(item));
}
