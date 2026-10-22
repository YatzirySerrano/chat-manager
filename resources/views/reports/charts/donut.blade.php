@php /** @var array<string, mixed> $geometry */ $center = $geometry['size'] / 2; @endphp
<div class="donut">
    <svg class="donut-svg" viewBox="0 0 {{ $geometry['size'] }} {{ $geometry['size'] }}" width="{{ $geometry['size'] }}" height="{{ $geometry['size'] }}" role="img" aria-label="{{ $chart->title }}">
        <circle cx="{{ $center }}" cy="{{ $center }}" r="{{ $geometry['radius'] }}" fill="none" stroke="#F1F4F6" stroke-width="{{ $geometry['stroke'] }}"/>
        <g transform="rotate(-90 {{ $center }} {{ $center }})">
            @foreach ($geometry['segments'] as $segment)
                <circle cx="{{ $center }}" cy="{{ $center }}" r="{{ $geometry['radius'] }}" fill="none" stroke="{{ $segment['color'] }}" stroke-width="{{ $geometry['stroke'] }}" stroke-dasharray="{{ $segment['dash'] }}" stroke-dashoffset="{{ $segment['offset'] }}"/>
            @endforeach
        </g>
        <text x="{{ $center }}" y="{{ $center + 2 }}" text-anchor="middle" class="donut-total">{{ $geometry['total'] }}</text>
        <text x="{{ $center }}" y="{{ $center + 16 }}" text-anchor="middle" class="donut-caption">total</text>
    </svg>
    <table class="donut-legend">
        @foreach ($geometry['segments'] as $segment)
            <tr>
                <td><span class="legend-swatch" style="background: {{ $segment['color'] }}"></span>{{ $segment['label'] }}</td>
                <td class="num strong">{{ $segment['value'] }}</td>
                <td class="num muted">{{ $segment['percent'] }}</td>
            </tr>
        @endforeach
    </table>
</div>
