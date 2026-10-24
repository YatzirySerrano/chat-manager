import { createApp } from 'vue';
import type { FrameConfig } from '../types';
import App from './App.vue';

/**
 * Entry of the widget frame (resources/views/widget/frame.blade.php): a
 * plain Vue 3 app, without Inertia, that reads the public configuration the
 * server embedded in #cm-config.
 */
const element = document.getElementById('cm-config');
const config = JSON.parse(element?.textContent ?? 'null') as FrameConfig | null;

if (config) {
    createApp(App, { config }).mount('#app');
}
