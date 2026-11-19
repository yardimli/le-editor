<?php
namespace Tests\Feature;

use App\Models\Book;
use App\Models\User;
use App\Services\BrowserQueue;
use App\Services\EditorialWorkflow;
use App\Services\OpenRouter;
use App\Services\TextEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EditorialConversationTest extends TestCase
{
    use RefreshDatabase;

    private function book(): Book
    {
        $user = User::factory()->create(['locale' => 'en', 'default_model' => 'test/model']);
        $this->actingAs($user);
        return Book::create(['user_id' => $user->id, 'title' => 'Book', 'language' => 'en',
            'original_path' => 'missing.docx', 'original_text' => 'Original manuscript.', 'text' => 'Original manuscript.',
            'model' => 'test/model', 'metadata_status' => 'complete', 'editorial_plan' => [], 'prompt' => 'Preserve voice.']);
    }

    private function answer(array $data, ?\Closure $inspect = null): void
    {
        $reply = array_merge(['action' => 'reply', 'title' => 'Book', 'author' => null, 'language' => 'en', 'genre' => 'fiction',
            'message' => 'Would you like to start editing?', 'suggestions' => []], $data);
        $this->mock(OpenRouter::class, function ($mock) use ($reply, $inspect) {
            $mock->shouldReceive('model')->andReturn(['id' => 'test/model']);
            $mock->shouldReceive('editingBudget')->andReturn(['max_tokens' => 8000, 'reasoning' => []]);
            $mock->shouldReceive('complete')->once()->andReturnUsing(function (...$args) use ($reply, $inspect) {
                if ($inspect) $inspect($args);
                return ['text' => json_encode($reply), 'tokens' => 10, 'cost' => 0.001];
            });
        });
    }

    private function send(Book $book, string $text, array $response): void
    {
        $this->postJson("/books/{$book->id}/chat", ['content' => $text])->assertOk();
        $this->answer($response);
        app(BrowserQueue::class)->step($book->user_id);
    }

    public function test_selections_persist_are_owned_versioned_and_lock_on_submission(): void
    {
        $book = $this->book();
        $message = $book->editorialMessages()->create(['role' => 'assistant', 'content' => 'Choose checks',
            'suggestions' => [['label' => 'Grammar', 'instruction' => 'Fix grammar.', 'reason' => 'Grammar errors.']], 'selected' => []]);
        $url = "/books/{$book->id}/chat/{$message->id}/selections";
        $this->patchJson($url, ['selected' => [0], 'version' => 1])->assertOk()->assertJsonPath('version', 2);
        $this->getJson("/books/{$book->id}/state")->assertJsonPath('chat.0.selected.0', 0);
        $this->patchJson($url, ['selected' => [], 'version' => 1])->assertStatus(409);
        $this->patchJson($url, ['selected' => [8], 'version' => 2])->assertStatus(422);
        $this->actingAs(User::factory()->create())->patchJson($url, ['selected' => [], 'version' => 2])->assertNotFound();
        $this->actingAs($book->user)->postJson("/books/{$book->id}/chat", ['content' => '', 'suggestion_id' => $message->id, 'selection_version' => 2])->assertOk();
        $this->assertNotNull($message->fresh()->submitted_at);
        $this->patchJson($url, ['selected' => [], 'version' => 2])->assertStatus(409);
        $this->answer(['prompt' => 'Fix grammar. Preserve voice.'], function ($args) {
            $input = json_decode($args[3], true);
            $this->assertSame('Fix grammar.', $input['submitted_selections'][0]['instruction']);
        });
        app(BrowserQueue::class)->step($book->user_id);
        $this->assertSame('Fix grammar. Preserve voice.', $book->fresh()->prompt);
        $this->assertNull($book->fresh()->active_run_id);
        $this->send($book, 'Yes', ['action' => 'start']);
        $this->assertSame('queued', $book->fresh()->activeRun->status);
        $this->assertSame('Fix grammar. Preserve voice.', $book->fresh()->activeRun->prompt);
    }

    public function test_restart_requires_source_and_confirmation_and_uses_requested_baseline(): void
    {
        $book = $this->book();
        app(EditorialWorkflow::class)->start($book, 'original');
        $book->refresh(); $oldRun = $book->activeRun;
        $oldRun->chunks()->first()->update(['status' => 'completed', 'output' => 'Edited manuscript.',
            'segments' => app(TextEditor::class)->diff('Original manuscript.', 'Edited manuscript.')]);
        $oldRun->update(['status' => 'completed', 'completed_chunks' => 1]);
        $this->send($book, 'Restart', ['action' => 'restart_ask']);
        $this->assertSame('restart_choice', $book->fresh()->editorial_stage);
        $this->assertSame($oldRun->id, $book->fresh()->active_run_id);
        $this->send($book, 'Keep the edits', ['action' => 'restart_keep', 'prompt' => 'Correct punctuation only.']);
        $this->assertSame('restart_confirmation', $book->fresh()->editorial_stage);
        $this->assertSame($oldRun->id, $book->fresh()->active_run_id);
        $this->send($book, 'Yes', ['action' => 'confirm_restart']);
        $kept = $book->fresh()->activeRun;
        $this->assertNotSame($oldRun->id, $kept->id);
        $this->assertSame('Edited manuscript.', $kept->chunks()->first()->input_text);
        $this->assertSame('Original manuscript.', $kept->chunks()->first()->source);
        $this->send($book, 'Start over with the original', ['action' => 'restart_original']);
        $this->assertSame($kept->id, $book->fresh()->active_run_id);
        $this->send($book, 'Confirm', ['action' => 'confirm_restart']);
        $this->assertSame('Original manuscript.', $book->fresh()->activeRun->chunks()->first()->input_text);
    }

    public function test_context_contains_five_pairs_and_current_prompt_without_progress_displacing_pairs(): void
    {
        $book = $this->book();
        for ($i = 0; $i < 7; $i++) {
            $user = $book->editorialMessages()->create(['role' => 'user', 'content' => "Question $i"]);
            $book->editorialMessages()->create(['role' => 'assistant', 'content' => "Answer $i", 'reply_to' => $user->id]);
            $book->editorialMessages()->create(['role' => 'assistant', 'content' => "Progress $i", 'kind' => 'progress']);
        }
        $this->postJson("/books/{$book->id}/chat", ['content' => 'Current message'])->assertOk();
        $this->answer([], function ($args) {
            $input = json_decode($args[3], true);
            $this->assertCount(5, $input['conversation']);
            $this->assertSame('Question 2', $input['conversation'][0]['user']['content']);
            $this->assertSame('Answer 6', $input['last_assistant_message']);
            $this->assertSame('Current message', $input['current_message']);
            $this->assertSame('Preserve voice.', $input['chosen_instructions']);
        });
        app(BrowserQueue::class)->step($book->user_id);
    }

    public function test_deviation_does_not_commit_edits_and_answer_updates_prompt_before_resume(): void
    {
        $book = $this->book(); app(EditorialWorkflow::class)->start($book, 'original');
        $book->refresh(); $chunk = $book->activeRun->chunks()->first();
        $this->answer(['needs_followup' => true, 'question' => 'Is this abrupt change of narrator intentional?', 'edited_text' => 'Must not be committed.', 'comment' => 'Different narrator.']);
        app(BrowserQueue::class)->step($book->user_id);
        $this->assertSame('awaiting_followup', $book->fresh()->editorial_stage);
        $this->assertSame('cancelled', $book->fresh()->activeRun->status);
        $this->assertNull($chunk->fresh()->output);
        $this->assertSame('pending', $chunk->fresh()->status);
        app(BrowserQueue::class)->resume($book->user_id);
        $this->assertSame('cancelled', $book->fresh()->activeRun->status);
        $this->send($book, 'Yes, preserve the narrator change and continue.', ['action' => 'resume', 'prompt' => 'Preserve voice and the intentional narrator change.']);
        $this->assertSame('queued', $book->fresh()->activeRun->status);
        $this->assertSame('Preserve voice and the intentional narrator change.', $book->fresh()->activeRun->prompt);
        $this->assertNull($book->fresh()->editorial_question);
    }

    public function test_readonly_page_has_one_chat_send_button_and_can_render_preview(): void
    {
        $book = $this->book();
        $this->getJson("/books/{$book->id}/state?all=1")->assertOk();
        $book->editorialMessages()->create(['role' => 'assistant', 'content' => 'I have read the opening. The restrained narrative voice is effective. These checks would help preserve it while improving clarity.',
            'suggestions' => [['label' => 'Clarify sentence structure', 'instruction' => 'Clarify long sentences while preserving the author’s voice.', 'reason' => 'Several long sentences obscure who is speaking.'], ['label' => 'Check consistency', 'instruction' => 'Check names and tense consistency.', 'reason' => 'The opening switches tense in two scenes.']], 'selected' => [0]]);
        $page = $this->get("/books/{$book->id}")->assertOk()->assertDontSee('id="run-button"', false)
            ->assertDontSee('id="chunk-editor"', false)->assertDontSee('id="learning-status"', false)
            ->assertSee('id="chat-send"', false);
        if (getenv('LE_CHAT_PREVIEW')) {
            $state = $this->getJson("/books/{$book->id}/state?all=1")->json();
            $state['chunks'][0]['segments'] = [['type' => 'equal', 'before' => 'The house stood at the end of the lane. ', 'after' => 'The house stood at the end of the lane. ', 'status' => 'accepted'], ['type' => 'change', 'before' => 'It was a house that nobody had lived in for years.', 'after' => 'Nobody had lived there for years.', 'status' => 'pending'], ['type' => 'equal', 'before' => "\n\nElena stopped at the gate. She remembered the garden, but not the silence.", 'after' => "\n\nElena stopped at the gate. She remembered the garden, but not the silence.", 'status' => 'accepted']];
            $state['queue'] = ['paused' => false, 'task' => null, 'working' => false];
            $mock = '<script>window.fetch = async () => ({ok:true, json:async () => ('.json_encode($state).')});</script>';
            $html = str_replace('</head>', $mock.'</head>', $page->getContent());
            $html = preg_replace('~https?://[^/"\s]+/(build|images|favicon|apple-touch)~', 'http://127.0.0.1:8097/$1', $html);
            file_put_contents(base_path('.scaffold/chat-preview.html'), $html);
        }
    }

    public function test_pause_button_fences_pending_response_and_chat_can_resume(): void
    {
        $book = $this->book(); app(EditorialWorkflow::class)->start($book, 'original');
        $queue = app(BrowserQueue::class); $token = $queue->claim($book->user_id);
        $this->postJson("/books/{$book->id}/cancel")->assertOk();
        $this->assertNull($queue->commit($book->user_id, $token, fn () => true));
        $this->assertSame('paused', $book->fresh()->editorial_stage);
        $this->assertStringContainsString('paused', $book->editorialMessages()->latest('id')->first()->content);
        $this->send($book, 'Please continue', ['action' => 'resume']);
        $this->assertSame('queued', $book->fresh()->activeRun->status);
    }
}
