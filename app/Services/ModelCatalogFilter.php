<?php
namespace App\Services;

use Illuminate\Support\Collection;

class ModelCatalogFilter
{
    public function apply(Collection $models): Collection
    {
        $models = $models->reject(function ($model) {
            $id = strtolower($model['id']);
            $label = strtolower(($model['name'] ?? '').' '.$id);
            if (preg_match('/\b(lite|code|coder|codex)\b/i', $label)) return true;
            if (str_starts_with($id, 'qwen/')) {
                if (preg_match('/\b(thinking|instruct)\b/i', $label)) return true;
                // A22B denotes active parameters, not the model's total size.
                $name = $model['name'] ?? '';
                if (preg_match('/(?<![a-z0-9.])(\d+(?:\.\d+)?)\s*([bt])\b/i', $name, $size)
                    || preg_match('/(?<![a-z0-9.])(\d+(?:\.\d+)?)\s*([bt])\b/i', $id, $size)) {
                    $billions = (float) $size[1] * (strtolower($size[2]) === 't' ? 1000 : 1);
                    if ($billions < 100) return true;
                }
            }
            // App policy: omit legacy generations from the editorial picker.
            return (bool) preg_match('~^(?:openai/(?:gpt-[34](?:[.o-]|$)|o1(?:-|$))|google/gemma-2(?:-|$)|qwen/qwen-?2(?:[.-]|$)|deepseek/(?:deepseek-chat(?:$|-v3)|deepseek-v3))~', $id);
        });
        $winners = [];
        foreach ($models as $model) {
            [$family, $version, $snapshot] = $this->identity($model);
            $rank = [$version, $snapshot, (int) ($model['created'] ?? 0)];
            if (!isset($winners[$family]) || $this->newer($rank, $winners[$family]['rank'])) {
                $winners[$family] = ['model' => $model, 'rank' => $rank];
            }
        }
        return collect(array_column($winners, 'model'));
    }

    private function newer(array $a, array $b): bool
    {
        $version = version_compare($a[0], $b[0]);
        return $version > 0 || ($version === 0 && [$a[1], $a[2]] > [$b[1], $b[2]]);
    }

    private function identity(array $model): array
    {
        $id = strtolower($model['id']); $snapshot = '';
        // Remove release dates, not model sizes such as 120b or architecture numbers.
        if (preg_match('/-(\d{4}-\d{2}-\d{2}|\d{8}|\d{2}-\d{2}|\d{4})$/', $id, $match)) {
            $snapshot = str_replace('-', '', $match[1]);
            $id = substr($id, 0, -strlen($match[0]));
        }
        if (preg_match('/(?:\b(\d{4}-\d{2}-\d{2})\b|\b(\d{4})\)?$)/', $model['name'] ?? '', $match)) {
            $snapshot = str_replace('-', '', $match[1] ?: ($match[2] ?? ''));
        }
        // Keep the complete semantic version and variant in the grouping key.
        // Use a full release timestamp when supplied, including years for short date suffixes.
        if ($snapshot && !empty($model['created'])) $snapshot = gmdate('Ymd', (int) $model['created']);
        return [$id, '0', $snapshot];
    }
}
