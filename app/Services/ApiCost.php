<?php
namespace App\Services;
class ApiCost
{
    public function calculate(string $model, array $usage, ?array $prices = null): array
    {
        $reported = isset($usage['cost']) && is_numeric($usage['cost']) ? (float) $usage['cost'] : null;
        $result = ['reported_cost' => $reported, 'cost' => $reported, 'cost_source' => 'openrouter',
            'input_price' => $prices['input_price'] ?? null, 'output_price' => $prices['output_price'] ?? null];
        if (empty($usage['is_byok']) || !in_array(explode('/', $model)[0], ['openai', 'anthropic'])) return $result;
        $upstream = $usage['cost_details']['upstream_inference_cost'] ?? null;
        if (is_numeric($upstream)) {
            $result['cost'] = (float) $upstream + ($reported ?? 0);
            $result['cost_source'] = 'byok_reported';
        } elseif ($prices && isset($usage['prompt_tokens'], $usage['completion_tokens'])) {
            // Completion usage already includes reasoning; do not add reasoning tokens again.
            $result['cost'] = ($usage['prompt_tokens'] * $prices['input_price']
                + $usage['completion_tokens'] * $prices['output_price']) / 1000000 + ($reported ?? 0);
            $result['cost_source'] = 'token_estimate';
        } else {
            $result['cost'] = null;
            $result['cost_source'] = 'byok_unavailable';
        }
        if ($result['cost'] !== null) $result['cost'] = round($result['cost'], 10);
        return $result;
    }
}
