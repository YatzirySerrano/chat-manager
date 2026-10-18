import type { ColorSwatch } from '@/types';

/**
 * Static class names per swatch (kept literal so Tailwind can detect them).
 */
export const swatchClasses: Record<
    ColorSwatch,
    { dot: string; soft: string; solid: string; label: string }
> = {
    slate: {
        dot: 'bg-slate-500',
        soft: 'bg-slate-500/10 text-slate-700 dark:text-slate-300',
        solid: 'bg-slate-600 text-white',
        label: 'Pizarra',
    },
    blue: {
        dot: 'bg-blue-500',
        soft: 'bg-blue-500/10 text-blue-700 dark:text-blue-300',
        solid: 'bg-blue-600 text-white',
        label: 'Azul',
    },
    teal: {
        dot: 'bg-teal-500',
        soft: 'bg-teal-500/10 text-teal-700 dark:text-teal-300',
        solid: 'bg-teal-600 text-white',
        label: 'Turquesa',
    },
    emerald: {
        dot: 'bg-emerald-500',
        soft: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
        solid: 'bg-emerald-600 text-white',
        label: 'Esmeralda',
    },
    amber: {
        dot: 'bg-amber-500',
        soft: 'bg-amber-500/10 text-amber-700 dark:text-amber-300',
        solid: 'bg-amber-500 text-white',
        label: 'Ámbar',
    },
    orange: {
        dot: 'bg-orange-500',
        soft: 'bg-orange-500/10 text-orange-700 dark:text-orange-300',
        solid: 'bg-orange-600 text-white',
        label: 'Naranja',
    },
    rose: {
        dot: 'bg-rose-500',
        soft: 'bg-rose-500/10 text-rose-700 dark:text-rose-300',
        solid: 'bg-rose-600 text-white',
        label: 'Rosa',
    },
    violet: {
        dot: 'bg-violet-500',
        soft: 'bg-violet-500/10 text-violet-700 dark:text-violet-300',
        solid: 'bg-violet-600 text-white',
        label: 'Violeta',
    },
};
