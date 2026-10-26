import { router, usePage } from '@inertiajs/vue3';
import { useMediaQuery, usePreferredReducedMotion } from '@vueuse/core';
import { computed, effectScope, readonly, ref, shallowRef, watch } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import { toast } from 'vue-sonner';
import { usePermissions } from '@/composables/usePermissions';
import type { PermissionName } from '@/composables/usePermissions';
import type { ModuleHelpKey } from '@/help';
import { loadTour, toursForScreen } from '@/tours';
import type {
    ResolvedStep,
    Tour,
    TourIndexEntry,
    TourStep,
} from '@/tours/types';

/** Position and size of the spotlighted control, in viewport pixels. */
export type TourRect = {
    top: number;
    left: number;
    width: number;
    height: number;
};

/** How long a step waits for its control to appear before it is skipped. */
const TARGET_WAIT_MS = 600;
/** How long a tour waits for an open dialog (the help popover) to close. */
const DIALOG_WAIT_MS = 700;
/** Fallback when a smooth scroll never reports its end. */
const SCROLL_FALLBACK_MS = 350;
/** Height of the sticky top bar: a control under it is not "in view". */
const TOP_BAR_PX = 64;

const STORAGE_PREFIX = 'cm.tours.seen.v1.';

/* ------------------------------------------------------------------ */
/* Module state (one tour at a time for the whole tab).                */
/* ------------------------------------------------------------------ */

const active = shallowRef<Tour | null>(null);
const plan = shallowRef<ResolvedStep[]>([]);
const index = ref(0);
const targetRect = ref<TourRect | null>(null);
const pageModule = ref<ModuleHelpKey | null>(null);
/** Who declared the current page module (the latest registration wins). */
let pageModuleOwner: symbol | null = null;
const announcement = ref('');
const seen = ref<Record<string, number>>({});
const userId = ref<number | null>(null);
/** True while a step is being prepared (scrolling, waiting for its control). */
const moving = ref(false);

let target: HTMLElement | null = null;
let tooltip: HTMLElement | null = null;
let restoreFocus: HTMLElement | null = null;
let startPath = '';
let moveToken = 0;
let starting = false;
let shownCount = 0;
let frame = 0;
let resizeObserver: ResizeObserver | null = null;
let inertElements: HTMLElement[] = [];

const scope = effectScope(true);
let isDesktop: Ref<boolean> = ref(true);
let reducedMotion: Ref<'reduce' | 'no-preference'> = ref('no-preference');
let installed = false;

/* ------------------------------------------------------------------ */
/* "Seen" preference (per person and device).                          */
/* ------------------------------------------------------------------ */

function storageKey(): string | null {
    return userId.value === null ? null : `${STORAGE_PREFIX}${userId.value}`;
}

function readSeen(): void {
    const key = storageKey();

    if (key === null) {
        seen.value = {};

        return;
    }

    try {
        const parsed: unknown = JSON.parse(localStorage.getItem(key) ?? '{}');
        seen.value =
            parsed && typeof parsed === 'object' && !Array.isArray(parsed)
                ? (parsed as Record<string, number>)
                : {};
    } catch {
        seen.value = {};
    }
}

function writeSeen(): void {
    const key = storageKey();

    if (key === null) {
        return;
    }

    try {
        localStorage.setItem(key, JSON.stringify(seen.value));
    } catch {
        // Private mode or blocked storage: tours simply show as new again.
    }
}

function markSeen(tour: Tour): void {
    seen.value = { ...seen.value, [tour.id]: tour.version };
    writeSeen();
}

/* ------------------------------------------------------------------ */
/* Targets.                                                            */
/* ------------------------------------------------------------------ */

function isVisible(element: HTMLElement): boolean {
    const rect = element.getBoundingClientRect();

    if (rect.width <= 0 || rect.height <= 0) {
        return false;
    }

    return typeof element.checkVisibility === 'function'
        ? element.checkVisibility({
              checkOpacity: true,
              checkVisibilityCSS: true,
          })
        : element.offsetParent !== null;
}

/** First visible element with that data-tour value (outside dialogs). */
function findTarget(name: string): HTMLElement | null {
    for (const element of document.querySelectorAll<HTMLElement>(
        `[data-tour="${CSS.escape(name)}"]`,
    )) {
        if (isVisible(element)) {
            return element;
        }
    }

    return null;
}

/** Waits (bounded) for one of the targets to appear: a page may still render. */
function waitForTarget(
    names: string[],
    timeoutMs = TARGET_WAIT_MS,
): Promise<HTMLElement | null> {
    const look = (): HTMLElement | null => {
        for (const name of names) {
            const found = findTarget(name);

            if (found) {
                return found;
            }
        }

        return null;
    };

    const immediate = look();

    if (immediate || names.length === 0) {
        return Promise.resolve(immediate);
    }

    return new Promise((resolve) => {
        let settled = false;
        let poll = 0;

        const done = (value: HTMLElement | null): void => {
            if (settled) {
                return;
            }

            settled = true;
            observer.disconnect();
            cancelAnimationFrame(poll);
            clearTimeout(timer);
            resolve(value);
        };

        const check = (): void => {
            cancelAnimationFrame(poll);
            poll = requestAnimationFrame(() => {
                const found = look();

                if (found) {
                    done(found);
                }
            });
        };

        const observer = new MutationObserver(check);
        observer.observe(document.body, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['data-tour', 'class', 'style', 'hidden'],
        });
        const timer = setTimeout(() => done(look()), timeoutMs);
        check();
    });
}

function openDialog(): Element | null {
    return document.querySelector(
        '[role="dialog"][data-state="open"], [role="alertdialog"][data-state="open"]',
    );
}

/** Waits (bounded) until no dialog or popover is open. */
async function waitForDialogsToClose(): Promise<boolean> {
    const until = performance.now() + DIALOG_WAIT_MS;

    while (openDialog() && performance.now() < until) {
        await new Promise((resolve) => setTimeout(resolve, 50));
    }

    return openDialog() === null;
}

/** Inside the sticky top bar, the sidebar or another fixed layer. */
function isPinned(element: HTMLElement): boolean {
    for (
        let current: HTMLElement | null = element;
        current && current !== document.body;
        current = current.parentElement
    ) {
        const position = getComputedStyle(current).position;

        if (position === 'fixed' || position === 'sticky') {
            return true;
        }
    }

    return false;
}

function scrollIntoViewIfNeeded(element: HTMLElement): Promise<void> {
    const rect = element.getBoundingClientRect();
    const pinned = isPinned(element);
    const fits =
        rect.top >= (pinned ? 0 : TOP_BAR_PX) &&
        rect.bottom <= window.innerHeight &&
        rect.left >= 0 &&
        rect.right <= window.innerWidth;
    // A control taller than the viewport is shown from its start.
    const tooTall = rect.height > window.innerHeight - TOP_BAR_PX;

    // A pinned control does not move by scrolling the page.
    if (
        fits ||
        pinned ||
        (tooTall && rect.top >= TOP_BAR_PX && rect.top < 200)
    ) {
        return Promise.resolve();
    }

    const smooth = reducedMotion.value !== 'reduce';

    element.scrollIntoView({
        block: tooTall ? 'start' : 'center',
        inline: 'nearest',
        behavior: smooth ? 'smooth' : 'auto',
    });

    if (!smooth) {
        return new Promise((resolve) => requestAnimationFrame(() => resolve()));
    }

    return new Promise((resolve) => {
        const timer = setTimeout(finish, SCROLL_FALLBACK_MS);

        function finish(): void {
            clearTimeout(timer);
            document.removeEventListener('scrollend', finish, true);
            resolve();
        }

        document.addEventListener('scrollend', finish, {
            capture: true,
            once: true,
        });
    });
}

/* ------------------------------------------------------------------ */
/* Steps.                                                              */
/* ------------------------------------------------------------------ */

function allowed(
    permission: PermissionName | PermissionName[] | undefined,
): boolean {
    if (permission === undefined) {
        return true;
    }

    const { canAny } = usePermissions();

    return canAny(...(Array.isArray(permission) ? permission : [permission]));
}

function stateAllows(step: Pick<TourStep, 'when'>): boolean {
    try {
        return step.when ? step.when() : true;
    } catch {
        return false;
    }
}

/** Steps for this person and viewport (permissions and phone overrides). */
function resolveSteps(steps: TourStep[]): ResolvedStep[] {
    const phone = !isDesktop.value;

    return steps.flatMap((step): ResolvedStep[] => {
        if (!allowed(step.permission) || (phone && step.mobile === false)) {
            return [];
        }

        const { mobile, ...rest } = step;
        const overrides = phone && mobile ? mobile : {};

        return [
            {
                ...rest,
                ...overrides,
                placement: overrides.placement ?? rest.placement ?? 'auto',
            },
        ];
    });
}

/** How many steps of a tour apply to this person on this viewport. */
export function plannedStepCount(steps: TourStep[]): number {
    return resolveSteps(steps).length;
}

/* ------------------------------------------------------------------ */
/* Tracking the target.                                                */
/* ------------------------------------------------------------------ */

function measure(): void {
    cancelAnimationFrame(frame);
    frame = requestAnimationFrame(() => {
        if (!active.value || !target) {
            return;
        }

        if (!target.isConnected || !isVisible(target)) {
            if (moving.value) {
                return;
            }

            // The page re-rendered the control (a reload, a filter): find it
            // again, or move on so the overlay never stays without target.
            const step = plan.value[index.value];
            const again = step ? findTarget(step.target) : null;

            if (again) {
                track(again);
            } else {
                void skipCurrent();

                return;
            }
        }

        const rect = target.getBoundingClientRect();
        targetRect.value = {
            top: rect.top,
            left: rect.left,
            width: rect.width,
            height: rect.height,
        };
    });
}

function track(element: HTMLElement): void {
    target = element;
    resizeObserver?.disconnect();
    resizeObserver?.observe(element);
}

async function skipCurrent(): Promise<void> {
    const current = index.value;
    plan.value = plan.value.filter((_, position) => position !== current);
    await show(current, 1);
}

function isInteractive(element: HTMLElement): boolean {
    return (
        element.closest(
            'button, a[href], input, select, textarea, summary, [role="button"], [role="link"], [contenteditable="true"]',
        ) !== null
    );
}

function onKeydown(event: KeyboardEvent): void {
    if (!active.value) {
        return;
    }

    const focused = document.activeElement;
    const inTooltip = tooltip !== null && tooltip.contains(focused);
    const handled = (): void => {
        event.preventDefault();
        event.stopPropagation();
    };

    switch (event.key) {
        case 'Escape':
            handled();
            close();
            break;
        case 'ArrowRight':
            handled();
            next();
            break;
        case 'ArrowLeft':
            handled();
            back();
            break;
        case 'Enter':
            // On a button, a link or a field, Enter does its own job instead.
            if (
                inTooltip &&
                !(focused instanceof HTMLElement && isInteractive(focused))
            ) {
                handled();
                next();
            }

            break;
        case 'Tab':
            trapFocus(event);
            break;
    }
}

function trapFocus(event: KeyboardEvent): void {
    if (!tooltip) {
        return;
    }

    const focusable = [
        ...tooltip.querySelectorAll<HTMLElement>(
            'button:not([disabled]), [href], [tabindex]:not([tabindex="-1"])',
        ),
    ];

    if (focusable.length === 0) {
        return;
    }

    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    const focused = document.activeElement;

    if (!tooltip.contains(focused)) {
        event.preventDefault();
        first.focus();
    } else if (event.shiftKey && focused === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && focused === last) {
        event.preventDefault();
        first.focus();
    }
}

function attach(): void {
    window.addEventListener('resize', measure, { passive: true });
    window.addEventListener('scroll', measure, {
        capture: true,
        passive: true,
    });
    document.addEventListener('keydown', onKeydown, true);
    resizeObserver =
        typeof ResizeObserver === 'undefined'
            ? null
            : new ResizeObserver(measure);

    // The page underneath is not reachable while the tour is open: clicks
    // are caught by the overlay and focus or a screen reader stay in the
    // step card.
    inertElements = inertTargets();
    inertElements.forEach((element) => element.setAttribute('inert', ''));
}

/** The notifications region of the layout's Toaster (vue-sonner). */
const TOASTER_REGION = 'section[aria-live][aria-relevant="additions text"]';

/**
 * What becomes inert during the tour: the whole app, except the toast
 * region, so a real-time toast shown meanwhile can still be read and
 * closed. `inert` cannot be undone inside an inert subtree, so instead of
 * #app every sibling along the path from the toasts up to #app is made
 * inert (elements that were inert already are left alone).
 */
function inertTargets(): HTMLElement[] {
    const app = document.getElementById('app');

    if (!app) {
        return [];
    }

    const toaster = app.querySelector<HTMLElement>(TOASTER_REGION);

    if (!toaster) {
        return app.hasAttribute('inert') ? [] : [app];
    }

    const targets: HTMLElement[] = [];

    for (
        let node: HTMLElement = toaster;
        node !== app && node.parentElement;
        node = node.parentElement
    ) {
        for (const sibling of node.parentElement.children) {
            if (
                sibling !== node &&
                sibling instanceof HTMLElement &&
                !sibling.hasAttribute('inert')
            ) {
                targets.push(sibling);
            }
        }
    }

    return targets;
}

function detach(): void {
    cancelAnimationFrame(frame);
    window.removeEventListener('resize', measure);
    window.removeEventListener('scroll', measure, { capture: true });
    document.removeEventListener('keydown', onKeydown, true);
    resizeObserver?.disconnect();
    resizeObserver = null;
    inertElements.forEach((element) => element.removeAttribute('inert'));
    inertElements = [];
}

/* ------------------------------------------------------------------ */
/* Navigation between steps.                                           */
/* ------------------------------------------------------------------ */

/**
 * Shows the step at `position`, moving in `direction` past the steps whose
 * state condition fails or whose control never appears (they are dropped).
 */
async function show(position: number, direction: 1 | -1): Promise<void> {
    const token = ++moveToken;
    let current = position;
    moving.value = true;

    while (current >= 0 && current < plan.value.length) {
        const step = plan.value[current];
        let element: HTMLElement | null = null;

        if (stateAllows(step)) {
            try {
                await step.before?.();
            } catch {
                // A failed preparation only skips the step.
            }

            element = await waitForTarget([step.target]);
        }

        if (token !== moveToken || !active.value) {
            return;
        }

        if (!element) {
            plan.value = plan.value.filter((_, at) => at !== current);

            if (direction < 0) {
                current -= 1;
            }

            continue;
        }

        index.value = current;
        track(element);
        measure();
        await scrollIntoViewIfNeeded(element);

        if (token !== moveToken || !active.value) {
            return;
        }

        moving.value = false;
        shownCount += 1;
        /*
         * The first step's card takes the focus on its title, which already
         * reads it: the live region then only says the progress. On later
         * steps the focus stays on Siguiente/Atrás, so it reads the title.
         */
        announcement.value =
            shownCount === 1
                ? `Paso ${current + 1} de ${plan.value.length}`
                : `Paso ${current + 1} de ${plan.value.length}: ${step.title}`;
        measure();

        return;
    }

    moving.value = false;

    if (direction < 0 && plan.value.length > 0) {
        await show(0, 1);

        return;
    }

    if (shownCount > 0 && plan.value.length > 0) {
        finish();

        return;
    }

    teardown();
    toast.info('Este recorrido no está disponible en esta vista.');
}

function teardown(): void {
    moveToken += 1;
    detach();
    active.value = null;
    plan.value = [];
    index.value = 0;
    targetRect.value = null;
    target = null;
    moving.value = false;
    announcement.value = '';

    const focusBack =
        restoreFocus && restoreFocus.isConnected && isVisible(restoreFocus)
            ? restoreFocus
            : (findTarget('shell.help')?.querySelector('button') ?? null);
    restoreFocus = null;
    requestAnimationFrame(() => focusBack?.focus({ preventScroll: true }));
}

async function start(tourId: string): Promise<void> {
    if (starting) {
        return;
    }

    starting = true;

    try {
        if (active.value) {
            teardown();
        }

        if (!(await waitForDialogsToClose())) {
            toast.info('Cierra la ventana abierta para iniciar el recorrido.');

            return;
        }

        const tour = await loadTour(tourId);

        if (!tour) {
            return;
        }

        const steps = resolveSteps(tour.steps);
        await waitForTarget(steps.map((step) => step.target));
        const available = steps.filter(
            (step) =>
                stateAllows(step) &&
                (step.before !== undefined || findTarget(step.target) !== null),
        );

        if (available.length === 0) {
            toast.info('Este recorrido no está disponible en esta vista.');

            return;
        }

        restoreFocus =
            document.activeElement instanceof HTMLElement &&
            document.activeElement !== document.body
                ? document.activeElement
                : null;
        startPath = window.location.pathname;
        shownCount = 0;
        plan.value = available;
        active.value = tour;
        attach();
        await show(0, 1);
    } finally {
        starting = false;
    }
}

/*
 * Next / back also work while a step is still being prepared (scrolling,
 * waiting for its control): the new move supersedes it.
 */
function next(): void {
    if (!active.value) {
        return;
    }

    if (index.value >= plan.value.length - 1) {
        finish();

        return;
    }

    void show(index.value + 1, 1);
}

function back(): void {
    if (!active.value || index.value === 0) {
        return;
    }

    void show(index.value - 1, -1);
}

/** Completes the tour: remembered as seen. */
function finish(): void {
    if (active.value) {
        markSeen(active.value);
    }

    teardown();
}

/** Closes without finishing; also remembered as seen (not to insist). */
function close(): void {
    finish();
}

/** Recomputes the steps for a new viewport, staying on the same step. */
function replan(): void {
    const tour = active.value;

    if (!tour) {
        return;
    }

    const currentId = plan.value[index.value]?.id;
    const steps = resolveSteps(tour.steps).filter(
        (step) =>
            stateAllows(step) &&
            (step.before !== undefined || findTarget(step.target) !== null),
    );

    if (steps.length === 0) {
        teardown();

        return;
    }

    plan.value = steps;
    const same = steps.findIndex((step) => step.id === currentId);
    void show(same === -1 ? 0 : same, 1);
}

function install(): void {
    if (installed || typeof window === 'undefined') {
        return;
    }

    installed = true;
    scope.run(() => {
        isDesktop = useMediaQuery('(min-width: 768px)');
        reducedMotion = usePreferredReducedMotion();
        watch(isDesktop, replan);
        watch(userId, readSeen, { immediate: true });
    });

    // Going to another screen ends the tour (not marked as seen); a reload
    // of the same screen (live updates, filters) keeps it and the control is
    // found again.
    router.on('start', (event) => {
        if (!active.value) {
            return;
        }

        const visit = event.detail.visit;
        const path = new URL(visit.url.toString(), window.location.href)
            .pathname;

        if (path !== startPath) {
            teardown();
        }
    });
    router.on('navigate', () => {
        if (active.value && window.location.pathname !== startPath) {
            teardown();
        } else if (active.value) {
            measure();
        }
    });
}

/**
 * Guided tours: contextual, step by step explanations anchored to the real
 * controls of each screen (the primary help of the platform; the full text
 * guide of a module is secondary).
 *
 * A singleton: the overlay, the help buttons and the page headers share
 * one state. Which tours a person has seen is a per-device preference kept
 * in localStorage under `cm.tours.seen.v1.<userId>` as { tourId: version }
 * (a private window behaves as "never seen"); raising a tour's version shows
 * it as new again. Nothing is stored on the server.
 */
export function useGuidedTour(): {
    available: ComputedRef<TourIndexEntry[]>;
    hasUnseen: ComputedRef<boolean>;
    active: Readonly<Ref<Tour | null>>;
    steps: ComputedRef<ResolvedStep[]>;
    index: Readonly<Ref<number>>;
    current: ComputedRef<ResolvedStep | null>;
    targetRect: Readonly<Ref<TourRect | null>>;
    announcement: Readonly<Ref<string>>;
    moving: Readonly<Ref<boolean>>;
    pageModule: Readonly<Ref<ModuleHelpKey | null>>;
    guideModule: ComputedRef<ModuleHelpKey | null>;
    reducedMotion: ComputedRef<boolean>;
    isDesktop: ComputedRef<boolean>;
    start: (tourId: string) => Promise<void>;
    next: () => void;
    back: () => void;
    finish: () => void;
    close: () => void;
    isSeen: (tourId: string) => boolean;
    resetSeen: () => void;
    setPageModule: (key: ModuleHelpKey | null) => void;
    registerPageModule: (key: ModuleHelpKey | null) => () => void;
    registerTooltip: (element: HTMLElement | null) => void;
} {
    install();

    const page = usePage();
    const { canAny } = usePermissions();

    watch(
        () => page.props.auth?.user?.id ?? null,
        (id) => {
            userId.value = id;
        },
        { immediate: true },
    );

    const available = computed(() =>
        toursForScreen(page.component, pageModule.value).filter(
            (entry) =>
                entry.permission === undefined ||
                canAny(
                    ...(Array.isArray(entry.permission)
                        ? entry.permission
                        : [entry.permission]),
                ),
        ),
    );

    function isSeen(tourId: string): boolean {
        const entry = available.value.find(
            (candidate) => candidate.id === tourId,
        );

        return entry !== undefined && seen.value[tourId] === entry.version;
    }

    return {
        available,
        hasUnseen: computed(() =>
            available.value.some(
                (entry) => seen.value[entry.id] !== entry.version,
            ),
        ),
        active: readonly(active) as Readonly<Ref<Tour | null>>,
        steps: computed(() => plan.value),
        index: readonly(index),
        current: computed(() =>
            active.value ? (plan.value[index.value] ?? null) : null,
        ),
        targetRect: readonly(targetRect),
        announcement: readonly(announcement),
        moving: readonly(moving),
        pageModule: readonly(pageModule),
        guideModule: computed(
            () =>
                pageModule.value ??
                available.value.find((entry) => entry.module !== null)
                    ?.module ??
                null,
        ),
        reducedMotion: computed(() => reducedMotion.value === 'reduce'),
        isDesktop: computed(() => isDesktop.value),
        start,
        next,
        back,
        finish,
        close,
        isSeen,
        resetSeen: () => {
            seen.value = {};
            writeSeen();
            toast.success('Los recorridos se mostrarán de nuevo como nuevos.');
        },
        setPageModule: (key) => {
            pageModuleOwner = null;
            pageModule.value = key;
        },
        registerPageModule: (key) => {
            const owner = Symbol('page-module');
            pageModuleOwner = owner;
            pageModule.value = key;

            // Unregistering only clears it while nobody declared another one
            // since (the next screen may mount before this one unmounts).
            return () => {
                if (pageModuleOwner === owner) {
                    pageModuleOwner = null;
                    pageModule.value = null;
                }
            };
        },
        registerTooltip: (element) => {
            tooltip = element;
        },
    };
}
