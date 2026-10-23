<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import {
    Archive,
    ArchiveRestore,
    LogOut,
    MoreHorizontal,
    ShieldCheck,
    ShieldOff,
    UserMinus,
    UserPlus,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import FormField from '@/components/FormField.vue';
import InputError from '@/components/InputError.vue';
import PeoplePicker from '@/components/team-chat/PeoplePicker.vue';
import TeamAvatar from '@/components/team-chat/TeamAvatar.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { useFormValidation } from '@/composables/useFormValidation';
import { useTeamPresence } from '@/composables/useTeamPresence';
import { formatDate } from '@/lib/format';
import { maxLength, minLength, normalizers, required } from '@/lib/validation';
import {
    archive,
    leave,
    mute,
    unarchive,
    update,
} from '@/routes/team-chat/threads';
import {
    destroy as removeParticipant,
    store as addParticipants,
    update as changeRole,
} from '@/routes/team-chat/threads/participants';
import type { TeamParticipant, TeamThreadDetail } from '@/types/team-chat';

/**
 * Details of the open chat: participants and, for group administrators,
 * renaming, membership, administrators and archiving. Every change is
 * visible to the participants as a line in the chat and is audited.
 */
const props = defineProps<{
    thread: TeamThreadDetail;
}>();

const open = defineModel<boolean>('open', { required: true });
const { isOnline } = useTeamPresence();

type PendingAction =
    | { kind: 'remove'; participant: TeamParticipant }
    | { kind: 'archive' }
    | { kind: 'leave' };

const pending = ref<PendingAction | null>(null);
const processing = ref(false);
const adding = ref(false);
const toAdd = ref<number[]>([]);
const addError = ref<string | undefined>();
const roleError = ref<string | undefined>();

const form = useForm<{ name: string; description: string }>({
    name: '',
    description: '',
});

const validation = useFormValidation(form, {
    rules: {
        name: [
            required('Escribe el nombre del grupo.'),
            minLength(2),
            maxLength(80),
        ],
        description: [maxLength(500)],
    },
    normalize: { name: normalizers.squish },
    requireChanges: true,
});

watch(
    () => [open.value, props.thread.name, props.thread.description] as const,
    ([isOpen]) => {
        if (isOpen) {
            validation.load({
                name: props.thread.name ?? '',
                description: props.thread.description ?? '',
            });
            adding.value = false;
            toAdd.value = [];
        }
    },
    { immediate: true },
);

const admins = computed(() =>
    props.thread.participants.filter(
        (participant) => participant.role === 'admin',
    ),
);
const isGroup = computed(() => props.thread.type === 'group');

const pendingCopy = computed(() => {
    const action = pending.value;

    if (!action) {
        return { title: '', description: '', confirm: '' };
    }

    if (action.kind === 'remove') {
        return {
            title: `¿Retirar a ${action.participant.name} del grupo?`,
            description:
                'Dejará de ver el grupo, su historial y sus archivos de inmediato. Podrás volver a agregarlo después.',
            confirm: 'Retirar del grupo',
        };
    }

    if (action.kind === 'archive') {
        return {
            title: '¿Archivar el grupo?',
            description:
                'Nadie podrá enviar mensajes. Los participantes conservarán el historial y un administrador podrá reactivarlo.',
            confirm: 'Archivar grupo',
        };
    }

    return {
        title: '¿Salir del grupo?',
        description:
            'Dejarás de ver el grupo, su historial y sus archivos. Solo podrás volver si un administrador te agrega de nuevo.',
        confirm: 'Salir del grupo',
    };
});

function saveDetails(): void {
    if (!validation.validate()) {
        return;
    }

    form.submit(update(props.thread.id), {
        preserveScroll: true,
        onSuccess: () => validation.commit(),
    });
}

function setMuted(muted: boolean): void {
    router.put(
        mute.url(props.thread.id),
        { muted },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['activeThread', 'threads', 'unreadTotal', 'teamChatUnread'],
        },
    );
}

function setRole(participant: TeamParticipant, role: 'admin' | 'member'): void {
    roleError.value = undefined;
    router.put(
        changeRole.url({ thread: props.thread.id, user: participant.id }),
        { role },
        {
            preserveScroll: true,
            preserveState: true,
            onError: (errors) => (roleError.value = errors.role),
        },
    );
}

function submitAdd(): void {
    addError.value = undefined;
    router.post(
        addParticipants.url(props.thread.id),
        { user_ids: toAdd.value },
        {
            preserveScroll: true,
            preserveState: true,
            onStart: () => (processing.value = true),
            onFinish: () => (processing.value = false),
            onSuccess: () => {
                adding.value = false;
                toAdd.value = [];
            },
            onError: (errors) =>
                (addError.value = errors.user_ids ?? Object.values(errors)[0]),
        },
    );
}

function confirmPending(): void {
    const action = pending.value;

    if (!action) {
        return;
    }

    const options = {
        preserveScroll: true,
        onStart: () => (processing.value = true),
        onFinish: () => (processing.value = false),
        onSuccess: () => {
            pending.value = null;

            if (action.kind === 'leave') {
                open.value = false;
            }
        },
        onError: (errors: Record<string, string>) => {
            pending.value = null;
            roleError.value = Object.values(errors)[0];
        },
    };

    if (action.kind === 'remove') {
        router.delete(
            removeParticipant.url({
                thread: props.thread.id,
                user: action.participant.id,
            }),
            options,
        );
    } else if (action.kind === 'archive') {
        router.post(archive.url(props.thread.id), {}, options);
    } else {
        router.post(leave.url(props.thread.id), {}, options);
    }
}

function restoreGroup(): void {
    router.post(unarchive.url(props.thread.id), {}, { preserveScroll: true });
}
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent
            class="flex w-full flex-col gap-0 overflow-y-auto p-0 sm:max-w-md"
        >
            <SheetHeader class="border-b p-4">
                <SheetTitle>{{
                    isGroup ? 'Ajustes del grupo' : 'Información del chat'
                }}</SheetTitle>
                <SheetDescription>
                    {{
                        isGroup
                            ? 'Solo los participantes ven este grupo y sus mensajes.'
                            : 'Chat privado: solo ustedes dos ven estos mensajes.'
                    }}
                </SheetDescription>
            </SheetHeader>

            <div class="grid gap-6 p-4">
                <section
                    v-if="isGroup"
                    class="grid gap-3"
                    aria-label="Datos del grupo"
                >
                    <form
                        v-if="thread.can.manage"
                        class="grid gap-3"
                        @submit.prevent="saveDetails"
                    >
                        <FormField
                            id="team-settings-name"
                            label="Nombre"
                            :error="validation.error('name')"
                        >
                            <Input
                                id="team-settings-name"
                                v-model="form.name"
                                maxlength="80"
                                v-bind="
                                    validation.field(
                                        'name',
                                        'team-settings-name',
                                    )
                                "
                            />
                        </FormField>
                        <FormField
                            id="team-settings-description"
                            label="Descripción (opcional)"
                            :error="validation.error('description')"
                        >
                            <Textarea
                                id="team-settings-description"
                                v-model="form.description"
                                rows="2"
                                maxlength="500"
                                v-bind="
                                    validation.field(
                                        'description',
                                        'team-settings-description',
                                    )
                                "
                            />
                        </FormField>
                        <div class="flex justify-end">
                            <Button
                                type="submit"
                                size="sm"
                                :disabled="
                                    form.processing || !validation.canSubmit
                                "
                            >
                                Guardar cambios
                            </Button>
                        </div>
                    </form>
                    <div v-else class="grid gap-1">
                        <p class="text-sm font-semibold">{{ thread.name }}</p>
                        <p
                            v-if="thread.description"
                            class="text-sm whitespace-pre-wrap text-muted-foreground"
                        >
                            {{ thread.description }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Creado el {{ formatDate(thread.created_at) }}
                        </p>
                    </div>
                </section>

                <section
                    class="flex items-center justify-between gap-3 rounded-lg border p-3"
                >
                    <div class="grid gap-0.5">
                        <Label for="team-settings-mute"
                            >Silenciar este chat</Label
                        >
                        <p class="text-xs text-muted-foreground">
                            Sus mensajes no sumarán al contador del menú
                            lateral.
                        </p>
                    </div>
                    <Switch
                        id="team-settings-mute"
                        :model-value="thread.me.muted"
                        @update:model-value="
                            (value: boolean) => setMuted(value)
                        "
                    />
                </section>

                <section class="grid gap-2" aria-label="Participantes">
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="text-sm font-semibold">
                            Participantes ({{ thread.participants.length }})
                        </h3>
                        <Button
                            v-if="thread.can.manage && !adding"
                            size="sm"
                            variant="outline"
                            @click="adding = true"
                        >
                            <UserPlus /> Agregar
                        </Button>
                    </div>

                    <div v-if="adding" class="grid gap-2 rounded-lg border p-3">
                        <PeoplePicker
                            v-model:selected="toAdd"
                            multiple
                            :exclude-thread-id="thread.id"
                        />
                        <InputError :message="addError" />
                        <div class="flex justify-end gap-2">
                            <Button
                                size="sm"
                                variant="secondary"
                                @click="adding = false"
                                >Cancelar</Button
                            >
                            <Button
                                size="sm"
                                :disabled="toAdd.length === 0 || processing"
                                @click="submitAdd"
                            >
                                Agregar
                                {{
                                    toAdd.length > 0 ? `(${toAdd.length})` : ''
                                }}
                            </Button>
                        </div>
                    </div>

                    <InputError :message="roleError" />

                    <ul class="divide-y rounded-lg border">
                        <li
                            v-for="participant in thread.participants"
                            :key="participant.id"
                            class="flex items-center gap-3 px-3 py-2"
                        >
                            <TeamAvatar
                                :name="participant.name"
                                :deleted="participant.deleted"
                                :availability="participant.availability"
                                :online="isOnline(participant.id)"
                                size="sm"
                            />
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium">
                                    {{ participant.name }}
                                    <span
                                        v-if="participant.is_me"
                                        class="text-muted-foreground"
                                        >(tú)</span
                                    >
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{
                                        participant.deleted
                                            ? 'Cuenta eliminada'
                                            : participant.availability ===
                                                'available'
                                              ? 'Disponible'
                                              : 'No disponible'
                                    }}<template
                                        v-if="
                                            !participant.deleted &&
                                            isOnline(participant.id)
                                        "
                                    >
                                        · En línea</template
                                    >
                                </p>
                            </div>
                            <Badge
                                v-if="isGroup && participant.role === 'admin'"
                                variant="secondary"
                            >
                                Administrador
                            </Badge>
                            <DropdownMenu v-if="thread.can.manage && isGroup">
                                <DropdownMenuTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="size-8"
                                        :aria-label="`Acciones para ${participant.name}`"
                                        :title="`Acciones para ${participant.name}`"
                                    >
                                        <MoreHorizontal class="size-4" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem
                                        v-if="
                                            participant.role === 'member' &&
                                            !participant.deleted
                                        "
                                        @select="setRole(participant, 'admin')"
                                    >
                                        <ShieldCheck /> Nombrar administrador
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        v-if="participant.role === 'admin'"
                                        :disabled="admins.length <= 1"
                                        @select="setRole(participant, 'member')"
                                    >
                                        <ShieldOff /> Quitar como administrador
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        v-if="!participant.is_me"
                                        class="text-destructive focus:text-destructive"
                                        @select="
                                            pending = {
                                                kind: 'remove',
                                                participant,
                                            }
                                        "
                                    >
                                        <UserMinus /> Retirar del grupo
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </li>
                    </ul>
                </section>

                <section
                    v-if="isGroup"
                    class="grid gap-2 border-t pt-4"
                    aria-label="Acciones del grupo"
                >
                    <Button
                        v-if="thread.can.archive"
                        variant="outline"
                        class="justify-start"
                        @click="pending = { kind: 'archive' }"
                    >
                        <Archive /> Archivar grupo
                    </Button>
                    <Button
                        v-if="thread.can.unarchive"
                        variant="outline"
                        class="justify-start"
                        @click="restoreGroup"
                    >
                        <ArchiveRestore /> Reactivar grupo
                    </Button>
                    <Button
                        v-if="thread.can.leave"
                        variant="outline"
                        class="justify-start text-destructive hover:text-destructive"
                        @click="pending = { kind: 'leave' }"
                    >
                        <LogOut /> Salir del grupo
                    </Button>
                </section>
            </div>

            <ConfirmDialog
                :open="pending !== null"
                :title="pendingCopy.title"
                :description="pendingCopy.description"
                :confirm-label="pendingCopy.confirm"
                destructive
                :processing="processing"
                @update:open="
                    (value) => {
                        if (!value) pending = null;
                    }
                "
                @confirm="confirmPending"
            />
        </SheetContent>
    </Sheet>
</template>
