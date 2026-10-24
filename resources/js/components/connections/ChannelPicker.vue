<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronRight, Globe, MessageCircle } from '@lucide/vue';
import type { Component } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { create as createConnection } from '@/routes/connections';
import type { ConnectionChannel } from '@/types';

/**
 * First step of "Nueva conexión": choose the channel. Each card opens the
 * creation form of that channel; the channel cannot be changed later.
 */
const open = defineModel<boolean>('open', { required: true });

const choices: {
    channel: ConnectionChannel;
    title: string;
    description: string;
    icon: Component;
}[] = [
    {
        channel: 'whatsapp',
        title: 'WhatsApp Cloud API',
        description:
            'Un número de WhatsApp Business conectado mediante la API oficial de Meta.',
        icon: MessageCircle,
    },
    {
        channel: 'web_chat',
        title: 'Chat web',
        description:
            'Un widget de chat para insertar en tu sitio web con un fragmento de código.',
        icon: Globe,
    },
];
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Nueva conexión</DialogTitle>
                <DialogDescription>
                    Elige el canal por el que te escribirán tus clientes. No se
                    puede cambiar después de crear la conexión.
                </DialogDescription>
            </DialogHeader>
            <ul class="grid gap-3">
                <li v-for="choice in choices" :key="choice.channel">
                    <Link
                        :href="
                            createConnection({
                                query: { channel: choice.channel },
                            })
                        "
                        class="flex items-center gap-3 rounded-lg border p-4 transition-colors hover:border-primary/40 hover:bg-primary-soft/40 focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                        :data-channel="choice.channel"
                    >
                        <span
                            class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary-soft text-primary-soft-foreground"
                        >
                            <component
                                :is="choice.icon"
                                class="size-5"
                                aria-hidden="true"
                            />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-semibold">{{
                                choice.title
                            }}</span>
                            <span class="block text-sm text-muted-foreground">{{
                                choice.description
                            }}</span>
                        </span>
                        <ChevronRight
                            class="size-4 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                    </Link>
                </li>
            </ul>
        </DialogContent>
    </Dialog>
</template>
