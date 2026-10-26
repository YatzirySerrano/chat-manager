<script setup lang="ts">
import { Link, router, useHttp } from '@inertiajs/vue3';
import { Download, FolderDown } from '@lucide/vue';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import {
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime, formatRelative } from '@/lib/format';
import { index as exportsIndex, recent } from '@/routes/exports';
import type { DataTransfer } from '@/types';

/**
 * «Archivos preparados recientemente» inside the «Exportar» menu of a
 * module: the person's own files of that report still being prepared or
 * still downloadable, so a large export can be found again without a
 * separate module. Mounted when the menu opens (the menu content is
 * rendered only while open), so the list is always fresh. The list is not
 * shown while it loads, when it fails or when there are no files; the link
 * to every prepared file («Ver todos tus archivos preparados») always is,
 * so failed or expired files can be found too.
 *
 * A file is opened with an Inertia visit to its download route: the server
 * answers with a full browser navigation that downloads it, or explains on
 * this page that it expired or may no longer be downloaded.
 */
const props = defineProps<{
    /** Report key registered in config/exports.php, e.g. "tags". */
    report: string;
}>();

const http = useHttp<Record<string, never>, { data: DataTransfer[] }>({});
const transfers = ref<DataTransfer[]>([]);

onMounted(async () => {
    try {
        const response = (await http.get(
            recent.url({ query: { report: props.report } }),
        )) as { data: DataTransfer[] };
        transfers.value = response.data;
    } catch {
        transfers.value = [];
    }
});

onBeforeUnmount(() => http.cancel());

/** Tells apart two files of the same report (their filters). */
function filtersText(transfer: DataTransfer): string {
    return transfer.filters.length > 0
        ? transfer.filters.join(' · ')
        : 'Sin filtros';
}

function download(transfer: DataTransfer): void {
    if (transfer.download_url) {
        router.visit(transfer.download_url);
    }
}
</script>

<template>
    <div :data-recent-exports="transfers.length > 0 ? '' : undefined">
        <DropdownMenuSeparator />
        <DropdownMenuLabel
            v-if="transfers.length > 0"
            class="text-xs text-muted-foreground"
        >
            Archivos preparados recientemente
        </DropdownMenuLabel>
        <template v-for="transfer in transfers" :key="transfer.id">
            <DropdownMenuItem
                v-if="transfer.download_url"
                class="items-start gap-3 py-2"
                @select="download(transfer)"
            >
                <Download
                    class="mt-0.5 size-4 text-muted-foreground"
                    aria-hidden="true"
                />
                <span class="flex min-w-0 flex-col">
                    <span class="font-medium">
                        Descargar {{ transfer.format.label }}
                    </span>
                    <span class="text-xs text-muted-foreground">
                        <time
                            :datetime="transfer.created_at ?? undefined"
                            :title="formatDateTime(transfer.created_at)"
                        >
                            {{ formatRelative(transfer.created_at) }}
                        </time>
                        <template v-if="transfer.expires_at">
                            · disponible hasta
                            {{ formatDateTime(transfer.expires_at) }}
                        </template>
                    </span>
                    <span
                        class="text-xs wrap-anywhere text-muted-foreground"
                        data-recent-export-filters
                    >
                        {{ filtersText(transfer) }}
                    </span>
                </span>
            </DropdownMenuItem>
            <DropdownMenuItem v-else disabled class="items-start gap-3 py-2">
                <Spinner class="mt-0.5 size-4" aria-hidden="true" />
                <span class="flex min-w-0 flex-col">
                    <span class="font-medium">
                        Preparando {{ transfer.format.label }}…
                    </span>
                    <span class="text-xs text-muted-foreground">
                        Te avisaremos cuando esté listo.
                    </span>
                    <span class="text-xs wrap-anywhere text-muted-foreground">
                        {{ filtersText(transfer) }}
                    </span>
                </span>
            </DropdownMenuItem>
        </template>
        <DropdownMenuItem as-child class="gap-3">
            <Link :href="exportsIndex()">
                <FolderDown
                    class="size-4 text-muted-foreground"
                    aria-hidden="true"
                />
                Ver todos tus archivos preparados
            </Link>
        </DropdownMenuItem>
    </div>
</template>
