<script lang="ts">
/* Only one audio of the chat sounds at a time. */
let playingNow: HTMLAudioElement | null = null;
</script>

<script setup lang="ts">
import { Download, Pause, Play } from '@lucide/vue';
import { computed, onBeforeUnmount, ref } from 'vue';
import { mediaClock } from '@/components/team-chat/teamChatFormat';
import { formatFileSize } from '@/lib/format';

/**
 * Audio of an internal message played in place: play/pause, a bar to seek
 * (pointer and keyboard), elapsed time and duration, playback speed and the
 * download. It draws with the bubble's own text color, so it reads on any
 * bubble (theme or personal colors). The file is only requested with byte
 * ranges: its header for the duration, the rest when it is played.
 */
const props = defineProps<{
    src: string;
    name: string;
    downloadUrl: string;
    sizeBytes: number;
}>();

const SPEEDS = [1, 1.5, 2] as const;

const audio = ref<HTMLAudioElement | null>(null);
const track = ref<HTMLElement | null>(null);
const playing = ref(false);
const failed = ref(false);
const current = ref(0);
const duration = ref(0);
const speed = ref<(typeof SPEEDS)[number]>(1);
const seeking = ref(false);

const known = computed(
    () => Number.isFinite(duration.value) && duration.value > 0,
);
const ratio = computed(() =>
    known.value ? Math.min(1, current.value / duration.value) : 0,
);
const clock = computed(() =>
    known.value
        ? `${mediaClock(current.value)} / ${mediaClock(duration.value)}`
        : mediaClock(current.value),
);

async function toggle(): Promise<void> {
    const element = audio.value;

    if (!element) {
        return;
    }

    if (!element.paused) {
        element.pause();

        return;
    }

    if (playingNow && playingNow !== element) {
        playingNow.pause();
    }

    playingNow = element;
    element.playbackRate = speed.value;

    try {
        await element.play();
    } catch {
        // The browser refused (format it cannot play): the error state says it.
        failed.value = element.error !== null;
    }
}

function nextSpeed(): void {
    speed.value = SPEEDS[(SPEEDS.indexOf(speed.value) + 1) % SPEEDS.length];

    if (audio.value) {
        audio.value.playbackRate = speed.value;
    }
}

function seekTo(seconds: number): void {
    const element = audio.value;

    if (!element || !known.value) {
        return;
    }

    element.currentTime = Math.max(0, Math.min(duration.value, seconds));
    current.value = element.currentTime;
}

function seekFromPointer(event: PointerEvent): void {
    const bar = track.value;

    if (!bar) {
        return;
    }

    const box = bar.getBoundingClientRect();

    seekTo(
        ((event.clientX - box.left) / Math.max(1, box.width)) * duration.value,
    );
}

function onPointerDown(event: PointerEvent): void {
    if (!known.value) {
        return;
    }

    seeking.value = true;
    (event.currentTarget as HTMLElement).setPointerCapture(event.pointerId);
    seekFromPointer(event);
}

function onPointerMove(event: PointerEvent): void {
    if (seeking.value) {
        seekFromPointer(event);
    }
}

function onTrackKey(event: KeyboardEvent): void {
    const step = { ArrowRight: 5, ArrowUp: 5, ArrowLeft: -5, ArrowDown: -5 }[
        event.key
    ];

    if (step !== undefined) {
        event.preventDefault();
        seekTo(current.value + step);
    } else if (event.key === 'Home') {
        event.preventDefault();
        seekTo(0);
    } else if (event.key === 'End') {
        event.preventDefault();
        seekTo(duration.value);
    }
}

function onEnded(): void {
    playing.value = false;
    current.value = 0;

    if (audio.value) {
        audio.value.currentTime = 0;
    }
}

onBeforeUnmount(() => {
    if (playingNow === audio.value) {
        playingNow = null;
    }
});
</script>

<template>
    <div
        class="flex w-72 max-w-full items-center gap-2.5 rounded-xl bg-current/8 p-2"
        data-team-audio
    >
        <audio
            ref="audio"
            :src="src"
            preload="metadata"
            class="hidden"
            @loadedmetadata="duration = audio?.duration ?? 0"
            @durationchange="duration = audio?.duration ?? 0"
            @timeupdate="current = audio?.currentTime ?? 0"
            @play="playing = true"
            @pause="playing = false"
            @ended="onEnded"
            @error="failed = true"
        />
        <button
            type="button"
            class="grid size-10 shrink-0 place-items-center rounded-full bg-current/15 transition-[background-color,scale] duration-(--duration-fast) outline-none hover:bg-current/25 focus-visible:ring-2 focus-visible:ring-current active:scale-95 disabled:opacity-50"
            :aria-label="
                playing ? `Pausar audio ${name}` : `Reproducir audio ${name}`
            "
            :disabled="failed"
            @click="toggle"
        >
            <Pause v-if="playing" class="size-4.5" aria-hidden="true" />
            <Play v-else class="size-4.5 translate-x-px" aria-hidden="true" />
        </button>
        <div class="min-w-0 flex-1">
            <p v-if="failed" class="text-xs">
                Este audio no se puede reproducir aquí. Descárgalo para
                escucharlo.
            </p>
            <template v-else>
                <div
                    ref="track"
                    role="slider"
                    tabindex="0"
                    class="group/track relative flex h-5 cursor-pointer touch-none items-center rounded outline-none focus-visible:ring-2 focus-visible:ring-current"
                    :aria-label="`Avance del audio ${name}`"
                    aria-valuemin="0"
                    :aria-valuemax="known ? Math.round(duration) : 0"
                    :aria-valuenow="Math.round(current)"
                    :aria-valuetext="clock"
                    :aria-disabled="!known"
                    @pointerdown="onPointerDown"
                    @pointermove="onPointerMove"
                    @pointerup="seeking = false"
                    @pointercancel="seeking = false"
                    @keydown="onTrackKey"
                >
                    <span
                        class="h-1 w-full overflow-hidden rounded-full bg-current/20"
                    >
                        <span
                            class="block h-full rounded-full bg-current"
                            :style="{ width: `${ratio * 100}%` }"
                        />
                    </span>
                    <span
                        class="absolute top-1/2 size-3 -translate-x-1/2 -translate-y-1/2 rounded-full bg-current opacity-0 transition-opacity group-hover/track:opacity-100 group-focus-visible/track:opacity-100 pointer-coarse:opacity-100"
                        :class="(playing || seeking) && 'opacity-100'"
                        :style="{ left: `${ratio * 100}%` }"
                    />
                </div>
                <p
                    class="flex items-center justify-between gap-2 text-[0.7rem] leading-none tabular-nums"
                >
                    <span class="shrink-0">{{ clock }}</span>
                    <span
                        class="truncate py-0.5 opacity-80"
                        :title="`${name} · ${formatFileSize(sizeBytes)}`"
                        >{{ name }}</span
                    >
                </p>
            </template>
        </div>
        <button
            v-if="!failed"
            type="button"
            class="h-7 min-w-9 shrink-0 rounded-full bg-current/12 px-1.5 text-[0.7rem] font-semibold tabular-nums outline-none hover:bg-current/20 focus-visible:ring-2 focus-visible:ring-current pointer-coarse:h-9"
            :aria-label="`Velocidad de reproducción: ${speed}×. Cambiar`"
            title="Velocidad de reproducción"
            @click="nextSpeed"
        >
            {{ speed }}×
        </button>
        <a
            :href="downloadUrl"
            class="grid size-7 shrink-0 place-items-center rounded-full outline-none hover:bg-current/15 focus-visible:ring-2 focus-visible:ring-current pointer-coarse:size-9"
            :aria-label="`Descargar ${name}`"
            :title="`Descargar ${name}`"
        >
            <Download class="size-4" aria-hidden="true" />
        </a>
    </div>
</template>
