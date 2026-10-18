<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Check, Eye, Lock, MessagesSquare, RotateCcw } from '@lucide/vue';
import { onBeforeUnmount, watch } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
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

    <div
        class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8"
    >
        <PageHeader
            title="Personalización"
            description="Elige el color principal de la plataforma. Se aplica a todos los módulos y usuarios, en modo claro y oscuro."
        />

        <p
            v-if="!can.update"
            class="flex items-center gap-2 rounded-xl border bg-card px-4 py-3 text-sm text-muted-foreground shadow-card"
        >
            <Lock class="size-4" />
            Puedes consultar la apariencia, pero no modificarla.
        </p>

        <div class="grid gap-6 lg:grid-cols-[1fr_22rem]">
            <SurfaceCard
                title="Paleta de color"
                description="Paletas curadas con contraste legible en ambos modos."
                body-class="p-5"
            >
                <div
                    class="grid grid-cols-2 gap-3 sm:grid-cols-4"
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
                        class="group relative flex flex-col gap-3 rounded-xl border bg-card p-3 text-left transition outline-none focus-visible:ring-[3px] focus-visible:ring-ring disabled:cursor-not-allowed"
                        :class="
                            form.palette === option.value
                                ? 'border-primary ring-1 ring-primary'
                                : 'hover:border-primary/40'
                        "
                        @click="form.palette = option.value"
                    >
                        <span
                            class="flex h-10 overflow-hidden rounded-lg"
                            aria-hidden="true"
                        >
                            <span class="flex-[2] bg-primary" />
                            <span class="flex-1 bg-primary-soft" />
                        </span>
                        <span
                            class="flex items-center justify-between gap-2 text-sm font-medium"
                        >
                            {{ option.label }}
                            <Check
                                v-if="form.palette === option.value"
                                class="size-4 text-primary"
                            />
                        </span>
                        <Badge
                            v-if="option.is_default"
                            variant="secondary"
                            class="w-fit text-[10px] font-normal"
                        >
                            Predeterminada
                        </Badge>
                    </button>
                </div>
            </SurfaceCard>

            <SurfaceCard title="Vista previa" body-class="space-y-4 p-5">
                <template #actions>
                    <Badge
                        v-if="form.isDirty"
                        variant="outline"
                        class="gap-1 font-normal"
                    >
                        <Eye class="size-3" /> Sin guardar
                    </Badge>
                </template>
                <div class="flex items-center gap-3">
                    <span
                        class="flex size-9 items-center justify-center rounded-lg bg-primary text-primary-foreground"
                    >
                        <MessagesSquare class="size-4.5" />
                    </span>
                    <div>
                        <p class="text-sm font-semibold">
                            {{ $page.props.platform.name }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Centro de atención
                        </p>
                    </div>
                </div>
                <div class="space-y-1 rounded-lg border p-1.5 text-sm">
                    <p
                        class="rounded-md bg-primary-soft px-2.5 py-1.5 font-medium text-primary-soft-foreground"
                    >
                        Usuarios
                    </p>
                    <p class="px-2.5 py-1.5 text-muted-foreground">
                        Departamentos
                    </p>
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

        <div
            v-if="can.update"
            class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"
        >
            <Button
                type="button"
                variant="ghost"
                :disabled="form.processing || form.palette === defaultPalette"
                @click="form.palette = defaultPalette"
            >
                <RotateCcw />
                Restaurar predeterminada
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
                :disabled="!form.isDirty || form.processing"
                @click="save"
            >
                <Spinner v-if="form.processing" />
                Guardar apariencia
            </Button>
        </div>
    </div>
</template>
