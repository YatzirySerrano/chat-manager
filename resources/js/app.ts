import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeDataFreshness } from '@/lib/dataFreshness';
import { initializeFlashToast } from '@/lib/flashToast';
import { initializePwa } from '@/lib/pwa/register';
import { initializeRealtime } from '@/lib/realtime';
import { initializeThemePalette } from '@/lib/theme';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    withApp: (app) => {
        app.directive('focus', {
            mounted: (el: HTMLElement, shouldFocus) => {
                if (shouldFocus.value !== false) {
                    el.focus();
                }
            },
        });
    },
    progress: {
        color: 'var(--primary)',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();

// Keep lists, counters and permissions fresh after every mutation...
initializeDataFreshness();

// Apply the global palette chosen in Personalización...
initializeThemePalette();

// Connect to the real-time server (Reverb) when the server enables it...
initializeRealtime();

// Service worker (offline page, updates, install) for signed-in pages...
initializePwa();
