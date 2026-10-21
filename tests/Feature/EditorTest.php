<?php

namespace Tests\Feature;

use App\Jobs\EditChunk;
use App\Jobs\InferBookMetadata;
use App\Jobs\LearnPrompt;
use App\Models\Book;
use App\Models\Feedback;
use App\Models\User;
use App\Services\Docx;
use App\Services\BrowserQueue;
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

    public function test_library_links_to_owned_history_download_and_confirmed_delete(): void
    {
        $book = $this->book();
        $this->actingAs($book->user)->get('/books')->assertOk()
            ->assertSee(route('books.activity', $book), false)
            ->assertSee(route('books.export', $book), false)
            ->assertSee('data-confirm=', false);
        $this->get(route('books.activity', $book))->assertOk()->assertSee($book->title);
        $this->actingAs(User::factory()->create())->get(route('books.activity', $book))->assertNotFound();
    }

    public function test_genre_presets_are_bilingual_distinct_and_idempotent(): void
    {
        $this->seed(); $this->seed();
        $this->assertDatabaseCount('prompt_templates', 28);
        foreach (['grammar', 'vocabulary', 'flow', 'clarity', 'formal', 'casual', 'emotion', 'poetic', 'italics'] as $key) {
            $fiction = \App\Models\PromptTemplate::where('slug', "editorial-fiction-{$key}")->firstOrFail();
            $nonfiction = \App\Models\PromptTemplate::where('slug', "editorial-nonfiction-{$key}")->firstOrFail();
            foreach (['tr', 'en'] as $locale) {
                $this->assertSame($fiction->names[$locale], $nonfiction->names[$locale]);
                $this->assertNotSame($fiction->instructions[$locale], $nonfiction->instructions[$locale]);
            }
        }
        $book = $this->book();
        $this->actingAs($book->user)->patchJson("/books/{$book->id}", [
            'title' => $book->title, 'language' => 'tr', 'prompt' => 'Edit carefully', 'learn' => true,
            'prompt_version' => 1, 'editorial_genre' => 'nonfiction', 'italicize' => true,
        ])->assertOk();
        $this->getJson("/books/{$book->id}/state")->assertJsonPath('editorial_genre', 'nonfiction')->assertJsonPath('italicize', true);
    }

    public function test_manual_italics_save_export_and_rerun_preserve_formatting(): void
    {
        $this->catalog();
        $book = $this->book(); $run = $this->runWithChanges($book); $chunk = $run->chunks()->first();
        $text = app(\App\Services\ChunkReview::class)->text($chunk->segments);
        $this->actingAs($book->user)->putJson("/books/{$book->id}/chunks/{$chunk->id}/text", [
            'text' => $text, 'revision' => $chunk->revision, 'italics' => [[0, 3]],
        ])->assertOk();
        $formatting = app(\App\Services\Italics::class);
        $this->assertSame([[0, 3]], $formatting->book($book->fresh()));
        $path = app(Docx::class)->export($book->original_text, app(TextEditor::class)->rendered($book->fresh()), 'Test', [[0, 3]]);
        $zip = new ZipArchive; $zip->open($path); $xml = $zip->getFromName('word/document.xml'); $zip->close(); unlink($path);
        $this->assertStringContainsString('<w:i/>', $xml);
        $this->postJson("/books/{$book->id}/run", ['model' => 'test/paid', 'chunk_size' => 101, 'version' => $book->fresh()->version])->assertOk();
        $this->assertSame([[0, 3]], $formatting->book($book->fresh()));
    }

    public function test_ai_italic_only_suggestions_are_reviewable_and_optional_on_export(): void
    {
        $this->catalog(); app(OpenRouter::class)->models();
        $book = $this->book(); $run = $this->runWithChanges($book);
        $run->update(['status' => 'queued', 'completed_chunks' => 0, 'italicize' => true]);
        $run->chunks()->update(['status' => 'pending', 'segments' => app(TextEditor::class)->diff($book->text, $book->text), 'input_text' => $book->text]);
        Http::fake(['*/chat/completions' => Http::response(['choices' => [['finish_reason' => 'stop',
            'message' => ['content' => "<le-italic>Old</le-italic> sentence.\n\nAnother paragraph."]]]])]);
        (new EditChunk($run->id, app(BrowserQueue::class)->claim($book->user_id)))->handle(app(OpenRouter::class), app(TextEditor::class));
        $chunk = $run->chunks()->first();
        $this->assertSame('change', $chunk->segments[0]['type']);
        $this->assertSame('pending', $chunk->segments[0]['status']);
        $formatting = app(\App\Services\Italics::class);
        $this->assertSame([], $formatting->book($book->fresh()));
        $this->assertSame([[0, 3]], $formatting->book($book->fresh(), true));
        $this->actingAs($book->user)->patchJson("/books/{$book->id}/chunks/{$chunk->id}", [
            'index' => 0, 'status' => 'accepted', 'revision' => $chunk->revision,
        ])->assertOk();
        $this->assertSame([[0, 3]], $formatting->book($book->fresh()));
        $path = app(Docx::class)->export($book->text, $book->text, 'Test', [[0, 3], [15, 22]]);
        $zip = new ZipArchive; $zip->open($path); $xml = $zip->getFromName('word/document.xml'); $zip->close(); unlink($path);
        $this->assertStringContainsString('<w:rPrChange', $xml);
        $doc = new \DOMDocument; $this->assertTrue($doc->loadXML($xml));
        $xp = new \DOMXPath($doc); $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $this->assertSame('Old', $xp->query('//w:r[w:rPr/w:i]/w:t')->item(0)->textContent);
        $this->assertSame('Another', $xp->query('//w:r[w:rPr/w:i]/w:t')->item(1)->textContent);
    }

    public function test_book_poll_returns_only_changed_sections_and_library_does_not_poll(): void
    {
        $book = $this->book(); $run = $this->runWithChanges($book);
        $this->actingAs($book->user);
        $initial = $this->getJson("/books/{$book->id}/state?chunk=1")->assertOk();
        $tokens = $initial->json('tokens');
        $known = http_build_query(['chunk' => 1, 'known_book' => $tokens['book'], 'known_run' => $tokens['run'], 'known_chunk' => $tokens['chunk']]);
        $idle = $this->getJson("/books/{$book->id}/state?{$known}")->assertOk();
        $this->assertSame(['queue', 'tokens'], array_keys($idle->json()));
        $this->assertLessThan(1024, strlen($idle->getContent()));
        $this->assertArrayNotHasKey('run', $initial->json('chunk'));
        $this->assertArrayNotHasKey('source', $initial->json('chunk'));
        $this->assertArrayNotHasKey('prompt', $initial->json('run'));
        $run->update(['completed_chunks' => 0]);
        $progress = $this->getJson("/books/{$book->id}/state?{$known}")->assertOk();
        $this->assertArrayHasKey('run', $progress->json());
        $this->assertArrayNotHasKey('chunk', $progress->json());
        $this->assertArrayNotHasKey('prompt', $progress->json());
        $chunk = $run->chunks()->first(); $chunk->increment('revision');
        $changed = $this->getJson("/books/{$book->id}/state?{$known}")->assertOk();
        $this->assertArrayHasKey('chunk', $changed->json());
        $this->get('/books')->assertOk()->assertDontSee('id="queue-notice"', false)->assertDontSee('window.leQueue = true', false);
    }

    private function catalog(): void
    {
        config(['editor.providers' => ['test'], 'editor.api_key' => 'server-secret', 'editor.dev_mode' => true, 'editor.model' => 'test/paid']);
        Cache::flush();
        Http::fake(['*/models' => Http::response(['data' => [
            ['id' => 'test/paid', 'name' => 'Paid', 'pricing' => ['completion' => '0.000002', 'prompt' => '0.000001'], 'context_length' => 100000],
            ['id' => 'test/free:free', 'name' => 'Free', 'pricing' => ['completion' => '0']],
            ['id' => 'test/expensive', 'name' => 'Expensive', 'pricing' => ['completion' => '0.0002']],
        ]])]);
    }

    public function test_browser_requests_process_one_chunk_at_a_time_and_resume_after_return(): void
    {
        $this->catalog(); app(OpenRouter::class)->models();
        $book = $this->book(); $run = $this->runWithChanges($book);
        $run->update(['status' => 'queued', 'completed_chunks' => 0, 'total_chunks' => 2]);
        $run->chunks()->update(['status' => 'pending', 'segments' => null]);
        $run->chunks()->create(['position' => 1, 'source' => $book->text]);
        Http::fake(['*/chat/completions' => Http::response(['choices' => [['finish_reason' => 'stop',
            'message' => ['content' => "A smooth sentence.\n\nAnother paragraph."]]]])]);
        $this->actingAs($book->user)->postJson('/queue/step')->assertOk();
        $this->assertSame(1, $run->fresh()->completed_chunks);
        // Reading state, refreshing and returning never perform a completion themselves.
        $this->getJson('/queue/state')->assertJsonPath('task.completed', 1);
        $this->get("/books/{$book->id}")->assertOk();
        $this->assertSame(1, $run->fresh()->completed_chunks);
        $this->postJson('/queue/step')->assertOk()->assertJsonPath('task', null);
        $this->assertSame(2, $run->fresh()->completed_chunks);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_cancel_persists_across_page_loads_until_explicit_resume(): void
    {
        Http::preventStrayRequests();
        $book = $this->book(); $run = $this->runWithChanges($book);
        $run->update(['status' => 'running']);
        $this->actingAs($book->user)->postJson('/queue/cancel')->assertJsonPath('paused', true);
        $this->get('/books')->assertOk();
        $this->getJson('/queue/state')->assertJsonPath('paused', true);
        $this->postJson('/queue/step')->assertJsonPath('paused', true);
        $this->assertSame('cancelled', $run->fresh()->status);
        Http::assertNothingSent();
        $this->postJson('/queue/resume')->assertJsonPath('paused', false);
        $this->assertSame('queued', $run->fresh()->status);
    }

    public function test_queue_is_authenticated_and_scoped_to_current_account(): void
    {
        Http::preventStrayRequests();
        $book = $this->book(); $book->update(['metadata_status' => 'pending']);
        $this->postJson('/queue/step')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/queue/state')->assertJsonPath('task', null);
        $this->postJson('/queue/step')->assertJsonPath('task', null);
        $this->postJson('/queue/cancel')->assertOk();
        $this->assertFalse($book->user->fresh()->llm_paused);
        $this->assertSame('pending', $book->fresh()->metadata_status);
        Http::assertNothingSent();
    }

    public function test_competing_tabs_wait_and_expired_leases_cannot_commit(): void
    {
        Http::preventStrayRequests();
        $book = $this->book(); $book->update(['metadata_status' => 'pending']);
        $queue = app(BrowserQueue::class);
        $old = $queue->claim($book->user_id);
        $this->assertNotNull($old);
        $this->assertNull($queue->claim($book->user_id));
        $this->actingAs($book->user)->postJson('/queue/step')->assertJsonPath('working', true);
        Http::assertNothingSent();
        $this->travel(301)->seconds();
        $new = $queue->claim($book->user_id);
        $this->assertNotSame($old, $new);
        $this->assertNull($queue->commit($book->user_id, $old, fn () => $book->update(['title' => 'Stale'])));
        $queue->release($book->user_id, $old);
        $this->assertSame($new, $book->user->fresh()->llm_lease);
        $this->assertSame('Eser', $book->fresh()->title);
        $this->travelBack();
    }

    public function test_cancel_then_resume_rejects_old_metadata_and_learning_responses(): void
    {
        $this->catalog();
        $book = $this->book(); $queue = app(BrowserQueue::class);
        foreach (['metadata', 'learning'] as $kind) {
            $book->update(['metadata_status' => $kind === 'metadata' ? 'pending' : 'complete']);
            if ($kind === 'learning') {
                $book->update(['learning_requested' => true]);
                Feedback::create(['book_id' => $book->id, 'before' => 'old', 'after' => 'new', 'decision' => 'accepted']);
            }
            Http::fake(['*/chat/completions' => function () use ($queue, $book, $kind) {
                $queue->pause($book->user_id);
                $queue->resume($book->user_id);
                return Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['content' =>
                    $kind === 'metadata' ? '{"title":"Late title","language":"en"}' : 'Late prompt']]]]);
            }]);
            $queue->step($book->user_id);
            $this->assertSame('Eser', $book->fresh()->title);
            $this->assertSame('Polish lightly.', $book->fresh()->prompt);
        }
        $this->assertDatabaseHas('feedback', ['book_id' => $book->id, 'status' => 'pending']);
    }

    public function test_legacy_worker_jobs_without_a_browser_lease_do_nothing(): void
    {
        Http::preventStrayRequests();
        $book = $this->book(); $book->update(['metadata_status' => 'pending']);
        (new InferBookMetadata($book->id))->handle(app(OpenRouter::class), app(TextEditor::class));
        (new LearnPrompt($book->id))->handle(app(OpenRouter::class));
        Http::assertNothingSent();
        $this->assertSame('pending', $book->fresh()->metadata_status);
    }

    public function test_environment_locale_default_and_user_switch_are_respected(): void
    {
        config(['app.locale' => 'en']);
        $this->get('/register')->assertSee('lang="en"', false);
        $this->post('/register', ['name' => 'Reader', 'email' => 'reader@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123'])->assertRedirect();
        $this->assertSame('en', User::where('email', 'reader@example.com')->first()->locale);
        $this->post('/locale', ['locale' => 'tr'])->assertRedirect();
        $this->get('/books')->assertSee('lang="tr"', false);
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

    public function test_catalog_groups_allowed_providers_by_price_and_excludes_batch_and_image_models(): void
    {
        Cache::flush();
        config(['editor.api_key' => 'server-secret', 'editor.providers' => ['openai', 'anthropic'], 'editor.min_output_price' => 1, 'editor.max_output_price' => 10]);
        $model = fn ($id, $name, $price) => ['id' => $id, 'name' => $name, 'pricing' => ['completion' => (string) ($price / 1000000)]];
        Http::fake(['*/models' => Http::response(['data' => [
            $model('anthropic/low', 'Low', 2), $model('openai/high', 'High', 10),
            $model('openai/low', 'Low', 1), $model('openai/batch-test', 'Test', 3),
            $model('openai/picture', 'Test IMAGE Generation', 3), $model('openai/image-test', 'Test', 3),
            $model('openai/hidden', 'Test BATCH', 3), $model('other/low', 'Low', 2),
            $model('openai/too-low', 'Low', 0.5), $model('openai/too-high', 'High', 11),
            $model('openai/free', 'Free', 0), $model('openai/test:free', 'Free variant', 2),
        ]])]);
        $models = app(OpenRouter::class)->models();
        $this->assertSame(['openai/low', 'openai/high', 'anthropic/low'], array_column($models, 'id'));
        $this->assertSame(['openai', 'openai', 'anthropic'], array_column($models, 'provider'));
        config(['editor.providers' => ['anthropic']]);
        $this->assertSame(['anthropic/low'], array_column(app(OpenRouter::class)->models(), 'id'));
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
        (new EditChunk($run->id, app(BrowserQueue::class)->claim($book->user_id)))->handle(app(OpenRouter::class), app(TextEditor::class));
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
        (new InferBookMetadata($book->id, app(BrowserQueue::class)->claim($book->user_id)))->handle(app(OpenRouter::class), app(TextEditor::class));
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
        app(BrowserQueue::class)->pause($book->user_id);
        $this->actingAs($book->user)->postJson("/books/{$book->id}/run", ['model' => 'test/paid', 'chunk_size' => 100, 'version' => 1])->assertOk();
        $book->refresh();
        $this->assertFalse($book->user->fresh()->llm_paused);
        $this->assertSame("New sentence.\n\nAnother paragraph.", $book->text);
        $this->assertSame(100, $book->activeRun->chunk_size);
        $this->assertNotSame($old->id, $book->active_run_id);
        $this->assertDatabaseHas('editing_runs', ['id' => $old->id]);
        Queue::assertNotPushed(EditChunk::class);
    }

    public function test_pre_run_manual_save_tracks_revision_and_rejects_stale_writes(): void
    {
        $book = $this->book();
        $this->actingAs($book->user)->putJson("/books/{$book->id}/text", ['text' => "  Manual text.\n", 'version' => 1])->assertOk()->assertJson(['version' => 2]);
        $this->assertSame("  Manual text.\n", $book->fresh()->text);
        $this->assertNull($book->fresh()->active_run_id);
        $this->assertDatabaseHas('manuscript_versions', ['book_id' => $book->id, 'reason' => 'manual', 'text' => "  Manual text.\n"]);
        $this->putJson("/books/{$book->id}/text", ['text' => 'stale', 'version' => 1])->assertConflict();
    }

    public function test_decisions_queue_learning_and_reject_stale_or_foreign_chunks(): void
    {
        Queue::fake();
        $book = $this->book(); $run = $this->runWithChanges($book); $chunk = $run->chunks()->first();
        $this->actingAs($book->user)->patchJson("/books/{$book->id}/chunks/{$chunk->id}", ['index' => 2, 'status' => 'accepted', 'version' => 1])->assertOk();
        $this->assertDatabaseHas('feedback', ['book_id' => $book->id, 'decision' => 'accepted']);
        Queue::assertNotPushed(LearnPrompt::class);
        $this->patchJson("/books/{$book->id}/chunks/{$chunk->id}", ['index' => 2, 'status' => 'rejected', 'version' => 1])->assertConflict();
        $other = $this->runWithChanges($this->book())->chunks()->first();
        $this->patchJson("/books/{$book->id}/chunks/{$other->id}", ['index' => 2, 'status' => 'accepted', 'version' => 2])->assertNotFound();
    }

    public function test_reviewing_a_cancelled_run_does_not_resume_it_or_unpause_the_queue(): void
    {
        $book = $this->book();
        $run = $this->runWithChanges($book);
        $run->update(['status' => 'cancelled']);
        app(BrowserQueue::class)->pause($book->user_id);
        $chunk = $run->chunks()->first();
        $this->actingAs($book->user)->patchJson("/books/{$book->id}/chunks/{$chunk->id}",
            ['index' => 2, 'status' => 'accepted', 'version' => 1])->assertOk();
        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertTrue($book->user->fresh()->llm_paused);
        $this->assertDatabaseHas('feedback', ['book_id' => $book->id, 'status' => 'awaiting']);
        $this->postJson("/books/{$book->id}/learning/retry")->assertOk();
        $this->assertFalse($book->user->fresh()->llm_paused);
        $this->assertSame('cancelled', $run->fresh()->status);
    }

    public function test_chunk_can_be_saved_and_reviewed_while_other_chunks_run(): void
    {
        $book = $this->book(); $run = $this->runWithChanges($book);
        $run->update(['status' => 'running', 'total_chunks' => 2]);
        $pending = $run->chunks()->create(['position' => 1, 'source' => 'Untouched.']);
        $chunk = $run->chunks()->first();
        $this->actingAs($book->user)->putJson("/books/{$book->id}/chunks/{$chunk->id}/text", [
            'text' => "New sentence.\n\nBetter paragraph. Added.", 'revision' => $chunk->revision,
        ])->assertOk()->assertJsonPath('chunk.revision', 2);
        $this->assertSame('running', $run->fresh()->status);
        $this->assertSame($chunk->source, $chunk->fresh()->source);
        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertDatabaseCount('chunk_revisions', 1);
        $this->putJson("/books/{$book->id}/chunks/{$chunk->id}/text", ['text' => 'stale', 'revision' => 1])->assertConflict();
        $this->putJson("/books/{$book->id}/chunks/{$pending->id}/text", ['text' => 'early', 'revision' => 1])->assertConflict();
        $chunk->refresh();
        $index = collect($chunk->segments)->search(fn ($s) => $s['status'] === 'pending' && $s['type'] === 'change');
        $this->assertNotFalse($index);
        $this->patchJson("/books/{$book->id}/chunks/{$chunk->id}", [
            'index' => $index, 'status' => 'accepted', 'revision' => $chunk->revision,
        ])->assertOk();
        $this->assertSame('running', $run->fresh()->status);
        $this->assertSame($run->id, $book->fresh()->active_run_id);
    }

    public function test_state_returns_only_requested_chunk_and_counts_processed_chunks(): void
    {
        $book = $this->book(); $run = $this->runWithChanges($book);
        $run->update(['total_chunks' => 2, 'status' => 'running']);
        $pending = $run->chunks()->create(['position' => 1, 'source' => 'Next chunk.']);
        $this->actingAs($book->user)->getJson("/books/{$book->id}/state?chunk=2")
            ->assertOk()->assertJsonPath('chunk.id', $pending->id)->assertJsonPath('chunk_count', 2)
            ->assertJsonPath('run.completed_chunks', 1)->assertJsonMissingPath('run.chunks')->assertJsonMissingPath('text');
        $this->getJson("/books/{$book->id}/state?chunk=999")->assertJsonPath('chunk.id', $pending->id);
        $this->getJson("/books/{$book->id}/state?chunk=0")->assertUnprocessable();
    }

    public function test_empty_chunk_author_edit_is_saved_without_losing_the_original(): void
    {
        $book = $this->book(); $run = $this->runWithChanges($book); $chunk = $run->chunks()->first();
        $this->actingAs($book->user)->putJson("/books/{$book->id}/chunks/{$chunk->id}/text",
            ['text' => '', 'revision' => 1])->assertOk();
        $this->assertSame('', app(TextEditor::class)->rendered($book->fresh()));
        $this->assertSame($book->original_text, $chunk->fresh()->source);
        $this->assertSame($run->id, $book->fresh()->active_run_id);
    }

    public function test_learning_hint_after_twenty_decisions_is_optional_and_dismissible(): void
    {
        Http::preventStrayRequests();
        $book = $this->book();
        for ($i = 0; $i < 20; $i++) {
            Feedback::create(['book_id' => $book->id, 'before' => "old $i", 'after' => "new $i",
                'decision' => 'accepted', 'status' => 'awaiting']);
        }
        $this->actingAs($book->user)->getJson("/books/{$book->id}/state")->assertJsonPath('learning_hint', false);
        Feedback::create(['book_id' => $book->id, 'before' => 'last', 'after' => 'final',
            'decision' => 'accepted', 'status' => 'awaiting']);
        $this->getJson("/books/{$book->id}/state")->assertJsonPath('learning_hint', true);
        $this->postJson('/queue/step')->assertOk()->assertJsonPath('task', null);
        Http::assertNothingSent();
        $this->postJson("/books/{$book->id}/learning/dismiss")->assertOk();
        $this->getJson("/books/{$book->id}/state")->assertJsonPath('learning_hint', false);
        $this->catalog();
        Http::fake(['*/chat/completions' => Http::response(['choices' => [['finish_reason' => 'stop',
            'message' => ['content' => 'A learned prompt.']]]])]);
        $this->postJson("/books/{$book->id}/learning/retry")->assertOk();
        $this->postJson('/queue/step')->assertOk();
        $this->assertSame('A learned prompt.', $book->fresh()->prompt);
        $this->assertSame(21, Feedback::where('book_id', $book->id)->where('status', 'complete')->count());
        $this->assertFalse($book->fresh()->learning_requested);
    }

    public function test_ai_edit_counter_and_original_survive_a_same_size_rerun(): void
    {
        $this->catalog(); app(OpenRouter::class)->models();
        $book = $this->book(); $run = $this->runWithChanges($book);
        $original = $run->chunks()->first();
        $original->update(['ai_edit_count' => 2]);
        $this->actingAs($book->user)->postJson("/books/{$book->id}/run",
            ['model' => 'test/paid', 'chunk_size' => 2500, 'version' => 1])->assertOk();
        $new = $book->fresh()->activeRun->chunks()->first();
        $this->assertSame($original->source, $new->source);
        $this->assertSame(2, $new->ai_edit_count);
        $this->assertSame("New sentence.\n\nAnother paragraph.", $new->input_text);
        Http::fake(['*/chat/completions' => Http::response(['choices' => [['finish_reason' => 'stop',
            'message' => ['content' => "New sentence.\n\nAnother paragraph."]]]])]);
        $this->postJson('/queue/step')->assertOk();
        $this->assertSame(3, $new->fresh()->ai_edit_count);
        $this->assertSame($original->source, $new->fresh()->source);
    }

    public function test_cancel_preserves_completed_work_and_prevents_further_calls(): void
    {
        Http::preventStrayRequests();
        $book = $this->book(); $run = $this->runWithChanges($book); $run->update(['status' => 'running']);
        $this->actingAs($book->user)->postJson("/books/{$book->id}/cancel")->assertOk();
        (new EditChunk($run->id, app(BrowserQueue::class)->claim($book->user_id)))->handle(app(OpenRouter::class), app(TextEditor::class));
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
        Queue::assertNotPushed(InferBookMetadata::class);
        $book = Book::firstOrFail();
        Http::fake(['*/chat/completions' => Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => '{"title":"Yeni Başlık","language":"tr"}']]]])]);
        (new InferBookMetadata($book->id, app(BrowserQueue::class)->claim($book->user_id)))->handle(app(OpenRouter::class), app(TextEditor::class));
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
        (new EditChunk($run->id, app(BrowserQueue::class)->claim($book->user_id)))->handle(app(OpenRouter::class), app(TextEditor::class));
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
        (new EditChunk($run->id, app(BrowserQueue::class)->claim($book->user_id)))->handle(app(OpenRouter::class), app(TextEditor::class));
        $this->assertSame('incomplete_output', $run->fresh()->error);
        $this->assertNull($run->chunks()->first()->segments);
    }

    public function test_prompt_learning_keeps_presets_unchanged_and_versions_book_prompt(): void
    {
        Queue::fake(); $this->catalog(); $this->seed();
        $book = $this->book(); $book->update(['learning_requested' => true]);
        Feedback::create(['book_id' => $book->id, 'before' => 'old', 'after' => 'new', 'decision' => 'rejected']);
        Http::fake(['*/chat/completions' => Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'Preserve this phrasing.']]]])]);
        (new LearnPrompt($book->id, app(BrowserQueue::class)->claim($book->user_id)))->handle(app(OpenRouter::class));
        $this->assertSame('Preserve this phrasing.', $book->fresh()->prompt);
        $this->assertDatabaseHas('prompt_revisions', ['book_id' => $book->id, 'prompt' => 'Polish lightly.']);
        $this->assertDatabaseHas('feedback', ['book_id' => $book->id, 'status' => 'complete']);
        $this->assertDatabaseCount('prompt_templates', 28);
    }
}
