import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';

/**
 * Feature flags shared by HandleInertiaRequests (config/features.php). They
 * only adapt the interface: the server refuses or masks what is off.
 */
export function useFeatures(): { phaseTwo: ComputedRef<boolean> } {
    const page = usePage();

    return {
        phaseTwo: computed(() => page.props.features?.phaseTwo === true),
    };
}
