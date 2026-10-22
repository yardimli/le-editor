<?php
namespace Tests\Feature;
use App\Models\Book;
use App\Models\BookAnalysis;
use App\Models\User;
use App\Services\OpenRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
class BookAnalysisTest extends TestCase {
 use RefreshDatabase;
 private function book(): Book {
  config(['editor.dev_mode'=>true,'editor.api_key'=>'test-key']);
  $this->partialMock(OpenRouter::class,fn($mock)=>$mock->shouldReceive('model')->andReturn(['id'=>'test/model','context_length'=>100000,'max_output'=>50000]));
  $book=Book::create(['user_id'=>User::factory()->create()->id,'title'=>'Book','original_path'=>'test.docx','original_text'=>'Full manuscript text','text'=>'Full manuscript text','model'=>'test/model']);
  $this->actingAs($book->user); return $book;
 }
 private function createReview(Book $book,string $kind='summary'): int {
  return $this->postJson("/books/{$book->id}/analyses",['kind'=>$kind,'model'=>'test/model','word_count'=>300,'prompt'=>'My review instructions'])->assertCreated()->assertJsonMissingPath('input_text')->json('id');
 }
 public function test_both_review_types_log_full_request_response_tokens_and_cost(): void {
  $book=$this->book();
  $body=['choices'=>[['message'=>['content'=>'Review result'],'finish_reason'=>'stop']], 'usage'=>['prompt_tokens'=>100,'completion_tokens'=>25,'total_tokens'=>125,'cost'=>0.003]];
  Http::fake(['*/chat/completions'=>Http::response($body)]);
  foreach(['summary','logic'] as $kind) {
   $id=$this->createReview($book,$kind);
   $this->postJson("/analyses/$id/step")->assertOk()->assertJsonPath('status','completed')->assertJsonPath('result','Review result');
   $log=$book->apiLogs()->latest('id')->firstOrFail();
   $this->assertSame($kind,$log->purpose);
   $request=json_decode($log->request_body,true);
   $this->assertSame('Full manuscript text',$request['messages'][1]['content']);
   $this->assertStringContainsString('My review instructions',$request['messages'][0]['content']);
   $this->assertSame($body,json_decode($log->response_body,true));
   $this->assertSame(125,$log->total_tokens); $this->assertEquals(.003,$log->cost);
  }
  $this->assertSame(2,BookAnalysis::count());
  $this->get("/books/{$book->id}/analyses")->assertOk()->assertSee('Review result');
 }
 public function test_preferences_reset_and_ownership(): void {
  $book=$this->book();
  $this->patchJson('/analysis-prompts',['kind'=>'summary','prompt'=>'Custom'])->assertOk()->assertJsonPath('prompts.summary','Custom');
  $this->getJson('/analysis-prompts')->assertJsonPath('prompts.summary','Custom');
  $this->patchJson('/analysis-prompts',['kind'=>'summary','reset'=>true])->assertOk();
  $this->assertArrayNotHasKey('summary',$book->user->fresh()->analysis_prompts);
  $id=$this->createReview($book);
  $this->actingAs(User::factory()->create());
  $this->getJson("/analyses/$id")->assertNotFound();
  $this->postJson("/analyses/$id/step")->assertNotFound();
  $this->get("/books/{$book->id}/analyses")->assertNotFound();
 }
 public function test_cancelled_reviews_do_not_send_requests_until_explicit_resume(): void {
  $book=$this->book();Http::fake();$id=$this->createReview($book);
  $this->postJson("/analyses/$id/cancel")->assertJsonPath('status','cancelled');
  $this->postJson("/analyses/$id/step")->assertJsonPath('status','cancelled');Http::assertNothingSent();
  $this->postJson("/analyses/$id/resume")->assertJsonPath('status','queued');
 }
 public function test_late_response_is_logged_but_not_saved_after_cancellation(): void {
  $book=$this->book();$id=$this->createReview($book);
  Http::fake(function() use($id) {
   BookAnalysis::whereKey($id)->update(['status'=>'cancelled','token'=>null]);
   return Http::response(['choices'=>[['message'=>['content'=>'Late result'],'finish_reason'=>'stop']]]);
  });
  $this->postJson("/analyses/$id/step")->assertJsonPath('status','cancelled')->assertJsonPath('result',null);
  $this->assertSame(1,$book->apiLogs()->count());
 }
}
