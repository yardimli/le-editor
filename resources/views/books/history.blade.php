@extends('layouts.editor')
@section('content')
<div class="page-heading"><div><a class="eyebrow" href="{{ route('books.show', $book) }}">← {{ $book->title }}</a><h1 class="mt-3">{{ __('editor.history') }} / #{{ $run->id }}</h1></div><span class="pill">{{ __('editor.'.$run->status) }}</span></div>
<div class="panel mb-6"><p class="muted text-sm">{{ $run->model }} · {{ $run->chunk_size }} {{ __('editor.words') }} · {{ $run->created_at->format('d.m.Y H:i') }}</p><details class="mt-4"><summary>{{ __('editor.prompt') }}</summary><pre class="whitespace-pre-wrap text-sm mt-4">{{ $run->prompt }}</pre></details></div>
<div class="manuscript-sheet max-w-4xl mx-auto">
    @foreach($run->chunks as $chunk)
        <p class="eyebrow my-5">{{ __('editor.chunk') }} {{ $chunk->position + 1 }}</p>
        <div class="manuscript-text whitespace-pre-wrap">@if($chunk->segments)@foreach($chunk->segments as $segment)@if($segment['type'] === 'equal'){{ $segment['after'] }}@else<span class="change" data-status="{{ $segment['status'] }}"><del>{{ $segment['before'] }}</del><ins>{{ $segment['after'] }}</ins><small class="pill font-sans text-xs mx-1">{{ __('editor.'.$segment['status']) }}</small></span>@endif@endforeach @else{{ $chunk->source }}@endif</div>
    @endforeach
</div>
@endsection
