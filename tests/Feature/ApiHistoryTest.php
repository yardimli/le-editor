<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\ApiLog;
use App\Models\User;
use App\Services\OpenRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApiHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function book(): Book
    {
        return Book::create(['user_id' => User::factory()->create()->id, 'title' => 'Book',
            'original_path' => 'test.docx', 'original_text' => 'Text', 'text' => 'Text', 'model' => 'test/model']);
    }

    public function test_requests_responses_usage_and_failures_are_logged_without_credentials(): void
    {
        $book = $this->book();
        $this->assertSame(1000, $book->fresh()->chunk_size);
        config(['editor.dev_mode' => true, 'editor.api_key' => 'secret-test-key']);
        $body = ['choices' => [['message' => ['content' => 'Edited'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 8, 'total_tokens' => 20, 'cost' => 0.000123],
            'extra' => ['retained' => true]];
        Http::fakeSequence()->push($body)->push(['error' => ['message' => 'secret-test-key']], 429)
            ->push(['choices' => [['message' => ['content' => 'Partial'], 'finish_reason' => 'length']]]);
        $api = app(OpenRouter::class);
        $api->complete($book->user, 'test/model', 'Instructions', 'Manuscript', 100, $book, 'editing');
        $log = $book->apiLogs()->firstOrFail();
        $this->assertSame($body, json_decode($log->response_body, true));
        $this->assertSame('Manuscript', json_decode($log->request_body, true)['messages'][1]['content']);
        $this->assertSame(20, $log->total_tokens);
        $this->assertEquals(0.000123, $log->cost);
        foreach (['rate_limited', 'incomplete_output'] as $error) {
            try {
                $api->complete($book->user, 'test/model', 'Instructions', 'Text', 100, $book, 'editing');
                $this->fail('Expected failure');
            } catch (\RuntimeException $e) {
                $this->assertSame($error, $e->getMessage());
            }
            $this->assertSame('failed', $book->apiLogs()->latest('id')->first()->status);
        }
        $this->assertStringNotContainsString('secret-test-key', ApiLog::all()->toJson());
        $this->assertStringContainsString('[REDACTED]', ApiLog::find($log->id + 1)->response_body);
    }

    public function test_network_failures_preserve_request_without_leaking_exception_details(): void
    {
        $book = $this->book();
        config(['editor.dev_mode' => true, 'editor.api_key' => 'secret-test-key']);
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('secret-test-key'));
        try {
            app(OpenRouter::class)->complete($book->user, 'test/model', 'Instructions', 'Text', 100, $book, 'metadata');
        } catch (\Illuminate\Http\Client\ConnectionException $e) {}
        $log = $book->apiLogs()->firstOrFail();
        $this->assertSame('failed', $log->status);
        $this->assertSame('request_failed', $log->error);
        $this->assertNull($log->response_body);
        $this->assertNull($log->cost);
    }

    public function test_history_is_owned_newest_first_paginated_and_deleted_with_book(): void
    {
        $book = $this->book();
        for ($i = 1; $i <= 51; $i++) {
            $book->apiLogs()->create(['user_id' => $book->user_id, 'model' => 'test/model',
                'purpose' => 'editing', 'status' => 'completed', 'request_body' => 'request-'.$i,
                'response_body' => '<script>unsafe</script>']);
        }
        $url = route('books.api-history', $book);
        $this->actingAs($book->user)->get('/books')->assertSee($url, false);
        $this->get($url)->assertOk()->assertViewHas('logs', fn ($logs) => $logs->count() === 50
            && $logs->first()->request_body === 'request-51' && $logs->last()->request_body === 'request-2')
            ->assertSee('&lt;script&gt;unsafe&lt;/script&gt;', false)->assertDontSee('<script>unsafe</script>', false);
        $this->get($url.'?page=2')->assertOk()->assertViewHas('logs', fn ($logs) => $logs->count() === 1);
        $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
        $book->delete();
        $this->assertDatabaseCount('api_logs', 0);
    }
}
