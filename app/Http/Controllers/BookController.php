<?php

namespace App\Http\Controllers;

use App\Services\BrowserQueue;
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
            'book' => $book, 'manuscript' => $editor->rendered($book),
            'presets' => PromptTemplate::whereNull('user_id')->orWhere('user_id', auth()->id())->get(),
            'runs' => $book->runs()->latest()->get(),
            'versions' => $book->versions()->latest()->select('id', 'book_id', 'reason', 'created_at')->get(),
            'promptRevisions' => $book->promptRevisions()->latest()->get(),
        ]);
    }

    public function state(Book $book, TextEditor $editor)
    {
        $this->owner($book);
        $run = $book->activeRun?->load('chunks');
        return response()->json([
            'run' => $run, 'version' => $book->version, 'prompt_version' => $book->prompt_version,
            'prompt' => $book->prompt, 'text' => $editor->rendered($book),
            'title' => $book->title, 'language' => $book->language,
            'metadata_status' => $book->metadata_status,
            'learning_failed' => Feedback::where('book_id', $book->id)->where('status', 'failed')->count(),
            'learning_pending' => Feedback::where('book_id', $book->id)->where('status', 'pending')->count(),
        ]);
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
            $this->idle($locked);
            abort_unless($locked->version === $data['version'], 409, __('editor.save_conflict'));
            if ($data['text'] !== $editor->rendered($locked)) {
                $locked->versions()->create(['text' => $data['text'], 'reason' => 'manual']);
                $locked->update(['text' => $data['text'], 'active_run_id' => null, 'version' => $locked->version + 1]);
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
            $locked->versions()->create(['text' => $text, 'reason' => 'run']);
            $chunks = $editor->chunks($text, $data['chunk_size']);
            $run = $locked->runs()->create([
                'prompt' => $locked->prompt, 'context' => $locked->context, 'model' => $data['model'],
                'language' => $locked->language, 'chunk_size' => $data['chunk_size'], 'total_chunks' => count($chunks),
            ]);
            foreach ($chunks as $position => $source) {
                $run->chunks()->create(compact('position', 'source'));
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
        $data = $request->validate(['index' => 'required|integer|min:0', 'status' => 'required|in:accepted,rejected,pending', 'version' => 'required|integer']);
        $learning = false;
        $response = DB::transaction(function () use ($book, $chunk, $data, &$learning) {
            $locked = Book::lockForUpdate()->findOrFail($book->id);
            $this->idle($locked);
            abort_unless($locked->version === $data['version'], 409, __('editor.save_conflict'));
            abort_unless($chunk->editing_run_id === $locked->active_run_id, 404);
            $chunk = Chunk::lockForUpdate()->findOrFail($chunk->id);
            $segments = $chunk->segments;
            abort_unless(isset($segments[$data['index']]) && $segments[$data['index']]['type'] === 'change', 422);
            $segment = $segments[$data['index']];
            if ($segment['status'] !== $data['status']) {
                $segments[$data['index']]['status'] = $data['status'];
                $chunk->update(['segments' => $segments]);
                $locked->increment('version');
                if ($locked->learn && $data['status'] !== 'pending') {
                    Feedback::create(['book_id' => $book->id, 'before' => $segment['before'],
                        'after' => $segment['after'], 'decision' => $data['status']]);
                    $learning = true;
                }
            }
            return response()->json(['version' => $locked->version]);
        });
        if ($learning) { app(BrowserQueue::class)->resume(auth()->id()); }
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
        Feedback::where('book_id', $book->id)->where('status', 'failed')->update(['status' => 'pending']);
        app(BrowserQueue::class)->resume(auth()->id());
        return response()->json(['ok' => true]);
    }

    public function export(Request $request, Book $book, TextEditor $editor, Docx $docx)
    {
        $this->owner($book);
        $this->idle($book);
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
