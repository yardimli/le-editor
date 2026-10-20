@extends('layouts.editor')
@section('content')
    @isset($header)<div class="page-heading"><h1>{{ $header }}</h1></div>@endisset
    {{ $slot }}
@endsection
