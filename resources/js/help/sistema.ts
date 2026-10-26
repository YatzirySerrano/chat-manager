import type { ModuleHelp } from './types';

const help: ModuleHelp = {
    title: 'Sistema',
    objective:
        'Estado técnico de la plataforma: servicios, tareas en segundo plano, tareas con error, conexiones de WhatsApp y errores recientes. No muestra contenido de clientes.',
    mainActions: [
        'Revisar si algún servicio falla o tiene advertencias.',
        'Ver cuántas tareas esperan en cada grupo y cuánto llevan esperando.',
        'Reintentar o descartar tareas con error que pueden repetirse sin hacer nada dos veces.',
        'Volver a consultar el estado con «Actualizar».',
    ],
    workflow: [
        'Mira el estado general arriba: si dice que todo funciona, no hace falta más.',
        'Si hay una falla, busca en «Verificaciones» el servicio afectado y lo que indica.',
        'Revisa «Tareas con error»: reintenta las que se pueden repetir o descártalas.',
        'Pulsa «Actualizar» para confirmar que el problema se resolvió.',
    ],
    fields: [
        {
            name: 'Tareas pendientes',
            description:
                'Tareas en espera de procesarse, como envíos de mensajes o exportaciones.',
        },
        {
            name: 'Programador',
            description:
                'Cuándo se ejecutaron por última vez las tareas automáticas (cierres, recordatorios, limpiezas).',
        },
    ],
    states: [
        {
            name: 'Correcto',
            description: 'El servicio funciona.',
        },
        {
            name: 'Advertencia',
            description:
                'Funciona, pero algo conviene revisarse pronto. Solo se muestra aquí.',
        },
        {
            name: 'Falla',
            description:
                'El servicio no funciona y puede afectar la atención; avisa a quien administra el servidor.',
        },
    ],
    permissions: [
        '«Ver el estado técnico de la plataforma» permite consultar esta pantalla.',
        '«Reintentar o descartar tareas con error» permite además actuar sobre esas tareas desde esta pantalla.',
    ],
    commonErrors: [
        {
            problem: 'El programador aparece detenido.',
            solution:
                'Las tareas automáticas no se están ejecutando: pide a quien administra el servidor que revise el proceso programado.',
        },
        {
            problem: 'No puedo reintentar una tarea con error.',
            solution:
                'Solo se reintentan las que no hacen nada dos veces. Los mensajes de WhatsApp se reintentan desde la conversación.',
        },
    ],
    tips: [
        'Los recorridos guiados recuerdan qué viste en este dispositivo; puedes volver a mostrarlos desde el botón de ayuda.',
    ],
    examples: [
        'Un cliente dice que no recibió una respuesta: revisa si hay tareas de envío con error y reintenta el mensaje desde la conversación.',
    ],
};

export default help;
