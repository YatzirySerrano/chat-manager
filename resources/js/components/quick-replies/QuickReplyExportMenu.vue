<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Download, FileSpreadsheet, FileText } from '@lucide/vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { generate } from '@/routes/exports';
import type { ExportFilters, ExportFormat } from '@/types';

/**
 * "Exportar" menu of the quick replies screen. It offers two reports with
 * the same columns:
 * - the general report: every reply the person may consult (corporate, their
 *   own and, when they supervise them, those of other users), whatever tab
 *   is open;
 * - the current view: the open tab with its filters, which the document
 *   states.
 * Like ExportMenu, the file is requested with a native POST form (CSRF
 * token) so the browser downloads it or the server redirects back when it
 * is prepared in the background.
 */
const props = defineProps<{
    /** Filters of the open tab (segment included). */
    filters: ExportFilters;
    /** Name of the open tab, e.g. "Mis respuestas". */
    currentLabel: string;
    /** Whether the open tab has filters besides the tab itself. */
    filtered: boolean;
}>();

const formats: {
    value: ExportFormat;
    label: string;
    icon: typeof FileSpreadsheet;
}[] = [
    { value: 'xlsx', label: 'Excel (.xlsx)', icon: FileSpreadsheet },
    { value: 'pdf', label: 'PDF', icon: FileText },
];

const page = usePage();

function hiddenInput(name: string, value: string | number): HTMLInputElement {
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = name;
    input.value = String(value);

    return input;
}

function exportAs(
    format: ExportFormat,
    label: string,
    filters: ExportFilters,
): void {
    const form = document.createElement('form');
    form.method = 'post';
    form.action = generate.url({ report: 'quick-replies', format });
    form.hidden = true;
    form.appendChild(hiddenInput('_token', page.props.csrfToken ?? ''));

    for (const [key, value] of Object.entries(filters)) {
        if (value === null || value === undefined || value === '') {
            continue;
        }

        for (const item of Array.isArray(value) ? value : [value]) {
            form.appendChild(
                hiddenInput(
                    Array.isArray(value) ? `${key}[]` : key,
                    typeof item === 'boolean' ? Number(item) : item,
                ),
            );
        }
    }

    toast.info(`Preparando ${label}…`, {
        description:
            'La descarga iniciará en unos segundos. Si el archivo es grande, lo encontrarás en Exportaciones.',
    });

    document.body.appendChild(form);
    form.submit();
    form.remove();
}
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button variant="outline">
                <Download />
                Exportar
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-72">
            <DropdownMenuGroup>
                <DropdownMenuLabel class="flex flex-col gap-0.5">
                    <span>Reporte general</span>
                    <span class="text-xs font-normal text-muted-foreground">
                        Todas las respuestas a las que tienes acceso
                    </span>
                </DropdownMenuLabel>
                <DropdownMenuItem
                    v-for="format in formats"
                    :key="`all-${format.value}`"
                    class="gap-3"
                    @select="
                        exportAs(format.value, format.label, { segment: 'all' })
                    "
                >
                    <component
                        :is="format.icon"
                        class="size-4 text-muted-foreground"
                        aria-hidden="true"
                    />
                    {{ format.label }}
                </DropdownMenuItem>
            </DropdownMenuGroup>
            <DropdownMenuSeparator />
            <DropdownMenuGroup>
                <DropdownMenuLabel class="flex flex-col gap-0.5">
                    <span>Solo la vista actual</span>
                    <span class="text-xs font-normal text-muted-foreground">
                        {{ currentLabel
                        }}{{
                            props.filtered ? ', con los filtros aplicados' : ''
                        }}
                    </span>
                </DropdownMenuLabel>
                <DropdownMenuItem
                    v-for="format in formats"
                    :key="`view-${format.value}`"
                    class="gap-3"
                    @select="exportAs(format.value, format.label, filters)"
                >
                    <component
                        :is="format.icon"
                        class="size-4 text-muted-foreground"
                        aria-hidden="true"
                    />
                    {{ format.label }}
                </DropdownMenuItem>
            </DropdownMenuGroup>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
