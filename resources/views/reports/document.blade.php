@extends('reports.layout')

@section('content')
    <section class="title-block">
        <div class="eyebrow">Reporte</div>
        <h1>{{ $title }}</h1>
        @if ($subtitle)
            <p class="subtitle">{{ $subtitle }}</p>
        @endif
    </section>

    <div class="filters">
        <span class="filters-label">Filtros</span>
        @forelse ($filters as $filter)
            <span class="chip">{{ $filter }}</span>
        @empty
            <span class="chip">Sin filtros · todos los registros visibles para ti</span>
        @endforelse
    </div>

    @if ($panels !== [])
        <section @class(['panels', 'single' => count($panels) === 1])>
            @foreach ($panels as $panel)
                <div class="panel">
                    <h2 class="panel-title">{{ $panel->title }}</h2>
                    <dl>
                        @foreach ($panel->items as $label => $value)
                            <dt>{{ $label }}</dt>
                            <dd>{{ $value === null || $value === '' ? '—' : $value }}</dd>
                        @endforeach
                    </dl>
                    @if ($panel->note)
                        <p class="panel-note">{{ $panel->note }}</p>
                    @endif
                </div>
            @endforeach
        </section>
    @endif

    @if ($metrics !== [])
        @php
            // Full rows: 6 cards as 2 × 3 rather than 4 + 2.
            $perRow = $orientation->value === 'landscape' ? 5 : 4;
            $metricColumns = count($metrics) > $perRow && count($metrics) % $perRow !== 0 && count($metrics) % ($perRow - 1) === 0 ? $perRow - 1 : min(count($metrics), $perRow);
        @endphp
        <section class="metrics" style="grid-template-columns: repeat({{ $metricColumns }}, 1fr)">
            @foreach ($metrics as $metric)
                <div class="metric">
                    <div class="metric-label">{{ $metric->label }}</div>
                    <div @class(['metric-value', 'is-text' => is_string($metric->value) || $metric->value === null])>{{ $metricValue($metric) }}</div>
                    @if ($metric->hint)
                        <div class="metric-hint">{{ $metric->hint }}</div>
                    @endif
                </div>
            @endforeach
        </section>
    @endif

    @if ($charts !== [])
        <section @class(['charts', 'single' => count($charts) === 1])>
            @foreach ($charts as $item)
                <div class="chart-card">
                    <h2 class="chart-title">{{ $item['chart']->title }}</h2>
                    <p class="chart-subtitle">{{ $item['chart']->subtitle ?? ' ' }}</p>
                    @include('reports.charts.'.$item['chart']->type->value, ['chart' => $item['chart'], 'geometry' => $item['geometry']])
                </div>
            @endforeach
        </section>
    @endif

    @foreach ($sectionsBefore as $section)
        @include('reports.partials.section', ['section' => $section])
    @endforeach

    @if ($includeTable)
        <section @class(['detail', 'after-summary' => ($metrics !== [] || $charts !== []) && $sectionsBefore === [] && $rowCount > 12, 'keep' => $rowCount <= 12])>
            <div class="section-head">
                <h2 class="section-title">{{ $tableTitle }}</h2>
                <span class="section-count">{{ number_format($rowCount, 0, '.', ',') }} {{ $rowCount === 1 ? 'registro' : 'registros' }}</span>
            </div>

            @if ($rowCount === 0)
                <div class="empty">Sin registros para los filtros aplicados.</div>
            @else
                <table class="data">
                    <colgroup>
                        @foreach ($widths as $width)
                            <col style="width: {{ $width }}%">
                        @endforeach
                    </colgroup>
                    <thead>
                        <tr>
                            @foreach ($columns as $column)
                                <th class="align-{{ $column->alignment()->value }}">{{ $column->label }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    @include('reports.partials.table-rows', ['columns' => $columns, 'entries' => $entries, 'totals' => $totals])
                </table>
            @endif
        </section>
    @endif

    @foreach ($sectionsAfter as $section)
        @include('reports.partials.section', ['section' => $section])
    @endforeach

    <p class="document-end">Fin del reporte · Información limitada a los registros que tu rol permite consultar.</p>
@endsection
