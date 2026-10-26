<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Download, FileSpreadsheet, FileText } from '@lucide/vue';
import { computed } from 'vue';
import { toast } from 'vue-sonner';
import RecentExportsSection from '@/components/exports/RecentExportsSection.vue';
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
 * download it now or prepare it in the background (the person is notified
 * when it is ready; the menu also lists their recently prepared files).
 * Generating a file has effects (audit, background job), so it is a POST
 * with the CSRF token, sent as a native form so the browser downloads it.
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

const page = usePage();

const fields = computed(() => {
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

function hiddenInput(name: string, value: string | number): HTMLInputElement {
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = name;
    input.value = String(value);

    return input;
}

function exportAs(format: ExportFormat, label: string): void {
    const form = document.createElement('form');
    form.method = 'post';
    form.action = generate.url({ report: props.report, format });
    form.hidden = true;
    form.appendChild(hiddenInput('_token', page.props.csrfToken ?? ''));

    for (const [key, value] of Object.entries(fields.value)) {
        for (const item of Array.isArray(value) ? value : [value]) {
            form.appendChild(
                hiddenInput(Array.isArray(value) ? `${key}[]` : key, item),
            );
        }
    }

    toast.info(`Preparando ${label}…`, {
        description:
            'Si es pequeño, la descarga empezará en unos segundos. Si es grande, te avisaremos cuando esté listo; podrás descargarlo desde la notificación.',
    });

    // A regular form submission: the browser downloads the file, or the
    // server redirects back with a notice when the export was queued.
    document.body.appendChild(form);
    form.submit();
    form.remove();
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
            <RecentExportsSection :report="report" />
        </DropdownMenuContent>
    </DropdownMenu>
</template>
