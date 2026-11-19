<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronRight, QrCode } from '@lucide/vue';
import { computed } from 'vue';
import ChannelIcon from '@/components/connections/ChannelIcon.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { WHATSAPP_WEB_PROVIDER } from '@/lib/whatsappWeb';
import { create as createConnection } from '@/routes/connections';
import { show as metaAuthorization } from '@/routes/connections/meta';
import type { ConnectionChannel, MetaChannelAvailability } from '@/types';
import '@/components/admin/admin.css';

/**
 * First step of "Nueva conexión": choose the channel and, for WhatsApp, how
 * the number is connected (the Cloud API, or WhatsApp Web by QR when the
 * server enables it). WhatsApp, Telegram and the web chat open their creation form;
 * Facebook Messenger and Instagram open the
 * official Meta authorization (requirements + "Autorizar acceso"). When the
 * server has no Meta app configured, those cards say so (the page explains
 * which environment variables are missing). The channel cannot be changed later.
 */
const open = defineModel<boolean>('open', { required: true });

const props = defineProps<{
    metaChannels?: Partial<
        Record<'messenger' | 'instagram', MetaChannelAvailability>
    >;
    /** The server has WhatsApp Web (QR) enabled (WHATSAPP_WEB_ENABLED). */
    whatsappWebEnabled?: boolean;
}>();

type Choice = {
    key: string;
    channel: ConnectionChannel;
    /** Way of connecting, when the channel has more than one. */
    provider?: string;
    title: string;
    description: string;
};

const whatsappWeb: Choice = {
    key: WHATSAPP_WEB_PROVIDER,
    channel: 'whatsapp',
    provider: WHATSAPP_WEB_PROVIDER,
    title: 'WhatsApp Web (QR)',
    description:
        'Un WhatsApp normal vinculado escaneando un código QR, sin cuenta de Meta Business.',
};

const baseChoices: Choice[] = [
    {
        key: 'whatsapp',
        channel: 'whatsapp',
        title: 'WhatsApp Cloud API',
        description:
            'Un número de WhatsApp Business conectado mediante la API oficial de Meta.',
    },
    {
        key: 'web_chat',
        channel: 'web_chat',
        title: 'Chat web',
        description:
            'Un widget de chat para insertar en tu sitio web con un fragmento de código.',
    },
    {
        key: 'telegram',
        channel: 'telegram',
        title: 'Telegram',
        description: 'Un bot de Telegram creado con @BotFather.',
    },
    {
        key: 'messenger',
        channel: 'messenger',
        title: 'Facebook Messenger',
        description:
            'Los mensajes de una página de Facebook, autorizando el acceso con tu cuenta de Facebook.',
    },
    {
        key: 'instagram',
        channel: 'instagram',
        title: 'Instagram',
        description:
            'Los mensajes directos de una cuenta profesional de Instagram, autorizando el acceso con Instagram.',
    },
];

/* WhatsApp Web sits right after WhatsApp Cloud API. */
const choices = computed<Choice[]>(() =>
    props.whatsappWebEnabled
        ? [baseChoices[0], whatsappWeb, ...baseChoices.slice(1)]
        : baseChoices,
);

function hrefFor(choice: Choice) {
    if (choice.channel === 'messenger' || choice.channel === 'instagram') {
        return metaAuthorization(choice.channel);
    }

    return createConnection({
        query: choice.provider
            ? { channel: choice.channel, provider: choice.provider }
            : { channel: choice.channel },
    });
}

function needsMetaApp(channel: ConnectionChannel): boolean {
    if (channel !== 'messenger' && channel !== 'instagram') {
        return false;
    }

    return props.metaChannels?.[channel]?.configured === false;
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90dvh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Nueva conexión</DialogTitle>
                <DialogDescription>
                    Elige el canal por el que te escribirán tus clientes. No se
                    puede cambiar después de crear la conexión.
                </DialogDescription>
            </DialogHeader>
            <ul class="grid reveal-stagger gap-2.5">
                <li v-for="choice in choices" :key="choice.key">
                    <Link
                        :href="hrefFor(choice)"
                        class="group/choice admin-card relative isolate flex items-center gap-3.5 overflow-hidden p-3.5 outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 sm:p-4"
                        data-interactive
                        :data-channel="choice.channel"
                        :data-channel-theme="choice.channel"
                        :data-provider="choice.provider"
                    >
                        <span class="channel-wash -z-10" aria-hidden="true" />
                        <span class="channel-tile size-11" aria-hidden="true">
                            <QrCode
                                v-if="choice.provider === WHATSAPP_WEB_PROVIDER"
                                class="size-5"
                            />
                            <ChannelIcon
                                v-else
                                :channel="choice.channel"
                                class="size-5"
                            />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-semibold">{{
                                choice.title
                            }}</span>
                            <span
                                class="block text-sm text-pretty text-muted-foreground"
                                >{{ choice.description }}</span
                            >
                            <span
                                v-if="needsMetaApp(choice.channel)"
                                class="mt-1.5 block text-xs font-medium text-warning-soft-foreground"
                            >
                                Requiere configurar la aplicación de Meta en el
                                servidor; solicítalo al administrador del
                                servidor.
                            </span>
                        </span>
                        <ChevronRight
                            class="size-4 shrink-0 text-muted-foreground group-hover/choice:text-foreground"
                            aria-hidden="true"
                        />
                    </Link>
                </li>
            </ul>
        </DialogContent>
    </Dialog>
</template>
