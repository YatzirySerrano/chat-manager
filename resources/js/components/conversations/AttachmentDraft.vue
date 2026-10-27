<script setup lang="ts">
import {
    FileArchive,
    FileAudio,
    FileText,
    FileVideo,
    Link2,
    ShieldCheck,
    TriangleAlert,
    X,
} from '@lucide/vue';
import { computed, onScopeDispose, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { kindLabel } from '@/lib/conversations/attachments';
import type {
    AttachmentEvaluation,
    AttachmentState,
} from '@/lib/conversations/attachments';
import { formatFileSize } from '@/lib/format';

/**
 * The file waiting in the composer: a preview (image thumbnail, the first
 * frame of a video, an icon for documents and audio), its name, size and
 * kind, how it will reach the customer (as a file or as a private link),
 * the upload progress and the reason it cannot be sent, if any. A file the
 * channel only carries as a private link needs the agent's explicit choice
 * («Usar enlace privado», which can be undone) before «Enviar» sends it.
 * A file the server refused after the upload stays marked as refused, with
 * the server's explanation, until it is removed or changed.
 */
const props = defineProps<{
    file: File;
    evaluation: AttachmentEvaluation;
    channelLabel: string;
    /** Days the private download link works. */
    linkTtlDays: number;
    linkConfirmed: boolean;
    /** 0–100 while uploading, null otherwise. */
    progress: number | null;
    sending: boolean;
    canCancel: boolean;
    /** Another problem to show (text that cannot travel with this file). */
    problem: string | null;
    /** Informative notice (the same file chosen again or already sent). */
    notice: string | null;
    /** Where the file is (checked here, uploading, refused by the server). */
    state: AttachmentState;
    /** The server's explanation when it refused the file. */
    rejection: string | null;
}>();

const emit = defineEmits<{
    remove: [];
    cancel: [];
    confirmLink: [];
    undoLink: [];
}>();

const previewUrl = ref<string | null>(null);
const previewFailed = ref(false);

function releasePreview(): void {
    if (previewUrl.value) {
        URL.revokeObjectURL(previewUrl.value);
    }

    previewUrl.value = null;
}

watch(
    () =>
        [
            props.file,
            props.evaluation.category,
            props.evaluation.error,
        ] as const,
    ([file, category, error]) => {
        releasePreview();
        previewFailed.value = false;

        if (error === null && (category === 'image' || category === 'video')) {
            previewUrl.value = URL.createObjectURL(file);
        }
    },
    { immediate: true },
);

onScopeDispose(releasePreview);

const label = computed(() =>
    kindLabel(props.evaluation.rule, props.evaluation.category),
);
const ruleLabel = computed(
    () => props.evaluation.rule?.label ?? label.value.toLowerCase(),
);
const isLink = computed(() => props.evaluation.delivery === 'link');
const ttl = computed(
    () => `${props.linkTtlDays} ${props.linkTtlDays === 1 ? 'día' : 'días'}`,
);
const deliveryText = computed(() => {
    if (props.evaluation.delivery === null || props.state === 'rejected') {
        return null;
    }

    if (isLink.value) {
        return props.linkConfirmed
            ? 'Se envía como enlace privado'
            : 'Necesita enviarse como enlace privado';
    }

    return 'Se envía como archivo';
});
const kindIcon = computed(() => {
    if (props.evaluation.rule?.key === 'archive') {
        return FileArchive;
    }

    return (
        { video: FileVideo, audio: FileAudio }[
            props.evaluation.category as 'video' | 'audio'
        ] ?? FileText
    );
});
</script>

<template>
    <div
        data-layout-box="attachment"
        class="grid min-w-0 grid-cols-1 gap-2 rounded-lg border px-3 py-2"
        :class="
            (evaluation.error || problem || rejection) &&
            'border-destructive/60'
        "
        role="group"
        aria-label="Archivo adjunto"
        :data-attachment-category="evaluation.category"
        :data-attachment-delivery="evaluation.delivery ?? 'none'"
        :data-attachment-state="state"
        :data-attachment-reason="evaluation.reason ?? undefined"
    >
        <div class="flex items-center gap-3">
            <img
                v-if="
                    previewUrl &&
                    evaluation.category === 'image' &&
                    !previewFailed
                "
                :src="previewUrl"
                :alt="`Vista previa de ${file.name}`"
                class="size-12 shrink-0 rounded object-cover"
                @error="previewFailed = true"
            />
            <video
                v-else-if="
                    previewUrl &&
                    evaluation.category === 'video' &&
                    !previewFailed
                "
                :src="previewUrl"
                preload="metadata"
                muted
                playsinline
                :aria-label="`Vista previa de ${file.name}`"
                class="size-12 shrink-0 rounded bg-muted object-cover"
                @error="previewFailed = true"
            />
            <component
                :is="kindIcon"
                v-else
                class="size-8 shrink-0 text-muted-foreground"
                aria-hidden="true"
            />
            <div class="min-w-0 flex-1 text-xs">
                <p class="truncate font-medium" :title="file.name">
                    {{ file.name }}
                </p>
                <p class="text-muted-foreground">
                    <span data-attachment-kind>{{ label }}</span> ·
                    <span data-attachment-size>{{
                        formatFileSize(file.size)
                    }}</span>
                    <template v-if="deliveryText">
                        ·
                        <span data-attachment-delivery-label>{{
                            deliveryText
                        }}</span></template
                    >
                    <template v-if="progress !== null">
                        ·
                        {{
                            progress < 100
                                ? `subiendo ${progress} %`
                                : 'procesando…'
                        }}</template
                    >
                </p>
            </div>
            <Button
                v-if="canCancel"
                type="button"
                variant="outline"
                size="sm"
                class="shrink-0 pointer-coarse:h-11"
                @click="emit('cancel')"
            >
                Cancelar envío
            </Button>
            <button
                v-else
                type="button"
                class="inline-flex size-8 shrink-0 items-center justify-center rounded-md text-muted-foreground outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50 pointer-coarse:size-11"
                :aria-label="`Quitar el archivo adjunto ${file.name}`"
                title="Quitar el archivo adjunto"
                :disabled="sending"
                @click="emit('remove')"
            >
                <X class="size-4" aria-hidden="true" />
            </button>
        </div>
        <div
            v-if="progress !== null"
            class="h-1.5 overflow-hidden rounded-full bg-muted"
            role="progressbar"
            aria-label="Progreso de la subida del archivo"
            aria-valuemin="0"
            aria-valuemax="100"
            :aria-valuenow="progress"
        >
            <div
                class="h-full rounded-full bg-primary transition-[width] motion-reduce:transition-none"
                :style="{ width: `${progress}%` }"
            />
        </div>
        <p
            v-if="evaluation.error"
            class="text-xs text-destructive"
            role="alert"
        >
            {{ evaluation.error }}
        </p>
        <p
            v-else-if="rejection"
            class="text-xs text-destructive"
            role="alert"
            data-attachment-rejection
        >
            {{ rejection }} Quita el archivo o elige otro.
        </p>
        <p v-else-if="problem" class="text-xs text-destructive" role="alert">
            {{ problem }}
        </p>
        <template v-else-if="isLink">
            <div
                v-if="!linkConfirmed"
                class="grid min-w-0 grid-cols-1 gap-2 rounded-md border border-warning/40 bg-warning/10 p-2 text-xs"
                role="status"
            >
                <p class="flex items-start gap-1.5">
                    <TriangleAlert
                        class="mt-px size-3.5 shrink-0 text-warning"
                        aria-hidden="true"
                    />
                    <span class="min-w-0">
                        {{ channelLabel }} no admite {{ ruleLabel }} como
                        archivo adjunto. Puedes enviarlo como enlace privado
                        solo si lo eliges: el cliente recibirá un mensaje con un
                        enlace que vence en {{ ttl }}.
                    </span>
                </p>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    class="h-auto min-h-8 max-w-full justify-self-start py-1.5 text-left whitespace-normal pointer-coarse:min-h-11"
                    aria-pressed="false"
                    :disabled="sending"
                    @click="emit('confirmLink')"
                >
                    <Link2 aria-hidden="true" />
                    Usar enlace privado
                </Button>
            </div>
            <div
                v-else
                class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground"
                role="status"
            >
                <p class="flex min-w-0 flex-1 basis-56 items-start gap-1.5">
                    <ShieldCheck
                        class="mt-px size-3.5 shrink-0"
                        aria-hidden="true"
                    />
                    <span class="min-w-0">
                        Se enviará como enlace privado porque
                        {{ channelLabel }} no admite {{ ruleLabel }}. El enlace
                        vence en {{ ttl }}. Pulsa «Enviar» para mandarlo.
                    </span>
                </p>
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    class="h-auto min-h-8 py-1 pointer-coarse:min-h-11"
                    :disabled="sending"
                    @click="emit('undoLink')"
                >
                    No usar enlace
                </Button>
            </div>
        </template>
        <p
            v-if="notice"
            class="text-xs text-muted-foreground"
            role="status"
            data-attachment-notice
        >
            {{ notice }}
        </p>
    </div>
</template>
