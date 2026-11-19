<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\User;
use App\Services\Docx;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RevisionAuthorTest extends TestCase
{
    use RefreshDatabase;

    private function revisions(string $path): \DOMXPath
    {
        $zip = new \ZipArchive;
        $zip->open($path);
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        $doc = new \DOMDocument;
        $this->assertTrue($doc->loadXML($xml));
        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        return $xp;
    }

    public function test_setting_defaults_saves_per_user_and_rejects_invalid_names(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->assertSame('LE Editor', $user->fresh()->revision_author);
        $this->actingAs($user)->get('/settings')->assertOk()->assertSee('name="revision_author"', false)->assertSee('LE Editor');
        $data = ['locale' => 'en', 'theme' => 'light', 'revision_author' => 'Özge & "Team" <Review>'];
        $this->patch('/settings', $data)->assertRedirect();
        $this->assertSame($data['revision_author'], $user->fresh()->revision_author);
        $this->assertSame('LE Editor', $other->fresh()->revision_author);
        foreach (['', str_repeat('a', 256), "Name\x01"] as $invalid) {
            $this->patchJson('/settings', array_replace($data, ['revision_author' => $invalid]))->assertUnprocessable()->assertJsonValidationErrors('revision_author');
        }
    }

    public function test_custom_author_is_xml_safe_for_text_paragraph_and_formatting_revisions(): void
    {
        $name = 'Özge & "Team" <Review>';
        $docx = app(Docx::class);
        $path = $docx->export("Same old text.\n\nRemoved paragraph.", "Same new text.", 'Book', [[0, 4]], null, 'en', $name);
        try {
            $xp = $this->revisions($path);
            foreach (['//w:ins', '//w:del', '//w:rPrChange', '//w:pPr/w:rPr/w:del'] as $query) {
                $this->assertGreaterThan(0, $xp->query($query)->length, $query);
            }
            foreach ($xp->query('//@w:author') as $author) $this->assertSame($name, $author->nodeValue);
        } finally { unlink($path); }
        $path = $docx->export('Old text', 'New text', 'Book');
        try {
            foreach ($this->revisions($path)->query('//@w:author') as $author) $this->assertSame('LE Editor', $author->nodeValue);
        } finally { unlink($path); }
    }

    public function test_direct_and_prepared_downloads_use_saved_author(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['revision_author' => 'My editorial team']);
        $book = Book::create(['user_id' => $user->id, 'title' => 'Book', 'original_path' => 'missing.docx',
            'original_text' => 'Original text.', 'text' => 'Revised text.', 'model' => 'test/model']);
        $this->actingAs($user);
        $direct = $this->get("/books/{$book->id}/export")->assertOk();
        $url = $this->postJson("/books/{$book->id}/export/prepare")->assertOk()->json('url');
        $prepared = $this->get($url)->assertOk();
        foreach ([$direct, $prepared] as $response) {
            $xp = $this->revisions($response->baseResponse->getFile()->getPathname());
            $this->assertGreaterThan(0, $xp->query('//@w:author')->length);
            foreach ($xp->query('//@w:author') as $author) $this->assertSame('My editorial team', $author->nodeValue);
        }
        unlink($direct->baseResponse->getFile()->getPathname());
    }
}
