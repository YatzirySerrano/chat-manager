<script setup lang="ts">
import { Search, Smile } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';

/**
 * Small local emoji picker (no external catalog or network): the emojis an
 * attention team uses most, grouped and searchable by Spanish keywords.
 * It only inserts the character; the composer keeps the text.
 */
withDefaults(defineProps<{ disabled?: boolean }>(), { disabled: false });

const emit = defineEmits<{
    select: [emoji: string];
}>();

type EmojiEntry = [emoji: string, keywords: string];

const groups: { label: string; emojis: EmojiEntry[] }[] = [
    {
        label: 'Caras',
        emojis: [
            ['😀', 'sonrisa feliz'],
            ['😃', 'alegre feliz'],
            ['😄', 'risa feliz'],
            ['😁', 'sonrisa dientes'],
            ['😅', 'nervios sudor'],
            ['😂', 'carcajada risa lagrimas'],
            ['🙂', 'sonrisa leve'],
            ['😉', 'guiño'],
            ['😊', 'sonrojado contento'],
            ['😍', 'enamorado encanta'],
            ['🥰', 'carino amor'],
            ['😘', 'beso'],
            ['🤗', 'abrazo'],
            ['🤔', 'pensando duda'],
            ['😐', 'neutral serio'],
            ['🙄', 'ojos fastidio'],
            ['😴', 'sueño dormir'],
            ['😌', 'alivio tranquilo'],
            ['😎', 'genial lentes'],
            ['🤩', 'emocionado estrellas'],
            ['🥳', 'fiesta celebracion'],
            ['😢', 'triste lagrima'],
            ['😭', 'llanto llorar'],
            ['😤', 'molesto'],
            ['😡', 'enojado'],
            ['😱', 'sorpresa miedo'],
            ['😳', 'sorprendido apenado'],
            ['🤒', 'enfermo'],
            ['🙏', 'gracias por favor rezar'],
            ['🤝', 'trato acuerdo saludo'],
        ],
    },
    {
        label: 'Gestos',
        emojis: [
            ['👍', 'bien ok pulgar arriba'],
            ['👎', 'mal pulgar abajo'],
            ['👌', 'perfecto ok'],
            ['✌️', 'paz victoria'],
            ['🤞', 'suerte dedos cruzados'],
            ['👏', 'aplausos felicidades'],
            ['🙌', 'celebrar manos'],
            ['👋', 'hola adios saludo'],
            ['💪', 'fuerza animo'],
            ['👀', 'ojos revisar ver'],
            ['🫶', 'corazon manos'],
            ['☝️', 'atencion uno'],
        ],
    },
    {
        label: 'Símbolos',
        emojis: [
            ['❤️', 'corazon amor'],
            ['💙', 'corazon azul'],
            ['💚', 'corazon verde'],
            ['⭐', 'estrella'],
            ['✨', 'brillos'],
            ['🔥', 'fuego genial'],
            ['🎉', 'fiesta confeti'],
            ['✅', 'listo hecho check'],
            ['☑️', 'casilla marcada'],
            ['❌', 'no error cancelar'],
            ['⚠️', 'advertencia aviso'],
            ['❗', 'importante exclamacion'],
            ['❓', 'pregunta duda'],
            ['💯', 'cien perfecto'],
            ['🆗', 'ok'],
            ['🆕', 'nuevo'],
            ['🔔', 'aviso campana'],
            ['📌', 'fijar nota'],
        ],
    },
    {
        label: 'Objetos',
        emojis: [
            ['📞', 'telefono llamada'],
            ['📱', 'celular movil'],
            ['💻', 'computadora laptop'],
            ['📧', 'correo email'],
            ['📄', 'documento archivo'],
            ['📎', 'adjunto clip'],
            ['📦', 'paquete envio'],
            ['🚚', 'envio entrega camion'],
            ['🛒', 'compra carrito'],
            ['💳', 'tarjeta pago'],
            ['💵', 'dinero pago efectivo'],
            ['🧾', 'factura recibo ticket'],
            ['🎁', 'regalo promocion'],
            ['🏷️', 'etiqueta precio'],
            ['🔧', 'reparar soporte'],
            ['🔒', 'seguro candado'],
            ['🔑', 'llave acceso'],
            ['📍', 'ubicacion lugar'],
            ['🏠', 'casa domicilio'],
            ['🏢', 'oficina empresa'],
        ],
    },
    {
        label: 'Tiempo',
        emojis: [
            ['⏰', 'alarma hora'],
            ['⏳', 'espera reloj arena'],
            ['🕐', 'hora reloj'],
            ['📅', 'fecha calendario cita'],
            ['🗓️', 'agenda calendario'],
            ['☀️', 'sol dia'],
            ['🌙', 'noche luna'],
            ['☕', 'cafe'],
        ],
    },
];

const open = ref(false);
const search = ref('');

watch(open, (isOpen) => {
    if (isOpen) {
        search.value = '';
    }
});

const normalize = (value: string): string =>
    value
        .toLocaleLowerCase('es')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '');

const filtered = computed(() => {
    const term = normalize(search.value.trim());

    if (term === '') {
        return groups;
    }

    return groups
        .map((group) => ({
            label: group.label,
            emojis: group.emojis.filter(([, keywords]) =>
                normalize(keywords).includes(term),
            ),
        }))
        .filter((group) => group.emojis.length > 0);
});

function choose(emoji: string): void {
    emit('select', emoji);
    open.value = false;
}
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                class="shrink-0 text-muted-foreground pointer-coarse:size-10"
                :disabled="disabled"
                title="Insertar emoji"
                aria-label="Insertar emoji"
            >
                <Smile aria-hidden="true" />
            </Button>
        </PopoverTrigger>
        <PopoverContent
            align="start"
            side="top"
            class="w-80 p-0"
            aria-label="Emojis"
        >
            <div class="border-b p-2">
                <div class="relative">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <Input
                        v-model="search"
                        type="search"
                        class="h-8 pl-8 pointer-coarse:h-10"
                        placeholder="Buscar emoji (gracias, envío…)"
                        aria-label="Buscar emoji"
                    />
                </div>
            </div>
            <div class="max-h-64 overflow-y-auto p-2">
                <p
                    v-if="filtered.length === 0"
                    class="py-6 text-center text-sm text-muted-foreground"
                >
                    Ningún emoji coincide.
                </p>
                <section
                    v-for="group in filtered"
                    :key="group.label"
                    class="mb-2 last:mb-0"
                >
                    <h3
                        class="mb-1 px-1 text-xs font-medium text-muted-foreground"
                    >
                        {{ group.label }}
                    </h3>
                    <div
                        class="grid grid-cols-8 gap-0.5 pointer-coarse:grid-cols-7"
                    >
                        <button
                            v-for="[emoji, keywords] in group.emojis"
                            :key="emoji"
                            type="button"
                            class="flex size-8 items-center justify-center rounded-md text-lg hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none pointer-coarse:size-10"
                            :title="keywords"
                            :aria-label="`${emoji} ${keywords}`"
                            @click="choose(emoji)"
                        >
                            {{ emoji }}
                        </button>
                    </div>
                </section>
            </div>
        </PopoverContent>
    </Popover>
</template>
