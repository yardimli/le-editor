<?php
namespace App\Services;

use App\Models\Book;
use Illuminate\Support\Facades\DB;

class ExportFilename
{
    public function next(Book $book): string
    {
        return DB::transaction(function () use ($book) {
            $locked = Book::lockForUpdate()->findOrFail($book->id);
            $date = now()->toDateString();
            $count = $locked->export_date === $date ? min(26, $locked->export_count + 1) : 1;
            // Older uploads did not store the original name; use a stable fallback.
            $name = $locked->original_filename ?: 'manuscript-'.$locked->id.'.docx';
            $name = basename(str_replace('\\', '/', $name));
            $name = preg_replace('/[\x00-\x1F\x7F<>:"|?*]/u', '_', $name);
            $locked->timestamps = false;
            $locked->update(['export_date' => $date, 'export_count' => $count]);
            return 'le-editor-'.$date.'-'.chr(64 + $count).'-'.$name;
        });
    }
}
