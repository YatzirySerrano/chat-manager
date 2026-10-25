<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Download, LogOut, Settings } from '@lucide/vue';
import { toast } from 'vue-sonner';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import UserInfo from '@/components/UserInfo.vue';
import { usePwaInstall } from '@/lib/pwa/install';
import { edit } from '@/routes/profile';
import type { User } from '@/types';

type Props = {
    user: User;
};

defineProps<Props>();

/**
 * Logging out is confirmed by the parent, which renders the dialog outside the
 * dropdown so it survives the menu closing.
 */
const emit = defineEmits<{
    logout: [];
}>();

const { canPrompt, showIosInstructions, install } = usePwaInstall();

async function installApp(): Promise<void> {
    if (canPrompt.value) {
        await install();

        return;
    }

    toast('Instalar en este dispositivo', {
        description:
            'En Safari toca Compartir y luego «Agregar a inicio». La aplicación aparecerá en tu pantalla de inicio.',
        duration: 12000,
    });
}
</script>

<template>
    <DropdownMenuLabel class="p-0 font-normal">
        <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
            <UserInfo :user="user" :show-email="true" />
        </div>
    </DropdownMenuLabel>
    <DropdownMenuSeparator />
    <DropdownMenuGroup>
        <DropdownMenuItem :as-child="true">
            <Link class="block w-full cursor-pointer" :href="edit()" prefetch>
                <Settings class="mr-2 h-4 w-4" />
                Mi cuenta
            </Link>
        </DropdownMenuItem>
        <DropdownMenuItem
            v-if="canPrompt || showIosInstructions"
            class="cursor-pointer"
            @select="installApp"
        >
            <Download class="mr-2 h-4 w-4" />
            Instalar aplicación
        </DropdownMenuItem>
    </DropdownMenuGroup>
    <DropdownMenuSeparator />
    <DropdownMenuItem
        class="cursor-pointer"
        data-test="logout-button"
        @select="emit('logout')"
    >
        <LogOut class="mr-2 h-4 w-4" />
        Cerrar sesión
    </DropdownMenuItem>
</template>
