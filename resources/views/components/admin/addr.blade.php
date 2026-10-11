@props(['address' => null, 'full' => false, 'tx' => false])
@php
    use App\Support\Idtc;
    use App\Services\Chain\Address;
    $valid = $tx ? (is_string($address) && preg_match('/^0x[0-9a-fA-F]{64}$/', $address)) : Address::isValid($address);
    $shown = $valid ? ($tx ? strtolower($address) : Address::checksum($address)) : null;
    $link = $valid ? ($tx ? Idtc::explorerTx($shown) : Idtc::explorerAddress($shown)) : null;
@endphp
@if ($shown)
    <span {{ $attributes->class('ad') }} x-data>
        <code title="{{ $shown }}">{{ $full ? $shown : Idtc::short($shown, $tx ? 10 : 6, $tx ? 6 : 4) }}</code>
        <button type="button" @click="$store.toast.copy(@js($shown))" aria-label="คัดลอก {{ $tx ? 'tx hash' : 'ที่อยู่' }}" title="คัดลอก"><x-admin.icon name="copy"/></button>
        <a href="{{ $link }}" target="_blank" rel="noopener" aria-label="ดูบน explorer" title="ดูบน explorer"><x-admin.icon name="external"/></a>
    </span>
@else
    <span {{ $attributes->class('muted') }}>—</span>
@endif
