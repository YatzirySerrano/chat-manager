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
 * once, as the page loads; afterwards it stays still.
 *
 * Every text here is an illustration (no real customer or metric). The
 * block is an ink surface ([data-ink], app.css), tinted by the palette.
 */
defineProps<{ platform: string }>();
</script>

<template>
    <aside
        data-ink
        aria-hidden="true"
        class="auth-stage relative hidden min-w-0 flex-col justify-between overflow-hidden bg-sidebar p-12 text-sidebar-foreground lg:flex xl:p-16"
    >
        <!-- Ruled lines of a control desk: a quiet structure, no decoration. -->
        <div class="auth-rules pointer-events-none absolute inset-0" />

        <p
            class="relative max-w-[18ch] font-display text-5xl leading-[1.05] font-semibold text-foreground xl:text-6xl"
        >
            Cada conversación, atendida a tiempo.
        </p>

        <div class="relative flex w-full max-w-2xl flex-col gap-3.5">
            <div class="auth-step flex items-end gap-3" style="--step: 0">
                <span
                    class="grid size-9 shrink-0 place-items-center rounded-full bg-sidebar-accent text-sm font-semibold text-foreground"
                    >MR</span
                >
                <div
                    class="max-w-[30rem] rounded-2xl rounded-bl-md bg-sidebar-accent px-4 py-3 text-foreground"
                >
                    <p class="text-xs font-medium text-muted-foreground">
                        María Robles
                        <span class="ml-1.5 inline-flex items-center gap-1">
                            <MessageCircle class="size-3" />
                            WhatsApp
                        </span>
                    </p>
                    <p class="mt-0.5 text-lg">
                        Hola, ¿me pueden ayudar con mi solicitud?
                    </p>
                </div>
            </div>

            <div
                class="auth-step flex items-center gap-2 pl-12 text-sm text-muted-foreground"
                style="--step: 1"
            >
                <UserRoundCheck class="size-4 text-primary" />
                Asignada a Ana, de Atención a clientes, en 8 segundos
            </div>

            <div class="auth-typing flex justify-end" style="--step: 2">
                <span
                    class="flex h-9 items-center gap-1 rounded-2xl rounded-br-md bg-primary/25 px-4"
                >
                    <i class="auth-dot" />
                    <i class="auth-dot [animation-delay:120ms]" />
                    <i class="auth-dot [animation-delay:240ms]" />
                </span>
            </div>

            <div
                class="auth-step auth-reply flex justify-end"
                style="--step: 3.4"
            >
                <div
                    class="max-w-[30rem] rounded-2xl rounded-br-md bg-primary px-4 py-3 text-primary-foreground"
                >
                    <p class="text-lg">
                        Hola, María. Claro que sí: ya tengo tu solicitud a la
                        vista.
                    </p>
                    <p
                        class="mt-1 flex items-center justify-end gap-1 text-xs opacity-80"
                    >
                        10:42
                        <CheckCheck class="size-3.5" />
                    </p>
                </div>
            </div>

            <div
                class="auth-step flex items-center gap-2 pl-12 text-sm text-muted-foreground"
                style="--step: 4.8"
            >
                <CircleCheckBig class="size-4 text-primary" />
                Resuelta en 4 minutos, dentro del objetivo de servicio
            </div>
        </div>

        <p class="relative text-sm text-muted-foreground">
            {{ platform }}, centro de atención
        </p>
    </aside>
</template>

<style scoped>
.auth-rules {
    background-image: repeating-linear-gradient(
        to bottom,
        transparent 0 71px,
        color-mix(in oklch, var(--sidebar-border) 70%, transparent) 71px 72px
    );
    mask-image: linear-gradient(to right, transparent, #000 35%);
}

.auth-step {
    animation: auth-in 520ms var(--ease-out-quint) backwards;
    animation-delay: calc(500ms + var(--step) * 650ms);
}

/* The typing bubble shows between the assignment and the reply… */
.auth-typing {
    height: 0;
    opacity: 0;
    animation: auth-typing 1000ms var(--ease-standard) both;
    animation-delay: calc(500ms + var(--step) * 650ms);
}

.auth-dot {
    display: block;
    width: 6px;
    height: 6px;
    border-radius: 9999px;
    background: var(--primary);
    animation: auth-bounce 900ms ease-in-out infinite;
}

@keyframes auth-in {
    from {
        opacity: 0;
        transform: translateY(14px) scale(0.98);
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

@keyframes auth-bounce {
    0%,
    60%,
    100% {
        transform: translateY(0);
        opacity: 0.55;
    }

    30% {
        transform: translateY(-4px);
        opacity: 1;
    }
}
</style>
