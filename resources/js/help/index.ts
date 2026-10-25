import type { ModuleHelp } from './types';

export type { ModuleHelp, ModuleHelpItem, ModuleHelpProblem } from './types';

/**
 * Registry of the modules that have a "¿Cómo funciona este módulo?" panel.
 *
 * Content is loaded on demand (one small chunk per module) the first time a
 * person opens the panel, so pages do not ship every module's help text.
 */
const loaders = {
    dashboard: () => import('./dashboard'),
    conversaciones: () => import('./conversaciones'),
    contactos: () => import('./contactos'),
    importacion: () => import('./importacion'),
    etiquetas: () => import('./etiquetas'),
    'respuestas-rapidas': () => import('./respuestas-rapidas'),
    plantillas: () => import('./plantillas'),
    conexiones: () => import('./conexiones'),
    departamentos: () => import('./departamentos'),
    usuarios: () => import('./usuarios'),
    roles: () => import('./roles'),
    reportes: () => import('./reportes'),
    auditoria: () => import('./auditoria'),
    configuracion: () => import('./configuracion'),
    'chat-interno': () => import('./chat-interno'),
    integraciones: () => import('./integraciones'),
} satisfies Record<string, () => Promise<{ default: ModuleHelp }>>;

export type ModuleHelpKey = keyof typeof loaders;

export const moduleHelpKeys = Object.keys(loaders) as ModuleHelpKey[];

const cache = new Map<ModuleHelpKey, ModuleHelp>();

/**
 * Load (and memoize) the help content of a module.
 */
export async function loadModuleHelp(key: ModuleHelpKey): Promise<ModuleHelp> {
    const cached = cache.get(key);

    if (cached) {
        return cached;
    }

    const content = (await loaders[key]()).default;
    cache.set(key, content);

    return content;
}
