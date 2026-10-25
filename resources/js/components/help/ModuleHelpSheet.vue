<script setup lang="ts">
import { ChevronDown } from '@lucide/vue';
import { useMediaQuery } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { loadModuleHelp } from '@/help';
import type { ModuleHelp, ModuleHelpKey } from '@/help';

/**
 * "¿Cómo funciona este módulo?" panel.
 *
 * A right-side sheet on desktop and a bottom sheet on phones. The content of
 * the module is loaded the first time the panel opens. Each section is a
 * collapsible region whose trigger lives inside a real heading, so screen
 * reader users can jump between sections; Escape, the close button and an
 * outside click close the panel and return focus to the trigger.
 */
const props = defineProps<{
    module: ModuleHelpKey;
}>();

const open = defineModel<boolean>('open', { default: false });

const isDesktop = useMediaQuery('(min-width: 768px)');
const help = ref<ModuleHelp | null>(null);
const failed = ref(false);

async function load(): Promise<void> {
    failed.value = false;

    try {
        help.value = await loadModuleHelp(props.module);
    } catch {
        failed.value = true;
    }
}

watch(
    () => [open.value, props.module] as const,
    ([isOpen]) => {
        if (isOpen && (help.value === null || failed.value)) {
            void load();
        }
    },
    { immediate: true },
);

watch(
    () => props.module,
    () => {
        help.value = null;
    },
);

type Section =
    | { id: string; title: string; kind: 'list'; items: string[] }
    | { id: string; title: string; kind: 'steps'; items: string[] }
    | {
          id: string;
          title: string;
          kind: 'definitions';
          items: { name: string; description: string }[];
      };

const sections = computed<Section[]>(() => {
    if (!help.value) {
        return [];
    }

    const content = help.value;
    const all: Section[] = [
        {
            id: 'acciones',
            title: 'Qué puedes hacer',
            kind: 'list',
            items: content.mainActions,
        },
        {
            id: 'flujo',
            title: 'Paso a paso',
            kind: 'steps',
            items: content.workflow,
        },
        {
            id: 'campos',
            title: 'Campos y columnas',
            kind: 'definitions',
            items: content.fields,
        },
        {
            id: 'estados',
            title: 'Estados',
            kind: 'definitions',
            items: content.states,
        },
        {
            id: 'permisos',
            title: 'Permisos',
            kind: 'list',
            items: content.permissions,
        },
        {
            id: 'errores',
            title: 'Problemas frecuentes',
            kind: 'definitions',
            items: content.commonErrors.map((error) => ({
                name: error.problem,
                description: error.solution,
            })),
        },
        {
            id: 'consejos',
            title: 'Consejos',
            kind: 'list',
            items: content.tips,
        },
        {
            id: 'ejemplos',
            title: 'Ejemplos',
            kind: 'list',
            items: content.examples,
        },
    ];

    return all.filter((section) => section.items.length > 0);
});

/** The first two sections start expanded; the rest on demand. */
const initiallyOpen = new Set(['acciones', 'flujo']);
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent
            :side="isDesktop ? 'right' : 'bottom'"
            :class="
                isDesktop
                    ? 'w-full gap-0 sm:max-w-md'
                    : 'max-h-[88dvh] gap-0 rounded-t-2xl'
            "
        >
            <SheetHeader class="border-b px-5 pt-5 pr-12 pb-4">
                <p class="text-xs font-medium text-muted-foreground">
                    ¿Cómo funciona este módulo?
                </p>
                <SheetTitle class="text-lg font-semibold">
                    {{ help?.title ?? 'Ayuda del módulo' }}
                </SheetTitle>
                <SheetDescription
                    v-if="help"
                    class="text-sm text-pretty text-muted-foreground"
                >
                    {{ help.objective }}
                </SheetDescription>
                <SheetDescription v-else class="sr-only">
                    Cargando la ayuda del módulo.
                </SheetDescription>
            </SheetHeader>

            <div
                class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-5 py-3 pb-[max(1rem,env(safe-area-inset-bottom))]"
            >
                <div
                    v-if="failed"
                    class="flex flex-col items-start gap-3 py-6 text-sm"
                    role="alert"
                >
                    <p>No se pudo cargar la ayuda. Revisa tu conexión.</p>
                    <Button variant="outline" size="sm" @click="load">
                        Reintentar
                    </Button>
                </div>

                <div
                    v-else-if="!help"
                    class="flex flex-col gap-3 py-3"
                    aria-busy="true"
                >
                    <Skeleton class="h-4 w-2/3" />
                    <Skeleton class="h-4 w-full" />
                    <Skeleton class="h-4 w-5/6" />
                    <Skeleton class="mt-3 h-4 w-1/2" />
                    <Skeleton class="h-4 w-full" />
                </div>

                <div v-else class="divide-y">
                    <Collapsible
                        v-for="section in sections"
                        :key="`${module}-${section.id}`"
                        v-slot="{ open: sectionOpen }"
                        :default-open="initiallyOpen.has(section.id)"
                        class="py-1"
                    >
                        <h3 class="text-sm font-semibold">
                            <CollapsibleTrigger
                                class="-mx-2 flex w-[calc(100%+1rem)] items-center justify-between gap-2 rounded-md px-2 py-2.5 text-left hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                {{ section.title }}
                                <ChevronDown
                                    :class="[
                                        'size-4 shrink-0 text-muted-foreground transition-transform duration-200',
                                        sectionOpen && 'rotate-180',
                                    ]"
                                    aria-hidden="true"
                                />
                            </CollapsibleTrigger>
                        </h3>
                        <CollapsibleContent class="pb-3 text-sm">
                            <ol
                                v-if="section.kind === 'steps'"
                                class="flex flex-col gap-2"
                            >
                                <li
                                    v-for="(item, index) in section.items"
                                    :key="index"
                                    class="flex gap-2.5"
                                >
                                    <span
                                        class="flex size-5 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-medium text-muted-foreground tabular-nums"
                                        aria-hidden="true"
                                    >
                                        {{ index + 1 }}
                                    </span>
                                    <span class="text-pretty">{{ item }}</span>
                                </li>
                            </ol>
                            <dl
                                v-else-if="section.kind === 'definitions'"
                                class="flex flex-col gap-2.5"
                            >
                                <div
                                    v-for="item in section.items"
                                    :key="item.name"
                                >
                                    <dt class="font-medium">{{ item.name }}</dt>
                                    <dd
                                        class="text-pretty text-muted-foreground"
                                    >
                                        {{ item.description }}
                                    </dd>
                                </div>
                            </dl>
                            <ul
                                v-else
                                class="flex list-disc flex-col gap-1.5 pl-5 marker:text-muted-foreground"
                            >
                                <li
                                    v-for="(item, index) in section.items"
                                    :key="index"
                                    class="text-pretty"
                                >
                                    {{ item }}
                                </li>
                            </ul>
                        </CollapsibleContent>
                    </Collapsible>
                </div>
            </div>
        </SheetContent>
    </Sheet>
</template>
