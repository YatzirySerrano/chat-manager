{{-- An extra sheet printed in the PDF (e.g. a timeline or a permission matrix). --}}
{{-- Short sections are kept on one page so their heading never stays alone. --}}
<section @class(['section', 'new-page' => $section['newPage'], 'keep' => count($section['rows']) <= 12])>
    <div class="section-head">
        <h2 class="section-title">{{ $section['title'] }}</h2>
        <span class="section-count">{{ number_format(count($section['rows']), 0, '.', ',') }} {{ count($section['rows']) === 1 ? 'registro' : 'registros' }}</span>
    </div>
    @if ($section['description'])
        <p class="section-description">{{ $section['description'] }}</p>
    @endif

    @if ($section['rows'] === [])
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
            <tbody>
                @foreach ($section['rows'] as $cells)
                    <tr class="row">
                        @foreach ($section['columns'] as $index => $column)
                            <td @class(['align-'.$column->alignment()->value, 'nowrap' => $column->unbreakable !== null || in_array($column->type->value, ['date', 'number', 'currency', 'percent'], true), 'datetime' => $column->type->value === 'datetime'])>{{ $cells[$index] }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</section>
