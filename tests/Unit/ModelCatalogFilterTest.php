<?php
namespace Tests\Unit;
use App\Services\ModelCatalogFilter;
use PHPUnit\Framework\TestCase;
class ModelCatalogFilterTest extends TestCase {
    public function test_filters_specialties_legacy_and_keeps_distinct_versions_and_newest_snapshots(): void {
        $models = [
            ['moonshotai/kimi-k2', 'Kimi K2 0711'], ['moonshotai/kimi-k2-0905', 'Kimi K2 0905'],
            ['moonshotai/kimi-k2-thinking', 'Kimi K2 Thinking'],
            ['openai/gpt-3.5-turbo', 'GPT 3.5'], ['openai/gpt-4o', 'GPT 4o'],
            ['openai/gpt-5.1-codex', 'GPT Codex'], ['qwen/qwen3-coder', 'Qwen Coder'],
            ['google/gemini-3.1-flash-lite', 'Flash Lite'], ['moonshotai/kimi-k2.7-code', 'Kimi Code'],
            ['anthropic/claude-sonnet-5', 'Sonnet 5'], ['anthropic/claude-sonnet-5.5', 'Sonnet 5.5'],
            ['openai/gpt-6-sol', 'GPT 6 Sol'], ['openai/gpt-6.1-sol', 'GPT 6.1 Sol'],
            ['openai/gpt-6-luna', 'GPT 6 Luna'], ['qwen/qwen3.5-27b', 'Qwen 27B'],
            ['qwen/qwen3.8-27b', 'Qwen 27B'], ['qwen/qwen3.5-397b-a17b', 'Qwen 397B'],
        ];
        $result = (new ModelCatalogFilter)->apply(collect($models)->map(fn ($m) => ['id'=>$m[0], 'name'=>$m[1]]))->pluck('id')->all();
        $this->assertSame(['moonshotai/kimi-k2-0905','moonshotai/kimi-k2-thinking','anthropic/claude-sonnet-5','anthropic/claude-sonnet-5.5','openai/gpt-6-sol','openai/gpt-6.1-sol','openai/gpt-6-luna','qwen/qwen3.5-397b-a17b'], $result);
    }
    public function test_qwen_filters_use_total_size_and_leave_other_providers_alone(): void {
        $names = ['Qwen 99.5B', 'Qwen 100B', 'Qwen 235B A22B', 'Qwen 2.4T A95B',
            'Qwen 235B THINKING', 'Qwen 235B Instruct', 'Qwen Plus'];
        $models = collect($names)->map(fn ($name, $i) => ['id'=>'qwen/test-'.$i, 'name'=>$name]);
        $models->push(['id'=>'qwen/qwen3-30b-a3b', 'name'=>'Qwen small']);
        $models->push(['id'=>'other/model', 'name'=>'Other 27B Thinking Instruct']);
        $result = (new ModelCatalogFilter)->apply($models)->pluck('name')->all();
        $this->assertSame(['Qwen 100B','Qwen 235B A22B','Qwen 2.4T A95B','Qwen Plus','Other 27B Thinking Instruct'], $result);
    }

    public function test_nano_banana_is_excluded_by_name_or_id(): void {
        $models = collect([
            ['id'=>'google/gemini-nano-banana-2.1', 'name'=>'Google model'],
            ['id'=>'google/image-model', 'name'=>'Google: NANO BANANA 2'],
            ['id'=>'openai/gpt-5.4-nano', 'name'=>'GPT-5.4 Nano'],
        ]);
        $this->assertSame(['openai/gpt-5.4-nano'], (new ModelCatalogFilter)->apply($models)->pluck('id')->all());
    }

    public function test_semantic_versions_are_not_collapsed(): void {
        $models = collect([
            ['id'=>'moonshotai/kimi-k2.6', 'name'=>'Kimi K2.6'],
            ['id'=>'moonshotai/kimi-k2-0905', 'name'=>'Kimi K2 0905'],
            ['id'=>'moonshotai/kimi-k2.5', 'name'=>'Kimi K2.5'],
        ]);
        $this->assertSame(['moonshotai/kimi-k2.6','moonshotai/kimi-k2-0905','moonshotai/kimi-k2.5'], (new ModelCatalogFilter)->apply($models)->pluck('id')->all());
    }
}
