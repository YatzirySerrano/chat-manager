<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import type { RecordStateOption, RecordStateValue } from '@/types';

/**
 * "Estado del registro" filter (Activos / Eliminados / Todos) shared by
 * every list. It renders nothing when the server sends no options, i.e.
 * when the user cannot consult deleted records.
 */
const props = defineProps<{
    options: RecordStateOption[];
    class?: HTMLAttributes['class'];
}>();

const model = defineModel<RecordStateValue>({ required: true });

const emit = defineEmits<{ change: [] }>();
</script>

<template>
    <Select
        v-if="options.length > 0"
        v-model="model"
        @update:model-value="emit('change')"
    >
        <SelectTrigger
            :class="cn('w-full lg:w-36', props.class)"
            aria-label="Mostrar activos o eliminados"
        >
            <SelectValue />
        </SelectTrigger>
        <SelectContent>
            <SelectItem
                v-for="option in options"
                :key="option.value"
                :value="option.value"
            >
                {{ option.label }}
            </SelectItem>
        </SelectContent>
    </Select>
</template>
