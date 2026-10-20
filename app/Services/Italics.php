<?php
namespace App\Services;

class Italics
{
    /** Ranges use Unicode code points, never bytes or UTF-16 positions. */
    public function slice(array $ranges, int $start, int $length): array
    {
        $result = [];
        foreach ($ranges as [$a, $b]) {
            if (max($a, $start) < min($b, $start + $length)) $result[] = [max($a, $start) - $start, min($b, $start + $length) - $start];
        }
        return $result;
    }

    public function ranges(array $segments, bool $pending = true): array
    {
        $result = []; $offset = 0;
        foreach ($segments as $s) {
            $after = $s['type'] === 'equal' || $s['status'] === 'accepted' || ($pending && $s['status'] === 'pending');
            foreach ($after ? ($s['italics'] ?? []) : ($s['before_italics'] ?? []) as [$a, $b]) $result[] = [$a + $offset, $b + $offset];
            $offset += mb_strlen($s[$after ? 'after' : 'before']);
        }
        return $result;
    }

    public function book(\App\Models\Book $book, bool $pending = false): array
    {
        if (!$book->activeRun) {
            if ($book->italics !== null) return $book->italics;
            $styles = app(SourceFormatting::class);
            return $styles->italicRanges($styles->project($book->original_text, $book->text, $styles->forBook($book)['runs'] ?? []));
        }
        $ranges = []; $offset = 0;
        foreach ($book->activeRun->chunks as $chunk) {
            foreach ($this->ranges(app(SourceFormatting::class)->chunk($book, $chunk), $pending) as [$a, $b]) $ranges[] = [$a + $offset, $b + $offset];
            $offset += mb_strlen($chunk->segments ? app(ChunkReview::class)->text($chunk->segments, $pending) : $chunk->source);
        }
        return $ranges;
    }

    public function apply(array $segments, array $ranges, string $status = 'accepted', string $origin = 'manual'): array
    {
        $result = []; $offset = 0;
        foreach ($segments as $s) {
            $display = app(ChunkReview::class)->text([$s]);
            $local = $this->slice($ranges, $offset, mb_strlen($display)); $offset += mb_strlen($display);
            $old = $this->ranges([$s]);
            if ($old === $local) { $result[] = $s; continue; }
            if ($s['type'] === 'equal') {
                $boundaries = [0, mb_strlen($display)];
                foreach (array_merge($local, $old) as [$a, $b]) { $boundaries[] = $a; $boundaries[] = $b; }
                $boundaries = array_values(array_unique($boundaries)); sort($boundaries);
                for ($i = 0; $i + 1 < count($boundaries); $i++) {
                    $a = $boundaries[$i]; $b = $boundaries[$i + 1]; if ($a === $b) continue;
                    $value = mb_substr($display, $a, $b - $a);
                    $before = $this->slice($s['before_italics'] ?? $old, $a, $b - $a);
                    $after = $this->slice($local, $a, $b - $a);
                    $result[] = ['type' => $before === $after ? 'equal' : 'change', 'before' => $value, 'after' => $value,
                        'status' => $status, 'origin' => $origin, 'italics' => $after, 'before_italics' => $before,
                        'styles' => app(SourceFormatting::class)->slice($s['styles'] ?? [], $a, $b - $a),
                        'before_styles' => app(SourceFormatting::class)->slice($s['before_styles'] ?? [], $a, $b - $a)];
                }
            } else {
                $s['after'] = $display; $s['italics'] = $local; $s['status'] = $status; $s['origin'] = $origin;
                if ($s['before'] === $display && $local === ($s['before_italics'] ?? [])) $s['type'] = 'equal';
                $result[] = $s;
            }
        }
        return $result;
    }

    public function encode(string $text, array $ranges): string
    {
        foreach (array_reverse($ranges) as [$a, $b]) $text = mb_substr($text, 0, $a).'<le-italic>'.mb_substr($text, $a, $b - $a).'</le-italic>'.mb_substr($text, $b);
        return $text;
    }

    public function decode(string $marked): array
    {
        $text = ''; $ranges = []; $start = null;
        foreach (preg_split('/(<\/?le-italic>)/', $marked, -1, PREG_SPLIT_DELIM_CAPTURE) as $part) {
            if ($part === '<le-italic>') { if ($start !== null) throw new \RuntimeException('suspicious_output'); $start = mb_strlen($text); }
            elseif ($part === '</le-italic>') { if ($start === null) throw new \RuntimeException('suspicious_output'); if ($start < mb_strlen($text)) $ranges[] = [$start, mb_strlen($text)]; $start = null; }
            else $text .= $part;
        }
        if ($start !== null) throw new \RuntimeException('suspicious_output');
        return [$text, $ranges];
    }
}
