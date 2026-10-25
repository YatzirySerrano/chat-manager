import { computed, ref } from 'vue';

/**
 * "Instalar aplicación": Chromium browsers fire beforeinstallprompt, which is
 * kept to show the native prompt from a click. iOS/iPadOS has no such event;
 * there the person installs from Compartir → Agregar a inicio, so only the
 * instructions are offered. Firefox desktop does not install web apps from a
 * manifest: nothing is offered there.
 */

type BeforeInstallPromptEvent = Event & {
    prompt: () => Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
};

const deferredPrompt = ref<BeforeInstallPromptEvent | null>(null);
const installed = ref(false);
let listening = false;

function isStandalone(): boolean {
    if (typeof window === 'undefined') {
        return false;
    }

    return (
        window.matchMedia('(display-mode: standalone)').matches ||
        (navigator as Navigator & { standalone?: boolean }).standalone === true
    );
}

function isIos(): boolean {
    if (typeof navigator === 'undefined') {
        return false;
    }

    const ua = navigator.userAgent;

    // iPadOS reports itself as a Mac with touch support.
    return (
        /iPad|iPhone|iPod/.test(ua) ||
        (ua.includes('Macintosh') && navigator.maxTouchPoints > 1)
    );
}

export function listenForInstallPrompt(): void {
    if (listening || typeof window === 'undefined') {
        return;
    }

    listening = true;
    installed.value = isStandalone();

    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        deferredPrompt.value = event as BeforeInstallPromptEvent;
    });

    window.addEventListener('appinstalled', () => {
        deferredPrompt.value = null;
        installed.value = true;
    });
}

export function usePwaInstall() {
    const canPrompt = computed(
        () => !installed.value && deferredPrompt.value !== null,
    );
    const showIosInstructions = computed(
        () =>
            !installed.value && !canPrompt.value && isIos() && !isStandalone(),
    );

    async function install(): Promise<boolean> {
        const prompt = deferredPrompt.value;

        if (!prompt) {
            return false;
        }

        deferredPrompt.value = null;
        await prompt.prompt();
        const choice = await prompt.userChoice;

        return choice.outcome === 'accepted';
    }

    return { canPrompt, showIosInstructions, install };
}
