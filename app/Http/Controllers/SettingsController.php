<?php

namespace App\Http\Controllers;

use App\Models\PromptTemplate;
use App\Services\OpenRouter;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function edit() { return view('settings'); }

    public function update(Request $request)
    {
        $data = $request->validate([
            'openrouter_api_key' => 'nullable|string|max:512',
            'locale' => 'required|in:tr,en', 'theme' => 'required|in:light,dark',
            'default_model' => 'nullable|string|max:255', 'remove_key' => 'nullable|boolean',
        ]);
        if (!$request->filled('openrouter_api_key')) { unset($data['openrouter_api_key']); }
        if ($request->boolean('remove_key')) { $data['openrouter_api_key'] = null; }
        unset($data['remove_key']);
        $request->user()->update($data);
        return back()->with('status', __('editor.saved'));
    }

    public function models(OpenRouter $api)
    {
        try { return response()->json(['models' => $api->models()]); }
        catch (\Throwable $e) { return response()->json(['message' => __('editor.catalog_unavailable')], 503); }
    }

    public function test(Request $request, OpenRouter $api)
    {
        try {
            $key = $api->key($request->user());
            $response = \Illuminate\Support\Facades\Http::withToken($key)->timeout(15)
                ->get(config('editor.base_url').'/key');
            return response()->json(['ok' => $response->successful()], $response->successful() ? 200 : 422);
        } catch (\Throwable $e) { return response()->json(['ok' => false], 422); }
    }

    public function prompts()
    {
        return view('prompts', ['presets' => PromptTemplate::whereNull('user_id')->orWhere('user_id', auth()->id())->get()]);
    }

    public function savePrompt(Request $request)
    {
        $data = $request->validate(['id' => 'nullable|integer', 'name' => 'required|string|max:100',
            'instruction' => 'required|string|max:10000', 'genre' => 'required|in:general,fiction,nonfiction,poetry',
            'intensity' => 'required|in:light,medium,deep']);
        $preset = isset($data['id'])
            ? PromptTemplate::where('user_id', auth()->id())->findOrFail($data['id'])
            : new PromptTemplate(['user_id' => auth()->id()]);
        $preset->fill(['names' => ['tr' => $data['name'], 'en' => $data['name']],
            'instructions' => ['tr' => $data['instruction'], 'en' => $data['instruction']],
            'genre' => $data['genre'], 'intensity' => $data['intensity']])->save();
        return back()->with('status', __('editor.saved'));
    }

    public function deletePrompt(PromptTemplate $preset)
    {
        abort_unless($preset->user_id === auth()->id(), 404);
        $preset->delete();
        return back();
    }
}
