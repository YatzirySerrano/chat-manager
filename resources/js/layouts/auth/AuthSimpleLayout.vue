<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { LockKeyhole, MessagesSquare } from '@lucide/vue';
import { computed } from 'vue';
import { home } from '@/routes';
import AuthStage from './AuthStage.vue';

defineProps<{
    title?: string;
    description?: string;
}>();

const page = usePage();
const platform = computed(
    () => page.props.platform?.name ?? page.props.name ?? 'Chat Manager',
);
</script>

<template>
    <!--
      Sign-in screens: the form sits on the left edge, in a column of its
      own, and the stage fills the rest of the window. On phones and
      tablets only the form is shown.
    -->
    <div
        class="grid min-h-svh bg-card lg:grid-cols-[minmax(26rem,34rem)_minmax(0,1fr)]"
    >
        <main
            class="flex min-w-0 flex-col px-6 py-7 sm:px-10 lg:px-14 lg:py-10"
        >
            <Link
                :href="home()"
                class="flex w-fit items-center gap-2.5 rounded-md"
            >
                <span
                    class="grid size-9 place-items-center rounded-lg bg-primary text-primary-foreground"
                >
                    <MessagesSquare class="size-5" aria-hidden="true" />
                </span>
                <span class="font-display text-lg font-semibold">{{
                    platform
                }}</span>
            </Link>

            <div class="my-auto w-full max-w-sm py-12">
                <div class="mb-8 space-y-2">
                    <h1
                        class="font-display text-3xl leading-tight font-semibold"
                    >
                        {{ title }}
                    </h1>
                    <p
                        v-if="description"
                        class="text-pretty text-muted-foreground"
                    >
                        {{ description }}
                    </p>
                </div>
                <slot />
            </div>

            <p class="flex items-center gap-1.5 text-xs text-muted-foreground">
                <LockKeyhole class="size-3.5" aria-hidden="true" />
                Acceso solo para personal autorizado.
            </p>
        </main>

        <AuthStage :platform="platform" />
    </div>
</template>
