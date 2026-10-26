@php /** @var array<string, mixed> $geometry */ @endphp
<svg class="chart-svg" viewBox="0 0 {{ $geometry['width'] }} {{ $geometry['height'] }}" width="100%" role="img" aria-label="{{ $chart->title }}">
    @foreach ($geometry['ticks'] as $tick)
        <line x1="{{ $geometry['left'] }}" x2="{{ $geometry['left'] + $geometry['plotWidth'] }}" y1="{{ $tick['y'] }}" y2="{{ $tick['y'] }}" stroke="{{ $loop->first ? '#C3CAD1' : '#ECEFF2' }}" stroke-width="1"/>
        <text x="{{ $geometry['left'] - 6 }}" y="{{ $tick['y'] + 3 }}" text-anchor="end" class="chart-axis">{{ $tick['label'] }}</text>
    @endforeach
    @foreach ($geometry['labels'] as $label)
        <text x="{{ $label['x'] }}" y="{{ $geometry['top'] + $geometry['plotHeight'] + 16 }}" text-anchor="middle" class="chart-axis">{{ $label['label'] }}</text>
    @endforeach
    @foreach ($geometry['series'] as $series)
        @foreach ($series['segments'] as $points)
            <polyline points="{{ $points }}" fill="none" stroke="{{ $series['color'] }}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>
        @endforeach
        @foreach ($series['dots'] as $dot)
            <circle cx="{{ $dot['x'] }}" cy="{{ $dot['y'] }}" r="2.5" fill="#ffffff" stroke="{{ $series['color'] }}" stroke-width="1.5"/>
        @endforeach
        @if ($series['last'] !== null)
            <text x="{{ $series['last']['x'] + 6 }}" y="{{ $series['last']['y'] + 3 }}" class="chart-value">{{ $series['last']['value'] }}</text>
        @endif
    @endforeach
</svg>
@if (count($geometry['series']) > 1)
    <div class="legend">
        @foreach ($geometry['series'] as $series)
            <span class="legend-item"><span class="legend-swatch" style="background: {{ $series['color'] }}"></span>{{ $series['name'] }}</span>
        @endforeach
    </div>
@endif
