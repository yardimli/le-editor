<?php
namespace App\Services;

use App\Models\Book;

class EditorialWorkflow
{
    public function say(Book $book, string $key, string $kind = 'event'): void
    {
        $book->editorialMessages()->create(['role' => 'assistant', 'kind' => $kind,
            'content' => trans('editor.'.$key, [], $book->user->locale)]);
    }

    // Called with the account lease and book row locked. Chat never starts a second worker.
    public function apply(Book $book, array $data): void
    {
        $action = $data['action'] ?? 'reply';
        if (!empty($data['prompt'])) {
            $book->update(['prompt' => $data['prompt'], 'prompt_version' => $book->prompt_version + 1, 'learn' => false]);
            $book->promptRevisions()->create(['prompt' => $data['prompt'], 'reason' => 'chat']);
            if ($book->activeRun) $book->activeRun->update(['prompt' => $data['prompt']]);
        }
        if ($action === 'reply' && $book->editorial_stage === 'editing') {
            if (empty($data['suggestions'])) $book->activeRun?->update(['status' => 'queued']);
            else $book->update(['editorial_stage' => 'paused']);
        }
        if ($action === 'plan') {
            $book->update(['title' => $data['title'], 'author' => $data['author'] ?? null,
                'language' => $data['language'], 'editorial_genre' => $data['genre'], 'editorial_plan' => $data['suggestions'],
                'metadata_status' => 'complete', 'metadata_error' => null]);
            if (in_array($book->editorial_stage, ['awaiting_consent', 'editing'])) $book->update(['editorial_stage' => 'ready']);
        }
        if ($action === 'pause') {
            $book->activeRun?->update(['status' => 'cancelled']);
            $book->update(['editorial_stage' => 'paused']);
        }
        if ($action === 'restart_ask') {
            $book->activeRun?->update(['status' => 'cancelled']);
            $book->update(['editorial_stage' => 'restart_choice', 'restart_source' => null]);
        }
        if (in_array($action, ['restart_keep', 'restart_original'])) {
            $book->activeRun?->update(['status' => 'cancelled']);
            $book->update(['editorial_stage' => 'restart_confirmation', 'restart_source' => $action === 'restart_keep' ? 'current' : 'original']);
        }
        if ($action === 'confirm_restart' && $book->editorial_stage === 'restart_confirmation' && $book->restart_source) {
            $this->start($book, $book->restart_source);
        } elseif (in_array($action, ['start', 'resume'])) {
            if (in_array($book->editorial_stage, ['awaiting_consent', 'restart_choice', 'restart_confirmation'])) return;
            if ($book->editorial_stage === 'awaiting_followup' && empty($data['prompt'])) return;
            $run = $book->activeRun;
            if ($run && $run->completed_chunks < $run->total_chunks) {
                if (trim($book->prompt ?? '') === '') return;
                $run->update(['status' => 'queued', 'error' => null, 'prompt' => $book->prompt,
                    'language' => $book->language, 'italicize' => true,
                    'model' => $book->user->default_model ?: config('editor.model'), 'reasoning_effort' => $book->user->reasoning_effort]);
                $book->update(['editorial_stage' => 'editing', 'editorial_question' => null, 'editorial_plan' => $book->editorial_plan ?? []]);
            } elseif (!$run || $run->completed_chunks === 0) {
                $this->start($book, 'current');
            } else {
                $book->update(['editorial_stage' => 'restart_choice']);
                $this->say($book, 'chat_restart_choice');
            }
        }
    }

    public function start(Book $book, string $source): void
    {
        if (trim($book->prompt ?? '') === '') return;
        $editor = app(TextEditor::class); $formatting = app(Italics::class); $styles = app(SourceFormatting::class);
        $text = $source === 'original' ? $book->original_text : $editor->rendered($book, true);
        $italics = $source === 'original' ? $styles->italicRanges($styles->forBook($book)['runs'] ?? []) : $formatting->book($book, true);
        $parts = $editor->chunks($text, $book->chunk_size);
        if (!$parts) return;
        $originals = $editor->originalChunks($book->original_text, $parts);
        $book->activeRun?->update(['status' => 'cancelled']);
        $book->versions()->create(['text' => $text, 'italics' => $italics, 'reason' => 'chat_'.$source]);
        $run = $book->runs()->create(['status' => 'queued', 'prompt' => $book->prompt, 'context' => $book->context,
            'model' => $book->user->default_model ?: config('editor.model'), 'reasoning_effort' => $book->user->reasoning_effort,
            'language' => $book->language, 'italicize' => true, 'chunk_size' => $book->chunk_size, 'total_chunks' => count($parts)]);
        $offset = 0;
        foreach ($parts as $position => $input) {
            $segments = $editor->diff($originals[$position], $input);
            foreach ($segments as &$segment) $segment['status'] = 'accepted';
            unset($segment);
            $segments = $formatting->apply($segments, $formatting->slice($italics, $offset, mb_strlen($input)));
            $run->chunks()->create(['position' => $position, 'source' => $originals[$position], 'input_text' => $input, 'segments' => $segments]);
            $offset += mb_strlen($input);
        }
        $book->update(['active_run_id' => $run->id, 'text' => $text, 'italics' => $italics,
            'editorial_stage' => 'editing', 'editorial_question' => null, 'restart_source' => null,
            'editorial_plan' => $book->editorial_plan ?? [], 'learn' => false, 'version' => $book->version + 1]);
        $book->unsetRelation('activeRun');
    }
}
