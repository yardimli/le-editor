<?php

namespace App\Services;

use App\Models\Book;

class TextEditor
{
    public function words(string $text): int
    {
        return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY));
    }

    public function firstWords(string $text, int $limit): string
    {
        preg_match_all('/\S+\s*/u', $text, $matches);
        return implode('', array_slice($matches[0], 0, $limit));
    }

    /** Concatenating chunks must reproduce the source byte for byte. */
    public function chunks(string $text, int $limit): array
    {
        $parts = preg_split('/(\n\s*\n)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $chunks = [];
        $buffer = '';
        foreach ($parts as $part) {
            if ($this->words($part) > $limit) {
                if ($buffer !== '') { $chunks[] = $buffer; $buffer = ''; }
                preg_match_all('/\s*\S+/u', $part, $tokens);
                foreach (array_chunk($tokens[0], $limit) as $batch) {
                    $chunks[] = implode('', $batch);
                }
                // Preserve trailing whitespace not consumed by the token expression.
                preg_match('/\s*$/u', $part, $trailing);
                if ($chunks) { $chunks[array_key_last($chunks)] .= $trailing[0]; }
            } else {
                if ($buffer !== '' && $this->words($buffer.$part) > $limit) {
                    $chunks[] = $buffer; $buffer = '';
                }
                $buffer .= $part;
            }
        }
        if ($buffer !== '') {
            if (trim($buffer) === '' && $chunks) { $chunks[array_key_last($chunks)] .= $buffer; }
            else { $chunks[] = $buffer; }
        }
        return $chunks;
    }

    public function diff(string $before, string $after): array
    {
        // Use the library's sequence diff on Unicode word/whitespace/punctuation tokens.
        $tokens = fn ($text) => preg_split('/(\s+|[\p{P}\p{S}])/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $a = $tokens($before);
        $b = $tokens($after);
        $differ = new \Jfcherng\Diff\Differ($a, $b, ['context' => \Jfcherng\Diff\Differ::CONTEXT_ALL]);
        $segments = [];
        foreach ($differ->getGroupedOpcodes() as $group) {
            foreach ($group as [$tag, $a1, $a2, $b1, $b2]) {
                $old = implode('', array_slice($a, $a1, $a2 - $a1));
                $new = implode('', array_slice($b, $b1, $b2 - $b1));
                $segments[] = ['type' => $tag === \Jfcherng\Diff\SequenceMatcher::OP_EQ ? 'equal' : 'change',
                    'before' => $old, 'after' => $new, 'status' => 'pending'];
            }
        }
        // Keep phrase replacements coherent instead of separate decisions around each space.
        $grouped = [];
        for ($i = 0; $i < count($segments); $i++) {
            $segment = $segments[$i];
            while ($segment['type'] === 'change' && isset($segments[$i + 2])
                && $segments[$i + 1]['type'] === 'equal'
                && preg_match('/^[ \t]*$/u', $segments[$i + 1]['before'])
                && $segments[$i + 2]['type'] === 'change') {
                $segment['before'] .= $segments[$i + 1]['before'].$segments[$i + 2]['before'];
                $segment['after'] .= $segments[$i + 1]['after'].$segments[$i + 2]['after'];
                $i += 2;
            }
            $grouped[] = $segment;
        }
        return $grouped ?: [['type' => 'equal', 'before' => $before, 'after' => $after, 'status' => 'pending']];
    }

    public function rendered(Book $book, bool $pending = false): string
    {
        $run = $book->activeRun;
        if (!$run) { return $book->text; }
        return $run->chunks->map(function ($chunk) use ($pending) {
            if (!$chunk->segments) { return $chunk->source; }
            return collect($chunk->segments)->map(fn ($segment) =>
                $segment['type'] === 'equal' || $segment['status'] === 'accepted'
                    || ($pending && $segment['status'] === 'pending')
                    ? $segment['after'] : $segment['before'])->implode('');
        })->implode('');
    }

    /** Map new chunk boundaries back to immutable imported text, including deleted passages. */
    public function originalChunks(string $original, array $inputs): array
    {
        $segments = $this->diff($original, implode('', $inputs));
        $mapped = array_fill(0, count($inputs), '');
        $boundaries = [];
        $offset = 0;
        foreach ($inputs as $input) {
            $boundaries[] = [$offset, $offset + mb_strlen($input)];
            $offset += mb_strlen($input);
        }
        $position = 0;
        foreach ($segments as $segment) {
            $afterLength = mb_strlen($segment['after']);
            $beforeLength = mb_strlen($segment['before']);
            foreach ($boundaries as $index => [$start, $end]) {
                if ($afterLength === 0) {
                    if (($position >= $start && $position < $end)
                        || ($position === $offset && $index === count($inputs) - 1)) {
                        $mapped[$index] .= $segment['before'];
                    }
                } elseif ($position < $end && $position + $afterLength > $start) {
                    $from = (int) floor(max(0, $start - $position) * $beforeLength / $afterLength);
                    $to = (int) floor(min($afterLength, $end - $position) * $beforeLength / $afterLength);
                    $mapped[$index] .= mb_substr($segment['before'], $from, $to - $from);
                }
            }
            $position += $afterLength;
        }
        return $mapped;
    }
}
