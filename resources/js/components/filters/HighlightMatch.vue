<script setup lang="ts">
import { computed } from 'vue';
import './filters.css';

/**
 * Text with the searched words marked, so a person scanning a filtered list
 * sees at once why each row is there. Case and accents are ignored («jose»
 * marks «José»); a phone typed with spaces or dashes is not matched here
 * (the server found it anyway). Renders plain text, never HTML.
 */
const props = defineProps<{
    text: string;
    query: string;
}>();

/* One character without accents and in lower case, same length. */
const fold = (character: string): string =>
    character
        .normalize('NFD')
        .replace(/\p{M}/gu, '')
        .toLocaleLowerCase('es')
        .charAt(0) || character;

const parts = computed<{ text: string; match: boolean }[]>(() => {
    const words = props.query
        .trim()
        .split(/\s+/)
        .map((word) => [...word].map(fold).join(''))
        .filter((word) => word.length >= 2);
    const characters = [...props.text];

    if (words.length === 0 || characters.length === 0) {
        return [{ text: props.text, match: false }];
    }

    const folded = characters.map(fold).join('');
    const marked = characters.map(() => false);

    for (const word of words) {
        let from = folded.indexOf(word);

        while (from !== -1) {
            marked.fill(true, from, from + word.length);
            from = folded.indexOf(word, from + word.length);
        }
    }

    const result: { text: string; match: boolean }[] = [];

    characters.forEach((character, index) => {
        const last = result[result.length - 1];

        if (last && last.match === marked[index]) {
            last.text += character;
        } else {
            result.push({ text: character, match: marked[index] });
        }
    });

    return result;
});
</script>

<template>
    <template v-for="(part, index) in parts" :key="index">
        <mark v-if="part.match" class="filter-mark">{{ part.text }}</mark>
        <template v-else>{{ part.text }}</template>
    </template>
</template>
