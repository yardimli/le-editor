<?php
namespace Tests\Unit;
use App\Services\ParagraphSpacing;
use PHPUnit\Framework\TestCase;
class ParagraphSpacingTest extends TestCase {
    private function change($before, $after, $origin = 'ai') { return compact('before', 'after', 'origin') + ['type' => 'change', 'status' => 'pending']; }
    public function test_trailing_spacing_is_restored_and_hidden(): void {
        foreach ([[" \n\n", "\n\n"], ["\n\n", " \n\n"], ['', ' '], [' ', '']] as [$before, $after]) {
            $result = (new ParagraphSpacing)->reject([$this->change($before, $after)]);
            $this->assertSame('equal', $result[0]['type']);
            $this->assertSame('rejected', $result[0]['status']);
            $this->assertSame($before, $result[0]['after']);
        }
    }
    public function test_internal_spaces_indentation_line_breaks_and_manual_changes_remain(): void {
        foreach ([[' ', ''], ["\n\n", "\n"], ["\n\n  ", "\n\n"], ['word ', 'word']] as [$before,$after]) {
            $segments = [$this->change($before,$after), ['type'=>'equal','before'=>'Next','after'=>'Next']];
            $this->assertSame($segments, (new ParagraphSpacing)->reject($segments));
        }
        $manual = [$this->change(' ', '', 'manual')];
        $this->assertSame($manual, (new ParagraphSpacing)->reject($manual));
    }
}
