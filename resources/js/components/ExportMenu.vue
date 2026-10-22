<script setup lang="ts">
import { Download, FileSpreadsheet, FileText } from '@lucide/vue';
import { computed } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { generate } from '@/routes/exports';
import type { ExportFilters, ExportFormat } from '@/types';

/**
 * "Exportar" menu of a module list. The file is generated from the same
 * filters as the screen; the server validates them and decides whether to
 * download it now or prepare it in the background (Exportaciones page).
 */
const props = defineProps<{
    /** Report key registered in config/exports.php, e.g. "tags". */
    report: string;
    /** Current filters of the list. */
    filters?: ExportFilters;
    disabled?: boolean;
    /** Why exporting is not possible right now (shown as a tooltip). */
    disabledReason?: string;
    /** Button text (default "Exportar"). */
    label?: string;
    /** Title of the menu (default "Exportar con los filtros actuales"). */
    heading?: string;
}>();

const formats: {
    value: ExportFormat;
    label: string;
    hint: string;
    icon: typeof FileSpreadsheet;
}[] = [
    {
        value: 'xlsx',
        label: 'Excel (.xlsx)',
        hint: 'Tabla con filtros, totales y resumen',
        icon: FileSpreadsheet,
    },
    {
        value: 'pdf',
        label: 'PDF',
        hint: 'Documento listo para imprimir',
        icon: FileText,
    },
];

const query = computed(() => {
    const result: Record<string, string | number | (string | number)[]> = {};

    for (const [key, value] of Object.entries(props.filters ?? {})) {
        if (value === null || value === undefined || value === '') {
            continue;
        }

        if (Array.isArray(value)) {
            if (value.length > 0) {
                result[key] = value;
            }

            continue;
        }

        result[key] = typeof value === 'boolean' ? Number(value) : value;
    }

    return result;
});

function exportAs(format: ExportFormat, label: string): void {
    const url = generate.url(
        { report: props.report, format },
        { query: query.value },
    );

    toast.info(`Preparando ${label}…`, {
        description:
            'La descarga iniciará en unos segundos. Si el archivo es grande, lo encontrarás en Exportaciones.',
    });

    // A regular navigation: the browser downloads the file, or the server
    // redirects back with a notice when the export was queued.
    window.location.assign(url);
}
</script>

<template>
    <Tooltip v-if="disabled">
        <TooltipTrigger as-child>
            <span tabindex="0">
                <Button variant="outline" disabled>
                    <Download />
                    {{ label ?? 'Exportar' }}
                </Button>
            </span>
        </TooltipTrigger>
        <TooltipContent v-if="disabledReason">
            {{ disabledReason }}
        </TooltipContent>
    </Tooltip>

    <DropdownMenu v-else>
        <DropdownMenuTrigger as-child>
            <Button variant="outline">
                <Download />
                {{ label ?? 'Exportar' }}
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-64">
            <DropdownMenuLabel class="text-xs text-muted-foreground">
                {{ heading ?? 'Exportar con los filtros actuales' }}
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuItem
                v-for="format in formats"
                :key="format.value"
                class="items-start gap-3 py-2"
                @select="exportAs(format.value, format.label)"
            >
                <component
                    :is="format.icon"
                    class="mt-0.5 size-4 text-muted-foreground"
                    aria-hidden="true"
                />
                <span class="flex flex-col">
                    <span class="font-medium">{{ format.label }}</span>
                    <span class="text-xs text-muted-foreground">
                        {{ format.hint }}
                    </span>
                </span>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
