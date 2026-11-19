<?php
namespace App\Jobs;
use App\Models\EditorialMessage;
use App\Services\BrowserQueue;
use App\Services\EditorialPlanner;
class RespondEditorialChat {
    public function __construct(public int $messageId, public string $token) {}
    public function handle(BrowserQueue $queue, EditorialPlanner $planner): void {
        $message = EditorialMessage::find($this->messageId);
        if (!$message || $message->status !== 'pending') return;
        $book = $message->book;
        if (!$queue->commit($book->user_id, $this->token, fn () => true)) return;
        try {
            $data = $planner->respond($book);
            $queue->commit($book->user_id, $this->token, function () use ($message, $book, $data) {
                $message->update(['status' => 'complete']);
                $book->editorialMessages()->create(['role' => 'assistant', 'content' => $data['message'], 'suggestions' => $data['suggestions']]);
            });
        } catch (\Throwable $e) {
            $queue->commit($book->user_id, $this->token, fn () => $message->update(['status' => 'failed']));
        }
    }
}
