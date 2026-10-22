<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('api-logs:recalculate-costs', function () {
    $updated = 0; $missing = 0;
    \App\Models\ApiLog::whereIn('cost_source', ['byok_unavailable', 'token_estimate'])->chunkById(100, function ($logs) use (&$updated, &$missing) {
        foreach ($logs as $log) {
            // Preserve previously captured rates; only missing historical rates use today's catalog.
            $prices = $log->input_price !== null && $log->output_price !== null
                ? ['input_price' => $log->input_price, 'output_price' => $log->output_price]
                : app(\App\Services\OpenRouter::class)->tokenPrices($log->model);
            $usage = json_decode($log->response_body ?? '', true)['usage'] ?? [];
            $cost = app(\App\Services\ApiCost::class)->calculate($log->model, $usage, $prices);
            $log->timestamps = false; $log->update($cost);
            $cost['cost'] === null ? $missing++ : $updated++;
        }
    });
    $this->info("Updated: {$updated}; missing pricing/usage: {$missing}. Historical estimates use current catalog rates where no saved rates exist.");
})->purpose('Backfill missing BYOK token-price estimates without changing request/response bodies');
