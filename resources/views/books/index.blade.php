@extends('layouts.editor')
@section('content')
<div class="page-heading"><div><p class="eyebrow">LE EDITOR / {{ __('editor.library') }}</p><h1>{{ __('editor.library') }}</h1><p class="muted mt-3">{{ __('editor.intro') }}</p></div><span class="count-badge">{{ $books->total() }}</span></div>
<div class="grid lg:grid-cols-[1fr_340px] gap-8 items-start">
    <section class="grid sm:grid-cols-2 xl:grid-cols-3 gap-5">
        @forelse($books as $book)
            <a href="{{ route('books.show', $book) }}" class="book-card group">
                <div class="book-cover"><span>{{ strtoupper($book->language) }}</span><h2>{{ $book->title }}</h2><small>LE / {{ str_pad($book->id, 3, '0', STR_PAD_LEFT) }}</small></div>
                <div class="p-5"><h3 class="font-semibold truncate">{{ $book->title }}</h3><p class="muted text-xs mt-2">{{ number_format(app(\App\Services\TextEditor::class)->words($book->text)) }} {{ __('editor.words') }} · {{ $book->updated_at->format('d.m.Y') }}</p><span class="text-accent text-sm inline-block mt-5">{{ __('editor.open') }} ↗</span></div>
            </a>
        @empty
            <div class="panel sm:col-span-2 py-20 text-center"><span class="text-5xl text-accent">¶</span><h2 class="mt-6">{{ __('editor.empty_library') }}</h2><p class="muted mt-3">{{ __('editor.empty_hint') }}</p></div>
        @endforelse
        <div class="col-span-full">{{ $books->links() }}</div>
    </section>
    <aside class="panel">
        <p class="eyebrow">01 / DOCX</p><h2 class="mt-3">{{ __('editor.upload') }}</h2>
        <form action="{{ route('books.store') }}" method="POST" enctype="multipart/form-data" class="mt-6 space-y-5" data-upload-form>@csrf
            <label class="upload-area"><span class="text-3xl">↥</span><span>{{ __('editor.upload_hint') }}</span><input class="w-full text-sm" type="file" name="file" accept=".docx" required></label>
            <p class="muted text-xs leading-relaxed">{{ __('editor.privacy') }}</p>
            <button class="button w-full">{{ __('editor.upload') }} →</button>
        </form>
    </aside>
</div>
@endsection
