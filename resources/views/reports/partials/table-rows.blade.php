{{--
    Body of a data table (main table and PDF sections). The last data rows
    and the totals row share an unbreakable row group, so the totals never
    start a page alone; group headings never stay alone at a page bottom
    (styles.blade.php). Zebra stripes are a class because nth-child would
    restart in the second row group.
--}}
@php
    $tailSize = $totals !== null ? min(2, count($entries)) : 0;
    $bodyEntries = array_slice($entries, 0, count($entries) - $tailSize);
    $tailEntries = array_slice($entries, count($entries) - $tailSize);
    $dataRow = 0;
@endphp
@foreach ([['class' => null, 'entries' => $bodyEntries], ['class' => 'table-end', 'entries' => $tailEntries]] as $group)
    @if ($group['entries'] !== [] || ($group['class'] !== null && $totals !== null))
        <tbody @class([$group['class']])>
            @foreach ($group['entries'] as $entry)
                @if ($entry['kind'] === 'group')
                    <tr class="group"><td colspan="{{ count($columns) }}">{{ $entry['label'] }}</td></tr>
                @else
                    <tr @class([$entry['kind'], 'is-even' => $entry['kind'] === 'row' && $dataRow++ % 2 === 1])>
                        @foreach ($columns as $index => $column)
                            <td @class(['align-'.$column->alignment()->value, 'nowrap' => $column->unbreakable !== null || in_array($column->type->value, ['date', 'number', 'currency', 'percent'], true), 'datetime' => $column->type->value === 'datetime'])>{{ $index === 0 && $entry['kind'] === 'subtotal' ? $entry['label'] : $entry['cells'][$index] }}</td>
                        @endforeach
                    </tr>
                @endif
            @endforeach
            @if ($group['class'] !== null && $totals !== null)
                <tr class="totals">
                    @foreach ($columns as $index => $column)
                        <td class="align-{{ $column->alignment()->value }}">{{ $index === 0 ? 'Total' : $totals[$index] }}</td>
                    @endforeach
                </tr>
            @endif
        </tbody>
    @endif
@endforeach
