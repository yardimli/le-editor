<?php

namespace App\Services;

class ChunkReview
{
    public function text(array $segments, bool $pending = true): string
    {
        return implode('', array_map(fn ($s) => $s['type'] === 'equal' || $s['status'] === 'accepted'
            || ($pending && $s['status'] === 'pending') ? $s['after'] : $s['before'], $segments));
    }

    /**
     * Apply author edits only to affected spans. Suggestions are atomic: editing inside one
     * replaces that suggestion with an accepted author revision. Unrelated decisions survive.
     * All offsets use Unicode code points, not bytes or browser UTF-16 offsets.
     */
    public function revise(array $segments, string $text, string $origin = 'manual', string $status = 'accepted'): array
    {
        $editor = app(TextEditor::class);
        $old = $this->text($segments);
        if ($old === $text) { return $segments; }
        $edits = [];
        $offset = 0;
        foreach ($editor->diff($old, $text) as $edit) {
            $end = $offset + mb_strlen($edit['before']);
            if ($edit['type'] === 'change') {
                $edits[] = ['start' => $offset, 'end' => $end, 'after' => $edit['after']];
            }
            $offset = $end;
        }
        $units = [];
        $offset = 0;
        foreach ($segments as $segment) {
            $display = $this->text([$segment]);
            $units[] = ['start' => $offset, 'end' => $offset + mb_strlen($display), 'segment' => $segment];
            $offset += mb_strlen($display);
        }
        $ranges = [];
        foreach ($edits as $edit) {
            $range = ['start' => $edit['start'], 'end' => $edit['end'], 'edits' => [$edit]];
            foreach ($units as $unit) {
                if ($unit['segment']['type'] !== 'change') { continue; }
                if ($range['start'] < $unit['end'] && $range['end'] > $unit['start']) {
                    $range['start'] = min($range['start'], $unit['start']);
                    $range['end'] = max($range['end'], $unit['end']);
                }
            }
            $last = array_key_last($ranges);
            if ($last !== null && $range['start'] <= $ranges[$last]['end']) {
                $ranges[$last]['end'] = max($ranges[$last]['end'], $range['end']);
                $ranges[$last]['edits'] = array_merge($ranges[$last]['edits'], $range['edits']);
            } else { $ranges[] = $range; }
        }
        $result = [];
        $cursor = 0;
        foreach ($ranges as $range) {
            $this->appendUnchanged($result, $units, $cursor, $range['start']);
            $before = '';
            foreach ($units as $unit) {
                if ($unit['start'] === $unit['end'] && $unit['start'] >= $range['start']
                    && ($unit['start'] < $range['end'] || ($range['start'] === $range['end'] && $unit['start'] === $range['start']))) {
                    $before .= $unit['segment']['before'];
                    continue;
                }
                if ($unit['end'] <= $range['start'] || $unit['start'] >= $range['end']) { continue; }
                $s = $unit['segment'];
                $before .= $s['type'] === 'change' ? $s['before']
                    : mb_substr($s['before'], max(0, $range['start'] - $unit['start']),
                        min($unit['end'], $range['end']) - max($unit['start'], $range['start']));
            }
            $after = mb_substr($old, $range['start'], $range['end'] - $range['start']);
            foreach (array_reverse($range['edits']) as $edit) {
                $start = $edit['start'] - $range['start'];
                $after = mb_substr($after, 0, $start).$edit['after'].mb_substr($after, $edit['end'] - $range['start']);
            }
            foreach ($editor->diff($before, $after) as $s) {
                $s['status'] = $status;
                $s['origin'] = $origin;
                $result[] = $s;
            }
            if ($range['start'] === $range['end']) {
                // Typing at a proposed deletion replaces that deletion, rather than restoring it later.
                $units = array_values(array_filter($units, fn ($unit) =>
                    $unit['start'] !== $range['start'] || $unit['end'] !== $range['end']));
            }
            $cursor = $range['end'];
        }
        $this->appendUnchanged($result, $units, $cursor, mb_strlen($old) + 1);
        // Never silently accept a mapping error: preserve the existing review and report a conflict.
        if (implode('', array_column($result, 'before')) !== implode('', array_column($segments, 'before'))
            || $this->text($result) !== $text) {
            throw new \RuntimeException('save_conflict');
        }
        return $result;
    }

    private function appendUnchanged(array &$result, array $units, int $start, int $end): void
    {
        foreach ($units as $unit) {
            $s = $unit['segment'];
            if ($unit['start'] === $unit['end']) {
                if ($unit['start'] >= $start && $unit['start'] < $end) { $result[] = $s; }
                continue;
            }
            if ($unit['end'] <= $start || $unit['start'] >= $end) { continue; }
            if ($s['type'] === 'equal') {
                $from = max(0, $start - $unit['start']);
                $length = min($unit['end'], $end) - max($unit['start'], $start);
                foreach (['italics', 'before_italics'] as $key) if (isset($s[$key])) $s[$key] = app(Italics::class)->slice($s[$key], $from, $length);
                $s['before'] = $s['after'] = mb_substr($s['before'], max(0, $start - $unit['start']),
                    min($unit['end'], $end) - max($unit['start'], $start));
            }
            $result[] = $s;
        }
    }
}
