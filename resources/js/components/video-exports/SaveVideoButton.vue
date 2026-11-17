<script setup lang="ts">
import {
    ArrowUp,
    ChevronRight,
    CircleAlert,
    CircleCheck,
    Folder,
    FolderPlus,
    HardDriveDownload,
    LoaderCircle,
    RotateCcw,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import FormField from '@/components/FormField.vue';
import InputError from '@/components/InputError.vue';
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
import {
    VIDEO_FOLDERS_URL,
    VideoExportRequestError,
    videoExportRequest,
    videoFoldersUrl,
} from '@/lib/videoExports';
import type {
    VideoExportState,
    VideoFolderListing,
    VideoSaveOptions,
} from '@/lib/videoExports';

/**
 * «Guardar video»: copies a video of a chat into a folder of the Synology
 * NAS. The dialog is a folder browser that starts in the root of saved
 * videos (or where the person saved last), lists the existing folders,
 * enters them and can create new ones; the person then types the name of
 * the video (its extension is added). The copy runs in the background;
 * meanwhile the button shows its state («Guardando…», «Guardado en
 * Synology» with the path as tooltip, or the error with a retry) and asks
 * the server again every few seconds until it finishes. The original video
 * stays in the chat as it is.
 *
 * Self-contained so each chat only drops it under its video: the server
 * decides who may save (`options` only exists for them).
 */
const props = withDefaults(
    defineProps<{
        options: VideoSaveOptions;
        /** Name proposed for the video (the contact of a conversation). */
        suggestedName?: string;
        /** Inside a bubble with personal colors: inherit its text color. */
        inheritColor?: boolean;
    }>(),
    { suggestedName: '', inheritColor: false },
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

function openDialog(): void {
    fileName.value = current.value?.file_name || props.suggestedName;
    fileNameError.value = undefined;
    creating.value = false;
    newFolder.value = '';
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

const destination = computed(() =>
    listing.value
        ? [listing.value.root, ...listing.value.segments].join(' / ')
        : '',
);

async function submit(): Promise<void> {
    fileNameError.value = undefined;

    if (fileName.value.trim() === '') {
        fileNameError.value = 'Escribe el nombre del video.';

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
        toast.success('El video se está guardando en Synology.');
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

const chip = computed(() =>
    props.inheritColor
        ? 'border border-current/30 hover:bg-current/10'
        : 'border border-border bg-background text-foreground hover:bg-accent',
);
const muted = computed(() =>
    props.inheritColor ? '' : 'text-muted-foreground',
);
</script>

<template>
    <div
        class="flex max-w-full flex-wrap items-center gap-1.5 text-xs"
        data-save-video
        :data-save-video-status="current?.status ?? 'none'"
    >
        <p
            v-if="pending"
            class="flex min-h-7 items-center gap-1.5 px-1"
            :class="muted"
            :title="current?.error ?? undefined"
            aria-live="polite"
        >
            <LoaderCircle
                class="size-3.5 shrink-0 animate-spin"
                aria-hidden="true"
            />
            Guardando…
        </p>

        <p
            v-else-if="current?.status === 'saved'"
            class="flex min-h-7 min-w-0 items-center gap-1.5 px-1"
            :title="current.path ?? undefined"
        >
            <CircleCheck
                class="size-3.5 shrink-0"
                :class="!inheritColor && 'text-success'"
                aria-hidden="true"
            />
            <span class="truncate">Guardado en Synology</span>
            <span class="sr-only">: {{ current.path }}</span>
        </p>

        <p
            v-else-if="current?.status === 'failed'"
            class="flex min-h-7 min-w-0 items-center gap-1.5 px-1"
            :class="!inheritColor && 'text-destructive'"
            :title="current.error ?? undefined"
        >
            <CircleAlert class="size-3.5 shrink-0" aria-hidden="true" />
            <span>Error al guardar</span>
            <span aria-hidden="true">·</span>
            <button
                type="button"
                class="inline-flex items-center gap-1 font-medium underline underline-offset-2 disabled:opacity-60"
                :disabled="retrying"
                @click="retry"
            >
                <RotateCcw
                    class="size-3"
                    :class="retrying && 'animate-spin'"
                    aria-hidden="true"
                />
                Reintentar
            </button>
        </p>

        <button
            v-if="!pending"
            type="button"
            class="inline-flex min-h-7 items-center gap-1.5 rounded-md px-2 font-medium outline-none focus-visible:ring-2 focus-visible:ring-ring pointer-coarse:min-h-10"
            :class="chip"
            @click="openDialog"
        >
            <HardDriveDownload class="size-3.5 shrink-0" aria-hidden="true" />
            {{
                current?.status === 'saved'
                    ? 'Guardar otra vez'
                    : 'Guardar video'
            }}
        </button>

        <Dialog v-model:open="open">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Guardar video en Synology</DialogTitle>
                    <DialogDescription>
                        Elige la carpeta y escribe el nombre del video. Se
                        guarda una copia; el video sigue disponible en el chat.
                    </DialogDescription>
                </DialogHeader>

                <form class="grid gap-4" novalidate @submit.prevent="submit">
                    <div class="grid gap-2">
                        <div class="flex items-center gap-2">
                            <Button
                                type="button"
                                size="icon"
                                variant="outline"
                                class="size-8 shrink-0"
                                :disabled="
                                    segments.length === 0 || loadingFolders
                                "
                                aria-label="Subir a la carpeta anterior"
                                title="Subir"
                                @click="goUp"
                            >
                                <ArrowUp />
                            </Button>
                            <nav
                                class="flex min-w-0 flex-1 flex-wrap items-center gap-0.5 text-sm"
                                aria-label="Carpeta actual"
                            >
                                <button
                                    type="button"
                                    class="rounded px-1 font-medium hover:bg-accent"
                                    @click="goTo(0)"
                                >
                                    {{ listing?.root ?? 'Videos' }}
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
                                        class="max-w-40 truncate rounded px-1 hover:bg-accent"
                                        :aria-current="
                                            index === segments.length - 1
                                                ? 'location'
                                                : undefined
                                        "
                                        @click="goTo(index + 1)"
                                    >
                                        {{ segment }}
                                    </button>
                                </template>
                            </nav>
                        </div>

                        <div
                            class="h-56 overflow-y-auto rounded-lg border"
                            data-video-folders
                        >
                            <p
                                v-if="loadingFolders"
                                class="flex h-full items-center justify-center gap-2 text-sm text-muted-foreground"
                            >
                                <LoaderCircle
                                    class="size-4 animate-spin"
                                    aria-hidden="true"
                                />
                                Cargando carpetas…
                            </p>
                            <div
                                v-else-if="folderError"
                                class="flex h-full flex-col items-center justify-center gap-3 px-6 text-center text-sm"
                                role="alert"
                            >
                                <CircleAlert
                                    class="size-5 text-destructive"
                                    aria-hidden="true"
                                />
                                <p>{{ folderError }}</p>
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
                                class="divide-y"
                            >
                                <li
                                    v-for="folder in listing.folders"
                                    :key="folder"
                                >
                                    <button
                                        type="button"
                                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm outline-none hover:bg-accent focus-visible:bg-accent"
                                        @click="enter(folder)"
                                    >
                                        <Folder
                                            class="size-4 shrink-0 text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                        <span class="min-w-0 flex-1 truncate">{{
                                            folder
                                        }}</span>
                                        <ChevronRight
                                            class="size-4 shrink-0 text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                    </button>
                                </li>
                            </ul>
                            <p
                                v-else
                                class="flex h-full items-center justify-center px-6 text-center text-sm text-muted-foreground"
                            >
                                Esta carpeta no tiene subcarpetas. Puedes
                                guardar el video aquí o crear una carpeta.
                            </p>
                        </div>

                        <div v-if="creating" class="flex items-start gap-2">
                            <div class="min-w-0 flex-1">
                                <Input
                                    v-model="newFolder"
                                    maxlength="80"
                                    placeholder="Nombre de la carpeta nueva"
                                    aria-label="Nombre de la carpeta nueva"
                                    autofocus
                                    @keydown="onFolderKey"
                                />
                                <InputError :message="newFolderError" />
                            </div>
                            <Button
                                type="button"
                                size="sm"
                                :loading="savingFolder"
                                @click="createFolder"
                            >
                                Crear
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                @click="creating = false"
                            >
                                Cancelar
                            </Button>
                        </div>
                        <div v-else>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                :disabled="!listing || !!folderError"
                                @click="creating = true"
                            >
                                <FolderPlus />
                                Crear carpeta
                            </Button>
                        </div>
                    </div>

                    <FormField
                        id="save-video-name"
                        label="Nombre del video"
                        hint="La extensión del archivo se agrega sola. Si ya existe un video con ese nombre se agrega «(2)»."
                        :error="fileNameError"
                    >
                        <Input
                            id="save-video-name"
                            v-model="fileName"
                            maxlength="120"
                            autocomplete="off"
                            :aria-invalid="!!fileNameError"
                        />
                    </FormField>

                    <p
                        v-if="destination"
                        class="text-xs wrap-anywhere text-muted-foreground"
                    >
                        Se guardará en: {{ destination }}
                    </p>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
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
                            <HardDriveDownload />
                            Guardar aquí
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
