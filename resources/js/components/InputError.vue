<script setup lang="ts">
defineProps<{
    message?: string;
    /** Referenced by the control's aria-describedby. */
    id?: string;
}>();
</script>

<template>
    <div v-show="message" aria-live="polite">
        <p :id="id" class="text-sm text-red-600 dark:text-red-500">
            {{ message }}
        </p>
    </div>
</template>
