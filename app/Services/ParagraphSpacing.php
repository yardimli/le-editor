<?php
namespace App\Services;

class ParagraphSpacing
{
    /** Restore original end-of-line spacing without hiding text, indentation or line-break edits. */
    public function reject(array $segments): array
    {
        foreach ($segments as $i => &$segment) {
            if ($segment['type'] !== 'change' || ($segment['origin'] ?? 'ai') === 'manual'
                || $segment['before'] === $segment['after']) continue;
            if (!preg_match('/^[\h\r\n]*$/u', $segment['before'].$segment['after'])) continue;
            $beforeTail = implode('', array_column(array_slice($segments, $i + 1), 'before'));
            $afterTail = implode('', array_column(array_slice($segments, $i + 1), 'after'));
            $clean = function ($text, $tail) {
                $text = preg_replace('/\h+(?=\r?\n)/u', '', $text);
                if ($tail === '' || preg_match('/^\r?\n/', $tail)) $text = preg_replace('/\h+$/u', '', $text);
                return $text;
            };
            if ($clean($segment['before'], $beforeTail) !== $clean($segment['after'], $afterTail)) continue;
            $segment['type'] = 'equal';
            $segment['status'] = 'rejected';
            $segment['after'] = $segment['before'];
            $segment['styles'] = $segment['before_styles'] ?? [];
            $segment['italics'] = $segment['before_italics'] ?? [];
        }
        unset($segment);
        return $segments;
    }
}
