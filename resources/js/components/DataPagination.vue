<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import type { PaginationMeta } from '@/types';

const props = defineProps<{
    meta: PaginationMeta;
    noun?: string;
}>();

const previous = computed(() => props.meta.links[0]?.url ?? null);
const next = computed(
    () => props.meta.links[props.meta.links.length - 1]?.url ?? null,
);
const pages = computed(() => props.meta.links.slice(1, -1));
</script>

<template>
    <nav
        v-if="meta.total > 0"
        class="flex flex-col items-center justify-between gap-3 text-sm sm:flex-row"
        aria-label="Paginación"
    >
        <p class="text-muted-foreground">
            Mostrando {{ meta.from }}–{{ meta.to }} de {{ meta.total }}
            {{ noun ?? 'registros' }}
        </p>

        <div v-if="meta.last_page > 1" class="flex items-center gap-1">
            <Button
                variant="outline"
                size="icon-sm"
                :disabled="!previous"
                :as-child="!!previous"
                aria-label="Página anterior"
            >
                <Link v-if="previous" :href="previous" preserve-scroll>
                    <ChevronLeft />
                </Link>
                <ChevronLeft v-else />
            </Button>

            <template v-for="(link, index) in pages" :key="index">
                <span
                    v-if="!link.url"
                    class="px-2 text-muted-foreground"
                    aria-hidden="true"
                    >…</span
                >
                <Button
                    v-else
                    :variant="link.active ? 'secondary' : 'ghost'"
                    size="icon-sm"
                    as-child
                >
                    <Link
                        :href="link.url"
                        preserve-scroll
                        :aria-current="link.active ? 'page' : undefined"
                    >
                        {{ link.label }}
                    </Link>
                </Button>
            </template>

            <Button
                variant="outline"
                size="icon-sm"
                :disabled="!next"
                :as-child="!!next"
                aria-label="Página siguiente"
            >
                <Link v-if="next" :href="next" preserve-scroll>
                    <ChevronRight />
                </Link>
                <ChevronRight v-else />
            </Button>
        </div>
    </nav>
</template>
