@props(['status'])
@php
    $labels = ['pending' => 'รอยืนยัน', 'confirmed' => 'สำเร็จ', 'failed' => 'ล้มเหลว'];
@endphp
@if ($status)
    <span {{ $attributes->class(['tx', $status]) }}>@if ($status === 'pending')<span class="spin" aria-hidden="true"></span>@endif{{ $labels[$status] ?? $status }}</span>
@endif
