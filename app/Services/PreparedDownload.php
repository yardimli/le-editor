<?php
namespace App\Services;

use App\Models\Book;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PreparedDownload
{
    public function cleanup(int $userId): void
    {
        $disk = Storage::disk('local');
        foreach ($disk->files("prepared-downloads/$userId") as $file) {
            if ($disk->lastModified($file) < now()->subDay()->timestamp) $disk->delete($file);
        }
    }

    public function store(Book $book, string $source, string $name): string
    {
        $disk = Storage::disk('local');
        $token = (string) Str::uuid();
        $base = "prepared-downloads/{$book->user_id}/$token";
        $stream = fopen($source, 'rb');
        try {
            if (!$disk->put($base.'.docx', $stream) || !$disk->put($base.'.json', json_encode(['book_id'=>$book->id, 'name'=>$name], JSON_THROW_ON_ERROR))) {
                throw new \RuntimeException('Could not save prepared download');
            }
        } catch (\Throwable $e) {
            $disk->delete([$base.'.docx', $base.'.json']);
            throw $e;
        } finally {
            if (is_resource($stream)) fclose($stream);
            if (is_file($source)) unlink($source);
        }
        return $token;
    }

    public function download(Book $book, string $token)
    {
        $disk = Storage::disk('local');
        $base = "prepared-downloads/{$book->user_id}/$token";
        abort_unless(Str::isUuid($token) && $disk->exists($base.'.json') && $disk->exists($base.'.docx'), 404);
        $meta = json_decode($disk->get($base.'.json'), true);
        abort_unless(($meta['book_id'] ?? null) === $book->id, 404);
        return response()->download($disk->path($base.'.docx'), $meta['name'], [
            'Content-Type'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Cache-Control'=>'private, no-store',
        ]);
    }
}
