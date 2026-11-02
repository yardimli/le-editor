<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OriginalDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_original_download_preserves_upload_and_is_private(): void
    {
        Storage::fake();
        $user = User::factory()->create();
        $book = Book::create(['user_id' => $user->id, 'title' => 'Renamed book',
            'original_path' => 'manuscripts/original.docx', 'original_filename' => 'Original.docx',
            'original_text' => 'Original text', 'text' => 'Edited text', 'model' => 'test/model']);
        Storage::put($book->original_path, 'Original uploaded bytes');
        $url = route('books.original', $book);

        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs($user)->get(route('books.index'))->assertOk()->assertSee($url);
        $response = $this->get($url)->assertOk()->assertDownload('Original.docx');
        $this->assertSame('Original uploaded bytes', $response->streamedContent());

        $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
        Storage::delete($book->original_path);
        $this->actingAs($user)->get($url)->assertNotFound();
    }
}
