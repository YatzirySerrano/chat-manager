import type { Tour, TourFileKey, TourIndexEntry, TourSteps } from './types';

export type {
    ResolvedStep,
    Tour,
    TourIndexEntry,
    TourPlacement,
    TourStep,
} from './types';

/**
 * Guided tours of the platform.
 *
 * The index below is light (no step texts) so the help button can list the
 * tours of the current screen and know which are new; the steps of a module
 * are loaded on demand, one small chunk per module, when a tour starts or
 * the help popover opens.
 */
export const tourIndex: TourIndexEntry[] = [
    {
        id: 'dashboard.basico',
        file: 'dashboard',
        module: 'dashboard',
        title: 'Conoce tu panel',
        summary: 'Filtros, indicadores y gráficas de la atención.',
        version: 1,
        routes: ['Dashboard'],
    },
    {
        id: 'conversaciones.basico',
        file: 'conversaciones',
        module: 'conversaciones',
        title: 'Conoce la bandeja',
        summary: 'Vistas, búsqueda, filtros y cómo atender una conversación.',
        version: 1,
        routes: ['conversations/Index'],
    },
    {
        id: 'contactos.basico',
        file: 'contactos',
        module: 'contactos',
        title: 'Conoce Contactos',
        summary: 'Crear, buscar, importar y abrir contactos.',
        version: 1,
        routes: ['contacts/Index'],
    },
    {
        id: 'contactos.ficha',
        file: 'contactos',
        module: 'contactos',
        title: 'La ficha del contacto',
        summary: 'Acciones, historial y etiquetas de un cliente.',
        version: 1,
        routes: ['contacts/Show'],
    },
    {
        id: 'usuarios.basico',
        file: 'usuarios',
        module: 'usuarios',
        title: 'Conoce Usuarios',
        summary: 'Crear cuentas, buscar personas y gestionar accesos.',
        version: 1,
        routes: ['admin/users/Index'],
    },
    {
        id: 'roles.basico',
        file: 'roles',
        module: 'roles',
        title: 'Conoce Roles y permisos',
        summary: 'Qué incluye cada rol y cómo crear uno nuevo.',
        version: 1,
        routes: ['admin/roles/Index'],
    },
    {
        id: 'departamentos.basico',
        file: 'departamentos',
        module: 'departamentos',
        title: 'Conoce Departamentos',
        summary: 'Crear equipos y abrir su configuración.',
        version: 1,
        routes: ['departments/Index'],
    },
    {
        id: 'departamentos.detalle',
        file: 'departamentos',
        module: 'departamentos',
        title: 'Configura un departamento',
        summary: 'Reparto, horario y miembros del equipo.',
        version: 1,
        routes: ['departments/Show'],
    },
    {
        id: 'etiquetas.basico',
        file: 'etiquetas',
        module: 'etiquetas',
        title: 'Conoce Etiquetas',
        summary: 'Crear, buscar y ver el uso de cada etiqueta.',
        version: 1,
        routes: ['tags/Index'],
    },
    {
        id: 'respuestas-rapidas.basico',
        file: 'respuestas-rapidas',
        module: 'respuestas-rapidas',
        title: 'Conoce Respuestas rápidas',
        summary: 'Corporativas, personales y cómo usarlas en el chat.',
        version: 1,
        routes: ['quick-replies/Index'],
    },
    {
        id: 'plantillas.basico',
        file: 'plantillas',
        module: 'plantillas',
        title: 'Conoce Plantillas',
        summary: 'Estado en Meta, cómo actualizarlas y detalle.',
        version: 1,
        routes: ['templates/Index'],
    },
    {
        id: 'conexiones.basico',
        file: 'conexiones',
        module: 'conexiones',
        title: 'Conoce Conexiones',
        summary: 'Canales conectados y su estado.',
        version: 1,
        routes: ['connections/Index'],
    },
    {
        id: 'reportes.basico',
        file: 'reportes',
        module: 'reportes',
        title: 'Conoce Reportes',
        summary: 'Filtros, indicadores, gráficas y exportación.',
        version: 1,
        routes: ['reports/Index'],
    },
    {
        id: 'reportes.exportaciones',
        file: 'reportes',
        module: 'reportes',
        title: 'Tus exportaciones',
        summary: 'Dónde quedan tus archivos y cómo descargarlos.',
        version: 1,
        routes: ['exports/Index'],
    },
    {
        id: 'auditoria.basico',
        file: 'auditoria',
        module: 'auditoria',
        title: 'Conoce Auditoría',
        summary: 'Filtrar el historial y ver el detalle de un cambio.',
        version: 1,
        routes: ['admin/audit/Index'],
    },
    {
        id: 'configuracion.basico',
        file: 'configuracion',
        module: 'configuracion',
        title: 'Conoce Configuración',
        summary: 'Datos generales, horario, operación y objetivos.',
        version: 1,
        routes: ['admin/settings/Edit'],
    },
    {
        id: 'personalizacion.basico',
        file: 'personalizacion',
        module: 'configuracion',
        title: 'Conoce Personalización',
        summary: 'Elegir y probar el color de la plataforma.',
        version: 1,
        routes: ['admin/appearance/Edit'],
    },
    {
        id: 'chat-interno.basico',
        file: 'chat-interno',
        module: 'chat-interno',
        title: 'Conoce el Chat interno',
        summary: 'Nuevos chats, búsqueda y mensajes al equipo.',
        version: 1,
        routes: ['team-chat/Index'],
    },
    {
        id: 'sistema.basico',
        file: 'sistema',
        module: 'sistema',
        title: 'Conoce Sistema',
        summary: 'Estado técnico, verificaciones y tareas con error.',
        version: 1,
        routes: ['admin/system/Index'],
    },
    {
        id: 'integraciones.basico',
        file: 'integraciones',
        module: 'integraciones',
        title: 'Conoce Integraciones',
        summary: 'Tokens de acceso para otros sistemas.',
        version: 1,
        routes: ['admin/integrations/Index'],
    },
    {
        id: 'app.inicio',
        file: 'app',
        module: null,
        title: 'Conoce la plataforma',
        summary: 'Menú, notificaciones, apariencia y ayuda.',
        version: 1,
        routes: ['*'],
    },
];

const loaders = {
    app: () => import('./app'),
    dashboard: () => import('./dashboard'),
    conversaciones: () => import('./conversaciones'),
    contactos: () => import('./contactos'),
    usuarios: () => import('./usuarios'),
    roles: () => import('./roles'),
    departamentos: () => import('./departamentos'),
    etiquetas: () => import('./etiquetas'),
    'respuestas-rapidas': () => import('./respuestas-rapidas'),
    plantillas: () => import('./plantillas'),
    conexiones: () => import('./conexiones'),
    reportes: () => import('./reportes'),
    auditoria: () => import('./auditoria'),
    configuracion: () => import('./configuracion'),
    personalizacion: () => import('./personalizacion'),
    'chat-interno': () => import('./chat-interno'),
    sistema: () => import('./sistema'),
    integraciones: () => import('./integraciones'),
} satisfies Record<TourFileKey, () => Promise<{ default: TourSteps }>>;

const cache = new Map<TourFileKey, TourSteps>();

/** Load (and memoize) the steps of every tour of a module file. */
export async function loadTourSteps(file: TourFileKey): Promise<TourSteps> {
    const cached = cache.get(file);

    if (cached) {
        return cached;
    }

    const steps = (await loaders[file]()).default;
    cache.set(file, steps);

    return steps;
}

/** Steps already loaded for a tour, without loading anything. */
export function cachedTourSteps(entry: TourIndexEntry): Tour['steps'] | null {
    return cache.get(entry.file)?.[entry.id] ?? null;
}

/** The full tour (index entry and steps), or null when it is unknown. */
export async function loadTour(id: string): Promise<Tour | null> {
    const entry = tourIndex.find((candidate) => candidate.id === id);

    if (!entry) {
        return null;
    }

    const steps = (await loadTourSteps(entry.file))[entry.id] ?? [];

    return { ...entry, steps };
}

/**
 * Tours offered on a screen: the ones registered for its page component
 * or, as a fallback, the route-less tours of the module its PageHeader
 * declares (a tour bound to other screens would find none of its controls
 * here); the platform tour comes last, on every screen.
 */
export function toursForScreen(
    component: string,
    pageModule: string | null,
): TourIndexEntry[] {
    const byRoute = tourIndex.filter((entry) =>
        entry.routes.includes(component),
    );
    const own =
        byRoute.length > 0 || pageModule === null
            ? byRoute
            : tourIndex.filter(
                  (entry) =>
                      entry.module === pageModule && entry.routes.length === 0,
              );

    return [...own, ...tourIndex.filter((entry) => entry.routes.includes('*'))];
}
