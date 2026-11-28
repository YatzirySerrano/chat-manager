<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import {
    ArrowDownLeft,
    ArrowUpRight,
    Combine,
    Loader2,
    Paperclip,
    RefreshCw,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import {
    formatTime,
    typeLabel,
} from '@/components/conversations/messageFormat';
import { requestClient } from '@/components/conversations/requestSummary';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { formatDate } from '@/lib/format';
import {
    show as boundaryShowRoute,
    store as boundaryStoreRoute,
} from '@/routes/conversations/boundary';
import type {
    RequestBoundaryMessage,
    RequestBoundaryResult,
    RequestBoundaryWindow,
    RequestSummary,
} from '@/types';

/**
 * «Corregir tamaño de la solicitud»: where the later request starts, when
 * a request was completed too early or the customer's messages landed in
 * the wrong one. Lists the last messages of the earlier request and the
 * first of the later one (GET conversations.boundary.show); the user picks
 * the message the later request starts at («La solicitud siguiente empieza
 * aquí»). Moving the marker up moves the tail of the earlier request into
 * the later one; moving it down, the head of the later one into the
 * earlier one. «Unir con la solicitud anterior» moves everything: the later
 * request is absorbed. A summary says what will move before confirming
 * (POST conversations.boundary.store); nothing is ever deleted.
 */
const props = defineProps<{
    /** The later request (the separator the button belongs to). */
    conversationId: number;
}>();

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{ adjusted: [result: RequestBoundaryResult] }>();

const loadHttp = useHttp<Record<string, never>, RequestBoundaryWindow>({});
const saveHttp = useHttp<
    {
        previous_id: number;
        starts_at: number | null;
        version: number;
        previous_version: number;
    },
    RequestBoundaryResult
>({ previous_id: 0, starts_at: null, version: 0, previous_version: 0 });

const data = ref<RequestBoundaryWindow | null>(null);
const loading = ref(false);
const saving = ref(false);
const error = ref<string | null>(null);
/** The message the later request starts at; null joins it into the earlier one. */
const startsAt = ref<number | null>(null);
let generation = 0;

/** Where the later request starts today: its first message. */
const currentStart = computed<number | null>(
    () =>
        data.value?.messages.find(
            (message) => message.conversation_id === data.value?.current.id,
        )?.id ?? null,
);

async function load(): Promise<void> {
    const current = ++generation;
    loading.value = true;
    error.value = null;

    try {
        const page = await loadHttp.get(
            boundaryShowRoute.url(props.conversationId),
        );

        if (current !== generation) {
            return;
        }

        data.value = page;
        startsAt.value = currentStart.value;
    } catch {
        if (current === generation) {
            error.value =
                'No se pudieron cargar los mensajes. Es posible que estas solicitudes hayan cambiado o que ya no tengas acceso.';
        }
    } finally {
        if (current === generation) {
            loading.value = false;
        }
    }
}

watch(
    () => [open.value, props.conversationId] as const,
    ([isOpen]) => {
        generation++;
        data.value = null;
        error.value = null;
        startsAt.value = null;

        if (isOpen) {
            void load();
        }
    },
    { immediate: true },
);

/** «la de BEATRIZ», or «la anterior» / «la siguiente» when no client was captured. */
function whose(summary: RequestSummary, fallback: string): string {
    return summary.client ? `la de ${summary.client}` : fallback;
}

function requestName(summary: RequestSummary, fallback: string): string {
    return summary.client
        ? `la solicitud de ${summary.client}`
        : `la solicitud ${fallback}`;
}

/** Which request each listed message will belong to with the chosen start. */
function belongsToCurrent(message: RequestBoundaryMessage): boolean {
    return startsAt.value !== null && message.id >= startsAt.value;
}

const plan = computed(() => {
    const window = data.value;

    if (!window) {
        return null;
    }

    const merge = startsAt.value === null;
    const toCurrent = merge
        ? 0
        : window.messages.filter(
              (message) =>
                  message.conversation_id === window.previous.id &&
                  message.id >= (startsAt.value ?? 0),
          ).length;
    const toPrevious = merge
        ? window.current_total
        : window.messages.filter(
              (message) =>
                  message.conversation_id === window.current.id &&
                  message.id < (startsAt.value ?? 0),
          ).length;

    return {
        toCurrent,
        toPrevious,
        // Every message of one request moves: it is joined into the other.
        absorbsCurrent: merge,
        absorbsPrevious:
            !merge &&
            window.previous_total > 0 &&
            toCurrent === window.previous_total,
    };
});

const unchanged = computed(
    () =>
        plan.value !== null &&
        plan.value.toCurrent === 0 &&
        plan.value.toPrevious === 0 &&
        !plan.value.absorbsCurrent,
);

function count(value: number): string {
    return value === 1 ? '1 mensaje' : `${value} mensajes`;
}

/** «Se moverán 4 mensajes de la solicitud de BEATRIZ a la de JULIA.» */
const summary = computed<string[]>(() => {
    const window = data.value;
    const moves = plan.value;

    if (!window || !moves) {
        return [];
    }

    const previous = window.previous;
    const current = window.current;
    const lines: string[] = [];

    if (moves.toCurrent > 0) {
        lines.push(
            `Se moverán ${count(moves.toCurrent)} de ${requestName(previous, 'anterior')} a ${whose(current, 'la siguiente')}.`,
        );
    }

    if (moves.toPrevious > 0) {
        lines.push(
            `Se moverán ${count(moves.toPrevious)} de ${requestName(current, 'siguiente')} a ${whose(previous, 'la anterior')}.`,
        );
    }

    if (moves.absorbsCurrent) {
        lines.push(
            `${capitalize(requestName(current, 'siguiente'))} quedará sin mensajes y se unirá a ${whose(previous, 'la anterior')}: una sola solicitud que sigue como esté la más reciente en atención. No se borra nada.`,
        );
    } else if (moves.absorbsPrevious) {
        lines.push(
            `${capitalize(requestName(previous, 'anterior'))} quedará sin mensajes y se unirá a ${whose(current, 'la siguiente')}. No se borra nada.`,
        );
    }

    return lines;
});

function capitalize(text: string): string {
    return text.charAt(0).toUpperCase() + text.slice(1);
}

function rowText(message: RequestBoundaryMessage): string {
    if (message.revoked) {
        return 'Mensaje eliminado';
    }

    return message.text ?? message.file_name ?? typeLabel(message.type);
}

function failureMessage(failure: unknown): string | undefined {
    const response =
        typeof failure === 'object' && failure !== null && 'response' in failure
            ? (failure as { response: { data: unknown } }).response
            : null;

    try {
        const body =
            typeof response?.data === 'string'
                ? (JSON.parse(response.data) as { message?: string })
                : (response?.data as { message?: string } | undefined);

        return body?.message;
    } catch {
        return undefined;
    }
}

async function confirm(): Promise<void> {
    const window = data.value;

    if (!window || saving.value || unchanged.value) {
        return;
    }

    saving.value = true;
    saveHttp.previous_id = window.previous.id;
    saveHttp.starts_at = startsAt.value;
    saveHttp.version = window.current.version;
    saveHttp.previous_version = window.previous.version;

    try {
        const result = await saveHttp.post(
            boundaryStoreRoute.url(props.conversationId),
            {
                onError: (errors) => {
                    toast.error(
                        Object.values(errors)[0] ??
                            'No se pudo corregir el tamaño de la solicitud.',
                    );
                },
            },
        );

        toast.success(result.message);
        open.value = false;
        emit('adjusted', result);
    } catch (failure) {
        toast.error(
            failureMessage(failure) ??
                'No se pudo corregir el tamaño de la solicitud. Inténtalo de nuevo.',
        );
        // Whatever changed meanwhile, show it as it is now.
        void load();
    } finally {
        saving.value = false;
    }
}

function joinIntoPrevious(): void {
    startsAt.value = null;
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="flex max-h-[min(52rem,calc(100dvh-2rem))] flex-col gap-0 overflow-hidden p-0 max-sm:h-dvh max-sm:max-h-dvh max-sm:max-w-full max-sm:rounded-none sm:max-w-xl"
            data-request-boundary
        >
            <DialogHeader class="border-b px-5 pt-5 pb-3 text-left">
                <DialogTitle class="pr-6 text-base"
                    >Corregir tamaño de la solicitud</DialogTitle
                >
                <DialogDescription class="text-xs">
                    <template v-if="data">
                        Elige el mensaje donde empieza
                        {{ requestName(data.current, 'siguiente') }}
                        ({{ formatDate(data.current.created_at) }}). Lo de
                        arriba queda en
                        {{ requestName(data.previous, 'anterior') }}
                        ({{ formatDate(data.previous.created_at) }}).
                    </template>
                    <template v-else
                        >Mueve mensajes entre esta solicitud y la anterior del
                        mismo contacto.</template
                    >
                </DialogDescription>
            </DialogHeader>

            <div
                class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-3 py-3 sm:px-4"
                :aria-busy="loading"
            >
                <div
                    v-if="loading && !data"
                    class="grid gap-2"
                    aria-hidden="true"
                >
                    <Skeleton v-for="row in 6" :key="row" class="h-10" />
                </div>

                <div
                    v-else-if="error && !data"
                    class="flex flex-col items-center gap-3 py-8 text-center text-sm"
                    role="alert"
                >
                    <p class="text-muted-foreground">{{ error }}</p>
                    <Button variant="outline" size="sm" @click="load()">
                        <RefreshCw aria-hidden="true" />
                        Reintentar
                    </Button>
                </div>

                <fieldset v-else-if="data" class="grid gap-1">
                    <legend class="sr-only">
                        La solicitud siguiente empieza aquí
                    </legend>

                    <p
                        v-if="data.previous_more"
                        class="py-1 text-center text-xs text-muted-foreground"
                    >
                        Antes: más mensajes de
                        {{ requestName(data.previous, 'anterior') }} (se quedan
                        donde están).
                    </p>

                    <template
                        v-for="message in data.messages"
                        :key="message.id"
                    >
                        <p
                            v-if="startsAt === message.id"
                            class="mt-1 flex items-center gap-2 text-xs font-semibold text-primary"
                            aria-hidden="true"
                        >
                            <span class="h-px flex-1 bg-primary/50" />
                            Aquí empieza
                            {{ requestName(data.current, 'siguiente') }}
                            <span class="h-px flex-1 bg-primary/50" />
                        </p>
                        <label
                            class="flex cursor-pointer items-start gap-2 rounded-md border px-2.5 py-1.5 text-xs transition-colors focus-within:ring-2 focus-within:ring-ring"
                            :class="
                                belongsToCurrent(message)
                                    ? 'border-primary/30 bg-primary-soft/40'
                                    : 'border-border bg-card'
                            "
                            :data-boundary-message="message.id"
                        >
                            <input
                                v-model="startsAt"
                                type="radio"
                                name="boundary-start"
                                class="mt-0.5 size-4 shrink-0 accent-primary"
                                :value="message.id"
                                :aria-label="`La solicitud siguiente empieza aquí: ${rowText(message)}`"
                            />
                            <span
                                class="w-12 shrink-0 text-muted-foreground tabular-nums"
                                >{{ formatTime(message.at) }}</span
                            >
                            <span
                                class="inline-flex shrink-0 items-center gap-0.5 font-medium"
                                :class="
                                    message.direction === 'inbound'
                                        ? 'text-foreground'
                                        : 'text-muted-foreground'
                                "
                            >
                                <ArrowDownLeft
                                    v-if="message.direction === 'inbound'"
                                    class="size-3"
                                    aria-hidden="true"
                                />
                                <ArrowUpRight
                                    v-else
                                    class="size-3"
                                    aria-hidden="true"
                                />
                                {{
                                    message.direction === 'inbound'
                                        ? 'Cliente'
                                        : (message.sender ?? 'Equipo')
                                }}
                            </span>
                            <span
                                class="min-w-0 flex-1 break-words"
                                :class="
                                    message.revoked &&
                                    'text-muted-foreground italic'
                                "
                            >
                                <Paperclip
                                    v-if="message.file_name"
                                    class="mr-0.5 inline size-3"
                                    aria-hidden="true"
                                />{{ rowText(message) }}
                            </span>
                        </label>
                    </template>

                    <p
                        v-if="startsAt === null"
                        class="mt-1 flex items-center gap-2 text-xs font-semibold text-primary"
                    >
                        <span class="h-px flex-1 bg-primary/50" />
                        Todo queda en
                        {{ requestName(data.previous, 'anterior') }}
                        <span class="h-px flex-1 bg-primary/50" />
                    </p>

                    <p
                        v-if="data.current_more"
                        class="py-1 text-center text-xs text-muted-foreground"
                    >
                        Después: más mensajes de
                        {{ requestName(data.current, 'siguiente') }}.
                    </p>
                </fieldset>
            </div>

            <DialogFooter
                v-if="data"
                class="grid gap-2 border-t px-5 py-3 sm:flex sm:flex-col sm:items-stretch"
            >
                <div
                    class="text-xs"
                    role="status"
                    aria-live="polite"
                    data-boundary-summary
                >
                    <p v-if="unchanged" class="text-muted-foreground">
                        Elige otro mensaje: la solicitud de
                        {{ requestClient(data.current) }} ya empieza ahí.
                    </p>
                    <p v-for="line in summary" v-else :key="line">
                        {{ line }}
                    </p>
                </div>
                <div class="flex flex-wrap items-center justify-end gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        class="mr-auto"
                        :disabled="saving || startsAt === null"
                        data-boundary-merge
                        @click="joinIntoPrevious"
                    >
                        <Combine aria-hidden="true" />
                        Unir con la solicitud anterior
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        :disabled="saving"
                        @click="open = false"
                        >Cancelar</Button
                    >
                    <Button
                        size="sm"
                        :disabled="saving || unchanged"
                        data-boundary-confirm
                        @click="confirm"
                    >
                        <Loader2
                            v-if="saving"
                            class="animate-spin"
                            aria-hidden="true"
                        />
                        Confirmar
                    </Button>
                </div>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
