@php /** @var array<string, mixed> $geometry */ @endphp
<svg class="chart-svg" viewBox="0 0 {{ $geometry['width'] }} {{ $geometry['height'] }}" width="100%" role="img" aria-label="{{ $chart->title }}">
    @foreach ($geometry['rows'] as $row)
        @foreach ($row['bars'] as $bar)
            @if ($loop->first)
                <text x="0" y="{{ $bar['y'] + 11 }}" class="chart-label">{{ $row['label'] }}</text>
            @endif
            <rect x="{{ $geometry['labelWidth'] }}" y="{{ $bar['y'] }}" width="{{ $geometry['plotWidth'] }}" height="14" rx="3" fill="#F1F4F6"/>
            @if ($bar['width'] > 0)
                <rect x="{{ $geometry['labelWidth'] }}" y="{{ $bar['y'] }}" width="{{ $bar['width'] }}" height="14" rx="3" fill="{{ $bar['color'] }}"/>
            @endif
            <text x="{{ $geometry['labelWidth'] + $geometry['plotWidth'] + 8 }}" y="{{ $bar['y'] + 11 }}" class="chart-value">{{ $bar['value'] }}</text>
        @endforeach
    @endforeach
</svg>
@if ($geometry['legend'] !== [])
    <div class="legend">
        @foreach ($geometry['legend'] as $item)
            <span class="legend-item"><span class="legend-swatch" style="background: {{ $item['color'] }}"></span>{{ $item['name'] }}</span>
        @endforeach
    </div>
@endif
