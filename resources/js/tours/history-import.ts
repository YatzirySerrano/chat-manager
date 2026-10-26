import type { TourSteps } from './types';

export default {
    'historial.importar': [
        {
            id: 'formato',
            target: 'history-import.format',
            title: 'Formato no verificado',
            body: 'Las columnas se reconocen por su nombre. Revisa siempre el inventario antes de confirmar.',
        },
        {
            id: 'crear',
            target: 'history-import.create',
            title: 'Crea un lote',
            body: 'Indica la zona horaria de las fechas de Whaticket y sube sus archivos. Nada se importa todavía.',
            permission: 'history-import.classify',
        },
        {
            id: 'lotes',
            target: 'history-import.batches',
            title: 'Tus lotes',
            body: 'Retoma un borrador o abre el reporte de una importación terminada.',
        },
    ],
    'historial.lote': [
        {
            id: 'pasos',
            target: 'history-import.steps',
            title: 'Pasos',
            body: 'Archivos, correspondencias, clasificar y confirmar. Puedes ir y volver entre ellos.',
        },
        {
            id: 'subir',
            target: 'history-import.upload',
            title: 'Sube los archivos',
            body: 'Elige qué archivo es y súbelo. Verás cómo se leyó y qué columnas faltan.',
            permission: 'history-import.classify',
            when: () =>
                document.querySelector(
                    '[data-tour="history-import.upload"]',
                ) !== null,
        },
        {
            id: 'archivos',
            target: 'history-import.files',
            title: 'Cómo se leyó',
            body: 'Revisa columnas no reconocidas, datos que faltan y filas rechazadas.',
            when: () =>
                document.querySelector('[data-tour="history-import.files"]') !==
                null,
        },
        {
            id: 'clasificar',
            target: 'history-import.inventory',
            title: 'Decide el destino',
            body: 'Importar, archivar o no importar cada conversación. Las sugerencias solo te ayudan.',
            when: () =>
                document.querySelector(
                    '[data-tour="history-import.inventory"]',
                ) !== null,
        },
        {
            id: 'masivo',
            target: 'history-import.bulk',
            title: 'Muchas a la vez',
            body: 'Selecciona varias o aplica el destino a todo lo que coincide con el filtro.',
            when: () =>
                document.querySelector('[data-tour="history-import.bulk"]') !==
                null,
        },
        {
            id: 'reglas',
            target: 'history-import.rules',
            title: 'Reglas',
            body: 'Crea criterios, mira cuántas cambian y aplícalos sin tocar lo que ya decidiste.',
            when: () =>
                document.querySelector('[data-tour="history-import.rules"]') !==
                null,
        },
    ],
    'historial.consulta': [
        {
            id: 'filtros',
            target: 'history.filters',
            title: 'Busca en el historial',
            body: 'Por nombre, número, fechas, departamento, agente, etiqueta o lote.',
        },
        {
            id: 'lista',
            target: 'history.list',
            title: 'Solo consulta',
            body: 'Abre una conversación para leerla. No se puede responder ni reabrir.',
        },
    ],
} satisfies TourSteps;
