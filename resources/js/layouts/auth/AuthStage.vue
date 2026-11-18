<script setup lang="ts">
import {
    CheckCheck,
    CircleCheckBig,
    MessageCircle,
    UserRoundCheck,
} from '@lucide/vue';

/**
 * Stage of the sign-in screens: what the platform does, shown instead of
 * described. A sample conversation arrives, is assigned and gets answered,
 * once, as the page loads; afterwards it stays still. That sequence is the
 * only motion of the screen (none under reduced motion).
 *
 * Every text here is an illustration (no real customer or metric). The
 * block is an ink surface ([data-ink], app.css), tinted by the palette and
 * ruled like the pages of a service log.
 */
defineProps<{ platform: string }>();
</script>

<template>
    <aside
        data-ink
        aria-hidden="true"
        class="relative hidden min-w-0 flex-col justify-between overflow-hidden bg-sidebar p-12 text-sidebar-foreground lg:flex xl:p-16"
    >
        <div class="auth-rules pointer-events-none absolute inset-0" />

        <p
            class="relative max-w-[16ch] font-display text-5xl leading-[1.05] font-semibold tracking-[-0.025em] text-foreground xl:text-6xl"
        >
            Cada conversación, atendida a tiempo.
        </p>

        <div class="relative flex w-full max-w-xl flex-col gap-3">
            <div class="auth-step flex items-end gap-3" style="--step: 0">
                <span
                    class="grid size-9 shrink-0 place-items-center rounded-full bg-sidebar-accent text-sm font-semibold text-foreground ring-1 ring-sidebar-border"
                    >MR</span
                >
                <div
                    class="max-w-[28rem] rounded-bubble rounded-bl-sm bg-sidebar-accent px-4 py-3 text-foreground ring-1 ring-sidebar-border"
                >
                    <p
                        class="flex items-center gap-1.5 text-xs font-medium text-muted-foreground"
                    >
                        María Robles
                        <MessageCircle class="ml-1 size-3" />
                        WhatsApp
                    </p>
                    <p class="mt-0.5 text-base">
                        Hola, ¿me pueden ayudar con mi solicitud?
                    </p>
                </div>
            </div>

            <p
                class="auth-step flex items-center gap-2 pl-12 text-sm text-muted-foreground"
                style="--step: 1"
            >
                <UserRoundCheck class="size-4 text-primary" />
                Asignada a Ana, de Atención a clientes, en 8 segundos
            </p>

            <div class="auth-typing flex justify-end" style="--step: 2">
                <span
                    class="flex h-9 items-center gap-1 rounded-bubble rounded-br-sm bg-sidebar-accent px-4"
                >
                    <i class="auth-dot" />
                    <i class="auth-dot [animation-delay:120ms]" />
                    <i class="auth-dot [animation-delay:240ms]" />
                </span>
            </div>

            <div class="auth-step flex justify-end" style="--step: 3.2">
                <div
                    class="max-w-[28rem] rounded-bubble rounded-br-sm bg-primary px-4 py-3 text-primary-foreground"
                >
                    <p class="text-base">
                        Hola, María. Claro que sí: ya tengo tu solicitud a la
                        vista.
                    </p>
                    <p
                        class="mt-1 flex items-center justify-end gap-1 text-xs tabular-nums opacity-75"
                    >
                        10:42
                        <CheckCheck class="size-3.5" />
                    </p>
                </div>
            </div>

            <p
                class="auth-step flex items-center gap-2 pl-12 text-sm text-muted-foreground"
                style="--step: 4.4"
            >
                <CircleCheckBig class="size-4 text-primary" />
                Resuelta en 4 minutos, dentro del objetivo de servicio
            </p>
        </div>

        <p class="relative text-sm text-muted-foreground">
            {{ platform }}, centro de atención
        </p>
    </aside>
</template>

<style scoped>
/* Ruled lines of a service log, fading in from the left edge. */
.auth-rules {
    background-image: repeating-linear-gradient(
        to bottom,
        transparent 0 55px,
        color-mix(in oklch, var(--sidebar-border) 80%, transparent) 55px 56px
    );
    mask-image: linear-gradient(to right, transparent, #000 40%);
}

.auth-step {
    animation: auth-in 260ms var(--ease-out-quint) backwards;
    animation-delay: calc(400ms + var(--step) * 560ms);
}

/* The typing bubble shows between the assignment and the reply… */
.auth-typing {
    height: 0;
    opacity: 0;
    animation: auth-typing 900ms var(--ease-standard) both;
    animation-delay: calc(400ms + var(--step) * 560ms);
}

.auth-dot {
    display: block;
    width: 6px;
    height: 6px;
    border-radius: 9999px;
    background: var(--muted-foreground);
    animation: auth-blink 900ms ease-in-out infinite;
}

@keyframes auth-in {
    from {
        opacity: 0;
        transform: translateY(6px);
    }
}

@keyframes auth-typing {
    0% {
        height: 0;
        opacity: 0;
    }

    15%,
    85% {
        height: 2.25rem;
        opacity: 1;
    }

    100% {
        height: 0;
        opacity: 0;
    }
}

@keyframes auth-blink {
    0%,
    60%,
    100% {
        opacity: 0.35;
    }

    30% {
        opacity: 1;
    }
}
</style>
