@extends('layouts.editor')
@section('content')
<div class="page-heading"><h1>{{ $book->title }} · {{ __('editor.api_history') }}</h1><span class="count-badge">{{ $logs->total() }}</span></div>
<div class="space-y-4">
@forelse($logs as $log)
    <details class="history-item">
        <summary>#{{ $log->id }} · {{ $log->created_at->format('d.m.Y H:i:s') }} · {{ __('editor.api_'.$log->purpose) }} · {{ __('editor.api_'.$log->status) }}</summary>
        <p class="hint mt-3">{{ $log->model }} · HTTP {{ $log->http_status ?? '—' }} @if($log->error) · {{ $log->error }} @endif</p>
        <p class="text-sm mt-2">{{ __('editor.api_input') }}: {{ $log->prompt_tokens ?? __('editor.api_unavailable') }} · {{ __('editor.api_output') }}: {{ $log->completion_tokens ?? __('editor.api_unavailable') }} · {{ __('editor.tokens') }}: {{ $log->total_tokens ?? __('editor.api_unavailable') }} · {{ __('editor.api_cost') }}: {{ $log->cost ?? __('editor.api_unavailable') }}</p>
        <h2 class="mt-5 text-sm">{{ __('editor.api_request') }}</h2>
        <pre class="whitespace-pre-wrap break-words text-xs mt-2 max-h-96 overflow-auto">{{ $log->request_body }}</pre>
        <h2 class="mt-5 text-sm">{{ __('editor.api_response') }}</h2>
        <pre class="whitespace-pre-wrap break-words text-xs mt-2 max-h-96 overflow-auto">{{ $log->response_body ?? __('editor.api_unavailable') }}</pre>
    </details>
@empty
    <p class="muted">{{ __('editor.api_empty') }}</p>
@endforelse
{{ $logs->links() }}
</div>
@endsection
