<?php
namespace Tests\Unit;
use App\Services\SourceFormatting;
use PHPUnit\Framework\TestCase;
class FormattingIndexTest extends TestCase {
    public function test_index_matches_linear_slices_for_overlaps_gaps_and_boundaries(): void {
        $styles = new SourceFormatting;
        $spans = [['start'=>0,'end'=>40,'attributes'=>['bold'=>true]], ['start'=>3,'end'=>8], ['start'=>11,'end'=>12], ['start'=>50,'end'=>60]];
        $slice = $styles->slicer($spans);
        foreach ([[0,0],[0,1],[4,3],[8,3],[12,2],[39,13],[60,8]] as [$start,$length]) {
            $this->assertSame($styles->slice($spans,$start,$length),$slice($start,$length));
        }
    }
    public function test_index_handles_many_small_ranges_across_a_large_manuscript(): void {
        $styles = new SourceFormatting; $spans = [];
        for ($i=0;$i<40000;$i++) $spans[]=['start'=>$i*10,'end'=>$i*10+8];
        $slice = $styles->slicer($spans);
        for ($i=0;$i<400;$i++) {
            $this->assertCount(100,$slice($i*1000,1000));
        }
    }
}
