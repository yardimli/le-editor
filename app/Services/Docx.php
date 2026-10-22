<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use RuntimeException;
use ZipArchive;

class Docx
{
    private const NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    public function import(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) { throw new RuntimeException('invalid_docx'); }
        try {
            $stat = $zip->statName('word/document.xml');
            if (!$stat || $stat['size'] > 15000000) { throw new RuntimeException('invalid_docx'); }
            $xml = $zip->getFromName('word/document.xml');
            if (str_contains($xml, '<!DOCTYPE')) { throw new RuntimeException('invalid_docx'); }
            $doc = new DOMDocument;
            if (!@$doc->loadXML($xml, LIBXML_NONET)) { throw new RuntimeException('invalid_docx'); }
            $xp = new DOMXPath($doc);
            $xp->registerNamespace('w', self::NS);
            $paragraphs = []; $formatting = ['paragraphs' => [], 'runs' => []]; $offset = 0;
            $styles = new OoxmlFormatting($zip);
            foreach ($xp->query('//w:body//w:p[not(ancestor::w:del)]') as $paragraph) {
                $text = ''; $paragraphStyle = $styles->paragraph($paragraph, $xp);
                $start = $offset;
                foreach ($xp->query('.//w:t[not(ancestor::w:del)] | .//w:br | .//w:tab', $paragraph) as $node) {
                    $part = match ($node->localName) { 'br' => "\n", 'tab' => "\t", default => $node->textContent };
                    $span = array_merge($styles->run($node, $xp, $paragraphStyle), ['start' => $offset, 'end' => $offset + mb_strlen($part)]);
                    $last = array_key_last($formatting['runs']);
                    if ($part !== '') {
                        if ($last !== null && $formatting['runs'][$last]['end'] === $offset
                            && $formatting['runs'][$last]['xml'] === $span['xml']
                            && $formatting['runs'][$last]['attributes'] === $span['attributes']) {
                            $formatting['runs'][$last]['end'] = $span['end'];
                        } else { $formatting['runs'][] = $span; }
                    }
                    $text .= $part; $offset += mb_strlen($part);
                }
                $formatting['paragraphs'][] = array_merge($paragraphStyle, ['start' => $start, 'end' => $offset]);
                $offset += 2;
                $paragraphs[] = $text;
            }
            $text = implode("\n\n", $paragraphs);
            if (trim($text) === '' || mb_strlen($text) > 1000000) { throw new RuntimeException('invalid_docx'); }
            return ['text' => $text, 'formatting' => $formatting, 'italics' => app(SourceFormatting::class)->italicRanges($formatting['runs']), 'complex' => $xp->query('//w:tbl | //w:drawing | //w:footnoteReference')->length > 0];
        } finally { $zip->close(); }
    }

    /** Generate an OOXML package with genuine w:ins/w:del revisions. */
    public function export(string $original, string $current, string $title, array $italics = [], ?string $sourcePath = null, string $language = 'tr'): string
    {
        $source = null; $format = ['runs' => [], 'paragraphs' => []];
        if ($sourcePath) {
            $source = $this->import($sourcePath);
            if ($source['text'] !== $original) throw new RuntimeException('invalid_docx');
            $format = $source['formatting'];
        }
        $styles = app(SourceFormatting::class);
        $mapped = $styles->project($original, $current, $format['runs']);
        $sourceSlice = $styles->slicer($format['runs']);
        $mappedSlice = $styles->slicer($mapped);
        $italicSlice = $styles->slicer(array_map(fn ($r) => ['start' => $r[0], 'end' => $r[1]], $italics));
        $escape = fn ($value) => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $run = function (string $text, string $properties = '', bool $deleted = false) use ($escape): string {
            $tag = $deleted ? 'delText' : 't'; $xml = '';
            foreach (preg_split('/(\n|\t)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE) as $part) {
                $xml .= match ($part) {
                    "\n" => '<w:r>'.$properties.'<w:br/></w:r>', "\t" => '<w:r>'.$properties.'<w:tab/></w:r>',
                    '' => '', default => '<w:r>'.$properties.'<w:'.$tag.' xml:space="preserve">'.$escape($part).'</w:'.$tag.'></w:r>',
                };
            }
            return $xml;
        };
        $formatted = function (string $text, int $offset, bool $inserted = false, bool $deleted = false) use ($run, $sourceSlice, $mappedSlice, $italicSlice, $styles): string {
            $spans = ($deleted ? $sourceSlice : $mappedSlice)($offset, mb_strlen($text));
            $ranges = $deleted ? $styles->italicRanges($spans) : array_map(fn ($s) => [$s['start'], $s['end']], $italicSlice($offset, mb_strlen($text)));
            $bounds = [0, mb_strlen($text)];
            foreach ($spans as $span) { $bounds[] = $span['start']; $bounds[] = $span['end']; }
            foreach ($ranges as [$a, $b]) { $bounds[] = $a; $bounds[] = $b; }
            $bounds = array_values(array_unique($bounds)); sort($bounds); $xml = '';
            for ($i = 0; $i + 1 < count($bounds); $i++) {
                $a = $bounds[$i]; $b = $bounds[$i + 1]; if ($a === $b) continue;
                $span = collect($spans)->first(fn ($s) => $s['start'] <= $a && $s['end'] > $a);
                $italic = (bool) collect($ranges)->first(fn ($r) => $r[0] <= $a && $r[1] > $a);
                $properties = $span['xml'] ?? '';
                $wasItalic = (bool) ($span['attributes']['italic'] ?? false);
                if (!$deleted && $italic !== $wasItalic) {
                    $prDoc = new DOMDocument;
                    $prDoc->loadXML('<root xmlns:w="'.self::NS.'">'.($properties ?: '<w:rPr/>').'</root>', LIBXML_NONET);
                    $pr = $prDoc->documentElement->firstChild;
                    $prior = $pr->cloneNode(true);
                    foreach (iterator_to_array($pr->childNodes) as $child) if (in_array($child->localName, ['i', 'iCs', 'rPrChange'])) $pr->removeChild($child);
                    $em = $prDoc->createElementNS(self::NS, 'w:i'); if (!$italic) $em->setAttributeNS(self::NS, 'w:val', '0'); $pr->appendChild($em);
                    if (!$inserted) {
                        $change = $prDoc->createElementNS(self::NS, 'w:rPrChange');
                        foreach (['id' => '0', 'author' => 'LE Editor', 'date' => gmdate('Y-m-d\TH:i:s\Z')] as $key => $value) $change->setAttributeNS(self::NS, 'w:'.$key, $value);
                        $change->appendChild($prior); $pr->appendChild($change);
                    }
                    $properties = $prDoc->saveXML($pr);
                }
                $xml .= $run(mb_substr($text, $a, $b - $a), $properties, $deleted);
            }
            return $xml;
        };
        $openParagraph = function (int $index, string $mark = '') use ($format): string {
            $properties = $format['paragraphs'][$index]['xml'] ?? '';
            if ($mark) {
                if (!$properties) $properties = '<w:pPr/> ';
                $doc = new DOMDocument; $doc->loadXML('<root xmlns:w="'.self::NS.'">'.$properties.'</root>', LIBXML_NONET);
                $pr = $doc->documentElement->firstChild;
                $xp = new DOMXPath($doc); $xp->registerNamespace('w', self::NS);
                $rPr = $xp->query('./w:rPr', $pr)->item(0);
                if (!$rPr) { $rPr = $doc->createElementNS(self::NS, 'w:rPr'); $pr->insertBefore($rPr, $xp->query('./w:sectPr', $pr)->item(0)); }
                $fragment = $doc->createDocumentFragment(); $fragment->appendXML('<root xmlns:w="'.self::NS.'">'.$mark.'</root>');
                $rPr->appendChild($fragment->firstChild->firstChild->cloneNode(true));
                $properties = $doc->saveXML($pr);
            }
            return '<w:p data-le-source="'.$index.'">'.$properties;
        };
        $body = '';
        $id = 0;
        $date = gmdate('Y-m-d\TH:i:s\Z');
        // Paragraph-sequence diff preserves real paragraphs; changed paragraphs contain word revisions.
        $old = explode("\n\n", $original);
        $new = explode("\n\n", $current);
        if ($source && !empty($format['paragraphs'])) {
            // Double soft line breaks inside a w:p are not paragraph boundaries.
            $old = []; $softBreaks = [];
            foreach ($format['paragraphs'] as $paragraph) {
                $text = mb_substr($original, $paragraph['start'], $paragraph['end'] - $paragraph['start']);
                $old[] = $text;
                $search = 0;
                while (($at = mb_strpos($text, "\n\n", $search)) !== false) {
                    $softBreaks[] = ['start' => $paragraph['start'] + $at, 'end' => $paragraph['start'] + $at + 2];
                    $search = $at + 1;
                }
            }
            $protected = $styles->project($original, $current, $softBreaks);
            $new = []; $start = 0; $search = 0;
            while (($at = mb_strpos($current, "\n\n", $search)) !== false) {
                $soft = collect($protected)->contains(fn ($span) => $span['start'] <= $at && $span['end'] >= $at + 2);
                if (!$soft) { $new[] = mb_substr($current, $start, $at - $start); $start = $at + 2; }
                $search = $at + 2;
            }
            $new[] = mb_substr($current, $start);
        }
        $oldOffsets = []; $offset = 0;
        foreach ($old as $paragraphText) { $oldOffsets[] = $offset; $offset += mb_strlen($paragraphText) + 2; }
        $newOffsets = []; $offset = 0;
        foreach ($new as $paragraph) { $newOffsets[] = $offset; $offset += mb_strlen($paragraph) + 2; }
        $matcher = new \Jfcherng\Diff\Differ($old, $new, ['context' => \Jfcherng\Diff\Differ::CONTEXT_ALL]);
        $groups = $matcher->getGroupedOpcodes();
        if (!$groups) {
            foreach ($new as $index => $paragraph) { $body .= $openParagraph($index).$formatted($paragraph, $newOffsets[$index]).'</w:p>'; }
        }
        foreach ($groups as $group) {
            foreach ($group as [$tag, $a1, $a2, $b1, $b2]) {
                if ($tag === \Jfcherng\Diff\SequenceMatcher::OP_EQ) {
                    foreach (array_slice($new, $b1, $b2 - $b1) as $index => $paragraph) {
                        $body .= $openParagraph($a1 + $index).$formatted($paragraph, $newOffsets[$b1 + $index]).'</w:p>';
                    }
                    continue;
                }
                $count = max($a2 - $a1, $b2 - $b1);
                for ($i = 0; $i < $count; $i++) {
                    $before = $i < $a2 - $a1 ? $old[$a1 + $i] : '';
                    $after = $i < $b2 - $b1 ? $new[$b1 + $i] : '';
                    $markXml = '';
                    if ($i >= $a2 - $a1 || $i >= $b2 - $b1) {
                        $mark = $i >= $a2 - $a1 ? 'ins' : 'del';
                        $markXml = '<w:'.$mark.' w:id="'.(++$id).'" w:author="LE Editor" w:date="'.$date.'"/>';
                    }
                    $sourceIndex = min($a1 + $i, max(0, count($old) - 1));
                    $body .= $openParagraph($sourceIndex, $markXml);
                    $oldPosition = $oldOffsets[$a1 + $i] ?? mb_strlen($original);
                    $position = $newOffsets[$b1 + $i] ?? mb_strlen($current);
                    foreach (app(TextEditor::class)->diff($before, $after) as $segment) {
                        if ($segment['type'] === 'equal') { $body .= $formatted($segment['after'], $position); $position += mb_strlen($segment['after']); $oldPosition += mb_strlen($segment['before']); continue; }
                        foreach (['del' => 'before', 'ins' => 'after'] as $kind => $key) {
                            if ($segment[$key] !== '') {
                                $body .= '<w:'.$kind.' w:id="'.(++$id).'" w:author="LE Editor" w:date="'.$date.'">'
                                    .($kind === 'del' ? $formatted($segment[$key], $oldPosition, false, true) : $formatted($segment[$key], $position, true)).'</w:'.$kind.'>';
                            }
                        }
                        $position += mb_strlen($segment['after']);
                        $oldPosition += mb_strlen($segment['before']);
                    }
                    $body .= '</w:p>';
                }
            }
        }
        $body = preg_replace_callback('/w:id="0"/', function () use (&$id) { return 'w:id="'.(++$id).'"'; }, $body);
        if ($sourcePath) return $this->setProofingLanguage($this->exportIntoSource($sourcePath, $body), $language);
        $body = preg_replace('/ data-le-source="[0-9]+"/', '', $body);
        $path = tempnam(storage_path('app'), 'export-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            .'<Override PartName="/word/settings.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings" Target="settings.xml"/></Relationships>');
        $zip->addFromString('word/settings.xml', '<?xml version="1.0"?><w:settings xmlns:w="'.self::NS.'"><w:trackRevisions/><w:revisionView w:markup="1" w:insDel="1"/></w:settings>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="'.self::NS.'"><w:body>'.$body
            .'<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440"/></w:sectPr></w:body></w:document>');
        $zip->close();
        return $this->setProofingLanguage($path, $language);
    }
    private function setProofingLanguage(string $path, string $language): string
    {
        $locale = $language === 'en' ? 'en-US' : 'tr-TR';
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) throw new RuntimeException('invalid_docx');
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (!preg_match('~^word/(document|styles|header[0-9]*|footer[0-9]*|footnotes|endnotes)\.xml$~', $name)) continue;
                $doc = new DOMDocument;
                if (!$doc->loadXML($zip->getFromName($name), LIBXML_NONET)) throw new RuntimeException('invalid_docx');
                $xp = new DOMXPath($doc); $xp->registerNamespace('w', self::NS);
                // Direct run properties override inherited English styles, including tracked text.
                foreach (iterator_to_array($xp->query('//w:r')) as $run) {
                    if (!$xp->query('./w:rPr', $run)->length) $run->insertBefore($doc->createElementNS(self::NS, 'w:rPr'), $run->firstChild);
                }
                // Paragraph-mark properties keep the chosen language when typing at paragraph ends.
                foreach (iterator_to_array($xp->query('//w:p')) as $paragraph) {
                    $pr = $xp->query('./w:pPr', $paragraph)->item(0);
                    if (!$pr) { $pr = $doc->createElementNS(self::NS, 'w:pPr'); $paragraph->insertBefore($pr, $paragraph->firstChild); }
                    if (!$xp->query('./w:rPr', $pr)->length) $pr->insertBefore($doc->createElementNS(self::NS, 'w:rPr'), $xp->query('./w:sectPr | ./w:pPrChange', $pr)->item(0));
                }
                if ($name === 'word/styles.xml') {
                    $defaults = $xp->query('/w:styles/w:docDefaults')->item(0);
                    if (!$defaults) { $defaults = $doc->createElementNS(self::NS, 'w:docDefaults'); $doc->documentElement->insertBefore($defaults, $doc->documentElement->firstChild); }
                    $runDefault = $xp->query('./w:rPrDefault', $defaults)->item(0);
                    if (!$runDefault) { $runDefault = $doc->createElementNS(self::NS, 'w:rPrDefault'); $defaults->insertBefore($runDefault, $defaults->firstChild); }
                    if (!$xp->query('./w:rPr', $runDefault)->length) $runDefault->appendChild($doc->createElementNS(self::NS, 'w:rPr'));
                }
                foreach (iterator_to_array($xp->query('//w:rPr')) as $pr) {
                    foreach (iterator_to_array($xp->query('./w:lang | ./w:noProof', $pr)) as $old) $pr->removeChild($old);
                    $lang = $doc->createElementNS(self::NS, 'w:lang');
                    foreach (['val', 'eastAsia', 'bidi'] as $attr) $lang->setAttributeNS(self::NS, 'w:'.$attr, $locale);
                    $pr->insertBefore($lang, $xp->query('./w:eastAsianLayout | ./w:specVanish | ./w:oMath | ./w:rPrChange', $pr)->item(0));
                }
                // Remove obsolete proofing marks without creating formatting review revisions.
                foreach (iterator_to_array($xp->query('//w:proofErr')) as $mark) $mark->parentNode->removeChild($mark);
                $zip->addFromString($name, $doc->saveXML());
            }
        } catch (\Throwable $e) { $zip->close(); @unlink($path); throw $e; }
        $zip->close();
        return $path;
    }

    /** Keep the original package, table containers, sections, headers, footers and style parts. */
    private function exportIntoSource(string $sourcePath, string $body): string
    {
        $path = tempnam(storage_path('app'), 'export-');
        if (!copy($sourcePath, $path)) throw new RuntimeException('invalid_docx');
        $zip = new ZipArchive; $zip->open($path);
        try {
            $doc = new DOMDocument; $doc->loadXML($zip->getFromName('word/document.xml'), LIBXML_NONET);
            $xp = new DOMXPath($doc); $xp->registerNamespace('w', self::NS);
            $originals = iterator_to_array($xp->query('//w:body//w:p[not(ancestor::w:del)]'));
            $generated = new DOMDocument; $generated->loadXML('<root xmlns:w="'.self::NS.'">'.$body.'</root>', LIBXML_NONET);
            foreach (iterator_to_array($generated->documentElement->childNodes) as $paragraph) {
                if (!$paragraph instanceof \DOMElement) continue;
                $index = (int) $paragraph->getAttribute('data-le-source'); $paragraph->removeAttribute('data-le-source');
                $anchor = $originals[$index] ?? null;
                if (!$anchor) throw new RuntimeException('invalid_docx');
                $gx = new DOMXPath($generated); $gx->registerNamespace('w', self::NS);
                $visible = fn ($xpath, $p) => implode('', array_map(fn ($n) => match ($n->localName) { 'br' => "\n", 'tab' => "\t", default => $n->textContent }, iterator_to_array($xpath->query('.//w:t[not(ancestor::w:del)] | .//w:br | .//w:tab', $p))));
                $unchanged = !$gx->query('.//w:ins | .//w:del | .//w:rPrChange', $paragraph)->length
                    && $visible($gx, $paragraph) === $visible($xp, $anchor);
                // Retain original hyperlinks, bookmarks and drawings verbatim in untouched paragraphs.
                $replacement = $unchanged ? $anchor->cloneNode(true) : $doc->importNode($paragraph, true);
                $anchor->parentNode->insertBefore($replacement, $anchor);
            }
            foreach ($originals as $paragraph) $paragraph->parentNode?->removeChild($paragraph);
            $zip->addFromString('word/document.xml', $doc->saveXML());
            $settingsXml = $zip->getFromName('word/settings.xml');
            $settings = new DOMDocument;
            if (!$settingsXml || str_contains($settingsXml, '<!DOCTYPE') || !@$settings->loadXML($settingsXml, LIBXML_NONET)) $settings->loadXML('<w:settings xmlns:w="'.self::NS.'"/>');
            $sx = new DOMXPath($settings); $sx->registerNamespace('w', self::NS);
            if (!$sx->query('//w:trackRevisions')->length) $settings->documentElement->appendChild($settings->createElementNS(self::NS, 'w:trackRevisions'));
            $zip->addFromString('word/settings.xml', $settings->saveXML());
            // Some minimal uploads omit settings relationships/content-type declarations.
            foreach ([['[Content_Types].xml', 'http://schemas.openxmlformats.org/package/2006/content-types', 'Override', 'PartName', '/word/settings.xml', ['ContentType' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml']],
                ['word/_rels/document.xml.rels', 'http://schemas.openxmlformats.org/package/2006/relationships', 'Relationship', 'Type', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings', ['Target' => 'settings.xml', 'Id' => 'leSettings'.bin2hex(random_bytes(4))]]] as [$name, $ns, $tag, $key, $value, $attrs]) {
                $part = new DOMDocument; $raw = $zip->getFromName($name);
                if (!$raw) $raw = '<Relationships xmlns="'.$ns.'"/>';
                if (str_contains($raw, '<!DOCTYPE') || !@$part->loadXML($raw, LIBXML_NONET)) throw new RuntimeException('invalid_docx');
                $exists = false;
                foreach ($part->getElementsByTagNameNS($ns, $tag) as $node) if ($node->getAttribute($key) === $value) $exists = true;
                if (!$exists) { $node = $part->createElementNS($ns, $tag); foreach (array_merge([$key => $value], $attrs) as $k => $v) $node->setAttribute($k, $v); $part->documentElement->appendChild($node); }
                $zip->addFromString($name, $part->saveXML());
            }
        } catch (\Throwable $error) { $zip->close(); @unlink($path); throw $error; }
        $zip->close(); return $path;
    }
}
