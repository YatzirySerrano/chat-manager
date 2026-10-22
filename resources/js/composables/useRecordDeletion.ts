import { router } from '@inertiajs/vue3';
import { RotateCcw, Trash2 } from '@lucide/vue';
import { computed, reactive, ref, shallowRef } from 'vue';
import { toastFirstError } from '@/lib/toastErrors';
import type { DeletionAbilities, RecordAction } from '@/types';
import type { RouteDefinition } from '@/wayfinder';

export type DeletionStep = 'delete' | 'restore';

export type DeletionCopy = { title: string; description: string };

type DeletableRecord = { id: number; can?: DeletionAbilities };

/**
 * Logical deletion and restoration of records from a list or a detail page:
 * the Eliminar / Restaurar record actions (only when the server allows them),
 * the confirmation dialog explaining the consequences, and the request.
 * Nothing is ever destroyed: a deleted record keeps its history and can be
 * restored.
 *
 * @example
 * const deletion = useRecordDeletion<Tag>({
 *     destroy: (tag) => destroyTag(tag.id),
 *     restore: (tag) => restoreTag(tag.id),
 *     copy: (tag, step) => ...,
 * });
 * recordActions(edit, ...deletion.actionsFor(tag));
 * <ConfirmDialog v-model:open="deletion.open" v-bind="deletion.dialog" @confirm="deletion.confirm" />
 */
export function useRecordDeletion<T extends DeletableRecord>(config: {
    destroy: (record: T) => RouteDefinition<'delete'>;
    restore: (record: T) => RouteDefinition<'patch'>;
    copy: (record: T, step: DeletionStep) => DeletionCopy;
    /** Runs after the server confirmed the change (e.g. leave a detail page). */
    onSuccess?: (step: DeletionStep, record: T) => void;
}) {
    const pending = shallowRef<{ step: DeletionStep; record: T } | null>(null);
    const open = ref(false);
    const processingId = ref<number | null>(null);

    function ask(step: DeletionStep, record: T): void {
        pending.value = { step, record };
        open.value = true;
    }

    function confirm(): void {
        const current = pending.value;

        if (!current) {
            return;
        }

        const { step, record } = current;

        router.visit(
            step === 'delete' ? config.destroy(record) : config.restore(record),
            {
                preserveScroll: true,
                onStart: () => (processingId.value = record.id),
                onSuccess: () => {
                    open.value = false;
                    config.onSuccess?.(step, record);
                },
                onFinish: () => (processingId.value = null),
                onError: (errors) => {
                    open.value = false;
                    toastFirstError(errors);
                },
            },
        );
    }

    const dialog = computed(() => {
        const current = pending.value;
        const copy = current
            ? config.copy(current.record, current.step)
            : { title: '', description: '' };
        const deleting = current?.step !== 'restore';

        return {
            ...copy,
            confirmLabel: deleting ? 'Eliminar' : 'Restaurar',
            destructive: deleting,
            processing: processingId.value !== null,
        };
    });

    function actionsFor(record: T): RecordAction[] {
        const actions: RecordAction[] = [];

        if (record.can?.delete) {
            actions.push({
                key: 'delete',
                label: 'Eliminar',
                icon: Trash2,
                destructive: true,
                onSelect: () => ask('delete', record),
            });
        }

        if (record.can?.restore) {
            actions.push({
                key: 'restore',
                label: 'Restaurar',
                icon: RotateCcw,
                onSelect: () => ask('restore', record),
            });
        }

        return actions;
    }

    return reactive({ open, processingId, dialog, ask, confirm, actionsFor });
}
