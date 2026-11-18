<script setup lang="ts">
import {
    ArrowUp,
    Check,
    ChevronRight,
    CircleAlert,
    CircleCheck,
    Clock,
    CloudOff,
    Folder,
    FolderOpen,
    FolderPlus,
    HardDrive,
    HardDriveUpload,
    Info,
    LoaderCircle,
    RotateCcw,
    X,
} from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import InputError from '@/components/InputError.vue';
import { fileLook } from '@/components/team-chat/teamChatFormat';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { formatFileSize } from '@/lib/format';
import { cn } from '@/lib/utils';
import {
    FILE_KINDS,
    VIDEO_FOLDERS_URL,
    VideoExportRequestError,
    fileKind,
    stemOf,
    videoExportRequest,
    videoFoldersUrl,
} from '@/lib/videoExports';
import type {
    FileSaveOptions,
    VideoExportState,
    VideoFolderListing,
} from '@/lib/videoExports';

/**
 * «Guardar video / imagen / PDF / documento / audio»: copies a file of a
 * chat into a folder of the Synology NAS.
 *
 * The trigger is a compact icon button (the icon of the kind of file with
 * a small upload badge), with its label as tooltip. Over a photo or a video
 * (`overlay`) it floats on the thumbnail and appears on hover or focus
 * (always on touch screens); under audios and documents it sits inline.
 * Next to it a status chip tells how the last copy went: en cola, guardando
 * (spinner), guardado (with its folder; the full path as tooltip) or error
 * (with the reason as tooltip and a retry). While a copy is on its way the
 * server is asked again every few seconds.
 *
 * The dialog shows the file (thumbnail or icon, extension and size), a
 * folder browser that starts where the person saved last (breadcrumb, a
 * grid of folders, «Nueva carpeta») and the name, with the extension of the
 * original as a fixed suffix. The copy runs in the background; the original
 * stays in the chat as it is.
 *
 * Self-contained so each chat only drops it next to its file: the server
 * decides who may save (`options` only exists for them).
 */
const props = withDefaults(
    defineProps<{
        options: FileSaveOptions;
        /** Name proposed for the file (the contact of a conversation). */
        suggestedName?: string;
        /** Inside a bubble with personal colors: inherit its text color. */
        inheritColor?: boolean;
        /** Floats over a photo or video thumbnail (positioned by the parent). */
        overlay?: boolean;
        /** Image or video shown as preview in the dialog. */
        previewUrl?: string | null;
    }>(),
    {
        suggestedName: '',
        inheritColor: false,
        overlay: false,
        previewUrl: null,
    },
);

const kind = computed(() => fileKind(props.options));
const meta = computed(() => FILE_KINDS[kind.value]);
const extension = computed(() => (props.options.extension ?? '').toLowerCase());
const look = computed(() =>
    fileLook(
        `${props.options.name ?? 'archivo'}.${extension.value || 'bin'}`,
        kind.value === 'pdf' ? 'document' : kind.value,
    ),
);

const current = ref<VideoExportState | null>(props.options.export);

watch(
    () => props.options.export,
    (value) => {
        // A newer state from the server (e.g. the history reloaded).
        if (value && (!current.value || value.id >= current.value.id)) {
            current.value = value;
        }
    },
);

const pending = computed(
    () =>
        current.value?.status === 'queued' ||
        current.value?.status === 'saving',
);

/* ------------------------------------------------------------- polling */

let timer: ReturnType<typeof setTimeout> | null = null;

function stopPolling(): void {
    if (timer) {
        clearTimeout(timer);
        timer = null;
    }
}

function schedulePoll(): void {
    stopPolling();

    if (!pending.value || !current.value) {
        return;
    }

    const url = current.value.status_url;

    timer = setTimeout(async () => {
        try {
            const { export: state } = await videoExportRequest<{
                export: VideoExportState;
            }>(url);

            current.value = state;
        } catch {
            // Asked again on the next round.
        }

        schedulePoll();
    }, 4000);
}

watch(pending, (isPending) => (isPending ? schedulePoll() : stopPolling()), {
    immediate: true,
});

onBeforeUnmount(stopPolling);

/* --------------------------------------------------------- status chip */

/** Folder the copy landed in (the last one of its path). */
const savedFolder = computed(() => {
    const parts = (current.value?.path ?? '').split('/').filter(Boolean);

    return parts.length > 1 ? parts[parts.length - 2] : 'Synology';
});

const chip = computed(() => {
    const state = current.value;

    if (!state) {
        return null;
    }

    switch (state.status) {
        case 'queued':
            return state.error
                ? {
                      icon: CloudOff,
                      text: 'En espera',
                      tip: state.error,
                      tone: 'bg-warning-soft text-warning-soft-foreground',
                      spin: false,
                  }
                : {
                      icon: Clock,
                      text: 'En cola',
                      tip: 'Esperando turno para copiarse a Synology',
                      tone: 'bg-muted text-muted-foreground',
                      spin: false,
                  };
        case 'saving':
            return {
                icon: LoaderCircle,
                text: 'Guardando…',
                tip: 'Copiando a Synology',
                tone: 'bg-info-soft text-info-soft-foreground',
                spin: true,
            };
        case 'saved':
            return {
                icon: CircleCheck,
                text: savedFolder.value,
                tip: `Guardado en ${state.path ?? 'Synology'}`,
                tone: 'bg-success-soft text-success-soft-foreground',
                spin: false,
            };
        default:
            return {
                icon: CircleAlert,
                text: 'Error',
                tip: state.error ?? 'No se pudo guardar en Synology.',
                tone: 'bg-destructive-soft text-destructive-soft-foreground',
                spin: false,
            };
    }
});

const chipClass = computed(() =>
    cn(
        'inline-flex h-6 max-w-full min-w-0 items-center gap-1 rounded-full px-2 text-[0.6875rem] leading-none font-medium outline-none focus-visible:ring-2 focus-visible:ring-ring',
        props.inheritColor && !props.overlay
            ? 'bg-current/12'
            : chip.value?.tone,
        props.overlay && 'shadow-sm',
    ),
);

/* ------------------------------------------------------ folder browser */

const open = ref(false);
const listing = ref<VideoFolderListing | null>(null);
const loadingFolders = ref(false);
const folderError = ref<string | null>(null);
const fileName = ref('');
const fileNameError = ref<string | undefined>();
const submitting = ref(false);

const creating = ref(false);
const newFolder = ref('');
const newFolderError = ref<string | undefined>();
const savingFolder = ref(false);
const newFolderInput = ref<InstanceType<typeof Input> | null>(null);

let lastRequest = 0;

async function load(path?: string | null): Promise<void> {
    const request = ++lastRequest;

    loadingFolders.value = true;
    folderError.value = null;

    try {
        const result = await videoExportRequest<VideoFolderListing>(
            videoFoldersUrl(path),
        );

        if (request === lastRequest) {
            listing.value = result;
        }
    } catch (error) {
        if (request === lastRequest) {
            folderError.value = (error as Error).message;
        }
    } finally {
        if (request === lastRequest) {
            loadingFolders.value = false;
        }
    }
}

/**
 * Name proposed: the last one typed for this file; for documents and PDFs
 * the name they arrived with; otherwise the contact, then the original.
 */
function proposedName(): string {
    const original = stemOf(props.options.name);
    const document = kind.value === 'pdf' || kind.value === 'document';

    return (
        current.value?.file_name ||
        (document ? original || props.suggestedName : '') ||
        props.suggestedName ||
        original ||
        meta.value.fallbackName
    );
}

function openDialog(): void {
    fileName.value = proposedName();
    fileNameError.value = undefined;
    creating.value = false;
    newFolder.value = '';
    newFolderError.value = undefined;
    open.value = true;
    // Where the person saved last (the server falls back to the root).
    void load(listing.value ? listing.value.path : undefined);
}

const segments = computed(() => listing.value?.segments ?? []);

function enter(name: string): void {
    void load([...segments.value, name].join('/'));
}

function goTo(depth: number): void {
    void load(segments.value.slice(0, depth).join('/'));
}

function goUp(): void {
    if (segments.value.length > 0) {
        goTo(segments.value.length - 1);
    }
}

function onBrowserKey(event: KeyboardEvent): void {
    if (event.key === 'Backspace' && !loadingFolders.value) {
        event.preventDefault();
        goUp();
    }
}

async function startCreating(): Promise<void> {
    creating.value = true;
    newFolderError.value = undefined;
    await nextTick();
    (newFolderInput.value?.$el as HTMLInputElement | undefined)?.focus();
}

async function createFolder(): Promise<void> {
    if (newFolder.value.trim() === '') {
        newFolderError.value = 'Escribe el nombre de la carpeta.';

        return;
    }

    savingFolder.value = true;
    newFolderError.value = undefined;

    try {
        listing.value = await videoExportRequest<VideoFolderListing>(
            VIDEO_FOLDERS_URL,
            'POST',
            { path: listing.value?.path ?? '', name: newFolder.value },
        );
        newFolder.value = '';
        creating.value = false;
    } catch (error) {
        newFolderError.value = (error as Error).message;
    } finally {
        savingFolder.value = false;
    }
}

function onFolderKey(event: KeyboardEvent): void {
    if (event.key === 'Enter') {
        event.preventDefault();
        void createFolder();
    } else if (event.key === 'Escape') {
        event.stopPropagation();
        creating.value = false;
    }
}

function capitalize(text: string): string {
    return text.charAt(0).toUpperCase() + text.slice(1);
}

async function submit(): Promise<void> {
    fileNameError.value = undefined;

    if (fileName.value.trim() === '') {
        fileNameError.value = 'Escribe el nombre.';

        return;
    }

    if (!listing.value) {
        return;
    }

    submitting.value = true;

    try {
        const { export: state } = await videoExportRequest<{
            export: VideoExportState;
        }>(props.options.url, 'POST', {
            folder: listing.value.path,
            file_name: fileName.value,
        });

        current.value = state;
        open.value = false;
        toast.success(
            `${capitalize(meta.value.noun)} se está guardando en Synology.`,
        );
    } catch (error) {
        if (
            error instanceof VideoExportRequestError &&
            error.errors.file_name
        ) {
            fileNameError.value = error.errors.file_name;
        } else {
            toast.error((error as Error).message);
        }
    } finally {
        submitting.value = false;
    }
}

/* --------------------------------------------------------------- retry */

const retrying = ref(false);

async function retry(): Promise<void> {
    const url = current.value?.retry_url;

    if (!url) {
        return;
    }

    retrying.value = true;

    try {
        const { export: state } = await videoExportRequest<{
            export: VideoExportState;
        }>(url, 'POST');

        current.value = state;
    } catch (error) {
        toast.error((error as Error).message);
    } finally {
        retrying.value = false;
    }
}

/* ----------------------------------------------------------- triggers */

const actionLabel = computed(() =>
    current.value?.status === 'saved'
        ? `${meta.value.action} otra vez`
        : meta.value.action,
);

const triggerClass = computed(() =>
    cn(
        'pointer-events-auto relative inline-flex size-8 shrink-0 items-center justify-center rounded-full transition-[opacity,background-color,color] duration-(--duration-fast) outline-none focus-visible:ring-2 focus-visible:ring-ring pointer-coarse:size-10',
        props.overlay
            ? 'media-scrim opacity-0 group-focus-within/save:opacity-100 group-hover/save:opacity-100 hover:brightness-110 focus-visible:opacity-100 pointer-coarse:opacity-100'
            : props.inheritColor
              ? 'border border-current/30 hover:bg-current/10'
              : 'border border-border bg-background text-muted-foreground hover:bg-accent hover:text-foreground',
        props.overlay && open.value && 'opacity-100',
    ),
);

const sizeLabel = computed(() => formatFileSize(props.options.size_bytes));
</script>

<template>
    <div
        :class="
            overlay
                ? 'pointer-events-none absolute z-10 flex max-w-[calc(100%-3.5rem)] items-center gap-1'
                : 'flex max-w-full min-w-0 items-center gap-1.5'
        "
        data-save-video
        data-save-file
        :data-save-file-kind="kind"
        :data-save-video-status="current?.status ?? 'none'"
    >
        <Tooltip v-if="!pending">
            <TooltipTrigger as-child>
                <button
                    type="button"
                    :class="triggerClass"
                    :aria-label="actionLabel"
                    @click="openDialog"
                >
                    <component
                        :is="meta.icon"
                        class="size-4"
                        aria-hidden="true"
                    />
                    <span
                        class="absolute -right-0.5 -bottom-0.5 grid size-4 place-items-center rounded-full bg-primary text-primary-foreground ring-2"
                        :class="overlay ? 'ring-black/40' : 'ring-background'"
                        aria-hidden="true"
                    >
                        <HardDriveUpload class="size-2.5" :stroke-width="2.5" />
                    </span>
                </button>
            </TooltipTrigger>
            <TooltipContent>{{ actionLabel }} en Synology</TooltipContent>
        </Tooltip>

        <template v-if="chip && current">
            <Tooltip>
                <TooltipTrigger as-child>
                    <span
                        :class="[chipClass, 'pointer-events-auto']"
                        tabindex="0"
                        role="status"
                        aria-live="polite"
                        data-save-file-chip
                    >
                        <component
                            :is="chip.icon"
                            class="size-3 shrink-0"
                            :class="chip.spin && 'animate-spin'"
                            aria-hidden="true"
                        />
                        <span class="min-w-0 truncate">{{ chip.text }}</span>
                        <span class="sr-only">: {{ chip.tip }}</span>
                    </span>
                </TooltipTrigger>
                <TooltipContent class="wrap-anywhere">{{
                    chip.tip
                }}</TooltipContent>
            </Tooltip>

            <Tooltip v-if="current.status === 'failed' && current.retry_url">
                <TooltipTrigger as-child>
                    <button
                        type="button"
                        class="pointer-events-auto grid size-6 shrink-0 place-items-center rounded-full outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-60 pointer-coarse:size-9"
                        :class="
                            overlay
                                ? 'media-scrim'
                                : inheritColor
                                  ? 'hover:bg-current/10'
                                  : 'text-destructive hover:bg-destructive-soft'
                        "
                        :disabled="retrying"
                        aria-label="Reintentar"
                        @click="retry"
                    >
                        <RotateCcw
                            class="size-3.5"
                            :class="retrying && 'animate-spin'"
                            aria-hidden="true"
                        />
                    </button>
                </TooltipTrigger>
                <TooltipContent>Reintentar</TooltipContent>
            </Tooltip>
        </template>

        <Dialog v-model:open="open">
            <DialogContent class="gap-0 overflow-hidden p-0 sm:max-w-xl">
                <DialogHeader
                    class="flex-row items-center gap-3 px-5 pt-5 pb-4 text-left"
                >
                    <span
                        class="relative grid size-10 shrink-0 place-items-center rounded-xl bg-primary-soft text-primary-soft-foreground"
                        aria-hidden="true"
                    >
                        <component :is="meta.icon" class="size-5" />
                        <span
                            class="absolute -right-1 -bottom-1 grid size-5 place-items-center rounded-full bg-primary text-primary-foreground ring-2 ring-background"
                        >
                            <HardDriveUpload class="size-3" />
                        </span>
                    </span>
                    <div class="min-w-0">
                        <DialogTitle>{{ meta.action }}</DialogTitle>
                        <DialogDescription
                            class="flex items-center gap-1 text-xs"
                        >
                            <HardDrive class="size-3" aria-hidden="true" />
                            Copia en Synology
                            <span class="sr-only"
                                >. El original sigue en el chat.</span
                            >
                        </DialogDescription>
                    </div>
                </DialogHeader>

                <form class="grid" novalidate @submit.prevent="submit">
                    <!-- The file -->
                    <div
                        class="mx-5 flex items-center gap-3 rounded-xl border bg-surface-sunken p-2"
                        data-save-file-preview
                    >
                        <div
                            class="grid size-14 shrink-0 place-items-center overflow-hidden rounded-lg"
                            :class="!previewUrl && look.tone"
                        >
                            <img
                                v-if="previewUrl && kind === 'image'"
                                :src="previewUrl"
                                alt=""
                                class="size-full object-cover"
                            />
                            <video
                                v-else-if="previewUrl && kind === 'video'"
                                :src="`${previewUrl}#t=0.1`"
                                class="size-full bg-black object-cover"
                                preload="metadata"
                                muted
                                playsinline
                                aria-hidden="true"
                            />
                            <component
                                :is="look.icon"
                                v-else
                                class="size-6"
                                aria-hidden="true"
                            />
                        </div>
                        <div class="min-w-0 flex-1">
                            <p
                                class="truncate text-sm font-medium"
                                :title="options.name ?? undefined"
                            >
                                {{ options.name || meta.fallbackName }}
                            </p>
                            <p
                                class="mt-1 flex items-center gap-1.5 text-xs text-muted-foreground"
                            >
                                <span
                                    v-if="extension"
                                    class="rounded bg-muted px-1.5 py-0.5 font-mono text-[0.625rem] font-semibold tracking-wide uppercase"
                                    >{{ extension }}</span
                                >
                                <span v-if="sizeLabel">{{ sizeLabel }}</span>
                            </p>
                        </div>
                    </div>

                    <!-- Where -->
                    <div class="grid gap-2 px-5 pt-4">
                        <div class="flex items-center gap-1">
                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <Button
                                        type="button"
                                        size="icon-sm"
                                        variant="ghost"
                                        class="shrink-0"
                                        :disabled="
                                            segments.length === 0 ||
                                            loadingFolders
                                        "
                                        aria-label="Subir a la carpeta anterior"
                                        @click="goUp"
                                    >
                                        <ArrowUp />
                                    </Button>
                                </TooltipTrigger>
                                <TooltipContent>Subir</TooltipContent>
                            </Tooltip>
                            <nav
                                class="flex min-w-0 flex-1 [scrollbar-width:none] items-center gap-0.5 overflow-x-auto text-sm"
                                aria-label="Carpeta actual"
                            >
                                <button
                                    type="button"
                                    class="inline-flex shrink-0 items-center gap-1.5 rounded-md px-1.5 py-1 font-medium outline-none hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring"
                                    :aria-current="
                                        segments.length === 0
                                            ? 'location'
                                            : undefined
                                    "
                                    @click="goTo(0)"
                                >
                                    <HardDrive
                                        class="size-4 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    {{ listing?.root ?? 'Synology' }}
                                </button>
                                <template
                                    v-for="(segment, index) in segments"
                                    :key="`${index}-${segment}`"
                                >
                                    <ChevronRight
                                        class="size-3.5 shrink-0 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    <button
                                        type="button"
                                        class="inline-flex max-w-40 shrink-0 items-center gap-1.5 rounded-md px-1.5 py-1 outline-none hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring"
                                        :class="
                                            index === segments.length - 1 &&
                                            'font-medium'
                                        "
                                        :title="segment"
                                        :aria-current="
                                            index === segments.length - 1
                                                ? 'location'
                                                : undefined
                                        "
                                        @click="goTo(index + 1)"
                                    >
                                        <FolderOpen
                                            v-if="index === segments.length - 1"
                                            class="size-4 shrink-0 text-primary"
                                            aria-hidden="true"
                                        />
                                        <span class="truncate">{{
                                            segment
                                        }}</span>
                                    </button>
                                </template>
                            </nav>
                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <Button
                                        type="button"
                                        size="icon-sm"
                                        variant="outline"
                                        class="shrink-0"
                                        :disabled="
                                            !listing ||
                                            !!folderError ||
                                            creating
                                        "
                                        aria-label="Nueva carpeta"
                                        @click="startCreating"
                                    >
                                        <FolderPlus />
                                    </Button>
                                </TooltipTrigger>
                                <TooltipContent>Nueva carpeta</TooltipContent>
                            </Tooltip>
                        </div>

                        <div
                            class="h-56 overflow-y-auto rounded-xl border bg-background p-1.5"
                            role="group"
                            aria-label="Carpetas"
                            :aria-busy="loadingFolders"
                            data-video-folders
                            @keydown="onBrowserKey"
                        >
                            <div
                                v-if="creating"
                                class="mb-1.5 rounded-lg border border-dashed border-primary/50 bg-primary-soft/40 p-1.5"
                            >
                                <div class="flex items-center gap-1.5">
                                    <FolderPlus
                                        class="ml-1 size-4 shrink-0 text-primary"
                                        aria-hidden="true"
                                    />
                                    <Input
                                        ref="newFolderInput"
                                        v-model="newFolder"
                                        maxlength="80"
                                        class="h-8 min-w-0 flex-1"
                                        placeholder="Nueva carpeta"
                                        aria-label="Nombre de la carpeta nueva"
                                        :aria-invalid="!!newFolderError"
                                        @keydown.stop="onFolderKey"
                                    />
                                    <Button
                                        type="button"
                                        size="icon-sm"
                                        :loading="savingFolder"
                                        aria-label="Crear carpeta"
                                        @click="createFolder"
                                    >
                                        <Check />
                                    </Button>
                                    <Button
                                        type="button"
                                        size="icon-sm"
                                        variant="ghost"
                                        aria-label="Cancelar"
                                        @click="creating = false"
                                    >
                                        <X />
                                    </Button>
                                </div>
                                <InputError
                                    class="mt-1 px-1"
                                    :message="newFolderError"
                                />
                            </div>

                            <div
                                v-if="loadingFolders"
                                class="grid grid-cols-2 gap-1.5 sm:grid-cols-3"
                                aria-hidden="true"
                            >
                                <Skeleton
                                    v-for="index in 6"
                                    :key="index"
                                    class="h-10 rounded-lg"
                                />
                            </div>
                            <div
                                v-else-if="folderError"
                                class="flex h-full flex-col items-center justify-center gap-3 px-6 text-center text-sm"
                                role="alert"
                            >
                                <span
                                    class="grid size-12 place-items-center rounded-full bg-destructive-soft text-destructive-soft-foreground"
                                    aria-hidden="true"
                                >
                                    <CloudOff class="size-6" />
                                </span>
                                <p class="text-muted-foreground">
                                    {{ folderError }}
                                </p>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    @click="load(listing?.path ?? '')"
                                >
                                    <RotateCcw />
                                    Reintentar
                                </Button>
                            </div>
                            <ul
                                v-else-if="
                                    listing && listing.folders.length > 0
                                "
                                class="grid grid-cols-2 gap-1.5 sm:grid-cols-3"
                            >
                                <li
                                    v-for="folder in listing.folders"
                                    :key="folder"
                                    class="min-w-0"
                                >
                                    <button
                                        type="button"
                                        class="group/folder flex h-10 w-full items-center gap-2 rounded-lg px-2.5 text-left text-sm outline-none hover:bg-accent focus-visible:bg-accent focus-visible:ring-2 focus-visible:ring-ring pointer-coarse:h-12"
                                        :title="folder"
                                        :aria-label="`Abrir carpeta ${folder}`"
                                        @click="enter(folder)"
                                    >
                                        <Folder
                                            class="size-5 shrink-0 fill-primary/15 text-primary transition-transform duration-(--duration-fast) group-hover/folder:scale-110"
                                            aria-hidden="true"
                                        />
                                        <span class="min-w-0 flex-1 truncate">{{
                                            folder
                                        }}</span>
                                    </button>
                                </li>
                            </ul>
                            <div
                                v-else-if="!creating"
                                class="flex h-full flex-col items-center justify-center gap-2 text-center"
                            >
                                <span
                                    class="relative grid size-14 place-items-center rounded-2xl bg-muted text-muted-foreground"
                                    aria-hidden="true"
                                >
                                    <FolderOpen class="size-7" />
                                    <span
                                        class="absolute -right-1.5 -bottom-1.5 grid size-6 place-items-center rounded-full bg-primary text-primary-foreground ring-4 ring-background"
                                    >
                                        <HardDriveUpload class="size-3.5" />
                                    </span>
                                </span>
                                <p class="mt-1 text-sm font-medium">
                                    Sin subcarpetas
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    Guarda aquí o crea una carpeta
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Name -->
                    <div class="grid gap-2 px-5 pt-4">
                        <div class="flex items-center gap-1.5">
                            <Label for="save-file-name">Nombre</Label>
                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <button
                                        type="button"
                                        class="grid size-5 place-items-center rounded-full text-muted-foreground outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring"
                                        aria-label="Sobre el nombre"
                                    >
                                        <Info
                                            class="size-3.5"
                                            aria-hidden="true"
                                        />
                                    </button>
                                </TooltipTrigger>
                                <TooltipContent>
                                    Se conserva la extensión del original. Si el
                                    nombre ya existe se agrega «(2)».
                                </TooltipContent>
                            </Tooltip>
                        </div>
                        <div class="flex min-w-0">
                            <Input
                                id="save-file-name"
                                v-model="fileName"
                                maxlength="120"
                                autocomplete="off"
                                class="min-w-0 flex-1"
                                :class="extension && 'rounded-r-none'"
                                :aria-invalid="!!fileNameError"
                                :aria-describedby="
                                    fileNameError
                                        ? 'save-file-name-error'
                                        : extension
                                          ? 'save-file-name-extension'
                                          : undefined
                                "
                            />
                            <span
                                v-if="extension"
                                id="save-file-name-extension"
                                class="inline-flex shrink-0 items-center rounded-r-md border border-l-0 bg-muted px-2.5 font-mono text-sm text-muted-foreground"
                                :aria-label="`Extensión .${extension}`"
                                >.{{ extension }}</span
                            >
                        </div>
                        <InputError
                            id="save-file-name-error"
                            :message="fileNameError"
                        />
                    </div>

                    <DialogFooter class="mt-5 border-t bg-muted/40 px-5 py-3">
                        <Button
                            type="button"
                            variant="ghost"
                            :disabled="submitting"
                            @click="open = false"
                        >
                            Cancelar
                        </Button>
                        <Button
                            type="submit"
                            :loading="submitting"
                            :disabled="
                                !listing || !!folderError || loadingFolders
                            "
                        >
                            <HardDriveUpload />
                            Guardar aquí
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
