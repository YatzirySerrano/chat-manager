<script setup lang="ts">
defineProps<{
    message?: string;
    /** Referenced by the control's aria-describedby. */
    id?: string;
}>();
</script>

<template>
    <div v-show="message" aria-live="polite">
        <p
            v-if="message"
            :id="id"
            class="animate-in text-sm text-destructive duration-(--duration-fast) fade-in-0"
        >
            {{ message }}
        </p>
    </div>
</template>
