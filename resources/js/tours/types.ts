import type { PermissionName } from '@/composables/usePermissions';
import type { ModuleHelpKey } from '@/help';

/** Side of the target where the step card prefers to sit. */
export type TourPlacement = 'top' | 'bottom' | 'left' | 'right' | 'auto';

/**
 * One step of a guided tour: a short explanation anchored to a real control
 * of the screen, found by its `data-tour` attribute.
 */
export type TourStep = {
    /** Unique within its tour, e.g. 'pendientes'. */
    id: string;
    /** Value of the target's data-tour attribute, e.g. 'inbox.pending'. */
    target: string;
    /** At most 40 characters. */
    title: string;
    /** One or two short sentences (at most 180 characters). */
    body: string;
    /** Defaults to 'auto'. */
    placement?: TourPlacement;
    /** Skipped unless the person holds at least one of these permissions. */
    permission?: PermissionName | PermissionName[];
    /** Screen state condition, checked when the tour starts and on every move. */
    when?: () => boolean;
    /** Overrides below 768 px; `false` skips the step on phones. */
    mobile?:
        | Partial<Pick<TourStep, 'target' | 'title' | 'body' | 'placement'>>
        | false;
    /** Prepares the screen (e.g. opens a panel); never navigates or submits. */
    before?: () => void | Promise<void>;
};

/** Steps of every tour of one module file, keyed by tour id. */
export type TourSteps = Record<string, TourStep[]>;

/**
 * What the help popover needs to list a tour without loading its texts.
 */
export type TourIndexEntry = {
    /** Global id: '<module>.<name>', e.g. 'conversaciones.basico'. */
    id: string;
    /** Module file (tours/<file>.ts) holding the steps. */
    file: TourFileKey;
    /** Module whose full guide (help/) the popover offers next to the tour. */
    module: ModuleHelpKey | null;
    /** E.g. «Conoce la bandeja». */
    title: string;
    /** One line for the popover. */
    summary: string;
    /** Raise it when the steps change: the tour shows as new again. */
    version: number;
    /**
     * Inertia page components where it applies; ['*'] = every screen and []
     * = wherever the PageHeader declares its module.
     */
    routes: string[];
    permission?: PermissionName | PermissionName[];
};

export type Tour = TourIndexEntry & { steps: TourStep[] };

/** A step after applying the phone overrides. */
export type ResolvedStep = Omit<TourStep, 'mobile'> & {
    placement: TourPlacement;
};

export type TourFileKey =
    | 'app'
    | 'dashboard'
    | 'conversaciones'
    | 'contactos'
    | 'usuarios'
    | 'roles'
    | 'departamentos'
    | 'etiquetas'
    | 'respuestas-rapidas'
    | 'plantillas'
    | 'conexiones'
    | 'reportes'
    | 'auditoria'
    | 'configuracion'
    | 'personalizacion'
    | 'chat-interno'
    | 'sistema'
    | 'integraciones'
    | 'history-import';
