<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Check, RefreshCw, Unplug } from '@lucide/vue';
import { computed, ref } from 'vue';
import StatusPill from '@/components/admin/StatusPill.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import ChannelIcon from '@/components/connections/ChannelIcon.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Button } from '@/components/ui/button';
import { formatDate, formatDateTime } from '@/lib/format';
import { authorize, disconnect } from '@/routes/connections/meta';
import type { Connection, MetaAccount } from '@/types';

/**
 * Facebook page / Instagram professional account of a Messenger or
 * Instagram connection, as authorized with Meta: real data only (name, id,
 * picture, permissions granted, who authorized and when, token expiry,
 * webhook subscription), with "Volver a autorizar" (Meta's official flow
 * again) and "Desconectar" (cancels the subscription and forgets the token;
 * conversations and history stay).
 */
const props = defineProps<{
    connection: Connection;
    canManage: boolean;
}>();

const account = computed(() => props.connection.meta_account as MetaAccount);
const channel = computed(() => props.connection.channel);
const confirmOpen = ref(false);
const disconnecting = ref(false);
const reauthorizing = ref(false);

const stateLabel = computed(
    () =>
        ({
            authorized: 'Autorizada',
            expired: 'Autorización vencida',
            invalid: 'Meta rechazó la autorización',
            disconnected: 'Desconectada de Meta',
        })[account.value.state],
);

const stateTone = computed(() =>
    account.value.state === 'authorized' ? 'success' : 'warning',
);

const subscriptionLabel = computed(() => {
    if (account.value.subscribed === true) {
        return 'Suscrito: los mensajes llegan a Chat Manager';
    }

    return account.value.subscribed === false
        ? 'Sin suscripción: Meta no enviará mensajes'
        : 'Sin comprobar';
});

function reauthorize(): void {
    router.post(
        authorize.url(channel.value.value),
        {},
        {
            onStart: () => (reauthorizing.value = true),
            onFinish: () => (reauthorizing.value = false),
        },
    );
}

function confirmDisconnect(): void {
    router.post(
        disconnect.url(props.connection.id),
        {},
        {
            preserveScroll: true,
            onStart: () => (disconnecting.value = true),
            onFinish: () => {
                disconnecting.value = false;
                confirmOpen.value = false;
            },
        },
    );
}
</script>

<template>
    <SurfaceCard
        :title="
            channel.value === 'instagram'
                ? 'Cuenta de Instagram'
                : 'Página de Facebook'
        "
        description="Datos que Meta entregó al autorizar el acceso."
        body-class="grid gap-5 p-5"
    >
        <template #actions>
            <StatusPill
                :label="stateLabel"
                :tone="stateTone"
                :live="account.state === 'authorized'"
            />
        </template>

        <div
            class="relative isolate flex items-center gap-4 overflow-hidden rounded-xl p-3 ring-1 ring-border/60 ring-inset"
            :data-channel-theme="channel.value"
        >
            <span class="channel-wash -z-10" aria-hidden="true" />
            <img
                v-if="account.picture"
                :src="account.picture"
                alt=""
                class="size-14 shrink-0 rounded-full object-cover shadow-float ring-2 ring-card"
                referrerpolicy="no-referrer"
            />
            <span v-else class="channel-tile size-14 rounded-full">
                <ChannelIcon
                    :channel="channel.value"
                    class="size-6"
                    aria-hidden="true"
                />
            </span>
            <div class="min-w-0">
                <p class="truncate font-display text-lg font-semibold">
                    {{ account.name ?? 'Sin nombre' }}
                </p>
                <p class="text-xs text-muted-foreground">
                    {{ channel.label }} · ID {{ account.id ?? '—' }}
                </p>
            </div>
        </div>

        <dl class="grid gap-2.5 text-sm sm:grid-cols-2">
            <div class="rounded-lg bg-surface-sunken/60 px-3 py-2.5">
                <dt class="text-xs text-muted-foreground">Autorizada</dt>
                <dd>
                    {{
                        account.authorized_at
                            ? formatDateTime(account.authorized_at)
                            : '—'
                    }}
                    <span
                        v-if="account.authorized_by"
                        class="text-muted-foreground"
                        >por {{ account.authorized_by }}</span
                    >
                </dd>
            </div>
            <div class="rounded-lg bg-surface-sunken/60 px-3 py-2.5">
                <dt class="text-xs text-muted-foreground">
                    Vigencia del token
                </dt>
                <dd>
                    <template v-if="!account.authorized">Sin token</template>
                    <template v-else-if="account.token_expires_at"
                        >Vence el {{ formatDate(account.token_expires_at) }} (se
                        renueva automáticamente)</template
                    >
                    <template v-else>Sin vencimiento</template>
                </dd>
            </div>
            <div
                class="rounded-lg bg-surface-sunken/60 px-3 py-2.5 sm:col-span-2"
            >
                <dt class="text-xs text-muted-foreground">Webhook</dt>
                <dd>
                    {{ subscriptionLabel }}
                    <span
                        v-if="account.subscription_checked_at"
                        class="text-muted-foreground"
                        >· comprobado
                        {{
                            formatDateTime(account.subscription_checked_at)
                        }}</span
                    >
                </dd>
            </div>
        </dl>

        <div v-if="account.granted_scopes.length > 0" class="space-y-2">
            <p class="text-xs text-muted-foreground">Permisos concedidos</p>
            <ul class="flex flex-wrap gap-1.5 text-sm">
                <li
                    v-for="scope in account.granted_scopes"
                    :key="scope.value"
                    class="inline-flex items-center gap-1 rounded-full bg-success-soft px-2.5 py-0.5 text-xs font-medium text-success-soft-foreground"
                >
                    <Check class="size-3" aria-hidden="true" />
                    {{ scope.label }}
                </li>
            </ul>
        </div>

        <div v-if="canManage" class="flex flex-wrap gap-2 border-t pt-4">
            <Button
                type="button"
                variant="outline"
                :loading="reauthorizing"
                @click="reauthorize"
            >
                <RefreshCw />
                Volver a autorizar
            </Button>
            <Button
                v-if="account.authorized"
                type="button"
                variant="ghost"
                class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                @click="confirmOpen = true"
            >
                <Unplug />
                Desconectar
            </Button>
        </div>

        <ConfirmDialog
            v-model:open="confirmOpen"
            :title="`¿Desconectar ${account.name ?? connection.name}?`"
            description="Se cancelará la suscripción del webhook y se borrará el token de acceso. Dejarán de llegar mensajes de esta cuenta hasta que vuelvas a autorizarla. Las conversaciones y el historial se conservan."
            confirm-label="Desconectar"
            destructive
            :processing="disconnecting"
            @confirm="confirmDisconnect"
        />
    </SurfaceCard>
</template>
