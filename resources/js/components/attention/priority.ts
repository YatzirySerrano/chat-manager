import { Info, OctagonAlert, TriangleAlert } from '@lucide/vue';
import type { Component } from 'vue';
import type { AttentionAlertPriority, ColorSwatch } from '@/types';

/**
 * How each priority is told apart: its name and its own icon (never the
 * color alone), plus a stronger frame for urgent alerts.
 */
export const PRIORITY_LOOK: Record<
    AttentionAlertPriority,
    { icon: Component; badge: string; frame: string }
> = {
    info: {
        icon: Info,
        badge: 'border-border bg-muted text-foreground',
        frame: '',
    },
    important: {
        icon: TriangleAlert,
        badge: 'border-warning/50 bg-warning-soft text-warning-soft-foreground',
        frame: 'ring-1 ring-warning/40',
    },
    urgent: {
        icon: OctagonAlert,
        badge: 'border-destructive/50 bg-destructive/10 text-destructive',
        frame: 'ring-2 ring-destructive/60',
    },
};

export const PRIORITY_RANK: Record<AttentionAlertPriority, number> = {
    info: 0,
    important: 1,
    urgent: 2,
};

/**
 * Background of an alert per color of the closed catalog (ColorSwatch),
 * kept literal so Tailwind detects the classes. Every surface is a very
 * light tint in light mode and a very dark one in dark mode, with
 * near-black or near-white text and its own secondary text, so the text
 * always keeps a contrast well above WCAG AA (4.5:1) whatever the color;
 * the priority keeps its own name, icon and frame on top of it.
 */
export const ALERT_SURFACE: Record<
    ColorSwatch,
    { surface: string; secondary: string }
> = {
    slate: {
        surface:
            'border-slate-300 bg-slate-100 text-slate-950 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-50',
        secondary: 'text-slate-700 dark:text-slate-300',
    },
    blue: {
        surface:
            'border-blue-200 bg-blue-50 text-slate-950 dark:border-blue-800 dark:bg-blue-950 dark:text-slate-50',
        secondary: 'text-slate-700 dark:text-slate-300',
    },
    teal: {
        surface:
            'border-teal-200 bg-teal-50 text-slate-950 dark:border-teal-800 dark:bg-teal-950 dark:text-slate-50',
        secondary: 'text-slate-700 dark:text-slate-300',
    },
    emerald: {
        surface:
            'border-emerald-200 bg-emerald-50 text-slate-950 dark:border-emerald-800 dark:bg-emerald-950 dark:text-slate-50',
        secondary: 'text-slate-700 dark:text-slate-300',
    },
    amber: {
        surface:
            'border-amber-200 bg-amber-50 text-slate-950 dark:border-amber-800 dark:bg-amber-950 dark:text-slate-50',
        secondary: 'text-slate-700 dark:text-slate-300',
    },
    orange: {
        surface:
            'border-orange-200 bg-orange-50 text-slate-950 dark:border-orange-800 dark:bg-orange-950 dark:text-slate-50',
        secondary: 'text-slate-700 dark:text-slate-300',
    },
    rose: {
        surface:
            'border-rose-200 bg-rose-50 text-slate-950 dark:border-rose-800 dark:bg-rose-950 dark:text-slate-50',
        secondary: 'text-slate-700 dark:text-slate-300',
    },
    violet: {
        surface:
            'border-violet-200 bg-violet-50 text-slate-950 dark:border-violet-800 dark:bg-violet-950 dark:text-slate-50',
        secondary: 'text-slate-700 dark:text-slate-300',
    },
};
