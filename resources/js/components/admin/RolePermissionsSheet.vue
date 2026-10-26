<script setup lang="ts">
import {
    ChevronDown,
    ChevronsDownUp,
    ChevronsUpDown,
    CircleCheck,
    CircleMinus,
    Pencil,
    Search,
} from '@lucide/vue';
import { useMediaQuery } from '@vueuse/core';
import { computed, nextTick, ref, useId, watch } from 'vue';
import DataScopeOption from '@/components/admin/DataScopeOption.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Switch } from '@/components/ui/switch';
import {
    SCOPED_GROUPS,
    groupIcon,
    normalizeSearch,
    summarizeRole,
} from '@/lib/roles';
import type {
    CatalogPermission,
    ManagedRole,
    PermissionGroupCatalog,
    ScopeOption,
} from '@/types';

/**
 * Full permission detail of a role («Ver permisos»): its scope, a summary
 * and every module as a collapsible section whose trigger lives inside a
 * heading. Each permission shows its readable name, what it allows and
 * whether the role has it (icon and text, never colour alone). Technical
 * names are never shown. A right-side sheet on desktop, a bottom sheet on
 * phones.
 */
const props = defineProps<{
    role: ManagedRole | null;
    catalog: PermissionGroupCatalog[];
    scopes: ScopeOption[];
    canEdit: boolean;
}>();

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{ edit: [role: ManagedRole] }>();

const isDesktop = useMediaQuery('(min-width: 768px)');

const search = ref('');
const onlyEnabled = ref(true);
const expanded = ref<Record<string, boolean>>({});

const held = computed(() => new Set(props.role?.permissions ?? []));

const summary = computed(() =>
    props.role ? summarizeRole(props.role, props.catalog) : null,
);

const scope = computed(
    () =>
        props.scopes.find((option) => option.value === props.role?.scope) ??
        null,
);

function isGranted(permission: CatalogPermission): boolean {
    return held.value.has(permission.value);
}

const groups = computed(() => {
    const term = normalizeSearch(search.value.trim());

    return props.catalog
        .map((group) => {
            const available = group.permissions.filter(
                (permission) => permission.available,
            );
            const permissions = group.permissions.filter(
                (permission) =>
                    (!onlyEnabled.value || isGranted(permission)) &&
                    (term === '' ||
                        normalizeSearch(
                            `${group.label} ${permission.label} ${permission.description}`,
                        ).includes(term)),
            );

            return {
                ...group,
                iconComponent: groupIcon(group.icon),
                permissions,
                enabled: available.filter(isGranted).length,
                available: available.length,
            };
        })
        .filter((group) => group.permissions.length > 0);
});

const shownCount = computed(() =>
    groups.value.reduce((total, group) => total + group.permissions.length, 0),
);

/** The first three modules with enabled permissions start expanded. */
function resetExpanded(): void {
    const firstEnabled = props.catalog
        .filter((group) =>
            group.permissions.some(
                (permission) => permission.available && isGranted(permission),
            ),
        )
        .slice(0, 3)
        .map((group) => group.value);

    expanded.value = Object.fromEntries(
        props.catalog.map((group) => [
            group.value,
            firstEnabled.includes(group.value),
        ]),
    );
}

watch(
    () => [open.value, props.role?.id] as const,
    ([isOpen]) => {
        if (isOpen) {
            search.value = '';
            onlyEnabled.value = true;
            resetExpanded();
        }
    },
    { immediate: true },
);

/** While searching every matching module is shown expanded. */
function isExpanded(value: string): boolean {
    return search.value.trim() !== '' || (expanded.value[value] ?? false);
}

function setExpanded(value: string, isOpen: boolean): void {
    expanded.value = { ...expanded.value, [value]: isOpen };
}

function setAll(isOpen: boolean): void {
    expanded.value = Object.fromEntries(
        props.catalog.map((group) => [group.value, isOpen]),
    );
}

/*
 * On open the focus goes to the sheet title, not to the search field: on
 * phones a focused field opens the on-screen keyboard over the sheet.
 */
const titleMarker = useId();

function focusTitle(event: Event): void {
    event.preventDefault();
    void nextTick(() => {
        document
            .querySelector<HTMLElement>(`[data-sheet-title="${titleMarker}"]`)
            ?.focus();
    });
}

function edit(): void {
    if (props.role) {
        emit('edit', props.role);
    }
}
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent
            :side="isDesktop ? 'right' : 'bottom'"
            :class="
                isDesktop
                    ? 'w-full gap-0 p-0 sm:max-w-xl'
                    : 'max-h-[90dvh] gap-0 rounded-t-2xl p-0'
            "
            @open-auto-focus="focusTitle"
        >
            <SheetHeader class="space-y-3 border-b px-5 pt-5 pr-12 pb-4">
                <div class="space-y-1">
                    <p class="text-xs font-medium text-muted-foreground">
                        Permisos del rol
                    </p>
                    <SheetTitle
                        class="text-lg font-semibold break-words outline-none"
                        tabindex="-1"
                        :data-sheet-title="titleMarker"
                    >
                        {{ role?.label ?? 'Rol' }}
                    </SheetTitle>
                    <SheetDescription
                        class="text-sm text-pretty text-muted-foreground"
                    >
                        {{ role?.description || 'Sin descripción.' }}
                    </SheetDescription>
                </div>
                <DataScopeOption v-if="scope" :option="scope" readonly />
                <p
                    v-if="summary"
                    class="text-sm font-medium text-foreground tabular-nums"
                >
                    {{ summary.enabled }} de {{ summary.available }} permisos
                    habilitados · Acceso a {{ summary.areas }} de
                    {{ summary.areasTotal }}
                    {{ summary.areasTotal === 1 ? 'área' : 'áreas' }}
                </p>
            </SheetHeader>

            <div
                class="flex flex-col gap-2 border-b bg-surface-sunken px-5 py-3"
            >
                <div class="relative">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <Input
                        v-model="search"
                        type="search"
                        class="bg-background pl-9"
                        placeholder="Buscar un permiso"
                        aria-label="Buscar un permiso"
                    />
                </div>
                <div
                    class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1"
                >
                    <label
                        class="flex min-h-9 cursor-pointer items-center gap-2 text-sm"
                    >
                        <Switch v-model="onlyEnabled" />
                        Mostrar solo los habilitados
                    </label>
                    <div class="flex items-center gap-1">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            :disabled="search.trim() !== ''"
                            @click="setAll(true)"
                        >
                            <ChevronsUpDown aria-hidden="true" />
                            Expandir todo
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            :disabled="search.trim() !== ''"
                            @click="setAll(false)"
                        >
                            <ChevronsDownUp aria-hidden="true" />
                            Contraer todo
                        </Button>
                    </div>
                </div>
                <p
                    class="text-xs text-muted-foreground tabular-nums"
                    aria-live="polite"
                >
                    {{
                        shownCount === 1
                            ? '1 permiso mostrado'
                            : `${shownCount} permisos mostrados`
                    }}
                </p>
            </div>

            <div
                class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-5 py-2"
            >
                <p
                    v-if="groups.length === 0"
                    class="py-8 text-center text-sm text-pretty text-muted-foreground"
                >
                    {{
                        search.trim() !== ''
                            ? `Ningún permiso coincide con «${search.trim()}».`
                            : 'Este rol no tiene permisos habilitados.'
                    }}
                </p>

                <div v-else class="divide-y">
                    <Collapsible
                        v-for="group in groups"
                        :key="group.value"
                        v-slot="{ open: groupOpen }"
                        :open="isExpanded(group.value)"
                        class="py-1"
                        @update:open="
                            (value: boolean) => setExpanded(group.value, value)
                        "
                    >
                        <h3 class="text-sm font-semibold">
                            <CollapsibleTrigger
                                class="-mx-2 flex w-[calc(100%+1rem)] items-start gap-3 rounded-md px-2 py-2.5 text-left hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                <span
                                    class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground"
                                    aria-hidden="true"
                                >
                                    <component
                                        :is="group.iconComponent"
                                        class="size-4"
                                    />
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block">{{ group.label }}</span>
                                    <span
                                        class="block text-xs font-normal text-pretty text-muted-foreground"
                                        >{{ group.description }}</span
                                    >
                                </span>
                                <span
                                    class="shrink-0 pt-0.5 text-xs font-medium text-muted-foreground tabular-nums"
                                >
                                    {{ group.enabled }} de
                                    {{ group.available }}
                                </span>
                                <ChevronDown
                                    :class="[
                                        'mt-0.5 size-4 shrink-0 text-muted-foreground transition-transform duration-(--duration-base)',
                                        groupOpen && 'rotate-180',
                                    ]"
                                    aria-hidden="true"
                                />
                            </CollapsibleTrigger>
                        </h3>
                        <CollapsibleContent class="pb-2">
                            <p
                                v-if="SCOPED_GROUPS.has(group.value) && scope"
                                class="pb-1 pl-11 text-xs text-muted-foreground"
                            >
                                Se aplica a: {{ scope.label }}
                            </p>
                            <ul class="space-y-1 pl-11">
                                <li
                                    v-for="permission in group.permissions"
                                    :key="permission.value"
                                    class="flex items-start gap-2.5 py-1.5"
                                >
                                    <CircleCheck
                                        v-if="isGranted(permission)"
                                        class="mt-0.5 size-4 shrink-0 text-success"
                                        aria-hidden="true"
                                    />
                                    <CircleMinus
                                        v-else
                                        class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    <div class="min-w-0 flex-1 space-y-0.5">
                                        <p
                                            class="flex flex-wrap items-baseline gap-x-2 text-sm font-medium text-foreground"
                                        >
                                            <span class="min-w-0">{{
                                                permission.label
                                            }}</span>
                                            <span
                                                class="text-xs font-medium"
                                                :class="
                                                    isGranted(permission)
                                                        ? 'text-success'
                                                        : 'text-muted-foreground'
                                                "
                                            >
                                                {{
                                                    isGranted(permission)
                                                        ? 'Permitido'
                                                        : 'No permitido'
                                                }}
                                            </span>
                                        </p>
                                        <p
                                            class="text-sm text-pretty text-muted-foreground"
                                        >
                                            {{ permission.description }}
                                        </p>
                                        <p
                                            v-if="
                                                permission.global_only ||
                                                permission.sensitive ||
                                                permission.reserved ||
                                                !permission.available
                                            "
                                            class="flex flex-wrap gap-1 pt-0.5"
                                        >
                                            <Badge
                                                v-if="permission.global_only"
                                                variant="muted"
                                                class="font-normal whitespace-normal"
                                            >
                                                Solo funciona con «Toda la
                                                organización»
                                            </Badge>
                                            <Badge
                                                v-if="permission.sensitive"
                                                variant="warning"
                                                class="font-normal"
                                            >
                                                Alto impacto
                                            </Badge>
                                            <Badge
                                                v-if="permission.reserved"
                                                variant="info"
                                                class="font-normal"
                                            >
                                                Solo superadministrador
                                            </Badge>
                                            <Badge
                                                v-if="!permission.available"
                                                variant="outline"
                                                class="font-normal"
                                            >
                                                Disponible próximamente
                                            </Badge>
                                        </p>
                                    </div>
                                </li>
                            </ul>
                        </CollapsibleContent>
                    </Collapsible>
                </div>
            </div>

            <footer
                class="flex flex-col-reverse gap-2 border-t px-5 py-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] sm:flex-row sm:justify-end"
            >
                <Button type="button" variant="outline" @click="open = false">
                    Cerrar
                </Button>
                <Button
                    v-if="canEdit && role && !role.is_deleted"
                    type="button"
                    @click="edit"
                >
                    <Pencil aria-hidden="true" />
                    Editar rol
                </Button>
            </footer>
        </SheetContent>
    </Sheet>
</template>
