<script setup lang="ts">
import { Check, Copy } from '@lucide/vue';
import { useClipboard } from '@vueuse/core';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';

/**
 * Embed snippet of a web chat widget, ready to paste before </body> of the
 * site. It only carries the public key (not a secret).
 */
const props = defineProps<{
    snippet: string;
    configured: boolean;
}>();

const { copy, copied, isSupported } = useClipboard({ legacy: true });

async function copySnippet(): Promise<void> {
    await copy(props.snippet);
    toast.success('Fragmento de código copiado.');
}
</script>

<template>
    <div class="grid gap-3">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <h3 class="text-sm font-medium">Código para insertar</h3>
                <p class="text-xs text-muted-foreground">
                    Pégalo antes de la etiqueta &lt;/body&gt; en cada página
                    donde quieras mostrar el chat.
                </p>
            </div>
            <Button
                v-if="isSupported"
                type="button"
                variant="outline"
                size="sm"
                class="shrink-0"
                @click="copySnippet"
            >
                <Check v-if="copied" />
                <Copy v-else />
                {{ copied ? 'Copiado' : 'Copiar' }}
            </Button>
        </div>
        <pre
            class="max-h-48 overflow-auto rounded-lg border bg-muted/50 p-3 text-xs leading-relaxed"
        ><code class="font-mono break-all whitespace-pre-wrap" data-testid="web-chat-snippet">{{ snippet }}</code></pre>
        <p
            v-if="!configured"
            class="rounded-lg bg-warning/10 p-3 text-xs"
            role="note"
        >
            El chat solo aparecerá en los dominios autorizados. Guarda al menos
            un dominio para que el código funcione.
        </p>
    </div>
</template>
