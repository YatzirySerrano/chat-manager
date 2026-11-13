<script setup lang="ts">
import { Play, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import {
    draftKind,
    drawableImage,
    fileLook,
} from '@/components/team-chat/teamChatFormat';
import { formatFileSize } from '@/lib/format';
import { cn } from '@/lib/utils';

/**
 * A file attached to the message being written, before it is sent: images
 * and videos show what they are (drawn from the file itself, nothing is
 * uploaded yet), every other file its type; all of them their name and
 * size, and a button to take them out.
 */
const props = defineProps<{
    file: File;
    /** Object URL of the file (owned by the composer), for images and videos. */
    url: string | null;
    /** The server refused this file: id of the message that says why. */
    errorId?: string;
    disabled?: boolean;
}>();

const emit = defineEmits<{ remove: []; preview: [] }>();

const kind = computed(() => draftKind(props.file));
const look = computed(() => fileLook(props.file.name, kind.value));
const broken = ref(false);
const visual = computed(
    () =>
        props.url !== null &&
        !broken.value &&
        (kind.value === 'video' || drawableImage(props.file)),
);
</script>

<template>
    <li
        :class="
            cn(
                'group/draft relative flex w-56 max-w-full shrink-0 items-center gap-2 rounded-xl border bg-card p-1.5 pr-8 text-xs transition-colors duration-(--duration-fast)',
                errorId
                    ? 'border-destructive/60 bg-destructive-soft text-destructive-soft-foreground'
                    : 'border-border',
            )
        "
        :data-attachment-failed="errorId ? true : undefined"
        :aria-describedby="errorId"
        data-team-draft
    >
        <button
            v-if="visual"
            type="button"
            class="relative size-12 shrink-0 overflow-hidden rounded-lg bg-muted outline-none focus-visible:ring-2 focus-visible:ring-ring"
            :aria-label="`Ver ${file.name} antes de enviarlo`"
            title="Ver antes de enviar"
            @click="emit('preview')"
        >
            <img
                v-if="kind === 'image'"
                :src="url ?? ''"
                alt=""
                class="size-full object-cover"
                draggable="false"
                @error="broken = true"
            />
            <template v-else>
                <video
                    :src="`${url}#t=0.1`"
                    muted
                    playsinline
                    preload="metadata"
                    class="size-full bg-black object-cover"
                    @error="broken = true"
                />
                <span
                    class="absolute inset-0 grid place-items-center bg-black/25 text-white"
                >
                    <Play class="size-4 fill-current" aria-hidden="true" />
                </span>
            </template>
        </button>
        <span
            v-else
            :class="
                cn(
                    'grid size-12 shrink-0 place-items-center rounded-lg',
                    look.tone,
                )
            "
            aria-hidden="true"
        >
            <component :is="look.icon" class="size-5" />
        </span>
        <span class="min-w-0 flex-1">
            <span class="block truncate font-medium" :title="file.name">{{
                file.name
            }}</span>
            <span
                class="block truncate"
                :class="!errorId && 'text-muted-foreground'"
            >
                {{ look.label }} · {{ formatFileSize(file.size) }}
            </span>
            <span v-if="errorId" class="sr-only"> (no se pudo enviar)</span>
        </span>
        <button
            type="button"
            class="absolute top-1 right-1 grid size-6 place-items-center rounded-full text-muted-foreground outline-none hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-40 pointer-coarse:size-8"
            :aria-label="`Quitar ${file.name}`"
            :title="`Quitar ${file.name}`"
            :disabled="disabled"
            @click="emit('remove')"
        >
            <X class="size-3.5" />
        </button>
    </li>
</template>
