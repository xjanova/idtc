@extends('layouts.site')

@section('content')
    <div class="page-head">
        <div class="wrap">
            <p class="kicker">{{ __('addnet.kicker') }}</p>
            <h1>{!! __('addnet.h1') !!}</h1>
            <p class="lead">{{ __('addnet.lead') }}</p>
        </div>
    </div>
    <div class="wrap" style="padding-block: 8px 80px">
        @include('partials.add-network')
    </div>
@endsection
