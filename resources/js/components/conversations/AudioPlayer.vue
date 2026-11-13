<script setup lang="ts">
import { Download, Mic, Pause, Play, TriangleAlert } from '@lucide/vue';
import { useIntersectionObserver } from '@vueuse/core';
import { computed, onBeforeUnmount, ref } from 'vue';
import { Spinner } from '@/components/ui/spinner';
import {
    claimPlayback,
    formatClock,
    releasePlayback,
    waveformBars,
} from '@/lib/conversations/media';

/**
 * Player of an audio or a voice note inside a bubble: play and pause, a bar
 * track that shows and sets the position, elapsed and total time, playback
 * speed (1×, 1.5×, 2×) and the download.
 *
 * Nothing is downloaded until the player is near the screen, and then only
 * the header of the file (its duration); the audio itself travels when it
 * is played. The file is served with byte ranges, so seeking does not wait
 * for the whole download. Starting one player pauses any other one.
 */
const props = withDefaults(
    defineProps<{
        src: string;
        name: string;
        /** Seed of the bars (the attachment id). */
        seed: number;
        /** Duration announced by the channel, in seconds, when known. */
        seconds?: number | null;
        /** A voice note recorded in the chat (shows the microphone). */
        voice?: boolean;
        downloadUrl?: string | null;
        /** Inside a bubble with personal colors: follow its text color. */
        inheritColor?: boolean;
    }>(),
    { seconds: null, voice: false, downloadUrl: null, inheritColor: false },
);

const SPEEDS = [1, 1.5, 2] as const;

const root = ref<HTMLElement | null>(null);
const audio = ref<HTMLAudioElement | null>(null);
const near = ref(false);
const playing = ref(false);
const waiting = ref(false);
const failed = ref(false);
const position = ref(0);
const measured = ref<number | null>(null);
const speed = ref<(typeof SPEEDS)[number]>(1);

useIntersectionObserver(
    root,
    ([entry]) => {
        if (entry?.isIntersecting) {
            near.value = true;
        }
    },
    { rootMargin: '300px' },
);

const bars = computed(() => waveformBars(props.seed));
const duration = computed(() => measured.value ?? props.seconds ?? null);
const progress = computed(() =>
    duration.value && duration.value > 0
        ? Math.min(1, position.value / duration.value)
        : 0,
);
const clock = computed(() =>
    playing.value || position.value > 0
        ? `${formatClock(position.value)} / ${formatClock(duration.value)}`
        : formatClock(duration.value),
);

function onMetadata(): void {
    const value = audio.value?.duration;

    // A streamed Ogg may report Infinity until it has been read once.
    if (value !== undefined && Number.isFinite(value) && value > 0) {
        measured.value = value;
    }
}

async function toggle(): Promise<void> {
    const element = audio.value;

    if (!element || failed.value) {
        return;
    }

    if (!element.paused) {
        element.pause();

        return;
    }

    claimPlayback(element);
    element.playbackRate = speed.value;
    waiting.value = true;

    try {
        await element.play();
    } catch (error) {
        // An interrupted play (paused right away) is not a failure.
        if ((error as DOMException | null)?.name !== 'AbortError') {
            failed.value = true;
        }
    } finally {
        waiting.value = false;
    }
}

function cycleSpeed(): void {
    speed.value = SPEEDS[(SPEEDS.indexOf(speed.value) + 1) % SPEEDS.length];

    if (audio.value) {
        audio.value.playbackRate = speed.value;
    }
}

function seekTo(fraction: number): void {
    const element = audio.value;
    const total = duration.value;

    if (!element || !total) {
        return;
    }

    const target = Math.min(total - 0.05, Math.max(0, fraction * total));

    element.currentTime = target;
    position.value = target;
}

const track = ref<HTMLElement | null>(null);

function seekFromPointer(event: PointerEvent): void {
    const box = track.value?.getBoundingClientRect();

    if (box && box.width > 0) {
        seekTo((event.clientX - box.left) / box.width);
    }
}

function onTrackDown(event: PointerEvent): void {
    (event.currentTarget as HTMLElement).setPointerCapture(event.pointerId);
    seekFromPointer(event);
}

function onTrackMove(event: PointerEvent): void {
    if (
        (event.currentTarget as HTMLElement).hasPointerCapture(event.pointerId)
    ) {
        seekFromPointer(event);
    }
}

function onTrackKey(event: KeyboardEvent): void {
    const total = duration.value;

    if (!total) {
        return;
    }

    const step = { ArrowLeft: -5, ArrowRight: 5, Home: -total, End: total }[
        event.key
    ];

    if (step !== undefined) {
        event.preventDefault();
        seekTo((position.value + step) / total);
    }
}

function onEnded(): void {
    playing.value = false;
    position.value = 0;

    if (audio.value) {
        audio.value.currentTime = 0;
    }
}

onBeforeUnmount(() => {
    if (audio.value) {
        audio.value.pause();
        releasePlayback(audio.value);
    }
});

const mutedText = computed(() =>
    props.inheritColor ? 'opacity-80' : 'text-muted-foreground',
);
</script>

<template>
    <div
        ref="root"
        class="flex w-72 max-w-full items-center gap-2.5 py-0.5"
        role="group"
        :aria-label="voice ? `Nota de voz ${name}` : `Audio ${name}`"
        data-audio-player
        :data-audio-state="failed ? 'failed' : playing ? 'playing' : 'idle'"
    >
        <audio
            v-if="near"
            ref="audio"
            :src="src"
            preload="metadata"
            class="hidden"
            @loadedmetadata="onMetadata"
            @durationchange="onMetadata"
            @timeupdate="position = audio?.currentTime ?? 0"
            @play="playing = true"
            @pause="playing = false"
            @waiting="waiting = true"
            @playing="waiting = false"
            @ended="onEnded"
            @error="failed = true"
        />

        <button
            type="button"
            class="relative grid size-10 shrink-0 place-items-center rounded-full transition-transform duration-(--duration-fast) ease-(--ease-spring) outline-none focus-visible:ring-2 focus-visible:ring-ring active:scale-95 disabled:opacity-60 pointer-coarse:size-11"
            :class="
                inheritColor
                    ? 'border border-current/40 hover:bg-current/10'
                    : 'bg-primary text-primary-foreground hover:bg-primary/90'
            "
            :disabled="failed"
            :aria-label="playing ? 'Pausar' : 'Reproducir'"
            @click="toggle"
        >
            <TriangleAlert v-if="failed" class="size-4" aria-hidden="true" />
            <Spinner v-else-if="waiting && !playing" class="size-4" />
            <Pause
                v-else-if="playing"
                class="size-4 fill-current"
                aria-hidden="true"
            />
            <Play
                v-else
                class="size-4 translate-x-px fill-current"
                aria-hidden="true"
            />
            <span
                v-if="voice && !failed"
                class="absolute -right-0.5 -bottom-0.5 grid size-4 place-items-center rounded-full border"
                :class="
                    inheritColor
                        ? 'border-current/40 bg-(--cm-received-bg,var(--card))'
                        : 'border-background bg-card text-primary'
                "
                aria-hidden="true"
            >
                <Mic class="size-2.5" />
            </span>
        </button>

        <div class="min-w-0 flex-1">
            <p v-if="failed" class="text-xs leading-snug" :class="mutedText">
                Este audio no se puede reproducir en el navegador.
                <a
                    v-if="downloadUrl"
                    :href="downloadUrl"
                    class="font-medium underline underline-offset-2"
                    >Descárgalo</a
                >
                para escucharlo.
            </p>
            <template v-else>
                <div
                    ref="track"
                    class="flex h-8 cursor-pointer touch-none items-center gap-[2px] rounded outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    role="slider"
                    tabindex="0"
                    aria-label="Posición del audio"
                    aria-valuemin="0"
                    :aria-valuemax="Math.round(duration ?? 0)"
                    :aria-valuenow="Math.round(position)"
                    :aria-valuetext="`${formatClock(position)} de ${formatClock(duration)}`"
                    @pointerdown="onTrackDown"
                    @pointermove="onTrackMove"
                    @keydown="onTrackKey"
                >
                    <span
                        v-for="(bar, index) in bars"
                        :key="index"
                        class="min-w-px flex-1 rounded-full bg-current transition-opacity duration-(--duration-fast)"
                        :class="
                            (index + 0.5) / bars.length <= progress
                                ? inheritColor
                                    ? 'opacity-100'
                                    : 'text-primary opacity-100'
                                : 'opacity-30'
                        "
                        :style="{ height: `${Math.round(bar * 100)}%` }"
                    />
                </div>
                <div
                    class="mt-0.5 flex items-center gap-2 text-[0.6875rem] leading-none tabular-nums"
                    :class="mutedText"
                >
                    <span data-audio-clock>{{ clock }}</span>
                    <button
                        type="button"
                        class="ml-auto rounded-full border px-1.5 py-0.5 font-semibold outline-none focus-visible:ring-2 focus-visible:ring-ring pointer-coarse:px-2.5 pointer-coarse:py-1.5"
                        :class="
                            inheritColor
                                ? 'border-current/40 hover:bg-current/10'
                                : speed === 1
                                  ? 'hover:bg-accent hover:text-foreground'
                                  : 'border-primary/40 bg-primary-soft text-primary-soft-foreground'
                        "
                        :aria-label="`Velocidad de reproducción: ${speed}×. Cambiar`"
                        :title="`Velocidad: ${speed}×`"
                        @click="cycleSpeed"
                    >
                        {{ String(speed).replace('.', ',') }}×
                    </button>
                </div>
            </template>
        </div>

        <a
            v-if="downloadUrl && !failed"
            :href="downloadUrl"
            class="grid size-8 shrink-0 place-items-center rounded-full outline-none focus-visible:ring-2 focus-visible:ring-ring pointer-coarse:size-10"
            :class="
                inheritColor
                    ? 'hover:bg-current/10'
                    : 'text-muted-foreground hover:bg-accent hover:text-foreground'
            "
            :title="`Descargar ${name}`"
        >
            <Download class="size-4" aria-hidden="true" />
            <span class="sr-only">Descargar {{ name }}</span>
        </a>
    </div>
</template>
