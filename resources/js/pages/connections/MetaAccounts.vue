<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import ChannelIcon from '@/components/connections/ChannelIcon.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { index as connectionsIndex } from '@/routes/connections';
import { connect, show as metaAuthorization } from '@/routes/connections/meta';
import type { ConnectionChannel, MetaAccountCandidate } from '@/types';

/**
 * Second step of a Messenger / Instagram authorization: Meta returned the
 * pages (or the Instagram account) the administrator can connect; choosing
 * one creates the connection (or renews the authorization of an existing
 * one). Tokens never reach the browser.
 */
defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Conexiones', href: connectionsIndex() }],
    },
});

const props = defineProps<{
    channel: { value: ConnectionChannel; label: string };
    accounts: MetaAccountCandidate[];
}>();

const form = useForm<{ account_id: string }>({
    account_id: props.accounts.length === 1 ? props.accounts[0].id : '',
});

const noun = computed(() =>
    props.channel.value === 'instagram' ? 'la cuenta' : 'la página',
);

function choose(account: MetaAccountCandidate): void {
    form.account_id = account.id;
    form.post(connect.url(props.channel.value), { preserveScroll: true });
}

function actionLabel(account: MetaAccountCandidate): string {
    if (account.existing?.is_deleted) {
        return 'Pertenece a una conexión eliminada';
    }

    return account.existing ? 'Renovar autorización' : 'Conectar';
}
</script>

<template>
    <Head :title="`Elegir ${noun} de ${channel.label}`" />

    <div class="page-shell [--page-max:48rem]">
        <PageHeader
            help="conexiones"
            show-title
            :title="`Elige ${noun} que quieres conectar`"
            :description="`Meta autorizó el acceso. Elige ${noun} cuyos mensajes llegarán a Chat Manager; quedará inactiva hasta que la actives.`"
        >
            <template #actions>
                <Button variant="outline" as-child>
                    <Link :href="metaAuthorization(channel.value)">
                        <ArrowLeft class="size-4" aria-hidden="true" />
                        Cancelar
                    </Link>
                </Button>
            </template>
        </PageHeader>

        <InputError :message="form.errors.account_id" />

        <SurfaceCard body-class="divide-y">
            <div
                v-for="account in accounts"
                :key="account.id"
                class="flex flex-wrap items-center gap-4 p-4"
            >
                <img
                    v-if="account.picture"
                    :src="account.picture"
                    alt=""
                    class="size-12 shrink-0 rounded-full border object-cover"
                    referrerpolicy="no-referrer"
                />
                <span
                    v-else
                    class="flex size-12 shrink-0 items-center justify-center rounded-full bg-primary-soft text-primary-soft-foreground"
                >
                    <ChannelIcon
                        :channel="channel.value"
                        class="size-5"
                        aria-hidden="true"
                    />
                </span>
                <div class="min-w-0 flex-1 basis-48 space-y-0.5">
                    <p class="truncate font-semibold">{{ account.name }}</p>
                    <p class="text-xs text-muted-foreground">
                        <span v-if="account.username"
                            >@{{ account.username }} ·
                        </span>
                        ID {{ account.id }}
                    </p>
                    <p
                        v-if="account.existing && !account.existing.is_deleted"
                        class="text-xs text-muted-foreground"
                    >
                        Ya conectada como «{{ account.existing.name }}»: se
                        renovará su autorización.
                    </p>
                    <p
                        v-if="!account.can_message"
                        class="flex items-center gap-1 text-xs text-warning"
                    >
                        <TriangleAlert class="size-3.5" aria-hidden="true" />
                        Tu rol en esta página no permite administrar mensajes.
                    </p>
                </div>
                <Button
                    type="button"
                    :variant="account.existing ? 'outline' : 'default'"
                    :disabled="
                        form.processing ||
                        !account.can_message ||
                        account.existing?.is_deleted === true
                    "
                    @click="choose(account)"
                >
                    <Spinner
                        v-if="form.processing && form.account_id === account.id"
                    />
                    {{ actionLabel(account) }}
                </Button>
            </div>
        </SurfaceCard>
    </div>
</template>
