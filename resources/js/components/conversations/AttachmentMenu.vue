<script setup lang="ts">
import { FileText, Image, Mic, Paperclip, Video } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { acceptFor, categoryOptions } from '@/lib/conversations/attachments';
import type { AttachmentCategory } from '@/lib/conversations/attachments';
import type { ChannelAttachmentRules } from '@/types';

/**
 * The composer's clip: a compact menu (as in WhatsApp) with the kinds of
 * files the channel can send, each opening the system file chooser with
 * the matching `accept` hint. There is a single hidden file input; a file
 * set on it without going through the menu (drag and drop, tests) is
 * classified by its own type. When the channel cannot carry files the clip
 * stays visible but unavailable, with the reason.
 */
const props = defineProps<{
    rules: ChannelAttachmentRules | null;
    channelLabel: string;
    /** A send is running. */
    busy: boolean;
}>();

const emit = defineEmits<{
    choose: [file: File, category: AttachmentCategory | null];
}>();

const ICONS = { image: Image, video: Video, document: FileText, audio: Mic };

const options = computed(() => categoryOptions(props.rules));
const unavailableReason = computed(() => {
    if (props.rules?.storage && !props.rules.storage.ready) {
        return (
            props.rules.storage.message ??
            'Por ahora no se pueden adjuntar archivos.'
        );
    }

    return options.value.length === 0
        ? `Por ${props.channelLabel} no se pueden enviar archivos desde aquí.`
        : null;
});

const fileInput = ref<HTMLInputElement | null>(null);
const pending = ref<AttachmentCategory | null>(null);
const accept = computed(() => {
    const option = options.value.find(
        (candidate) => candidate.category === pending.value,
    );

    return option ? option.accept : acceptFor(props.rules?.kinds ?? []);
});

function pick(category: AttachmentCategory): void {
    const input = fileInput.value;

    if (!input) {
        return;
    }

    pending.value = category;
    // Set synchronously: the chooser opens before Vue re-renders.
    const option = options.value.find(
        (candidate) => candidate.category === category,
    );
    input.accept = option?.accept ?? '';
    input.click();
}

function changed(): void {
    const input = fileInput.value;
    const file = input?.files?.[0] ?? null;
    const category = pending.value;

    pending.value = null;

    if (input) {
        // Lets the same file be chosen again (the composer reports it).
        input.value = '';
    }

    if (file) {
        emit('choose', file, category);
    }
}
</script>

<template>
    <div class="contents">
        <input
            ref="fileInput"
            type="file"
            class="sr-only"
            :accept="accept"
            tabindex="-1"
            aria-hidden="true"
            data-attachment-input
            :data-category="pending ?? undefined"
            @change="changed"
            @cancel="pending = null"
        />
        <Popover v-if="unavailableReason">
            <PopoverTrigger as-child>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    class="cursor-not-allowed text-muted-foreground opacity-50 pointer-coarse:size-11"
                    aria-label="Adjuntar archivo"
                    aria-disabled="true"
                    aria-describedby="attachment-menu-unavailable"
                    :title="unavailableReason"
                    data-attachment-clip-unavailable
                >
                    <Paperclip aria-hidden="true" />
                </Button>
            </PopoverTrigger>
            <span id="attachment-menu-unavailable" class="sr-only">{{
                unavailableReason
            }}</span>
            <PopoverContent
                side="top"
                align="start"
                :side-offset="6"
                :collision-padding="8"
                class="w-[min(18rem,calc(100vw-1rem))] p-3 text-sm"
                data-attachment-clip-reason
            >
                {{ unavailableReason }}
            </PopoverContent>
        </Popover>
        <DropdownMenu v-else :modal="false">
            <DropdownMenuTrigger as-child>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    class="text-muted-foreground pointer-coarse:size-11"
                    :disabled="busy"
                    :title="
                        busy
                            ? 'Espera a que termine el envío para adjuntar otro archivo'
                            : 'Adjuntar archivo'
                    "
                    aria-label="Adjuntar archivo"
                >
                    <Paperclip aria-hidden="true" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                side="top"
                align="start"
                :side-offset="6"
                :collision-padding="8"
                class="w-[min(18rem,calc(100vw-1rem))] p-1.5"
                data-attachment-menu
            >
                <DropdownMenuLabel
                    class="px-2 pt-1 pb-1.5 text-xs font-medium text-muted-foreground"
                >
                    Adjuntar por {{ channelLabel }}
                </DropdownMenuLabel>
                <DropdownMenuItem
                    v-for="option in options"
                    :key="option.category"
                    class="items-start gap-3 py-2 pointer-coarse:min-h-12"
                    :data-category="option.category"
                    @select="pick(option.category)"
                >
                    <span
                        class="mt-0.5 inline-flex size-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary"
                        aria-hidden="true"
                    >
                        <component
                            :is="ICONS[option.category]"
                            class="size-4 text-primary"
                        />
                    </span>
                    <span class="grid min-w-0 gap-0.5">
                        <span class="font-medium">{{ option.label }}</span>
                        <span
                            class="text-xs wrap-anywhere text-muted-foreground"
                            >{{ option.hint }}</span
                        >
                    </span>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    </div>
</template>
