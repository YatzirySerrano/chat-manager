# Sistema de diseño «Mesa de control»

Contrato para quien diseñe o migre una página de Chat Manager. Describe los tokens, las utilidades de layout a ancho
completo, los controles propios y las reglas de movimiento. Las páginas de referencia ya migradas son el **Panel
principal** (`pages/Dashboard.vue`), **Mi cuenta** (`layouts/settings/Layout.vue` y `pages/settings/*`) y las
pantallas de **inicio de sesión** (`layouts/auth/*`, `pages/auth/*`); la barra de filtros
`components/reports/AnalyticsFilterBar.vue` es el ejemplo de migración de `<select>` y fechas nativas.

Todo vive en `resources/css/app.css`, `resources/js/components/ui/**` y los componentes compartidos de
`resources/js/components/*.vue`.

## 1. Principios

1. **Ancho completo, alineado a la izquierda.** Ninguna página se centra en una columna angosta. El contenido usa todo
   el espacio junto a la barra lateral; lo que sobra a la derecha se llena con más columnas, no con márgenes.
2. **Superficies con jerarquía.** Lienzo (`bg-background`) → panel (línea fina, **sin sombra en reposo**) → elevado
   (popover y diálogo, con sombra teñida por la paleta). No todo es una tarjeta: agrupa con un panel solo lo que es una
   unidad (una tabla, un formulario, un indicador).
3. **Controles propios.** Nada nativo del navegador a la vista (`<select>`, `type="date"`, `type="time"`, radios,
   `type="file"`), ni componentes con el aspecto por defecto del kit.
4. **Movimiento que responde.** Rápido (150–280 ms) y con motivo: una entrada orquestada por página, deslizamientos
   que muestran qué cambió, hovers que confirman que algo es interactivo.
5. **Todo color sale de tokens.** Ocho paletas (`data-palette`) y dos temas: nunca `bg-white`, `text-black`,
   `neutral-*`, `green-600` ni hex sueltos.

## 2. Layout a ancho completo

### 2.1 Contenedor de página

```vue
<div class="page-shell">
    <PageHeader title="Contactos" description="Personas que atiende tu equipo." help="contacts">
        <template #actions><Button>Nuevo contacto</Button></template>
    </PageHeader>
    …secciones…
</div>
```

`page-shell` ya no tiene `max-width` ni `margin: auto`. `--page-max` quedó obsoleto: **quita** cualquier
`[--page-max:…]` que encuentres (no hace nada, pero confunde). Las pantallas de altura completa (Conversaciones, Chat
interno) no usan `page-shell`.

### 2.2 Utilidades (definidas en `app.css`)

| Utilidad                           | Qué hace                                                                                                       | Variables                                   |
| ---------------------------------- | -------------------------------------------------------------------------------------------------------------- | ------------------------------------------- |
| `auto-grid`                        | Tantas columnas iguales como quepan (tarjetas, indicadores, filtros).                                          | `--col-min` (18rem), `--grid-gap` (0.75rem) |
| `field-grid`                       | Campos de formulario en columnas; un campo ocupa la fila con `col-span-full`.                                  | `--col-min` (17rem)                         |
| `form-row`                         | Sección de formulario: descripción a la izquierda, controles a la derecha desde `lg`. Úsala con `FormSection`. | —                                           |
| `split-layout`                     | Contenido + panel lateral a la derecha desde `xl`.                                                             | `--aside-w` (22rem)                         |
| `split-layout-start`               | Panel lateral primero (riel de filtros) + contenido.                                                           | `--aside-w` (18rem)                         |
| `surface-panel` / `surface-raised` | Superficie de panel / elevada para elementos sin componente.                                                   | —                                           |
| `font-display`                     | Tipografía de títulos y cifras.                                                                                | —                                           |

```vue
<!-- Indicadores: 1 columna en teléfono, las que quepan en escritorio -->
<div class="auto-grid reveal-stagger [--col-min:14rem]">
    <StatCard v-for="…" />
</div>

<!-- Lista + detalle -->
<div class="split-layout [--aside-w:26rem]">
    <SurfaceCard title="Usuarios">…tabla…</SurfaceCard>
    <aside class="space-y-4">…resumen, ayuda, actividad…</aside>
</div>
```

### 2.3 Formularios y configuración: `FormSection`

Sustituye a las columnas angostas (`max-w-xl`, `max-w-2xl`). Título y descripción a la izquierda, controles en un
panel a la derecha.

```vue
<form novalidate @submit.prevent="submit">
    <FormSection title="Perfil" description="Tu nombre y el correo con el que inicias sesión.">
        <div class="field-grid [--col-min:20rem]">
            <FormField id="name" label="Nombre" :error="errors.name"><Input id="name" v-model="form.name" /></FormField>
            <FormField id="email" label="Correo" :error="errors.email"><Input id="email" v-model="form.email" /></FormField>
        </div>
        <template #footer><Button :loading="form.processing">Guardar</Button></template>
    </FormSection>
</form>
```

Props: `title`, `description`, `flush` (sin relleno: tablas y listas que llegan al borde), `bare` (sin panel: el
contenido ya trae sus paneles). Slots: default, `aside` (bajo la descripción), `footer` (barra de acciones).

### 2.4 Prosa

Los párrafos conservan `max-w-prose` **anclados a la izquierda** (`PageHeader`, `SectionHeading`, `FormSection` y
`EmptyState` ya lo hacen). Un control de texto no debe medir 1 400 px: dentro de `field-grid` cada campo toma una
columna.

## 3. Tokens

- **Tipografía.** `font-display` (Bricolage Grotesque 500/600/700): `<h1>` de página, títulos de sección, de panel,
  de diálogo y de estado vacío, y cifras de indicadores (siempre con `tabular-nums`). Instrument Sans para todo lo
  demás. Roles: h1 `font-display text-3xl font-semibold` (lo pone `PageHeader`), h2 de sección `font-display text-xl`
  (`SectionHeading`, `FormSection`), título de panel `font-display text-lg` (`SurfaceCard`), cuerpo `text-sm`,
  metadatos `text-xs text-muted-foreground`. Sin mayúsculas sostenidas ni etiquetas decorativas sobre los títulos.
- **Radios por jerarquía.** `rounded-md` (8 px) controles · `rounded-lg` (10 px) popovers e ítems agrupados ·
  `rounded-xl` (14 px) paneles · `rounded-2xl` (18 px) diálogos.
- **Sombras** (teñidas con `--shadow-ink`, que sigue a la paleta): `shadow-card` es **vacía** a propósito (los
  paneles descansan planos; no hace falta quitarla, pero no la agregues), `shadow-(--shadow-control)` para botones
  y pulgares, `shadow-hover` al pasar sobre algo navegable, `shadow-popover`, `shadow-elevated`.
- **Paleta.** Cada paleta define `--brand-hue`, `--brand-chroma` e `--ink-chroma`. La barra lateral es una
  **superficie de tinta** derivada de ellas en ambos temas. Cualquier bloque puede ser tinta con el atributo
  `data-ink` (lo usa el escenario del inicio de sesión): dentro de él `text-foreground`, `text-muted-foreground`,
  `bg-primary`, `border`, etc. ya valen para fondo oscuro.
- **Movimiento.** `--duration-fast` 150 ms (controles), `--duration-base` 200 ms (popovers, conmutadores),
  `--duration-panel` 280 ms (paneles, indicadores deslizantes), `--stagger` 36 ms. Curvas: `ease-(--ease-standard)`,
  `ease-(--ease-out-quint)` (entradas), `ease-(--ease-spring)` (pulgares e iconos).

## 4. Componentes compartidos (API sin cambios incompatibles)

| Componente                                                              | Cambios                                                                                                                                                          |
| ----------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `PageHeader`                                                            | El título es **visible por defecto**, en display y a la izquierda. `hideTitle` lo deja solo para lectores de pantalla; `showTitle` se acepta y ya no hace falta. |
| `SurfaceCard`                                                           | Panel plano; título en display. Mismo API (`title`, `description`, `bodyClass`, slots `actions`/`footer`).                                                       |
| `StatCard`                                                              | Cifra en display; los números (o cadenas de solo dígitos) cuentan hasta su valor; luz que sigue al cursor.                                                       |
| `EmptyState`                                                            | Alineado a la izquierda (icono, texto, acción). Mismo API.                                                                                                       |
| `SectionHeading`, `Heading`                                             | Títulos en display.                                                                                                                                              |
| `FormSection`                                                           | **Nuevo** (§2.3).                                                                                                                                                |
| `ViewModeToggle`                                                        | Pulgar deslizante; mismos nombres accesibles («Vista de tabla», «Vista de tarjetas»).                                                                            |
| `AppearanceTabs`                                                        | Ahora es un `Segmented`.                                                                                                                                         |
| `Button`                                                                | Nueva prop `loading` (muestra el indicador, `aria-busy`, bloquea). Sustituye a `<Spinner v-if="processing" />` + `:disabled`.                                    |
| `Table*`                                                                | La fila muestra una barra de acento que entra por la izquierda al pasar el cursor (y en `data-state="selected"`).                                                |
| `Dialog*`, `Sheet*`, `Popover*`, `DropdownMenu*`, `Select*`, `Tooltip*` | Nueva animación (escala y desvanecido desde el disparador; los diálogos suben). Encabezados de diálogo alineados a la izquierda.                                 |
| `Skeleton`                                                              | Barrido de luz en lugar de pulso.                                                                                                                                |
| `Checkbox`, `Switch`, `Input`, `Textarea`, `Badge`, `Card`              | Rediseñados; mismo API.                                                                                                                                          |

`fieldControl` (exportado por `@/components/ui/input`) es la clase base de todo control tipo texto: úsala si
necesitas un disparador propio que se vea igual que un `Input`.

## 5. Controles nuevos

Todos están en `@/components/ui/<carpeta>` y aceptan `class` para el ancho (por defecto ocupan el 100 % de su
contenedor), `disabled`, `invalid` e `id`.

### 5.1 `AppSelect` — sustituye a `<select>`

```vue
<script setup lang="ts">
import { AppSelect } from '@/components/ui/app-select';

const statusOptions = [
    { value: '', label: 'Todos' },
    { value: 'open', label: 'Abiertas' },
    {
        value: 'closed',
        label: 'Cerradas',
        description: 'Finalizadas por una persona',
    },
];
</script>

<template>
    <AppSelect
        v-model="filters.status"
        :options="statusOptions"
        aria-label="Estado"
        @change="apply"
    />
</template>
```

- `options`: `{ value, label, description?, icon?, disabled?, group? }`. `value` puede ser cadena, número, booleano,
  `''` o `null`: el modelo recibe exactamente ese valor (igual que el `<select>` con `:value`).
- `placeholder`, `searchable` (automático con más de 9 opciones; fuerza con `searchable` o `:searchable="false"`),
  `searchPlaceholder`, `emptyText`, `clearable` + `clearValue`, `size="sm"`, `align`, `name` (agrega un
  `<input type="hidden">` para envíos nativos), `contentClass`.
- Evento `change(value)` además de `update:modelValue`.
- Los atributos extra (`data-filter`, `data-tour`…) caen en el contenedor.

**Receta.** `<select v-model="x" @change="f"><option value="">Todos</option><option v-for="o in list" :value="o.id">{{ o.name }}</option></select>`
→ `<AppSelect v-model="x" :options="[{ value: '', label: 'Todos' }, ...list.map((o) => ({ value: o.id, label: o.name }))]" @change="f" />`.
Si el `<select>` estaba dentro de un `<label>`, cambia el `<label>` por un `<div>` y pasa `aria-label` (o usa
`FormField` con `id`).

Los `Select*` de `@/components/ui/select` siguen disponibles para casos con contenido a medida; ya comparten el
aspecto nuevo.

### 5.2 `DatePicker` y `DateRangePicker` — sustituyen a `type="date"`

```vue
<DatePicker v-model="form.due_date" :min="today" />
<DateRangePicker
    v-model:from="filters.from"
    v-model:to="filters.to"
    @change="apply"
/>
```

- El modelo es la misma cadena `YYYY-MM-DD` que producía el input nativo; al borrar vale `emptyValue` (`''` por
  defecto; pasa `:empty-value="null"` si el formulario usa `null`). El backend no cambia.
- `min` / `max` (cadenas ISO), `required` (oculta «Borrar»), `placeholder`, `size="sm"`, `align`, `name` (solo
  `DatePicker`).
- Español de México, semana en lunes, «Hoy» según la zona horaria de la plataforma, salto rápido de mes y año desde
  el título del mes, teclado completo (flechas y Re Pág/Av Pág para cambiar de mes).
- `DateRangePicker`: dos meses en escritorio, uno en teléfono; atajos «Hoy, Ayer, Últimos 7 días, Últimos 30 días,
  Este mes, Mes pasado» (`presets` para cambiarlos, `:presets="false"` para quitarlos). `change({ from, to })` se
  emite una vez por rango completo: úsalo para aplicar el filtro sin botón «Aplicar».
- `Calendar` (mismo módulo) es el calendario suelto, por si hace falta en línea.

**Receta.** Dos `<Input type="date">` «Desde/Hasta» → un `DateRangePicker`. Un `<Input type="date">` → `DatePicker`
con el mismo `v-model`, `min` y `max`.

### 5.3 `TimePicker` — sustituye a `type="time"`

```vue
<TimePicker
    v-model="day.opens_at"
    :minute-step="15"
    aria-label="Hora de apertura"
/>
```

Modelo `HH:mm` (24 h). La hora y los minutos se escriben o se ajustan con las flechas; el botón del reloj abre dos
columnas para elegir con el puntero. `emptyValue`, `required`, `name`, `size`.

### 5.4 `Segmented` y `RadioCards` — sustituyen a los radios nativos

```vue
<Segmented
    v-model="mode"
    :options="[
        { value: 'all', label: 'Todas' },
        { value: 'mine', label: 'Mías' },
    ]"
    aria-label="Alcance"
/>

<RadioCards
    v-model="form.distribution"
    aria-label="Modo de distribución"
    :options="[
        {
            value: 'manual',
            label: 'Manual',
            description: 'Cada agente toma lo que puede atender.',
            icon: Hand,
        },
        {
            value: 'round_robin',
            label: 'Por turnos',
            description: 'Se reparte en orden entre los disponibles.',
            icon: Repeat,
        },
    ]"
/>
```

- `Segmented`: 2–4 opciones cortas, con pulgar deslizante. Props `iconOnly`, `size="sm"`, `block`.
- `RadioCards`: opciones que necesitan una línea de explicación. Fluyen en columnas (`--col-min`, 14rem);
  `class="grid-cols-1"` para apilarlas. `name` agrega el input oculto.
- Ambos son un grupo de radio para teclado y lectores de pantalla y emiten `change(value)`.

### 5.5 `Tabs` — pestañas con subrayado deslizante

```vue
<Tabs v-model="tab">
    <TabsList>
        <TabsTrigger value="general">General</TabsTrigger>
        <TabsTrigger value="sla">Objetivos de servicio</TabsTrigger>
    </TabsList>
    <TabsContent value="general">…</TabsContent>
    <TabsContent value="sla">…</TabsContent>
</Tabs>
```

Para pestañas que son **páginas** (enlaces de Inertia) sigue el patrón de `layouts/settings/Layout.vue`: enlaces con
`aria-current="page"` y `useSlidingIndicator`.

### 5.6 `FileDropzone` — sustituye a `type="file"`

```vue
<FileDropzone
    v-model="form.file"
    accept=".csv,.xlsx"
    :max-size-mb="5"
    :invalid="!!form.errors.file"
/>
<FileDropzone
    v-model="form.files"
    multiple
    :max-files="4"
    compact
    @reject="showProblems"
/>
```

Modelo `File | null` (o `File[]` con `multiple`). Arrastrar y soltar con estado animado, clic/Enter/Espacio para el
diálogo del sistema, lista de archivos con «Quitar», rechazo de tipo/tamaño/cantidad con mensaje (evento `reject`).
Expone `open()` y `clear()`. Los botones de adjuntar de los compositores de chat pueden seguir usando un input
oculto disparado por su botón: ahí no hay control nativo a la vista.

### 5.7 `NumberTicker`

```vue
<NumberTicker :value="128" />
<NumberTicker :value="87.5" :decimals="1" suffix=" %" />
```

Cuenta desde cero al montar y desde el valor anterior al cambiar; con movimiento reducido muestra el valor final.
`StatCard` ya lo usa.

### 5.8 `useSlidingIndicator`

`const { style, animated, update } = useSlidingIndicator(containerRef, '[aria-current=page]')`: posición de un
resaltado único que se desliza entre los elementos de un grupo. El contenedor debe ser `relative`; llama a
`update()` cuando cambie el elemento activo. Lo usan la barra lateral, `Segmented`, `ViewModeToggle` y Mi cuenta.

## 6. Movimiento

| Qué                         | Cómo                                                                                                                                                                                                                                                 |
| --------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Cambio de página            | Automático: transición de vista de Inertia solo cuando cambia la ruta (`app.ts`). El shell no se mueve; el contenido se desvanece y sube. Filtros, paginación y recargas **no** animan. No agregues transiciones propias al contenedor de la página. |
| Entrada de la página        | `reveal-stagger` en **una** rejilla o lista principal (escalona sus primeros 12 hijos), o `reveal` con `style="--i: n"` en elementos sueltos. Una entrada orquestada por página, no una por sección.                                                 |
| Indicadores                 | `StatCard` (contador + `spotlight`). Para una tarjeta propia: clase `spotlight`.                                                                                                                                                                     |
| Tarjetas y filas navegables | `interactive-surface` (borde teñido, sube 2 px, sombra). Filas de tabla: ya incluido en `TableRow` (`row-accent`); para listas propias añade `row-accent` al `<li>`/fila.                                                                            |
| Capas flotantes             | `pop` (popover, menú, select, tooltip) y `dialog-motion` ya están en los componentes de `ui`.                                                                                                                                                        |
| Carga                       | `Skeleton` (barrido) y `Button :loading`.                                                                                                                                                                                                            |
| Navegación lateral          | Resaltado deslizante y precarga al pasar el cursor (`<Link prefetch>`): úsala también en enlaces principales de tus páginas.                                                                                                                         |

**No animes:** el contenido que se actualiza en vivo (mensajes nuevos, contadores en tiempo real más allá del
`NumberTicker`), cada fila de una tabla larga, ni nada en bucle salvo indicadores de carga. `prefers-reduced-motion`
ya desactiva todo lo anterior: no lo evites con estilos en línea.

## 7. Prohibido

- `mx-auto`, `max-w-*` o `[--page-max:…]` en contenedores de página o de sección; columnas de formulario angostas.
  (`max-w-prose` en párrafos y `max-w-*` dentro de diálogos sí.)
- `text-center` / `items-center justify-center` para colocar contenido de página (estados vacíos, encabezados,
  mensajes). Solo se centra dentro de un control (el icono de un botón, una celda numérica).
- Controles nativos visibles: `<select>`, `<input type="date|time|datetime-local|radio|checkbox|file|color|range">`.
  Para color usa `ColorPicker`.
- `shadow-sm`/`shadow-md`/`shadow-lg` y sombras en paneles en reposo; `rounded-full` en paneles.
- Colores fuera de tokens (`bg-white`, `text-black`, `neutral-*`, `slate-*`, `green-600`, hex).
- Mayúsculas sostenidas, eyebrows sobre los títulos, numeración «01 / 02 / 03» que no sea una secuencia real,
  degradados de relleno decorativos.
- Animaciones de más de 300 ms, o transiciones propias en el contenedor `[data-page-content]`.

## 8. Lista de comprobación por página

1. Raíz `page-shell` sin `--page-max`; `PageHeader` con `title` (visible) y `help`.
2. Rejillas con `auto-grid`, `split-layout` o columnas explícitas que aprovechen 1 440 y 1 920 px.
3. Formularios con `FormSection` + `field-grid`; botones con `:loading`.
4. Sin controles nativos (§7); `<select>` → `AppSelect`, fechas → `DatePicker`/`DateRangePicker`, horas →
   `TimePicker`, radios → `Segmented`/`RadioCards`, archivos → `FileDropzone`.
5. Una entrada `reveal-stagger`; tarjetas navegables con `interactive-surface`.
6. Claro y oscuro, 390 px sin desplazamiento horizontal.
7. `npm run types:check` y `npm run check` sin errores.

## 9. Pruebas de navegador

Los escenarios de `tests/e2e` que usan `selectOption(...)` o escriben en inputs `type="date"` fallarán en las
pantallas migradas: deben pasar a abrir el control y elegir la opción por su nombre accesible
(`getByRole('option', { name })` dentro de `AppSelect`; `getByRole('button', { name: 'Elegir mes y año' })` y las
celdas del calendario en los selectores de fecha). `AppSelect` conserva los atributos `data-*` en su contenedor.
