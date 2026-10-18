import { router } from '@inertiajs/vue3';
import type { ThemePaletteValue } from '@/types';

/**
 * Apply one of the curated palettes defined in resources/css/app.css.
 */
export function applyPalette(palette: ThemePaletteValue): void {
    document.documentElement.dataset.palette = palette;
}

/**
 * Keep the global palette in sync with the server on every visit, so a
 * change made in Personalización reaches all modules without reloading.
 */
export function initializeThemePalette(): void {
    router.on('navigate', (event) => {
        const palette = event.detail.page.props.platform?.palette;

        if (palette) {
            applyPalette(palette);
        }
    });
}
