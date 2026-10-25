<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CircleCheck,
    Copy,
    ShieldCheck,
    TriangleAlert,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import ChannelIcon from '@/components/connections/ChannelIcon.vue';
import PageHeader from '@/components/PageHeader.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { index as connectionsIndex } from '@/routes/connections';
import { authorize } from '@/routes/connections/meta';
import type { ConnectionChannel, MetaScope } from '@/types';

/**
 * Requirements of a Facebook Messenger or Instagram connection and the
 * "Autorizar acceso" button, which sends the administrator to Meta's
 * official authorization. When the server lacks the Meta app configuration
 * the page says which variables are missing and never fakes a connection.
 */
defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Conexiones', href: connectionsIndex() }],
    },
});

const props = defineProps<{
    channel: { value: ConnectionChannel; label: string };
    configured: boolean;
    missing: string[];
    requirements: string[];
    scopes: MetaScope[];
    usesLoginConfiguration: boolean;
    redirectUri: string;
    webhookUrl: string;
    subscribedFields: string[];
}>();

const processing = ref(false);

const title = computed(() => `Conectar ${props.channel.label}`);

const providerName = computed(() =>
    props.channel.value === 'instagram' ? 'Instagram' : 'Facebook',
);

function start(): void {
    router.post(
        authorize.url(props.channel.value),
        {},
        {
            onStart: () => (processing.value = true),
            onFinish: () => (processing.value = false),
        },
    );
}

async function copy(value: string, label: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(value);
        toast.success(`${label} copiada.`);
    } catch {
        toast.error(
            'No fue posible copiar; selecciona el texto y cópialo manualmente.',
        );
    }
}
</script>

<template>
    <Head :title="title" />

    <div
        class="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8"
    >
        <PageHeader
            help="conexiones"
            show-title
            :title="title"
            :description="`Autoriza el acceso con ${providerName} para recibir y responder los mensajes en la bandeja de Chat Manager.`"
        >
            <template #eyebrow>
                <span
                    class="inline-flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <ChannelIcon
                        :channel="channel.value"
                        class="size-4"
                        aria-hidden="true"
                    />
                    {{ channel.label }}
                </span>
            </template>
            <template #actions>
                <Button variant="outline" as-child>
                    <Link :href="connectionsIndex()">
                        <ArrowLeft class="size-4" aria-hidden="true" />
                        Volver a conexiones
                    </Link>
                </Button>
            </template>
        </PageHeader>

        <div
            v-if="!configured"
            role="alert"
            class="flex gap-3 rounded-xl border border-warning/40 bg-warning/10 p-4 text-sm"
        >
            <TriangleAlert
                class="mt-0.5 size-5 shrink-0 text-warning"
                aria-hidden="true"
            />
            <div class="min-w-0 space-y-1">
                <p class="font-semibold">
                    Requiere configurar la aplicación de Meta en el servidor
                </p>
                <p class="text-muted-foreground">
                    Pide al equipo técnico que registre los datos de la
                    aplicación de Meta en el servidor. Mientras tanto no se
                    puede autorizar ninguna cuenta.
                </p>
                <details v-if="missing.length > 0" class="text-xs">
                    <summary
                        class="cursor-pointer font-medium select-none pointer-coarse:py-2"
                    >
                        Detalles para el administrador del servidor
                    </summary>
                    <p class="mt-1 text-muted-foreground">
                        Faltan estas variables de entorno:
                        <span class="font-mono break-all text-foreground">{{
                            missing.join(', ')
                        }}</span
                        >.
                    </p>
                </details>
            </div>
        </div>

        <SurfaceCard
            title="Antes de autorizar"
            description="Revisa que se cumplan estos requisitos de Meta."
            body-class="p-5"
        >
            <ul class="space-y-3 text-sm">
                <li
                    v-for="requirement in requirements"
                    :key="requirement"
                    class="flex gap-2"
                >
                    <CircleCheck
                        class="mt-0.5 size-4 shrink-0 text-primary"
                        aria-hidden="true"
                    />
                    <span>{{ requirement }}</span>
                </li>
            </ul>
        </SurfaceCard>

        <SurfaceCard
            title="Permisos que se solicitarán"
            :description="
                usesLoginConfiguration
                    ? 'Se usa la configuración de inicio de sesión para empresas definida en la app de Meta; estos son los permisos que necesita.'
                    : `${providerName} te pedirá confirmar estos permisos. Puedes revisarlos o retirarlos después desde tu cuenta.`
            "
            body-class="p-5"
        >
            <ul class="grid gap-2 text-sm sm:grid-cols-2">
                <li
                    v-for="scope in scopes"
                    :key="scope.value"
                    class="flex gap-2"
                >
                    <ShieldCheck
                        class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <span>{{ scope.label }}</span>
                </li>
            </ul>
        </SurfaceCard>

        <SurfaceCard
            title="Datos para la app de Meta"
            description="Quien administra la app de Meta debe registrar estas direcciones."
            body-class="space-y-4 p-5 text-sm"
        >
            <div class="space-y-1">
                <p class="font-medium">Dirección de retorno (OAuth)</p>
                <div class="flex items-center gap-2">
                    <code
                        class="min-w-0 flex-1 rounded-md bg-muted px-2 py-1 break-all"
                        >{{ redirectUri }}</code
                    >
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        aria-label="Copiar dirección de retorno"
                        @click="copy(redirectUri, 'Dirección de retorno')"
                    >
                        <Copy class="size-4" aria-hidden="true" />
                    </Button>
                </div>
            </div>
            <div class="space-y-1">
                <p class="font-medium">URL del webhook</p>
                <div class="flex items-center gap-2">
                    <code
                        class="min-w-0 flex-1 rounded-md bg-muted px-2 py-1 break-all"
                        >{{ webhookUrl }}</code
                    >
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        aria-label="Copiar URL del webhook"
                        @click="copy(webhookUrl, 'URL del webhook')"
                    >
                        <Copy class="size-4" aria-hidden="true" />
                    </Button>
                </div>
                <p class="text-muted-foreground">
                    Campos que se suscriben automáticamente:
                    {{ subscribedFields.join(', ') }}.
                </p>
            </div>
        </SurfaceCard>

        <div class="flex flex-wrap items-center justify-end gap-3">
            <p class="text-sm text-muted-foreground">
                Se abrirá {{ providerName }}; al terminar volverás aquí para
                elegir la cuenta.
            </p>
            <Button
                type="button"
                :disabled="!configured || processing"
                @click="start"
            >
                <Spinner v-if="processing" />
                Autorizar acceso
            </Button>
        </div>
    </div>
</template>
