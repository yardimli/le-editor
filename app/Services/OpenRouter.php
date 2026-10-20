<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenRouter
{
    public function key(User $user): string
    {
        $key = config('editor.dev_mode') ? config('editor.api_key') : $user->openrouter_api_key;
        if (!$key) {
            throw new RuntimeException('api_key_required');
        }
        return $key;
    }

    public function models(): array
    {
        $cacheKey = 'openrouter.models.'.hash('sha256', implode('|', [
            config('editor.min_output_price'), config('editor.max_output_price'),
            config('editor.api_key'), implode(',', config('editor.providers')), 'provider-catalog-v1',
        ]));
        return Cache::remember($cacheKey, 3600, function () {
            if (!config('editor.api_key')) {
                throw new RuntimeException('catalog_key_required');
            }
            $response = Http::withToken(config('editor.api_key'))->timeout(30)
                ->get(config('editor.base_url').'/models');
            if (!$response->successful()) {
                throw new RuntimeException('catalog_unavailable');
            }
            return collect($response->json('data', []))->filter(function ($model) {
                $price = $model['pricing']['completion'] ?? null;
                $outputs = $model['architecture']['output_modalities'] ?? ['text'];
                $provider = strtolower(explode('/', $model['id'] ?? '')[0]);
                return in_array($provider, config('editor.providers'), true)
                    && !str_contains(strtolower(($model['name'] ?? '').' '.($model['id'] ?? '')), 'batch')
                    && is_numeric($price) && (float) $price > 0
                    && !str_ends_with($model['id'], ':free')
                    && in_array('text', $outputs)
                    && (float) $price * 1000000 >= config('editor.min_output_price')
                    && (float) $price * 1000000 <= config('editor.max_output_price');
            })->map(fn ($model) => [
                'id' => $model['id'], 'name' => $model['name'],
                'provider' => strtolower(explode('/', $model['id'])[0]),
                'output_price' => (float) $model['pricing']['completion'] * 1000000,
                'input_price' => (float) ($model['pricing']['prompt'] ?? 0) * 1000000,
                'context_length' => $model['context_length'] ?? 0,
                'max_output' => $model['top_provider']['max_completion_tokens'] ?? null,
            ])->sortBy([
                fn ($a, $b) => array_search($a['provider'], config('editor.providers'), true) <=> array_search($b['provider'], config('editor.providers'), true),
                ['output_price', 'asc'], ['name', 'asc'], ['id', 'asc'],
            ])->values()->all();
        });
    }

    public function model(string $id): array
    {
        return collect($this->models())->firstWhere('id', $id)
            ?? throw new RuntimeException('model_unavailable');
    }

    public function complete(User $user, string $model, string $system, string $content, ?int $maxTokens = null): array
    {
        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $content],
            ],
            'temperature' => 0.2,
        ];
        if ($maxTokens) {
            $payload['max_tokens'] = $maxTokens;
        }
        $response = Http::withToken($this->key($user))->acceptJson()
            ->withHeaders(['HTTP-Referer' => config('app.url'), 'X-Title' => 'LE Editor'])
            ->connectTimeout(15)->timeout(180)
            ->post(config('editor.base_url').'/chat/completions', $payload);
        if (!$response->successful()) {
            // Provider bodies may contain manuscript text or credentials. Never log them.
            throw new RuntimeException(match ($response->status()) {
                401, 403 => 'api_key_invalid', 402 => 'insufficient_credits',
                429 => 'rate_limited', default => 'provider_error',
            });
        }
        $text = $response->json('choices.0.message.content');
        if (!is_string($text) || trim($text) === '') {
            throw new RuntimeException('empty_output');
        }
        if ($response->json('choices.0.finish_reason') !== 'stop') {
            throw new RuntimeException('incomplete_output');
        }
        return ['text' => $text, 'tokens' => (int) $response->json('usage.total_tokens', 0),
            'cost' => (float) $response->json('usage.cost', 0)];
    }
}
