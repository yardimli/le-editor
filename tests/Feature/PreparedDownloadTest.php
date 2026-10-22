<?php
namespace Tests\Feature;
use App\Models\Book;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
class PreparedDownloadTest extends TestCase {
 use RefreshDatabase;
 public function test_prepare_download_is_private_reusable_and_cleans_only_old_files(): void {
  Storage::fake('local');
  $user=User::factory()->create();
  $book=Book::create(['user_id'=>$user->id,'title'=>'Book','original_path'=>'missing.docx','original_filename'=>'Original.docx','original_text'=>'Text','text'=>'Text','model'=>'test/model']);
  $disk=Storage::disk('local');$old="prepared-downloads/{$user->id}/old.docx";$recent="prepared-downloads/{$user->id}/recent.docx";
  $disk->put($old,'old');touch($disk->path($old),now()->subDays(2)->timestamp);$disk->put($recent,'recent');
  $other='prepared-downloads/999/old.docx';$disk->put($other,'other');touch($disk->path($other),now()->subDays(2)->timestamp);
  $this->actingAs($user);
  $url=$this->postJson("/books/{$book->id}/export/prepare")->assertOk()->json('url');
  $disk->assertMissing($old);$disk->assertExists($recent);$disk->assertExists($other);
  $response=$this->get($url)->assertOk();
  $this->assertStringContainsString('Original.docx',$response->headers->get('content-disposition'));
  $this->get($url)->assertOk();
  $this->actingAs(User::factory()->create());$this->get($url)->assertNotFound();
  $this->postJson("/books/{$book->id}/export/prepare")->assertNotFound();
 }
}
