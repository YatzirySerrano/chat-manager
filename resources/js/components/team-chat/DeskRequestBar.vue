<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { CircleCheck, Clock, Hand, Headset } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { formatDateTime, formatRelative } from '@/lib/format';
import { accept, complete } from '@/routes/team-chat/threads/desk';
import type { TeamThreadDetail } from '@/types/team-chat';

/**
 * State of the «Mesa de control» request of the open desk thread, under
 * its header. The requester reads where her request stands; an auditor
 * gets «Aceptar solicitud» (pending) or «Solicitud completa» (hers), or
 * who attends it otherwise. The server decides both buttons
 * (`can.acceptDesk`, `can.completeDesk`) and checks them again under lock.
 */
const props = defineProps<{ thread: TeamThreadDetail }>();

const busy = ref<'accept' | 'complete' | null>(null);

const desk = computed(() => props.thread.desk ?? null);

const copy = computed(() => {
    const info = desk.value;

    if (!info) {
        return '';
    }

    if (info.is_requester) {
        return {
            new: 'Escribe tu solicitud: el equipo de la mesa de control la atenderá. Puedes enviar fotos, videos y documentos.',
            pending:
                'Tu solicitud está en espera. Alguien de la mesa de control la tomará en cuanto pueda.',
            open: 'Tu solicitud está en atención.',
            closed: 'Tu solicitud se completó. Si necesitas algo más, escribe de nuevo.',
        }[info.status];
    }

    switch (info.status) {
        case 'pending':
            return info.requested_at
                ? `Solicitud en espera desde ${formatRelative(info.requested_at)}.`
                : 'Solicitud en espera.';
        case 'open':
            return info.is_mine
                ? 'Atiendes esta solicitud. Márcala como completa cuando termines.'
                : `La atiende ${info.assignee?.name ?? 'otra persona'}. Puedes leerla, pero solo quien la atiende responde.`;
        case 'closed':
            return info.completed_by
                ? `Completada por ${info.completed_by}. Si la persona vuelve a escribir, regresará a «Pendientes».`
                : 'Solicitud completada.';
        default:
            return 'Esta persona aún no ha escrito a la mesa de control.';
    }
});

const tone = computed(() =>
    desk.value?.status === 'pending'
        ? 'border-warning/25 bg-warning-soft/80 text-warning-soft-foreground'
        : desk.value?.status === 'open'
          ? 'border-primary/20 bg-primary-soft/70 text-primary-soft-foreground'
          : 'border-border bg-muted/60 text-muted-foreground',
);

function run(action: 'accept' | 'complete'): void {
    router.post(
        (action === 'accept' ? accept : complete).url(props.thread.id),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onStart: () => (busy.value = action),
            onFinish: () => (busy.value = null),
        },
    );
}
</script>

<template>
    <div
        v-if="desk"
        class="flex shrink-0 flex-wrap items-center gap-x-3 gap-y-1.5 border-t px-4 py-2 text-xs"
        :class="tone"
        role="status"
        data-desk-bar
    >
        <span class="flex min-w-0 flex-1 items-center gap-2">
            <Clock
                v-if="desk.status === 'pending'"
                class="size-3.5 shrink-0"
                aria-hidden="true"
            />
            <CircleCheck
                v-else-if="desk.status === 'closed'"
                class="size-3.5 shrink-0"
                aria-hidden="true"
            />
            <Headset v-else class="size-3.5 shrink-0" aria-hidden="true" />
            <span
                class="min-w-0"
                :title="
                    desk.requested_at
                        ? `Solicitud del ${formatDateTime(desk.requested_at)}`
                        : undefined
                "
                >{{ copy }}</span
            >
        </span>
        <Button
            v-if="thread.can.acceptDesk"
            size="sm"
            class="h-8 gap-1.5"
            :loading="busy === 'accept'"
            data-desk-accept
            @click="run('accept')"
        >
            <Hand aria-hidden="true" /> Aceptar solicitud
        </Button>
        <Button
            v-if="thread.can.completeDesk"
            size="sm"
            variant="outline"
            class="h-8 gap-1.5 bg-background"
            :loading="busy === 'complete'"
            data-desk-complete
            @click="run('complete')"
        >
            <CircleCheck aria-hidden="true" /> Solicitud completa
        </Button>
    </div>
</template>
