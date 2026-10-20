<?php

namespace App\Http\Controllers;

use App\Services\BrowserQueue;
use App\Services\ChunkReview;
use App\Models\Book;
use App\Models\Chunk;
use App\Models\Feedback;
use App\Models\PromptTemplate;
use App\Services\Docx;
use App\Services\OpenRouter;
use App\Services\TextEditor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class BookController extends Controller
{
    private function owner(Book $book): void { abort_unless($book->user_id === auth()->id(), 404); }
    private function idle(Book $book): void { abort_if($book->activeRun?->isActive(), 409, __('editor.busy')); }

    public function index()
    {
        return view('books.index', ['books' => Book::where('user_id', auth()->id())->latest('updated_at')->paginate(12)]);
    }

    public function store(Request $request, Docx $docx, OpenRouter $api)
    {
        $request->validate(['file' => ['required', 'file', 'max:20480', 'extensions:docx']]);
        try {
            $api->key($request->user());
            $model = $request->user()->default_model ?: config('editor.model');
            $api->model($model);
            $import = $docx->import($request->file('file')->getRealPath());
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['file' => __('editor.'.$e->getMessage()) === 'editor.'.$e->getMessage()
                ? __('editor.import_failed') : __('editor.'.$e->getMessage())]);
        }
        $path = $request->file('file')->store('manuscripts/'.$request->user()->id);
        $book = Book::create([
            'user_id' => $request->user()->id, 'title' => pathinfo($request->file('file')->getClientOriginalName(), PATHINFO_FILENAME),
            'original_path' => $path, 'original_text' => $import['text'], 'text' => $import['text'], 'model' => $model,
        ]);
        $book->versions()->create(['text' => $book->text, 'reason' => 'import']);
        app(BrowserQueue::class)->resume(auth()->id());
        return redirect()->route('books.show', $book)->with('status', __('editor.import_notice'));
    }

    public function show(Book $book, TextEditor $editor)
    {
        $this->owner($book);
        return view('books.show', [
            'book' => $book,
            'presets' => PromptTemplate::whereNull('user_id')->orWhere('user_id', auth()->id())->get(),
            'runs' => $book->runs()->latest()->get(),
            'versions' => $book->versions()->latest()->select('id', 'book_id', 'reason', 'created_at')->get(),
            'promptRevisions' => $book->promptRevisions()->latest()->get(),
        ]);
    }

    public function state(Request $request, Book $book, TextEditor $editor)
    {
        $this->owner($book);
        $request->validate(['chunk' => 'nullable|integer|min:1']);
        $run = $book->activeRun;
        $number = max(1, min($request->integer('chunk', 1), $run?->total_chunks ?? PHP_INT_MAX));
        if ($run) {
            $chunk = $run->chunks()->where('position', $number - 1)->first();
            $count = $run->total_chunks;
        } else {
            $parts = $editor->chunks($book->text, $book->chunk_size);
            $count = max(1, count($parts));
            $number = min($number, $count);
            $chunk = ['id' => null, 'position' => $number - 1, 'source' => $parts[$number - 1] ?? '',
                'status' => 'pending', 'segments' => null, 'revision' => 0, 'ai_edit_count' => 0];
        }
        $waiting = Feedback::where('book_id', $book->id)->where('status', 'awaiting')->count();
        return response()->json([
            'run' => $run, 'version' => $book->version, 'prompt_version' => $book->prompt_version,
            'chunk' => $chunk, 'chunk_count' => $count,
            'prompt' => $book->prompt, 'context' => $book->context, 'learn' => $book->learn,
            'word_count' => $editor->words($book->text),
            'title' => $book->title, 'language' => $book->language,
            'metadata_status' => $book->metadata_status,
            'learning_hint' => $book->learn && !$book->learning_hint_dismissed && $waiting > 20,
            'learning_awaiting' => $waiting, 'learning_requested' => (bool) $book->learning_requested,
            'learning_failed' => Feedback::where('book_id', $book->id)->where('status', 'failed')->count(),
            'learning_pending' => Feedback::where('book_id', $book->id)->where('status', 'pending')->count(),
        ]);
    }

    public function saveChunk(Request $request, Book $book, Chunk $chunk, ChunkReview $review)
    {
        $this->owner($book);
        $data = $request->validate(['text' => 'present|nullable|string|max:1000000', 'revision' => 'required|integer|min:1']);
        $data['text'] ??= '';
        return DB::transaction(function () use ($book, $chunk, $data, $review) {
            $locked = Book::lockForUpdate()->findOrFail($book->id);
            abort_unless($chunk->editing_run_id === $locked->active_run_id, 404);
            $chunk = Chunk::lockForUpdate()->findOrFail($chunk->id);
            abort_unless($chunk->status === 'completed', 409, __('editor.chunk_not_processed'));
            abort_unless($chunk->revision === $data['revision'], 409, __('editor.save_conflict'));
            try { $segments = $review->revise($chunk->segments, $data['text']); }
            catch (\RuntimeException $e) { abort(409, __('editor.save_conflict')); }
            if ($segments !== $chunk->segments) {
                DB::table('chunk_revisions')->insert(['chunk_id' => $chunk->id,
                    'text' => $review->text($chunk->segments), 'created_at' => now(), 'updated_at' => now()]);
                $chunk->update(['segments' => $segments, 'revision' => $chunk->revision + 1]);
                $locked->increment('version');
            }
            return response()->json(['version' => $locked->version, 'chunk' => $chunk]);
        });
    }

    public function history(Book $book, int $run)
    {
        $this->owner($book);
        return view('books.history', ['book' => $book, 'run' => $book->runs()->with('chunks')->findOrFail($run)]);
    }

    public function update(Request $request, Book $book)
    {
        $this->owner($book);
        $data = $request->validate([
            'title' => 'required|string|max:255', 'language' => 'required|in:tr,en',
            'prompt' => 'nullable|string|max:10000', 'context' => 'nullable|string|max:10000',
            'learn' => 'required|boolean', 'prompt_version' => 'required|integer',
        ]);
        DB::transaction(function () use ($data, $book) {
            $locked = Book::lockForUpdate()->findOrFail($book->id);
            $this->idle($locked);
            abort_unless($locked->prompt_version === $data['prompt_version'], 409, __('editor.prompt_conflict'));
            if ($locked->prompt !== ($data['prompt'] ?? null)) {
                $locked->promptRevisions()->create(['prompt' => $locked->prompt ?? '', 'reason' => 'manual']);
                $data['prompt_version']++;
            }
            $data['metadata_status'] = 'complete';
            $locked->update($data);
        });
        return response()->json(['ok' => true, 'prompt_version' => $book->fresh()->prompt_version]);
    }

    public function save(Request $request, Book $book, TextEditor $editor)
    {
        $this->owner($book);
        $data = $request->validate(['text' => 'required|string|max:1000000', 'version' => 'required|integer']);
        return DB::transaction(function () use ($data, $book, $editor) {
            $locked = Book::lockForUpdate()->findOrFail($book->id);
            abort_if($locked->active_run_id, 409, __('editor.chunk_edit_required'));
            abort_unless($locked->version === $data['version'], 409, __('editor.save_conflict'));
            if ($data['text'] !== $editor->rendered($locked)) {
                $locked->versions()->create(['text' => $data['text'], 'reason' => 'manual']);
                $locked->update(['text' => $data['text'], 'version' => $locked->version + 1]);
            }
            return response()->json(['version' => $locked->version]);
        });
    }

    public function run(Request $request, Book $book, OpenRouter $api, TextEditor $editor)
    {
        $this->owner($book);
        $data = $request->validate(['model' => 'required|string|max:255', 'chunk_size' => 'required|integer|min:100|max:10000', 'version' => 'required|integer']);
        try { $api->key($request->user()); $api->model($data['model']); }
        catch (\Throwable $e) { throw ValidationException::withMessages(['model' => __('editor.model_unavailable')]); }
        DB::transaction(function () use ($book, $data, $editor) {
            $locked = Book::lockForUpdate()->findOrFail($book->id);
            $this->idle($locked);
            abort_unless($locked->version === $data['version'], 409, __('editor.save_conflict'));
            abort_unless(trim($locked->prompt ?? '') !== '', 422, __('editor.prompt_required'));
            $text = $editor->rendered($locked);
            abort_unless(trim($text) !== '', 422, __('editor.empty_manuscript'));
            $locked->versions()->create(['text' => $text, 'reason' => 'run']);
            $chunks = $editor->chunks($text, $data['chunk_size']);
            // A pass with the same chunk size preserves chunk identity, original text and edit counts.
            $previous = $locked->activeRun?->chunk_size === $data['chunk_size']
                ? $locked->activeRun->chunks : collect();
            if ($previous->pluck('source')->implode('') !== $locked->original_text) { $previous = collect(); }
            if ($previous->isNotEmpty()) {
                $chunks = $previous->map(fn ($chunk) => app(ChunkReview::class)->text($chunk->segments
                    ?: $editor->diff($chunk->source, $chunk->input_text ?? $chunk->source), false))->all();
            }
            $originals = $previous->isNotEmpty() ? $previous->pluck('source')->all()
                : $editor->originalChunks($locked->original_text, $chunks);
            $run = $locked->runs()->create([
                'prompt' => $locked->prompt, 'context' => $locked->context, 'model' => $data['model'],
                'language' => $locked->language, 'chunk_size' => $data['chunk_size'], 'total_chunks' => count($chunks),
            ]);
            foreach ($chunks as $position => $source) {
                $prior = $previous->get($position);
                $baseline = $originals[$position];
                $accepted = $editor->diff($baseline, $source);
                foreach ($accepted as &$segment) { $segment['status'] = 'accepted'; }
                unset($segment);
                $run->chunks()->create(['position' => $position, 'source' => $baseline, 'input_text' => $source,
                    'segments' => $accepted, 'ai_edit_count' => $prior?->ai_edit_count ?? 0]);
            }
            $locked->update(['text' => $text, 'active_run_id' => $run->id, 'model' => $data['model'],
                'chunk_size' => $data['chunk_size'], 'version' => $locked->version + 1]);
        });
        app(BrowserQueue::class)->resume(auth()->id());
        return response()->json(['ok' => true]);
    }

    public function cancel(Book $book)
    {
        $this->owner($book);
        app(BrowserQueue::class)->pause(auth()->id());
        return response()->json(['ok' => true]);
    }

    public function retry(Book $book)
    {
        $this->owner($book);
        DB::transaction(function () use ($book) {
            $locked = Book::lockForUpdate()->findOrFail($book->id);
            $run = $locked->activeRun()->lockForUpdate()->first();
            abort_unless($run && in_array($run->status, ['failed', 'cancelled']), 409);
            $run->update(['status' => 'queued', 'error' => null]);
        });
        app(BrowserQueue::class)->resume(auth()->id());
        return response()->json(['ok' => true]);
    }

    public function decision(Request $request, Book $book, Chunk $chunk)
    {
        $this->owner($book);
        $data = $request->validate(['index' => 'required|integer|min:0', 'status' => 'required|in:accepted,rejected,pending',
            'version' => 'required_without:revision|integer', 'revision' => 'nullable|integer|min:1']);
        $response = DB::transaction(function () use ($book, $chunk, $data) {
            $locked = Book::lockForUpdate()->findOrFail($book->id);
            if (!isset($data['revision'])) {
                abort_unless($locked->version === $data['version'], 409, __('editor.save_conflict'));
            }
            abort_unless($chunk->editing_run_id === $locked->active_run_id, 404);
            $chunk = Chunk::lockForUpdate()->findOrFail($chunk->id);
            abort_unless($chunk->status === 'completed', 409, __('editor.chunk_not_processed'));
            if (isset($data['revision'])) {
                abort_unless($chunk->revision === $data['revision'], 409, __('editor.save_conflict'));
            }
            $segments = $chunk->segments;
            abort_unless(isset($segments[$data['index']]) && $segments[$data['index']]['type'] === 'change', 422);
            $segment = $segments[$data['index']];
            if ($segment['status'] !== $data['status']) {
                $segments[$data['index']]['status'] = $data['status'];
                $chunk->update(['segments' => $segments, 'revision' => $chunk->revision + 1]);
                $locked->increment('version');
                if ($locked->learn) {
                    $offset = mb_strlen(implode('', array_column(array_slice($segments, 0, $data['index']), 'before')));
                    $identity = ['book_id' => $book->id, 'chunk_id' => $chunk->id,
                        'suggestion_key' => hash('sha256', json_encode([$offset, $segment['before'], $segment['after']]))];
                    if ($data['status'] === 'pending') {
                        Feedback::where($identity)->whereIn('status', ['awaiting', 'pending', 'failed'])->delete();
                    } else {
                        Feedback::updateOrCreate($identity, ['before' => $segment['before'],
                            'after' => $segment['after'], 'decision' => $data['status'], 'status' => 'awaiting']);
                    }
                }
            }
            return response()->json(['version' => $locked->version, 'chunk' => $chunk]);
        });
        // Reviewing never resumes cancelled editing or overrides an explicitly paused queue.
        return $response;
    }

    public function restore(Request $request, Book $book)
    {
        $this->owner($book);
        $data = $request->validate(['kind' => 'required|in:manuscript,prompt', 'id' => 'required|integer']);
        DB::transaction(function () use ($book, $data) {
            $locked = Book::lockForUpdate()->findOrFail($book->id);
            $this->idle($locked);
            if ($data['kind'] === 'prompt') {
                $revision = $locked->promptRevisions()->findOrFail($data['id']);
                $locked->promptRevisions()->create(['prompt' => $locked->prompt ?? '', 'reason' => 'restore']);
                $locked->update(['prompt' => $revision->prompt, 'prompt_version' => $locked->prompt_version + 1]);
            } else {
                $revision = $locked->versions()->findOrFail($data['id']);
                $locked->versions()->create(['text' => app(TextEditor::class)->rendered($locked), 'reason' => 'before_restore']);
                $locked->update(['text' => $revision->text, 'active_run_id' => null, 'version' => $locked->version + 1]);
            }
        });
        return back()->with('status', __('editor.restored'));
    }

    public function retryLearning(Book $book)
    {
        $this->owner($book);
        DB::transaction(function () use ($book) {
            $locked = Book::lockForUpdate()->findOrFail($book->id);
            abort_unless($locked->learn, 422);
            Feedback::where('book_id', $book->id)->whereIn('status', ['awaiting', 'failed'])->update(['status' => 'pending']);
            $locked->update(['learning_requested' => true, 'learning_hint_dismissed' => true]);
        });
        app(BrowserQueue::class)->resume(auth()->id(), false);
        return response()->json(['ok' => true]);
    }

    public function dismissLearning(Book $book)
    {
        $this->owner($book);
        $book->update(['learning_hint_dismissed' => true]);
        return response()->json(['ok' => true]);
    }

    public function export(Request $request, Book $book, TextEditor $editor, Docx $docx)
    {
        $this->owner($book);
        $request->validate(['include_pending' => 'nullable|boolean']);
        $path = $docx->export($book->original_text, $editor->rendered($book, $request->boolean('include_pending')), $book->title);
        return response()->download($path, 'manuscript-'.$book->id.'.docx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'])->deleteFileAfterSend();
    }

    public function destroy(Book $book)
    {
        $this->owner($book);
        Storage::delete($book->original_path);
        $book->delete();
        return redirect()->route('books.index');
    }
}
