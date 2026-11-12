<script setup lang="ts">
import { Monitor, Moon, Sun } from '@lucide/vue';
import { computed } from 'vue';
import { Segmented } from '@/components/ui/segmented';
import { useAppearance } from '@/composables/useAppearance';

type Appearance = 'light' | 'dark' | 'system';

const { appearance, updateAppearance } = useAppearance();

const options = [
    { value: 'light' as const, icon: Sun, label: 'Claro' },
    { value: 'dark' as const, icon: Moon, label: 'Oscuro' },
    { value: 'system' as const, icon: Monitor, label: 'Sistema' },
];

const model = computed<Appearance>({
    get: () => appearance.value,
    set: (value) => updateAppearance(value),
});
</script>

<template>
    <Segmented v-model="model" :options="options" aria-label="Tema" />
</template>
