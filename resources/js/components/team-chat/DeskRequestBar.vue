<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    CircleCheck,
    Clock,
    Hand,
    Headset,
    MessageSquarePlus,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import FieldHelp from '@/components/FieldHelp.vue';
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

type BarCopy = { text: string; hint?: string };

const copy = computed<BarCopy | null>(() => {
    const info = desk.value;

    if (!info) {
        return null;
    }

    if (info.is_requester) {
        return {
            new: {
                text: 'Escribe tu solicitud',
                hint: 'Puedes enviar fotos, videos y documentos. El equipo de la mesa de control la atenderá.',
            },
            pending: {
                text: 'Tu solicitud está en espera',
                hint: 'Alguien de la mesa de control la tomará en cuanto pueda.',
            },
            open: { text: 'Tu solicitud está en atención' },
            closed: {
                text: 'Tu solicitud se completó',
                hint: 'Si necesitas algo más, escribe de nuevo.',
            },
        }[info.status];
    }

    switch (info.status) {
        case 'pending':
            return {
                text: info.requested_at
                    ? `Solicitud enviada ${formatRelative(info.requested_at)}`
                    : 'Esperando a que alguien la tome',
            };
        case 'open':
            return info.is_mine
                ? {
                      text: 'La atiendes tú',
                      hint: 'Márcala como completa cuando termines.',
                  }
                : {
                      text: `La atiende ${info.assignee?.name ?? 'otra persona'}`,
                      hint: 'Puedes leerla, pero solo quien la atiende responde.',
                  };
        case 'closed':
            return {
                text: info.completed_by
                    ? `Completada por ${info.completed_by}`
                    : 'Solicitud completada',
                hint: 'Si la persona vuelve a escribir, regresará a «Pendientes».',
            };
        default:
            return { text: 'Aún no escribe a la mesa de control' };
    }
});

const status = computed(() => {
    switch (desk.value?.status) {
        case 'pending':
            return {
                label: 'En espera',
                icon: Clock,
                bar: 'border-warning/25 bg-warning-soft/70 text-warning-soft-foreground',
                chip: 'bg-warning/15 text-warning-soft-foreground ring-warning/30',
            };
        case 'open':
            return {
                label: 'En atención',
                icon: Headset,
                bar: 'border-primary/20 bg-primary-soft/60 text-primary-soft-foreground',
                chip: 'bg-primary/12 text-primary-soft-foreground ring-primary/25',
            };
        case 'closed':
            return {
                label: 'Completada',
                icon: CircleCheck,
                bar: 'border-success/20 bg-success-soft/50 text-success-soft-foreground',
                chip: 'bg-success/15 text-success-soft-foreground ring-success/30',
            };
        default:
            return {
                label: 'Nueva',
                icon: MessageSquarePlus,
                bar: 'border-border bg-muted/60 text-muted-foreground',
                chip: 'bg-background text-foreground ring-border',
            };
    }
});

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
        v-if="desk && copy"
        class="flex shrink-0 flex-wrap items-center gap-x-3 gap-y-1.5 border-t px-3 py-2 text-xs sm:px-4"
        :class="status.bar"
        role="status"
        data-desk-bar
        :data-desk-status="desk.status"
    >
        <span class="flex min-w-0 flex-1 items-center gap-2">
            <span
                class="inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-[0.6875rem] font-semibold ring-1 ring-inset"
                :class="status.chip"
            >
                <component
                    :is="status.icon"
                    class="size-3.5"
                    aria-hidden="true"
                />
                {{ status.label }}
            </span>
            <span
                class="min-w-0 truncate font-medium"
                :title="
                    desk.requested_at
                        ? `Solicitud del ${formatDateTime(desk.requested_at)}`
                        : undefined
                "
                >{{ copy.text }}</span
            >
            <FieldHelp v-if="copy.hint" :title="copy.text">
                <p class="text-muted-foreground">{{ copy.hint }}</p>
            </FieldHelp>
        </span>
        <Button
            v-if="thread.can.acceptDesk"
            size="sm"
            class="h-8 gap-1.5 rounded-full"
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
            class="h-8 gap-1.5 rounded-full bg-background"
            :loading="busy === 'complete'"
            data-desk-complete
            @click="run('complete')"
        >
            <CircleCheck aria-hidden="true" /> Solicitud completa
        </Button>
    </div>
</template>
