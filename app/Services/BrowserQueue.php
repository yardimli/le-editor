<?php

namespace App\Services;

use App\Jobs\EditChunk;
use App\Jobs\InferBookMetadata;
use App\Jobs\LearnPrompt;
use App\Models\Book;
use App\Models\EditingRun;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BrowserQueue
{
    // One lease per account prevents competing tabs from sending duplicate requests.
    public function claim(int $userId): ?string
    {
        return DB::transaction(function () use ($userId) {
            $user = User::lockForUpdate()->findOrFail($userId);
            if ($user->llm_paused || ($user->llm_lease_until && $user->llm_lease_until->isFuture())) {
                return null;
            }
            $token = (string) Str::uuid();
            $user->forceFill(['llm_lease' => $token, 'llm_lease_until' => now()->addSeconds(300)])->save();
            return $token;
        });
    }

    // Every write from an LLM response is fenced by the same row lock as cancellation.
    public function commit(int $userId, ?string $token, callable $callback): mixed
    {
        return DB::transaction(function () use ($userId, $token, $callback) {
            $user = User::lockForUpdate()->find($userId);
            if (!$token || !$user || $user->llm_paused || $user->llm_lease !== $token
                || !$user->llm_lease_until?->isFuture()) {
                return null;
            }
            return $callback();
        });
    }

    public function release(int $userId, string $token): void
    {
        User::whereKey($userId)->where('llm_lease', $token)
            ->update(['llm_lease' => null, 'llm_lease_until' => null]);
    }

    public function pause(int $userId): void
    {
        DB::transaction(function () use ($userId) {
            $user = User::lockForUpdate()->findOrFail($userId);
            $user->forceFill(['llm_paused' => true, 'llm_lease' => null, 'llm_lease_until' => null])->save();
            EditingRun::whereIn('id', Book::where('user_id', $userId)->select('active_run_id'))
                ->whereIn('status', ['queued', 'running'])->update(['status' => 'cancelled']);
        });
    }

    public function resume(int $userId): void
    {
        DB::transaction(function () use ($userId) {
            $user = User::lockForUpdate()->findOrFail($userId);
            $user->forceFill(['llm_paused' => false])->save();
            EditingRun::whereIn('id', Book::where('user_id', $userId)->select('active_run_id'))
                ->where('status', 'cancelled')->update(['status' => 'queued', 'error' => null]);
        });
    }

    private function next(int $userId): ?array
    {
        $books = Book::where('user_id', $userId);
        if ($book = (clone $books)->where('metadata_status', 'pending')->orderBy('id')->first()) {
            return ['kind' => 'metadata', 'book_id' => $book->id, 'title' => $book->title];
        }
        $run = EditingRun::whereIn('id', (clone $books)->select('active_run_id'))
            ->whereIn('status', ['queued', 'running'])->orderBy('id')->first();
        if ($run) {
            return ['kind' => 'editing', 'book_id' => $run->book_id, 'title' => $run->book->title,
                'run_id' => $run->id, 'completed' => $run->completed_chunks, 'total' => $run->total_chunks];
        }
        $feedback = Feedback::whereIn('book_id', (clone $books)->where('learn', true)->select('id'))
            ->where('status', 'pending')->orderBy('id')->first();
        if ($feedback) {
            $book = Book::findOrFail($feedback->book_id);
            return ['kind' => 'learning', 'book_id' => $book->id, 'title' => $book->title];
        }
        return null;
    }

    public function state(int $userId): array
    {
        $user = User::findOrFail($userId);
        return ['paused' => (bool) $user->llm_paused,
            'working' => (bool) ($user->llm_lease_until?->isFuture()), 'task' => $this->next($userId)];
    }

    public function step(int $userId): array
    {
        $token = $this->claim($userId);
        if (!$token) { return $this->state($userId); }
        try {
            $task = $this->next($userId);
            if ($task) {
                $job = match ($task['kind']) {
                    'metadata' => new InferBookMetadata($task['book_id'], $token),
                    'editing' => new EditChunk($task['run_id'], $token),
                    'learning' => new LearnPrompt($task['book_id'], $token),
                };
                app()->call([$job, 'handle']);
            }
        } finally {
            $this->release($userId, $token);
        }
        return $this->state($userId);
    }
}
