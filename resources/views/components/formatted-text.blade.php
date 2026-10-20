@props(['text', 'ranges' => [], 'styles' => []])
@php
$bounds = [0, mb_strlen($text)];
foreach ($ranges as [$a, $b]) { $bounds[] = $a; $bounds[] = $b; }
foreach ($styles as $span) { $bounds[] = $span['start']; $bounds[] = $span['end']; }
$bounds = array_values(array_unique($bounds)); sort($bounds);
@endphp
@for($i = 0; $i + 1 < count($bounds); $i++)@php
$a = $bounds[$i]; $b = $bounds[$i+1];
$attrs = collect($styles)->first(fn ($s) => $s['start'] <= $a && $s['end'] > $a)['attributes'] ?? [];
$italic = (bool) collect($ranges)->first(fn ($r) => $r[0] <= $a && $r[1] > $a);
$css = [];
if ($italic) $css[] = 'font-style:italic';
if (($attrs['bold'] ?? false) || ($attrs['header'] ?? false)) $css[] = 'font-weight:700';
if ($attrs['underline'] ?? false) $css[] = 'text-decoration:underline';
if ($attrs['strike'] ?? false) $css[] = 'text-decoration:line-through';
if (preg_match('/^#[a-f0-9]{6}$/i', $attrs['color'] ?? '')) $css[] = 'color:'.$attrs['color'];
if (preg_match('/^[0-9.]+pt$/', $attrs['size'] ?? '')) $css[] = 'font-size:'.$attrs['size'];
@endphp<span style="{{ implode(';', $css) }}">{{ mb_substr($text, $a, $b - $a) }}</span>@endfor
