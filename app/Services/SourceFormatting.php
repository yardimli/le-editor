<?php
namespace App\Services;

use App\Models\Book;
use Illuminate\Support\Facades\Storage;

class SourceFormatting
{
    public function forBook(Book $book): array
    {
        if ($book->original_formatting !== null) return $book->original_formatting;
        if (!Storage::exists($book->original_path)) return [];
        $import = app(Docx::class)->import(Storage::path($book->original_path));
        // Recover formatting for older uploads only when the original text still matches exactly.
        if ($import['text'] !== $book->original_text) return [];
        $book->original_formatting = $import['formatting'];
        $book->saveQuietly();
        return $book->original_formatting;
    }

    public function slice(array $spans, int $offset, int $length): array
    {
        $result = [];
        foreach ($spans as $span) {
            $a = max($offset, $span['start']); $b = min($offset + $length, $span['end']);
            if ($a < $b) $result[] = array_merge($span, ['start' => $a - $offset, 'end' => $b - $offset]);
        }
        return $result;
    }

    /** Build once for repeated range lookups, including overlapping spans. */
    public function slicer(array $spans): \Closure
    {
        usort($spans, fn ($a, $b) => $a['start'] <=> $b['start']);
        $ends = []; $max = 0;
        foreach ($spans as $span) { $max = max($max, $span['end']); $ends[] = $max; }
        return static function (int $offset, int $length) use ($spans, $ends): array {
            if ($length <= 0) return [];
            $lo = 0; $hi = count($spans);
            while ($lo < $hi) { $mid = intdiv($lo + $hi, 2); if ($ends[$mid] <= $offset) $lo = $mid + 1; else $hi = $mid; }
            $result = []; $limit = $offset + $length;
            for ($i = $lo; $i < count($spans) && $spans[$i]['start'] < $limit; $i++) {
                $span = $spans[$i]; $a = max($offset, $span['start']); $b = min($limit, $span['end']);
                if ($a < $b) $result[] = array_merge($span, ['start' => $a - $offset, 'end' => $b - $offset]);
            }
            return $result;
        };
    }

    /** Equal text keeps exact runs; replacements inherit source styles proportionally. */
    public function project(string $before, string $after, array $spans): array
    {
        if ($before === $after || !$spans) return $spans;
        $slice = $this->slicer($spans);
        $result = []; $old = 0; $new = 0;
        foreach (app(TextEditor::class)->diff($before, $after) as $segment) {
            $a = mb_strlen($segment['before']); $b = mb_strlen($segment['after']);
            if ($a && $b) {
                foreach ($slice($old, $a) as $span) {
                    $start = $new + (int) floor($span['start'] * $b / $a);
                    $end = $new + (int) floor($span['end'] * $b / $a);
                    if ($start < $end) $result[] = array_merge($span, ['start' => $start, 'end' => $end]);
                }
            } elseif ($b) {
                $at = $old < mb_strlen($before) ? $old : max(0, $old - 1);
                foreach ($slice($at, 1) as $span) $result[] = array_merge($span, ['start' => $new, 'end' => $new + $b]);
            }
            $old += $a; $new += $b;
        }
        return $result;
    }

    public function italicRanges(array $spans): array
    {
        $ranges = [];
        foreach ($spans as $span) {
            if (!($span['attributes']['italic'] ?? false)) continue;
            $last = array_key_last($ranges);
            if ($last !== null && $ranges[$last][1] === $span['start']) $ranges[$last][1] = $span['end'];
            else $ranges[] = [$span['start'], $span['end']];
        }
        return $ranges;
    }

    public function annotate(array $segments, array $spans): array
    {
        $offset = 0; $slice = $this->slicer($spans);
        foreach ($segments as &$s) {
            $before = $slice($offset, mb_strlen($s['before']));
            $after = $this->project($s['before'], $s['after'], $before);
            $s['before_styles'] = $before; $s['styles'] = $after;
            $s['before_italics'] = $this->italicRanges($before);
            if (!array_key_exists('italics', $s)) $s['italics'] = $this->italicRanges($after);
            $offset += mb_strlen($s['before']);
        }
        return $segments;
    }

    public function chunk(Book $book, $chunk): array
    {
        $format = $this->forBook($book);
        $offset = $chunk->run->chunks()->where('position', '<', $chunk->position)->get(['source'])->sum(fn ($c) => mb_strlen($c->source));
        $spans = $this->slice($format['runs'] ?? [], $offset, mb_strlen($chunk->source));
        return $this->annotate($chunk->segments ?? app(TextEditor::class)->diff($chunk->source, $chunk->input_text ?? $chunk->source), $spans);
    }
}
