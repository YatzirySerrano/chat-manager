<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Ellipsis } from '@lucide/vue';
import { computed } from 'vue';
import type { HTMLAttributes } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import type { RecordAction } from '@/types';

/**
 * Operations of one record (table row or card) as visible, discreet icon
 * buttons. Each button has a tooltip (hover and keyboard focus) and an
 * accessible name with the operation and the record, so nothing depends on
 * hovering. Only actions the server allows should be passed in.
 *
 * Rarely used actions marked as `secondary` move into a "Más acciones" menu
 * only when the record has more than four actions.
 */
const props = defineProps<{
    actions: RecordAction[];
    /** Name of the record, used in accessible names ("Editar Ana López"). */
    record: string;
    /** Disables every action while a request for this record is running. */
    busy?: boolean;
    class?: HTMLAttributes['class'];
}>();

const MAX_VISIBLE = 4;

const visible = computed(() =>
    props.actions.length > MAX_VISIBLE
        ? props.actions.filter((action) => !action.secondary)
        : props.actions,
);

const overflow = computed(() =>
    props.actions.length > MAX_VISIBLE
        ? props.actions.filter((action) => action.secondary)
        : [],
);

function buttonClass(action: RecordAction): string {
    return action.destructive
        ? 'text-muted-foreground hover:bg-destructive/10 hover:text-destructive dark:hover:bg-destructive/20'
        : 'text-muted-foreground hover:text-foreground';
}
</script>

<template>
    <div
        v-if="actions.length > 0"
        role="group"
        :aria-label="`Acciones de ${record}`"
        :class="
            cn('flex shrink-0 items-center justify-end gap-0.5', props.class)
        "
    >
        <Tooltip v-for="action in visible" :key="action.key">
            <TooltipTrigger as-child>
                <Button
                    v-if="action.href && !action.disabled && !busy"
                    variant="ghost"
                    size="icon-sm"
                    class="size-9 sm:size-8"
                    :class="buttonClass(action)"
                    as-child
                >
                    <Link
                        :href="action.href"
                        :aria-label="`${action.label} ${record}`"
                    >
                        <component :is="action.icon" aria-hidden="true" />
                    </Link>
                </Button>
                <Button
                    v-else
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    class="size-9 sm:size-8"
                    :class="buttonClass(action)"
                    :disabled="action.disabled || busy"
                    :aria-label="`${action.label} ${record}`"
                    @click="action.onSelect?.()"
                >
                    <component :is="action.icon" aria-hidden="true" />
                </Button>
            </TooltipTrigger>
            <TooltipContent>{{ action.label }}</TooltipContent>
        </Tooltip>

        <DropdownMenu v-if="overflow.length > 0">
            <Tooltip>
                <TooltipTrigger as-child>
                    <DropdownMenuTrigger as-child>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            class="size-9 text-muted-foreground hover:text-foreground sm:size-8"
                            :disabled="busy"
                            :aria-label="`Más acciones de ${record}`"
                        >
                            <Ellipsis aria-hidden="true" />
                        </Button>
                    </DropdownMenuTrigger>
                </TooltipTrigger>
                <TooltipContent>Más acciones</TooltipContent>
            </Tooltip>
            <DropdownMenuContent align="end" class="w-52">
                <template v-for="action in overflow" :key="action.key">
                    <DropdownMenuItem
                        v-if="action.href"
                        as-child
                        :disabled="action.disabled"
                        :variant="
                            action.destructive ? 'destructive' : 'default'
                        "
                    >
                        <Link :href="action.href">
                            <component :is="action.icon" aria-hidden="true" />
                            {{ action.label }}
                        </Link>
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-else
                        :disabled="action.disabled"
                        :variant="
                            action.destructive ? 'destructive' : 'default'
                        "
                        @select="action.onSelect?.()"
                    >
                        <component :is="action.icon" aria-hidden="true" />
                        {{ action.label }}
                    </DropdownMenuItem>
                </template>
            </DropdownMenuContent>
        </DropdownMenu>
    </div>
</template>
