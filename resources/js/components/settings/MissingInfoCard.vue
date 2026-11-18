<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, ListPlus, Trash2 } from '@lucide/vue';
import { computed, watch } from 'vue';
import FormField from '@/components/FormField.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { update } from '@/routes/admin/settings/missing-info';
import type { MissingInfoCatalog } from '@/types';

/**
 * Editor of the catalog of «Marcar que necesita información»: the options
 * the agent ticks and the first line of the message the customer receives.
 * Like the non-working days, it lives inside the settings page form and
 * saves with its own button and request.
 */
const props = defineProps<{
    catalog: MissingInfoCatalog;
    canUpdate: boolean;
}>();

const MAX_ITEMS = 30;
const ITEM_MAX = 80;
const INTRO_MAX = 300;

function copy(): MissingInfoCatalog {
    return { intro: props.catalog.intro, items: [...props.catalog.items] };
}

const form = useForm<MissingInfoCatalog>(copy());

watch(
    () => props.catalog,
    () => {
        if (!form.isDirty) {
            form.defaults(copy());
            form.reset();
        }
    },
    { deep: true },
);

const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);

const duplicates = computed(() => {
    const seen = new Set<string>();
    const repeated = new Set<number>();

    form.items.forEach((item, index) => {
        const key = item.trim().toLocaleLowerCase('es-MX');

        if (key === '') {
            return;
        }

        if (seen.has(key)) {
            repeated.add(index);
        } else {
            seen.add(key);
        }
    });

    return repeated;
});

function rowError(index: number): string | undefined {
    if (duplicates.value.has(index)) {
        return 'Esta opción ya está en la lista.';
    }

    return errors.value[`items.${index}`];
}

const invalid = computed(
    () =>
        form.intro.trim() === '' ||
        duplicates.value.size > 0 ||
        form.items.some((item) => item.trim() === ''),
);

function add(): void {
    form.items.push('');
}

function remove(index: number): void {
    form.items.splice(index, 1);
}

function move(index: number, offset: number): void {
    const target = index + offset;

    if (target < 0 || target >= form.items.length) {
        return;
    }

    const [item] = form.items.splice(index, 1);
    form.items.splice(target, 0, item);
}

function submit(): void {
    if (invalid.value || !form.isDirty) {
        return;
    }

    form.put(update.url(), {
        preserveScroll: true,
        onSuccess: () => {
            form.defaults(copy());
            form.reset();
        },
    });
}

const preview = computed(() =>
    [
        form.intro.trim(),
        ...form.items
            .filter((item) => item.trim() !== '')
            .slice(0, 2)
            .map((item) => `• ${item.trim()}`),
    ].join('\n'),
);
</script>

<template>
    <div
        class="overflow-hidden rounded-xl border bg-card"
        role="group"
        aria-labelledby="missing-info-title"
        data-settings-missing-info
    >
        <div class="flex items-center justify-between gap-3 border-b p-5">
            <span id="missing-info-title" class="text-sm font-medium"
                >Opciones para elegir</span
            >
            <Button
                v-if="canUpdate"
                type="button"
                variant="outline"
                size="sm"
                :disabled="form.items.length >= MAX_ITEMS"
                @click="add"
            >
                <ListPlus />
                Agregar opción
            </Button>
        </div>

        <p
            v-if="form.items.length === 0"
            class="p-5 text-sm text-muted-foreground"
        >
            No hay opciones. Los agentes solo podrán describir lo que falta en
            «Otro».
        </p>

        <ul v-else class="divide-y">
            <li
                v-for="(item, index) in form.items"
                :key="index"
                class="row-accent grid gap-2 px-5 py-3 transition-colors hover:bg-accent/40 sm:grid-cols-[1fr_auto] sm:items-center"
            >
                <template v-if="canUpdate">
                    <Input
                        v-model="form.items[index]"
                        :maxlength="ITEM_MAX"
                        placeholder="P. ej. INE por ambos lados"
                        :aria-label="`Opción ${index + 1}`"
                        :aria-invalid="Boolean(rowError(index)) || undefined"
                        @keydown.enter.prevent="submit"
                    />
                    <div class="flex items-center gap-1">
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            :disabled="index === 0"
                            :aria-label="`Subir ${item || 'opción'}`"
                            @click="move(index, -1)"
                        >
                            <ArrowUp />
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            :disabled="index === form.items.length - 1"
                            :aria-label="`Bajar ${item || 'opción'}`"
                            @click="move(index, 1)"
                        >
                            <ArrowDown />
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            :aria-label="`Quitar ${item || 'opción'}`"
                            @click="remove(index)"
                        >
                            <Trash2 />
                        </Button>
                    </div>
                </template>
                <span v-else class="text-sm">{{ item }}</span>
                <InputError class="sm:col-span-2" :message="rowError(index)" />
            </li>
        </ul>

        <div class="grid gap-3 border-t p-5">
            <FormField
                id="missing-info-intro"
                label="Mensaje para el cliente"
                hint="Primera línea del mensaje que se envía al cliente; debajo va la lista de lo que falta."
                :error="errors.intro"
            >
                <Textarea
                    id="missing-info-intro"
                    v-model="form.intro"
                    rows="2"
                    :maxlength="INTRO_MAX"
                    :disabled="!canUpdate"
                    required
                />
            </FormField>
            <p
                class="rounded-lg bg-muted/60 px-3 py-2 text-sm whitespace-pre-line text-muted-foreground"
                aria-label="Ejemplo del mensaje"
            >
                {{ preview }}
            </p>
        </div>

        <div
            v-if="canUpdate"
            class="flex flex-wrap items-center gap-3 border-t bg-surface-sunken/50 px-5 py-3"
        >
            <Button
                type="button"
                :disabled="!form.isDirty || invalid"
                :loading="form.processing"
                @click="submit"
            >
                Guardar información faltante
            </Button>
            <Button
                type="button"
                variant="outline"
                :disabled="!form.isDirty || form.processing"
                @click="form.reset()"
            >
                Descartar
            </Button>
            <InputError :message="errors.items" />
        </div>
    </div>
</template>
