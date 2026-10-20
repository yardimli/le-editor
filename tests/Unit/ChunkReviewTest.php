<?php

namespace Tests\Unit;

use App\Services\ChunkReview;
use App\Services\TextEditor;
use Tests\TestCase;

class ChunkReviewTest extends TestCase
{
    public function test_author_edits_keep_original_and_unrelated_review_decisions(): void
    {
        $segments = [
            ['type' => 'change', 'before' => 'Old', 'after' => 'New', 'status' => 'pending'],
            ['type' => 'equal', 'before' => ' sentence. ', 'after' => ' sentence. ', 'status' => 'pending'],
            ['type' => 'change', 'before' => 'Bad', 'after' => 'Good', 'status' => 'rejected'],
            ['type' => 'equal', 'before' => ' ending.', 'after' => ' ending.', 'status' => 'pending'],
        ];
        $review = app(ChunkReview::class);
        $revised = $review->revise($segments, 'New sentence. Bad final ending.');
        $this->assertSame('Old sentence. Bad ending.', implode('', array_column($revised, 'before')));
        $this->assertSame($segments[0], $revised[0]);
        $this->assertContains($segments[2], $revised);
        $this->assertSame('New sentence. Bad final ending.', $review->text($revised));
        $this->assertSame('Old sentence. Bad final ending.', $review->text($revised, false));
        $changedSuggestion = $review->revise($segments, 'Fresh sentence. Bad ending.');
        $this->assertSame('Fresh sentence. Bad ending.', $review->text($changedSuggestion, false));
    }

    public function test_unicode_revisions_preserve_original_and_reconstruct_author_text(): void
    {
        $editor = app(TextEditor::class);
        $review = app(ChunkReview::class);
        foreach ([
            ["  Şiir burada.\nBir dize.\n\nİkinci kıta.  ", "  Güzel şiir burada.\nYeni dize.\n\nİkinci kıta.  "],
            ['A old B remove C', 'A new B C'],
            ['old tail', 'tail'],
            ['tail old', 'tail'],
            ['abc', ''],
            ['', 'new'],
        ] as [$original, $proposal]) {
            $segments = $editor->diff($original, $proposal);
            for ($start = 0; $start <= mb_strlen($proposal); $start++) {
                foreach ([0, 1, 3] as $delete) {
                    $author = mb_substr($proposal, 0, $start).'İ🙂'.mb_substr($proposal, $start + $delete);
                    $result = $review->revise($segments, $author);
                    $this->assertSame($original, implode('', array_column($result, 'before')));
                    $this->assertSame($author, $review->text($result));
                }
            }
        }
    }

    public function test_rechunking_preserves_every_original_character(): void
    {
        $editor = app(TextEditor::class);
        foreach ([
            ['Old words and trailing deletion', ['New ', 'words']],
            ['İlk satır. İkinci satır.', ['Yepyeni ', 'bir metin.']],
            ['abc', ['', 'a', 'bc']],
            ['prefix middle suffix', ['middle']],
            ['original', ['']],
        ] as [$original, $parts]) {
            $mapped = $editor->originalChunks($original, $parts);
            $this->assertSame($original, implode('', $mapped));
            $this->assertCount(count($parts), $mapped);
        }
    }
    public function test_typing_into_a_deleted_passage_replaces_the_deletion(): void
    {
        $review = app(ChunkReview::class);
        $segments = app(TextEditor::class)->diff('Original passage', '');
        $result = $review->revise($segments, 'My replacement');
        $this->assertSame('Original passage', implode('', array_column($result, 'before')));
        $this->assertSame('My replacement', $review->text($result, false));
    }
}
