@extends('layouts.editor')
@section('content')
<div class="page-heading api-history-heading"><h1>{{ $book->title }} · {{ __('editor.analyses') }}</h1></div>
<div class="flex gap-3 mb-4">
@foreach(['summary','logic'] as $kind)<button class="button-secondary" data-analysis-book="{{ $book->id }}" data-analysis-kind="{{ $kind }}">{{ __('editor.create_'.$kind) }}</button>@endforeach
</div>
@forelse($analyses as $analysis)
<details class="history-item"><summary>{{ __('editor.create_'.$analysis->kind) }} · {{ $analysis->created_at->format('d.m.Y H:i') }} · {{ __('editor.analysis_'.$analysis->status) }}</summary>
<p class="hint">{{ $analysis->model }} · {{ $analysis->word_count }} {{ __('editor.words') }} · v{{ $analysis->book_version }}</p>
<details class="my-3"><summary>{{ __('editor.prompt') }}</summary><p class="whitespace-pre-wrap text-sm">{{ $analysis->prompt }}</p></details>
<pre class="whitespace-pre-wrap text-sm">{{ $analysis->result }}</pre>
@if($analysis->status !== 'completed')<button type="button" class="button-secondary mt-3" data-analysis-resume="{{ $analysis->id }}">{{ __('editor.resume') }}</button>@endif
</details>
@empty<p class="muted">{{ __('editor.analysis_empty') }}</p>@endforelse
{{ $analyses->links() }}
@include('components.book-analysis')
@endsection
