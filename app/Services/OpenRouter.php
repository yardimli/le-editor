<?php

namespace App\Services;

use App\Models\User;
use App\Models\Book;
use App\Models\ApiLog;
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
            config('editor.api_key'), implode(',', config('editor.providers')), 'provider-catalog-v2',
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
                    && !str_contains(strtolower(($model['name'] ?? '').' '.($model['id'] ?? '')), 'image')
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

    public function complete(User $user, string $model, string $system, string $content, ?int $maxTokens = null, ?Book $book = null, string $purpose = 'completion'): array
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
        $key = $this->key($user);
        // Store bodies only, never authentication headers. Redact echoed credentials too.
        $redact = fn (string $value) => str_replace($key, '[REDACTED]', $value);
        $log = ApiLog::create([
            'user_id' => $user->id, 'book_id' => $book?->id, 'purpose' => $purpose,
            'model' => $model, 'status' => 'running',
            'request_body' => $redact(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
        ]);
        try {
            $response = Http::withToken($key)->acceptJson()
                ->withHeaders(['HTTP-Referer' => config('app.url'), 'X-Title' => 'LE Editor'])
                ->connectTimeout(15)->timeout(180)
                ->post(config('editor.base_url').'/chat/completions', $payload);
            $log->update([
                'response_body' => $redact($response->body()), 'http_status' => $response->status(),
                'prompt_tokens' => $response->json('usage.prompt_tokens'),
                'completion_tokens' => $response->json('usage.completion_tokens'),
                'total_tokens' => $response->json('usage.total_tokens'),
                'cost' => $response->json('usage.cost'),
            ]);
            if (!$response->successful()) {
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
            $log->update(['status' => 'completed']);
            return ['text' => $text, 'tokens' => (int) $response->json('usage.total_tokens', 0),
                'cost' => (float) $response->json('usage.cost', 0)];
        } catch (\Throwable $e) {
            $log->update(['status' => 'failed', 'error' => in_array($e->getMessage(), [
                'api_key_invalid', 'insufficient_credits', 'rate_limited', 'provider_error', 'empty_output', 'incomplete_output',
            ], true) ? $e->getMessage() : 'request_failed']);
            throw $e;
        }
    }
}
