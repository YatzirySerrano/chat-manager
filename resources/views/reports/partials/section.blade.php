{{-- An extra sheet printed in the PDF (e.g. a timeline or a permission matrix). --}}
{{-- Short sections are kept on one page; a long one keeps its heading with its first rows. --}}
<section @class(['section', 'new-page' => $section['newPage'], 'keep' => $section['count'] <= 12])>
    <div class="section-head">
        <h2 class="section-title">{{ $section['title'] }}</h2>
        <span class="section-count">{{ number_format($section['count'], 0, '.', ',') }} {{ $section['count'] === 1 ? 'registro' : 'registros' }}</span>
    </div>
    @if ($section['description'])
        <p class="section-description">{{ $section['description'] }}</p>
    @endif

    @if ($section['entries'] === [])
        <div class="empty">Sin registros.</div>
    @else
        <table class="data">
            <colgroup>
                @foreach ($section['widths'] as $width)
                    <col style="width: {{ $width }}%">
                @endforeach
            </colgroup>
            <thead>
                <tr>
                    @foreach ($section['columns'] as $column)
                        <th class="align-{{ $column->alignment()->value }}">{{ $column->label }}</th>
                    @endforeach
                </tr>
            </thead>
            @include('reports.partials.table-rows', ['columns' => $section['columns'], 'entries' => $section['entries'], 'totals' => $section['totals']])
        </table>
    @endif
</section>
