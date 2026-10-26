/** A row of a chart tooltip: the value leads, the series name follows. */
export type ChartTooltipRow = {
    key: string;
    name: string;
    value: string;
    color: string;
    /** Key of the row: a short line (lines) or a square (bars, segments). */
    shape?: 'line' | 'dashed' | 'square';
};

export type ChartLegendItem = {
    key: string;
    label: string;
    color: string;
    shape?: 'line' | 'dashed' | 'square';
    /** Optional figure shown next to the label (e.g. «12 · 40 %»). */
    value?: string;
};

export type ChartTableColumn = {
    key: string;
    label: string;
    align?: 'left' | 'right';
    /** Turns the raw cell into text; null/undefined cells show «—». */
    format?: (value: unknown, row: Record<string, unknown>) => string;
};

export type ValueFormat = 'number' | 'duration' | 'percent';

export type LineSeries = {
    key: string;
    name: string;
    /** null = not measured: the line is cut (never drawn as 0). */
    values: (number | null)[];
    color?: string;
    area?: boolean;
    dashed?: boolean;
};

export type BarItem = {
    key: string;
    label: string;
    values: number[];
    /** Entity color (e.g. a channel) for single series charts. */
    color?: string;
    /** Rendered muted (e.g. «Sin agente», «Otros»). */
    muted?: boolean;
};

export type BarSeries = { key: string; name: string; color?: string };
