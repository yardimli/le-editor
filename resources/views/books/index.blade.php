@extends('layouts.editor')
@section('content')
<div class="page-heading"><div><p class="eyebrow">LE EDITOR / {{ __('editor.library') }}</p><h1>{{ __('editor.library') }}</h1><p class="muted mt-3">{{ __('editor.intro') }}</p></div><span class="count-badge">{{ $books->total() }}</span></div>
<div class="grid lg:grid-cols-[1fr_340px] gap-8 items-start">
    <section class="grid sm:grid-cols-2 xl:grid-cols-3 gap-5">
        @forelse($books as $book)
            <article class="book-card group"><a href="{{ route('books.show', $book) }}" class="block">
                <div class="book-cover"><span>{{ strtoupper($book->language) }}</span><h2>{{ $book->title }}</h2><small>LE / {{ str_pad($book->id, 3, '0', STR_PAD_LEFT) }}</small></div>
                <div class="p-5"><h3 class="font-semibold truncate">{{ $book->title }}</h3><p class="muted text-xs mt-2">{{ number_format(app(\App\Services\TextEditor::class)->words($book->text)) }} {{ __('editor.words') }} · {{ $book->updated_at->format('d.m.Y') }}</p><span class="text-accent text-sm inline-block mt-5">{{ __('editor.open') }} ↗</span></div>
            </a><div class="px-5 pb-5 flex flex-wrap gap-3 text-xs">
                <button type="button" class="text-accent" data-analysis-book="{{ $book->id }}" data-analysis-kind="summary">{{ __('editor.create_summary') }}</button>
                <button type="button" class="text-accent" data-analysis-book="{{ $book->id }}" data-analysis-kind="logic">{{ __('editor.create_logic') }}</button>
                <a href="{{ route('books.analyses', $book) }}" class="text-accent">{{ __('editor.analyses') }}</a>
                <button type="button" class="text-accent" data-open-dialog="book-details-{{ $book->id }}">{{ __('editor.book_details') }}</button>
                <a href="{{ route('books.api-history', $book) }}" class="text-accent">{{ __('editor.api_history') }}</a>
                <a href="{{ route('books.activity', $book) }}" class="text-accent">{{ __('editor.history') }}</a>
                <a data-docx-download href="{{ route('books.export', $book) }}" class="text-accent">{{ __('editor.export') }}</a>
                <form method="POST" action="{{ route('books.destroy', $book) }}" data-confirm="{{ __('editor.delete_confirm') }}">@csrf @method('DELETE')<button type="submit" class="text-accent">{{ __('editor.delete') }}</button></form>
            </div>
            <dialog id="book-details-{{ $book->id }}" class="editor-dialog">
                <h2>{{ __('editor.book_details') }}</h2>
                <form action="{{ route('books.details', $book) }}" method="POST" class="space-y-3 mt-3">@csrf @method('PATCH')
                    <input type="hidden" name="version" value="{{ $book->version }}">
                    <label class="block">{{ __('editor.title') }}<input name="title" value="{{ $book->title }}" required maxlength="255"></label>
                    <label class="block">{{ __('editor.language') }}<select name="language"><option value="tr" @selected($book->language === 'tr')>Türkçe</option><option value="en" @selected($book->language === 'en')>English</option></select></label>
                    <label class="block">{{ __('editor.context') }}<textarea name="context" rows="3" maxlength="10000">{{ $book->context }}</textarea></label>
                    <label class="block">{{ __('editor.chunk_size') }}<input name="chunk_size" type="number" min="100" max="10000" value="{{ $book->chunk_size }}" required></label>
                    <div class="flex gap-2"><button class="button">{{ __('editor.save_settings') }}</button><button type="button" class="button-secondary" data-close-dialog>{{ __('editor.close') }}</button></div>
                </form>
            </dialog></article>
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
@include('components.book-analysis')
@endsection
