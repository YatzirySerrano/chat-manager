import type { Directive } from 'vue';
import type { ThemePaletteValue } from '@/types/admin';
import type { RealtimeConfig } from '@/lib/realtime/types';
import type { Auth, PasswordPolicy } from '@/types/auth';

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            platform: {
                name: string;
                timezone: string;
                palette: ThemePaletteValue;
            };
            auth: Auth;
            passwordPolicy: PasswordPolicy;
            realtime: RealtimeConfig;
            /** Unread internal chat messages (null without team-chat.use). */
            teamChatUnread: number | null;
            sidebarOpen: boolean;
            /** CSRF token for native form posts (export downloads). */
            csrfToken: string | null;
            [key: string]: unknown;
        };
    }
}

declare module 'vue' {
    interface GlobalDirectives {
        vFocus: Directive<HTMLElement, boolean | undefined>;
    }

    interface ComponentCustomProperties {
        $inertia: typeof Router;
        $page: Page;
        $headManager: ReturnType<typeof createHeadManager>;
    }
}
