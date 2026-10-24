<script setup lang="ts">
import { reactive } from 'vue';

/**
 * Optional form before the first message (name and/or email, as the
 * connection configures it), with the privacy notice. When it is not
 * required the visitor may skip it.
 */
const props = defineProps<{
    fields: ('name' | 'email')[];
    required: boolean;
    privacyNotice: string | null;
    busy: boolean;
}>();

const emit = defineEmits<{
    submit: [profile: { name?: string; email?: string }];
}>();

const values = reactive({ name: '', email: '' });

function submit(skip = false): void {
    if (skip) {
        emit('submit', {});

        return;
    }

    const profile: { name?: string; email?: string } = {};

    props.fields.forEach((field) => {
        const value = values[field].trim();

        if (value !== '') {
            profile[field] = value;
        }
    });

    emit('submit', profile);
}
</script>

<template>
    <form
        class="grid grid-cols-1 gap-4 p-4"
        aria-labelledby="cm-prechat-title"
        @submit.prevent="submit()"
    >
        <p
            id="cm-prechat-title"
            class="text-[15px] text-gray-700 dark:text-gray-300"
        >
            Antes de empezar, cuéntanos cómo dirigirnos a ti.
        </p>
        <div v-if="fields.includes('name')" class="grid gap-1.5">
            <label
                for="cm-name"
                class="text-sm font-medium text-gray-900 dark:text-gray-100"
                >Nombre</label
            >
            <input
                id="cm-name"
                v-model="values.name"
                type="text"
                autocomplete="name"
                maxlength="80"
                :required="required"
                class="min-h-11 rounded-xl border border-gray-300 bg-white px-3 text-base text-gray-900 focus:border-(--cm-ring) focus:outline-2 focus:outline-(--cm-ring) dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
            />
        </div>
        <div v-if="fields.includes('email')" class="grid gap-1.5">
            <label
                for="cm-email"
                class="text-sm font-medium text-gray-900 dark:text-gray-100"
                >Correo electrónico</label
            >
            <input
                id="cm-email"
                v-model="values.email"
                type="email"
                autocomplete="email"
                maxlength="191"
                :required="required"
                class="min-h-11 rounded-xl border border-gray-300 bg-white px-3 text-base text-gray-900 focus:border-(--cm-ring) focus:outline-2 focus:outline-(--cm-ring) dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
            />
        </div>
        <p
            v-if="privacyNotice"
            class="text-xs leading-relaxed whitespace-pre-line text-gray-600 dark:text-gray-400"
        >
            {{ privacyNotice }}
        </p>
        <div class="flex flex-wrap gap-2">
            <button
                type="submit"
                :disabled="busy"
                class="min-h-11 rounded-xl bg-(--cm-accent) px-4 text-[15px] font-semibold text-(--cm-accent-fg) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--cm-ring) disabled:opacity-60"
            >
                Comenzar chat
            </button>
            <button
                v-if="!required"
                type="button"
                :disabled="busy"
                class="min-h-11 rounded-xl px-4 text-[15px] text-gray-700 hover:bg-gray-100 focus-visible:outline-2 focus-visible:outline-(--cm-ring) dark:text-gray-300 dark:hover:bg-gray-800"
                @click="submit(true)"
            >
                Omitir
            </button>
        </div>
    </form>
</template>
