<?php
use Illuminate\Database\Migrations\Migration;
use App\Models\Chunk;
use App\Models\Book;
use App\Services\ParagraphSpacing;
return new class extends Migration {
    public function up(): void {
        Chunk::whereNotNull('segments')->chunkById(100, function ($chunks) {
            foreach ($chunks as $chunk) {
                $segments = app(ParagraphSpacing::class)->reject($chunk->segments);
                if ($segments !== $chunk->segments) {
                    $chunk->update(['segments' => $segments, 'revision' => $chunk->revision + 1]);
                    Book::where('active_run_id', $chunk->editing_run_id)->increment('version');
                }
            }
        });
    }
    public function down(): void { /* Rejected cosmetic suggestions are intentionally not restored. */ }
};
