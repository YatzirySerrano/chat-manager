<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';

defineProps<{
    title: string;
    description?: string;
    confirmLabel: string;
    destructive?: boolean;
    processing?: boolean;
}>();

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{
    confirm: [];
}>();
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription v-if="description">
                    {{ description }}
                </DialogDescription>
            </DialogHeader>
            <slot />
            <DialogFooter class="gap-2">
                <Button
                    variant="outline"
                    :disabled="processing"
                    @click="open = false"
                >
                    Cancelar
                </Button>
                <Button
                    :variant="destructive ? 'destructive' : 'default'"
                    :disabled="processing"
                    @click="emit('confirm')"
                >
                    <Spinner v-if="processing" />
                    {{ confirmLabel }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
