<?php
namespace Tests\Feature;
use App\Models\Book;
use App\Models\User;
use App\Services\ExportFilename;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class ExportFilenameTest extends TestCase {
    use RefreshDatabase;
    public function test_names_preserve_upload_name_reset_daily_and_stop_at_z(): void {
        $this->travelTo(now()->setDate(2026, 10, 21)->startOfDay());
        $book = Book::create(['user_id'=>User::factory()->create()->id,'title'=>'Changed title',
            'original_path'=>'stored.docx','original_filename'=>'Özgün Eser.docx','original_text'=>'Text','text'=>'Text','model'=>'test/model']);
        $service = app(ExportFilename::class);
        $this->assertSame('le-editor-2026-10-21-A-Özgün Eser.docx', $service->next($book));
        $this->assertSame('le-editor-2026-10-21-B-Özgün Eser.docx', $service->next($book));
        $book->update(['export_count'=>25]);
        $this->assertSame('le-editor-2026-10-21-Z-Özgün Eser.docx', $service->next($book));
        $this->assertSame('le-editor-2026-10-21-Z-Özgün Eser.docx', $service->next($book));
        $this->travel(1)->days();
        $this->assertSame('le-editor-2026-10-22-A-Özgün Eser.docx', $service->next($book));
        $book->update(['original_filename'=>null]);
        $this->assertSame('le-editor-2026-10-22-B-manuscript-'.$book->id.'.docx', $service->next($book));
    }
}
