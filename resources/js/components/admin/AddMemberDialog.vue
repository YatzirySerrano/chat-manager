<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Search, UserPlus } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import RoleBadge from '@/components/RoleBadge.vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { getInitials } from '@/composables/useInitials';
import { store as storeMember } from '@/routes/departments/members';
import type { DepartmentSummary, MemberCandidate } from '@/types';

const props = defineProps<{
    department: DepartmentSummary;
    candidates: MemberCandidate[];
}>();

const open = defineModel<boolean>('open', { required: true });

const search = ref('');
const addingUserId = ref<number | null>(null);

watch(open, (isOpen) => {
    if (isOpen) {
        search.value = '';
    }
});

const filteredCandidates = computed(() => {
    const term = search.value.trim().toLocaleLowerCase('es');

    if (term === '') {
        return props.candidates;
    }

    return props.candidates.filter(
        (candidate) =>
            candidate.name.toLocaleLowerCase('es').includes(term) ||
            candidate.email.toLocaleLowerCase('es').includes(term),
    );
});

function addMember(candidate: MemberCandidate): void {
    router.visit(storeMember(props.department.id), {
        data: { user_id: candidate.id },
        preserveScroll: true,
        preserveState: true,
        onStart: () => (addingUserId.value = candidate.id),
        onFinish: () => (addingUserId.value = null),
        onError: (errors) => {
            const message = Object.values(errors)[0];

            if (message) {
                toast.error(message);
            }
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="gap-0 p-0 sm:max-w-lg">
            <DialogHeader class="border-b p-5">
                <DialogTitle>Agregar miembros</DialogTitle>
                <DialogDescription>
                    Usuarios activos que aún no pertenecen a
                    {{ department.name }}.
                </DialogDescription>
            </DialogHeader>

            <div class="border-b p-3">
                <div class="relative">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <Input
                        v-model="search"
                        type="search"
                        class="pl-9"
                        placeholder="Buscar por nombre o correo"
                        aria-label="Buscar usuarios"
                    />
                </div>
            </div>

            <ul
                v-if="filteredCandidates.length > 0"
                class="max-h-80 divide-y overflow-y-auto"
            >
                <li
                    v-for="candidate in filteredCandidates"
                    :key="candidate.id"
                    class="flex items-center gap-3 px-5 py-3"
                >
                    <Avatar class="size-8 rounded-md">
                        <AvatarFallback class="rounded-md text-xs">
                            {{ getInitials(candidate.name) }}
                        </AvatarFallback>
                    </Avatar>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium">
                            {{ candidate.name }}
                        </p>
                        <p class="truncate text-xs text-muted-foreground">
                            {{ candidate.email }}
                        </p>
                    </div>
                    <RoleBadge :role="candidate.role" />
                    <Button
                        size="sm"
                        variant="outline"
                        :disabled="addingUserId !== null"
                        :aria-label="`Agregar a ${candidate.name}`"
                        @click="addMember(candidate)"
                    >
                        <Spinner v-if="addingUserId === candidate.id" />
                        <UserPlus v-else />
                        Agregar
                    </Button>
                </li>
            </ul>
            <p
                v-else
                class="px-5 py-10 text-center text-sm text-muted-foreground"
            >
                {{
                    candidates.length === 0
                        ? 'No hay usuarios disponibles para agregar.'
                        : 'Ningún usuario coincide con la búsqueda.'
                }}
            </p>
        </DialogContent>
    </Dialog>
</template>
