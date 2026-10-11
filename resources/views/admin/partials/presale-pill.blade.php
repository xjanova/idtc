@php
    use App\Services\Chain\PresaleReader;
    $status = $p['status'] ?? null;
    $cls = match ($status) {
        'live' => 'pill-ok',
        'paused' => 'pill-warn',
        'unknown' => 'pill-bad',
        default => 'pill-wait',
    };
@endphp
@if (! ($p['deployed'] ?? false))
    <span class="pill-wait">รอ deploy</span>
@elseif ($status)
    <span class="{{ $cls }}">● {{ PresaleReader::STATUS_LABELS[$status] ?? $status }}</span>
@endif
