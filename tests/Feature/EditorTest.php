<?php

namespace Tests\Feature;

use App\Jobs\EditChunk;
use App\Jobs\InferBookMetadata;
use App\Jobs\LearnPrompt;
use App\Models\Book;
use App\Models\Feedback;
use App\Models\User;
use App\Services\Docx;
use App\Services\OpenRouter;
use App\Services\TextEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class EditorTest extends TestCase
{
    use RefreshDatabase;

    private function book(?User $user = null): Book
    {
        $user ??= User::factory()->create();
        return Book::create(['user_id' => $user->id, 'title' => 'Eser', 'original_path' => 'test.docx',
            'original_text' => "Old sentence.\n\nAnother paragraph.", 'text' => "Old sentence.\n\nAnother paragraph.",
            'prompt' => 'Polish lightly.', 'model' => 'test/paid', 'metadata_status' => 'complete']);
    }

    private function catalog(): void
    {
        config(['editor.api_key' => 'server-secret', 'editor.dev_mode' => true, 'editor.model' => 'test/paid']);
        Cache::flush();
        Http::fake(['*/models' => Http::response(['data' => [
            ['id' => 'test/paid', 'name' => 'Paid', 'pricing' => ['completion' => '0.000002', 'prompt' => '0.000001'], 'context_length' => 100000],
            ['id' => 'test/free:free', 'name' => 'Free', 'pricing' => ['completion' => '0']],
            ['id' => 'test/expensive', 'name' => 'Expensive', 'pricing' => ['completion' => '0.0002']],
        ]])]);
    }

    private function runWithChanges(Book $book)
    {
        $run = $book->runs()->create(['status' => 'completed', 'prompt' => $book->prompt, 'model' => $book->model,
            'language' => 'en', 'chunk_size' => 2500, 'total_chunks' => 1, 'completed_chunks' => 1]);
        $run->chunks()->create(['position' => 0, 'source' => $book->text, 'status' => 'completed', 'segments' => [
            ['type' => 'change', 'before' => 'Old', 'after' => 'New', 'status' => 'accepted'],
            ['type' => 'equal', 'before' => ' sentence.', 'after' => ' sentence.', 'status' => 'pending'],
            ['type' => 'change', 'before' => "\n\nAnother", 'after' => "\n\nBetter", 'status' => 'pending'],
            ['type' => 'change', 'before' => ' paragraph.', 'after' => ' section.', 'status' => 'rejected'],
        ]]);
        $book->update(['active_run_id' => $run->id]);
        return $run;
    }

    public function test_catalog_filters_free_and_out_of_range_models_using_server_key(): void
    {
        $this->catalog();
        $models = app(OpenRouter::class)->models();
        $this->assertSame(['test/paid'], array_column($models, 'id'));
        $this->assertSame(2.0, $models[0]['output_price']);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer server-secret'));
    }

    public function test_email_header_control_characters_are_rejected(): void
    {
        $this->postJson('/register', ['name' => 'Test', 'email' => "test@example.com\r\nBcc: attacker@example.com",
            'password' => 'password123', 'password_confirmation' => 'password123'])->assertUnprocessable();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_cancel_during_provider_call_discards_inflight_result(): void
    {
        Queue::fake(); $this->catalog(); app(OpenRouter::class)->models();
        $book = $this->book(); $run = $this->runWithChanges($book);
        $run->update(['status' => 'running', 'completed_chunks' => 0]);
        $run->chunks()->update(['status' => 'pending', 'segments' => null]);
        Http::fake(['*/chat/completions' => function () use ($run) {
            $run->update(['status' => 'cancelled']);
            return Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'A smoother sentence. Another paragraph.']]]]);
        }]);
        (new EditChunk($run->id))->handle(app(OpenRouter::class), app(TextEditor::class));
        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertNull($run->chunks()->first()->segments);
        Queue::assertNotPushed(EditChunk::class);
    }

    public function test_metadata_does_not_overwrite_user_supplied_title(): void
    {
        $this->catalog();
        $book = $this->book(); $book->update(['metadata_status' => 'pending']);
        Http::fake(['*/chat/completions' => function () use ($book) {
            $book->update(['metadata_status' => 'complete', 'title' => 'My title']);
            return Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => '{"title":"AI title","language":"tr"}']]]]);
        }]);
        (new InferBookMetadata($book->id))->handle(app(OpenRouter::class), app(TextEditor::class));
        $this->assertSame('My title', $book->fresh()->title);
    }

    public function test_keys_are_encrypted_and_dev_flag_selects_correct_key(): void
    {
        $user = User::factory()->create();
        $user->update(['openrouter_api_key' => 'personal-secret']);
        $this->assertNotSame('personal-secret', $user->getRawOriginal('openrouter_api_key'));
        $this->assertArrayNotHasKey('openrouter_api_key', $user->toArray());
        config(['editor.dev_mode' => false]);
        $this->assertSame('personal-secret', app(OpenRouter::class)->key($user));
        config(['editor.dev_mode' => true, 'editor.api_key' => 'server-secret']);
        $this->assertSame('server-secret', app(OpenRouter::class)->key($user));
    }

    public function test_chunks_and_word_diff_reconstruct_unicode_and_whitespace_exactly(): void
    {
        $editor = app(TextEditor::class);
        foreach (["  Şiir burada.\nBir dize.\n\nİkinci kıta.  ", str_repeat(" sözcük", 610)."\n\nSon.", "A\n\n\n\nB"] as $text) {
            $chunks = $editor->chunks($text, 100);
            $this->assertSame($text, implode('', $chunks));
            foreach ($chunks as $chunk) $this->assertLessThanOrEqual(100, $editor->words($chunk));
        }
        foreach ([['Merhaba dünya.', 'Merhaba güzel dünya!'], ['', 'New'], ['Delete', ''], ["a\n\nb", "a\n\nc"], ['Same', 'Same']] as [$old, $new]) {
            $segments = $editor->diff($old, $new);
            $this->assertSame($old, implode('', array_column($segments, 'before')));
            $this->assertSame($new, implode('', array_column($segments, 'after')));
        }
    }

    public function test_book_routes_are_owner_scoped(): void
    {
        $book = $this->book();
        $this->actingAs(User::factory()->create());
        $this->get("/books/{$book->id}")->assertNotFound();
        $this->getJson("/books/{$book->id}/state")->assertNotFound();
        $this->get("/books/{$book->id}/export")->assertNotFound();
        $this->delete("/books/{$book->id}")->assertNotFound();
        $this->putJson("/books/{$book->id}/text", ['text' => 'hack', 'version' => 1])->assertNotFound();
    }

    public function test_editor_screens_render_in_both_languages(): void
    {
        $this->seed();
        $book = $this->book();
        foreach (['tr', 'en'] as $locale) {
            $book->user->update(['locale' => $locale]);
            $this->actingAs($book->user);
            foreach (['/books', "/books/{$book->id}", '/prompts', '/settings'] as $url) {
                $this->get($url)->assertOk();
            }
        }
    }

    public function test_rerun_applies_only_accepted_edits_and_snapshots_new_chunk_size(): void
    {
        Queue::fake(); $this->catalog();
        $book = $this->book(); $old = $this->runWithChanges($book);
        $this->actingAs($book->user)->postJson("/books/{$book->id}/run", ['model' => 'test/paid', 'chunk_size' => 100, 'version' => 1])->assertOk();
        $book->refresh();
        $this->assertSame("New sentence.\n\nAnother paragraph.", $book->text);
        $this->assertSame(100, $book->activeRun->chunk_size);
        $this->assertNotSame($old->id, $book->active_run_id);
        $this->assertDatabaseHas('editing_runs', ['id' => $old->id]);
        Queue::assertPushed(EditChunk::class);
    }

    public function test_manual_save_tracks_revision_archives_suggestions_and_rejects_stale_writes(): void
    {
        $book = $this->book(); $run = $this->runWithChanges($book);
        $this->actingAs($book->user)->putJson("/books/{$book->id}/text", ['text' => "  Manual text.\n", 'version' => 1])->assertOk()->assertJson(['version' => 2]);
        $this->assertSame("  Manual text.\n", $book->fresh()->text);
        $this->assertNull($book->fresh()->active_run_id);
        $this->assertDatabaseHas('manuscript_versions', ['book_id' => $book->id, 'reason' => 'manual', 'text' => "  Manual text.\n"]);
        $this->putJson("/books/{$book->id}/text", ['text' => 'stale', 'version' => 1])->assertConflict();
        $this->assertDatabaseHas('editing_runs', ['id' => $run->id]);
    }

    public function test_decisions_queue_learning_and_reject_stale_or_foreign_chunks(): void
    {
        Queue::fake();
        $book = $this->book(); $run = $this->runWithChanges($book); $chunk = $run->chunks()->first();
        $this->actingAs($book->user)->patchJson("/books/{$book->id}/chunks/{$chunk->id}", ['index' => 2, 'status' => 'accepted', 'version' => 1])->assertOk();
        $this->assertDatabaseHas('feedback', ['book_id' => $book->id, 'decision' => 'accepted']);
        Queue::assertPushed(LearnPrompt::class);
        $this->patchJson("/books/{$book->id}/chunks/{$chunk->id}", ['index' => 2, 'status' => 'rejected', 'version' => 1])->assertConflict();
        $other = $this->runWithChanges($this->book())->chunks()->first();
        $this->patchJson("/books/{$book->id}/chunks/{$other->id}", ['index' => 2, 'status' => 'accepted', 'version' => 2])->assertNotFound();
    }

    public function test_cancel_preserves_completed_work_and_prevents_further_calls(): void
    {
        Http::preventStrayRequests();
        $book = $this->book(); $run = $this->runWithChanges($book); $run->update(['status' => 'running']);
        $this->actingAs($book->user)->postJson("/books/{$book->id}/cancel")->assertOk();
        (new EditChunk($run->id))->handle(app(OpenRouter::class), app(TextEditor::class));
        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertSame("New sentence.\n\nAnother paragraph.", app(TextEditor::class)->rendered($book->fresh()));
        Http::assertNothingSent();
    }

    public function test_export_is_real_ooxml_tracks_manual_changes_and_does_not_approve_pending(): void
    {
        $book = $this->book(); $run = $this->runWithChanges($book);
        foreach ([false, true] as $pending) {
            $response = $this->actingAs($book->user)->get("/books/{$book->id}/export?include_pending=".($pending ? '1' : '0'))->assertOk();
            $path = $response->baseResponse->getFile()->getPathname();
            $zip = new ZipArchive; $this->assertTrue($zip->open($path));
            $xml = $zip->getFromName('word/document.xml');
            $this->assertStringContainsString('<w:ins ', $xml);
            $this->assertStringContainsString('<w:del ', $xml);
            $this->assertStringContainsString('<w:delText ', $xml);
            $this->assertStringContainsString('New', $xml);
            $this->assertSame($pending, str_contains($xml, 'Better'));
            $this->assertStringNotContainsString('section.', $xml);
            $zip->close(); unlink($path);
        }
        $this->assertSame('pending', $run->chunks()->first()->segments[2]['status']);
        $path = app(Docx::class)->export('Same text.', 'Same text.', 'Title');
        $this->assertSame('Same text.', app(Docx::class)->import($path)['text']);
        unlink($path);
    }

    public function test_upload_queues_metadata_and_sends_only_first_2000_words(): void
    {
        Queue::fake(); Storage::fake('local'); $this->catalog();
        $text = implode(' ', array_fill(0, 2100, 'kelime'));
        $path = app(Docx::class)->export($text, $text, 'Title');
        $file = new UploadedFile($path, 'book.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);
        $user = User::factory()->create();
        $this->actingAs($user)->post('/books', ['file' => $file])->assertRedirect();
        Queue::assertPushed(InferBookMetadata::class);
        $book = Book::firstOrFail();
        Http::fake(['*/chat/completions' => Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => '{"title":"Yeni Başlık","language":"tr"}']]]])]);
        (new InferBookMetadata($book->id))->handle(app(OpenRouter::class), app(TextEditor::class));
        $this->assertSame('Yeni Başlık', $book->fresh()->title);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/chat/completions')
            && app(TextEditor::class)->words($request['messages'][1]['content']) === 2000);
        unlink($path);
    }

    public function test_editing_job_stores_plain_text_diff_and_usage(): void
    {
        Queue::fake(); $this->catalog(); app(OpenRouter::class)->models();
        $book = $this->book(); $run = $this->runWithChanges($book);
        $run->update(['status' => 'queued', 'completed_chunks' => 0]);
        $run->chunks()->update(['status' => 'pending', 'segments' => null]);
        Http::fake(['*/chat/completions' => Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => "A smooth sentence.\n\nAnother paragraph."]]], 'usage' => ['total_tokens' => 42, 'cost' => 0.001]])]);
        (new EditChunk($run->id))->handle(app(OpenRouter::class), app(TextEditor::class));
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame(42, $run->fresh()->tokens);
        $this->assertNotEmpty($run->chunks()->first()->segments);
    }

    public function test_truncated_output_does_not_become_a_suggestion(): void
    {
        Queue::fake(); $this->catalog(); app(OpenRouter::class)->models();
        $book = $this->book(); $run = $this->runWithChanges($book);
        $run->update(['status' => 'queued', 'completed_chunks' => 0]);
        $run->chunks()->update(['status' => 'pending', 'segments' => null]);
        Http::fake(['*/chat/completions' => Http::response(['choices' => [['finish_reason' => 'length', 'message' => ['content' => 'partial']]]])]);
        (new EditChunk($run->id))->handle(app(OpenRouter::class), app(TextEditor::class));
        $this->assertSame('incomplete_output', $run->fresh()->error);
        $this->assertNull($run->chunks()->first()->segments);
    }

    public function test_prompt_learning_keeps_presets_unchanged_and_versions_book_prompt(): void
    {
        Queue::fake(); $this->catalog(); $this->seed();
        $book = $this->book();
        Feedback::create(['book_id' => $book->id, 'before' => 'old', 'after' => 'new', 'decision' => 'rejected']);
        Http::fake(['*/chat/completions' => Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'Preserve this phrasing.']]]])]);
        (new LearnPrompt($book->id))->handle(app(OpenRouter::class));
        $this->assertSame('Preserve this phrasing.', $book->fresh()->prompt);
        $this->assertDatabaseHas('prompt_revisions', ['book_id' => $book->id, 'prompt' => 'Polish lightly.']);
        $this->assertDatabaseHas('feedback', ['book_id' => $book->id, 'status' => 'complete']);
        $this->assertDatabaseCount('prompt_templates', 10);
    }
}
