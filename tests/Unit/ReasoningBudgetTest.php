<?php
namespace Tests\Unit;
use App\Services\OpenRouter;
use Tests\TestCase;
class ReasoningBudgetTest extends TestCase {
    public function test_numeric_budgets_scale_with_input_and_level_with_extra_margin(): void {
        $api = new OpenRouter;
        $model = ['reasoning' => ['supports_max_tokens' => true]];
        foreach (['low' => 2000, 'medium' => 4000, 'high' => 8000] as $level => $thinking) {
            $budget = $api->editingBudget($model, str_repeat('a', 8000), '', $level);
            $this->assertSame($thinking, $budget['reasoning']['max_tokens']);
            $this->assertSame(4000 + $thinking + 10000, $budget['max_tokens']);
        }
        $this->assertSame(1024 + 10000, $api->editingBudget([], 'text', '')['max_tokens']);
        $this->assertSame([], $api->editingBudget([], 'text', '')['reasoning']);
    }
    public function test_effort_uses_supported_values_and_respects_model_limits(): void {
        $api = new OpenRouter;
        $model = ['reasoning' => ['supported_efforts' => ['medium', 'high']]];
        $budget = $api->editingBudget($model, str_repeat('a', 8000), '', 'low');
        $this->assertSame(['effort' => 'medium'], $budget['reasoning']);
        $this->assertSame(18000, $budget['max_tokens']);
        $this->expectExceptionMessage('chunk_too_large');
        $api->editingBudget($model + ['max_output' => 5000], str_repeat('a', 8000), '');
    }
}
