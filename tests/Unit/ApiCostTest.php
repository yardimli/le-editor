<?php
namespace Tests\Unit;
use App\Services\ApiCost;
use PHPUnit\Framework\TestCase;
class ApiCostTest extends TestCase {
    public function test_byok_uses_provider_cost_plus_router_fee(): void {
        $result = (new ApiCost)->calculate('anthropic/test', ['is_byok'=>true,'cost'=>.01,'cost_details'=>['upstream_inference_cost'=>.2]]);
        $this->assertEquals(.21,$result['cost']);
        $this->assertEquals(.01,$result['reported_cost']);
        $this->assertSame('byok_reported',$result['cost_source']);
    }
    public function test_fallback_counts_output_including_reasoning_only_once(): void {
        $usage=['is_byok'=>true,'cost'=>0,'prompt_tokens'=>1000,'completion_tokens'=>2000,'completion_tokens_details'=>['reasoning_tokens'=>1000]];
        $result=(new ApiCost)->calculate('openai/test',$usage,['input_price'=>2,'output_price'=>10]);
        $this->assertEquals(.022,$result['cost']);
        $this->assertSame('token_estimate',$result['cost_source']);
        $this->assertNull((new ApiCost)->calculate('openai/test',$usage)['cost']);
        $usage['is_byok']=false;
        $this->assertSame(0.0,(new ApiCost)->calculate('openai/test',$usage)['cost']);
    }
}
