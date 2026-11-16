<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Check, Eye, Lock, MessagesSquare, RotateCcw } from '@lucide/vue';
import '@/components/admin/admin.css';
import { onBeforeUnmount, watch } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import StatusNote from '@/components/StatusNote.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { applyPalette } from '@/lib/theme';
import { edit, update } from '@/routes/admin/appearance';
import type { ThemePaletteValue } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Personalización', href: edit() }],
    },
});

const props = defineProps<{
    palette: ThemePaletteValue;
    palettes: {
        value: ThemePaletteValue;
        label: string;
        is_default: boolean;
    }[];
    defaultPalette: ThemePaletteValue;
    can: { update: boolean };
}>();

/** Bar heights (in %) of the decorative mini chart in the preview. */
const previewBars = [38, 56, 44, 70, 52, 86];

const form = useForm<{ palette: ThemePaletteValue }>({
    palette: props.palette,
});

/**
 * The whole interface previews the selected palette before saving; leaving
 * the page without saving restores the palette stored on the server.
 */
watch(
    () => form.palette,
    (palette) => applyPalette(palette),
);

onBeforeUnmount(() => applyPalette(props.palette));

function save(): void {
    form.submit(update(), {
        preserveScroll: true,
        onSuccess: () => {
            form.defaults({ palette: props.palette });
            form.reset();
        },
    });
}
</script>

<template>
    <Head title="Personalización" />

    <div class="page-shell">
        <PageHeader
            help="configuracion"
            title="Personalización"
            description="Elige el color principal de la plataforma. Se aplica a todos los módulos y usuarios, en modo claro y oscuro."
        />

        <StatusNote v-if="!can.update" tone="info" :icon="Lock">
            Puedes consultar la apariencia, pero no modificarla.
        </StatusNote>

        <div class="split-layout [--aside-w:24rem]">
            <SurfaceCard
                data-tour="appearance.palettes"
                title="Paleta de color"
                description="Combinaciones de color probadas para leerse bien en modo claro y oscuro."
                body-class="p-5"
            >
                <div
                    class="auto-grid reveal-stagger [--col-min:11rem]"
                    role="radiogroup"
                    aria-label="Paleta de color"
                >
                    <button
                        v-for="option in palettes"
                        :key="option.value"
                        type="button"
                        role="radio"
                        :data-palette="option.value"
                        :aria-checked="form.palette === option.value"
                        :disabled="!can.update || form.processing"
                        class="group relative flex press-spring flex-col gap-3 rounded-xl border bg-card p-3 text-left outline-none focus-visible:ring-[3px] focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-70"
                        :class="
                            form.palette === option.value
                                ? 'border-primary shadow-glow ring-2 ring-primary/60'
                                : 'hover-lift border-border/80'
                        "
                        @click="form.palette = option.value"
                    >
                        <span
                            class="relative flex h-16 overflow-hidden rounded-lg shadow-[inset_0_0_0_1px_color-mix(in_oklch,var(--foreground)_8%,transparent)]"
                            aria-hidden="true"
                        >
                            <span
                                class="flex-[2] accent-gradient transition-[flex-grow] duration-(--duration-panel) ease-(--ease-out-quint) group-hover:flex-[3]"
                            />
                            <span class="flex flex-1 flex-col bg-primary-soft">
                                <span class="flex-1" />
                                <span
                                    class="m-1.5 h-1.5 rounded-full bg-primary/40"
                                />
                            </span>
                            <span
                                v-if="form.palette === option.value"
                                class="absolute top-1.5 left-1.5 flex size-5 items-center justify-center rounded-full bg-background/90 text-primary shadow-sm motion-safe:animate-in motion-safe:zoom-in-50"
                            >
                                <Check class="size-3.5" />
                            </span>
                        </span>
                        <span
                            class="flex items-center justify-between gap-2 text-sm font-medium"
                        >
                            {{ option.label }}
                            <span
                                class="size-2.5 shrink-0 rounded-full bg-primary"
                                aria-hidden="true"
                            />
                        </span>
                        <Badge
                            v-if="option.is_default"
                            variant="secondary"
                            class="w-fit text-xs font-normal"
                        >
                            Predeterminada
                        </Badge>
                    </button>
                </div>

                <template v-if="can.update" #footer>
                    <div
                        class="flex w-full flex-wrap items-center gap-2"
                        data-tour="appearance.actions"
                    >
                        <Button
                            type="button"
                            :class="form.isDirty && 'shadow-glow'"
                            :disabled="!form.isDirty"
                            :loading="form.processing"
                            @click="save"
                        >
                            Guardar apariencia
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="!form.isDirty || form.processing"
                            @click="form.reset()"
                        >
                            Descartar
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            :disabled="
                                form.processing ||
                                form.palette === defaultPalette
                            "
                            @click="form.palette = defaultPalette"
                        >
                            <RotateCcw />
                            Restaurar predeterminada
                        </Button>
                    </div>
                </template>
            </SurfaceCard>

            <SurfaceCard
                title="Vista previa"
                class="glass-card xl:sticky xl:top-22"
                body-class="space-y-4 p-5"
                data-tour="appearance.preview"
            >
                <template #actions>
                    <Badge
                        v-if="form.isDirty"
                        variant="outline"
                        class="gap-1 font-normal motion-safe:animate-in motion-safe:zoom-in-95 motion-safe:fade-in"
                    >
                        <Eye class="size-3" /> Sin guardar
                    </Badge>
                </template>
                <div class="flex items-center gap-3">
                    <span
                        class="flex size-10 items-center justify-center rounded-xl accent-gradient shadow-glow"
                    >
                        <MessagesSquare class="size-4.5" />
                    </span>
                    <div>
                        <p class="font-display text-base font-semibold">
                            {{ $page.props.platform.name }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Centro de atención
                        </p>
                    </div>
                </div>
                <div class="space-y-1 rounded-xl border bg-card p-1.5 text-sm">
                    <p
                        class="relative rounded-lg bg-primary-soft px-2.5 py-1.5 pl-4 font-medium text-primary-soft-foreground before:absolute before:inset-y-2 before:left-1.5 before:w-0.75 before:rounded-full before:bg-primary"
                    >
                        Usuarios
                    </p>
                    <p class="px-2.5 py-1.5 text-muted-foreground">
                        Departamentos
                    </p>
                </div>
                <div
                    class="space-y-1.5 rounded-xl bg-surface-sunken/70 p-3"
                    aria-hidden="true"
                >
                    <p
                        class="w-fit max-w-[80%] rounded-2xl rounded-bl-md border bg-card px-3 py-1.5 text-xs"
                    >
                        Hola, quiero solicitar un crédito.
                    </p>
                    <p
                        class="ml-auto w-fit max-w-[80%] rounded-2xl rounded-br-md accent-gradient px-3 py-1.5 text-xs"
                    >
                        ¡Claro! Te ayudo con tu solicitud.
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Badge class="font-medium">Seleccionado</Badge>
                    <Badge variant="success">Activo</Badge>
                </div>
                <div
                    class="flex h-16 items-end gap-1.5 rounded-lg bg-surface-sunken px-3 pt-3 pb-2"
                    aria-hidden="true"
                >
                    <span
                        v-for="(height, index) in previewBars"
                        :key="index"
                        class="flex-1 rounded-t-sm transition-[height] duration-(--duration-slow) ease-(--ease-spring-soft)"
                        :class="
                            index === previewBars.length - 1
                                ? 'accent-gradient'
                                : 'bg-primary/35'
                        "
                        :style="{ height: `${height}%` }"
                    />
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button type="button" size="sm" tabindex="-1"
                        >Acción principal</Button
                    >
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        tabindex="-1"
                        >Secundaria</Button
                    >
                </div>
                <p class="text-xs text-muted-foreground">
                    Toda la interfaz muestra la paleta seleccionada mientras
                    estás en esta pantalla.
                </p>
            </SurfaceCard>
        </div>
    </div>
</template>
