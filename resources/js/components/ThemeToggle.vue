<script setup lang="ts">
import { Moon, Sun } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useAppearance } from '@/composables/useAppearance';

const { resolvedAppearance, updateAppearance } = useAppearance();

function toggle(): void {
    updateAppearance(resolvedAppearance.value === 'dark' ? 'light' : 'dark');
}
</script>

<template>
    <Tooltip>
        <TooltipTrigger as-child>
            <Button
                variant="ghost"
                size="icon"
                class="size-9 rounded-lg text-muted-foreground pointer-coarse:size-10"
                :aria-label="
                    resolvedAppearance === 'dark'
                        ? 'Cambiar a modo claro'
                        : 'Cambiar a modo oscuro'
                "
                @click="toggle"
            >
                <Sun v-if="resolvedAppearance === 'dark'" class="size-4" />
                <Moon v-else class="size-4" />
            </Button>
        </TooltipTrigger>
        <TooltipContent>
            {{ resolvedAppearance === 'dark' ? 'Modo claro' : 'Modo oscuro' }}
        </TooltipContent>
    </Tooltip>
</template>
