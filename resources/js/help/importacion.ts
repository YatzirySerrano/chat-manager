import type { ModuleHelp } from './types';

const help: ModuleHelp = {
    title: 'Importación de contactos',
    objective:
        'Registra o migra contactos desde un archivo de Excel (.xlsx) o .csv, por ejemplo una exportación de Whaticket. Revisas cada fila antes de confirmar, los datos existentes nunca se sobrescriben y cada lote puede revertirse si es seguro.',
    mainActions: [
        'Descargar la plantilla de ejemplo.',
        'Subir un archivo, asignar sus columnas y revisar la vista previa.',
        'Decidir qué hacer con los contactos que ya existen.',
        'Confirmar la importación y descargar el reporte del resultado.',
        'Revertir un lote reciente desde «Historial de lotes».',
    ],
    workflow: [
        'Elige el origen («Archivo de contactos (genérico)» o «Whaticket · contactos») y sube el archivo con «Subir y revisar».',
        'En «Relacionar columnas y revisar», asigna cada columna a Nombre, Teléfono, Correo electrónico, Etiquetas o Notas (o «No importar»).',
        'Elige «Omitir los contactos que ya existen» (recomendado) o «Completar solo los campos vacíos» y pulsa «Actualizar vista previa».',
        'Revisa el resumen (Nuevos, Ya existen, Repetidos, Con error, Con advertencias) y confirma con «Importar N filas válidas».',
        'Descarga el reporte: incluye las filas omitidas y el motivo.',
    ],
    fields: [
        {
            name: 'Archivo',
            description:
                '.xlsx o .csv, máximo 5 MB, 5000 filas y 30 columnas. Usa la primera hoja con los encabezados en la primera fila. No se aceptan libros con macros.',
        },
        {
            name: 'Teléfono',
            description:
                '10 dígitos para México o el número completo con lada internacional.',
        },
        {
            name: 'Etiquetas',
            description:
                'Hasta 20 por contacto; deben existir antes en el catálogo de Etiquetas.',
        },
        {
            name: 'Contactos que ya existen',
            description:
                'Se reconocen por teléfono o correo. Nunca se reemplazan datos existentes.',
        },
    ],
    states: [
        { name: 'Nuevo', description: 'Se creará un contacto.' },
        {
            name: 'Existe · se omite / Existe · completar vacíos',
            description:
                'Ya hay un contacto con ese teléfono o correo; se omite o solo se llenan sus campos vacíos.',
        },
        {
            name: 'Repetida (fila N)',
            description: 'La misma persona aparece antes en el archivo.',
        },
        {
            name: 'Con error',
            description:
                'La fila no se importa; el motivo aparece en la vista previa y en el reporte.',
        },
        {
            name: 'Revertida',
            description:
                'El lote se deshizo: se eliminaron (de forma recuperable) los contactos sin actividad posterior y se quitaron sus etiquetas.',
        },
    ],
    permissions: [
        '«Importar contactos desde Excel/CSV» requiere un permiso especial que no se otorga desde Roles.',
        'Solo quien subió un lote puede verlo, confirmarlo, cancelarlo, descargar su reporte o revertirlo.',
    ],
    commonErrors: [
        {
            problem: '«El archivo tiene más de 5,000 filas…»',
            solution: 'Divide el archivo e impórtalo por partes.',
        },
        {
            problem: '«No se pudo leer el archivo de Excel…»',
            solution:
                'Verifica que no esté dañado ni protegido con contraseña; guárdalo como .xlsx sin macros.',
        },
        {
            problem: '«La etiqueta «x» no existe.»',
            solution:
                'Créala primero en Etiquetas y actualiza la vista previa.',
        },
        {
            problem:
                '«Solo se pueden revertir importaciones de los últimos 30 días.»',
            solution:
                'Pasado ese plazo, corrige los contactos manualmente desde Contactos.',
        },
    ],
    tips: [
        'Si subes el mismo archivo dos veces, las filas existentes se detectan como duplicadas y no se crean contactos repetidos.',
        'Los contactos importados quedan guardados en el directorio.',
        'Las notas nunca se borran al revertir.',
    ],
    examples: [
        'Migrar 1200 contactos desde Whaticket: origen «Whaticket · contactos», omitir existentes y confirmar.',
        'Completar correos faltantes de clientes actuales con «Completar solo los campos vacíos».',
    ],
};

export default help;
