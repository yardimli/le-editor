<?php

namespace App\Jobs;

use App\Models\Book;
use App\Services\OpenRouter;
use App\Services\BrowserQueue;
use App\Services\TextEditor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class InferBookMetadata implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public $tries = 1;
    public $timeout = 210;
    public function __construct(public int $bookId, public ?string $token = null) {}

    public function handle(OpenRouter $api, TextEditor $editor): void
    {
        $book = Book::find($this->bookId);
        if (!($this->token ?? null) || !$book || $book->metadata_status !== 'pending') { return; }
        if (!app(BrowserQueue::class)->commit($book->user_id, $this->token, fn () => true)) { return; }
        try {
            $data = app(\App\Services\EditorialPlanner::class)->respond($book, true);
            app(BrowserQueue::class)->commit($book->user_id, $this->token, function () use ($book, $data) {
                $locked = Book::lockForUpdate()->find($book->id);
                if (!$locked || $locked->metadata_status !== 'pending') return;
                $locked->update(['title' => $data['title'], 'author' => $data['author'] ?? null,
                    'language' => $data['language'], 'editorial_genre' => $data['genre'], 'editorial_plan' => $data['suggestions'],
                    'metadata_status' => 'complete', 'metadata_error' => null]);
                $locked->editorialMessages()->create(['role' => 'assistant', 'content' => $data['message'], 'suggestions' => $data['suggestions']]);
            });
        } catch (\Throwable $e) {
            app(BrowserQueue::class)->commit($book->user_id, $this->token, fn () => Book::whereKey($book->id)->where('metadata_status', 'pending')
                ->update(['metadata_status' => 'failed', 'metadata_error' => 'metadata_failed']));
        }
    }

}
