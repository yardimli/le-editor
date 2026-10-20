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
            $paragraphs = [];
            foreach ($xp->query('//w:body//w:p[not(ancestor::w:del)]') as $paragraph) {
                $text = '';
                foreach ($xp->query('.//w:t[not(ancestor::w:del)] | .//w:br | .//w:tab', $paragraph) as $node) {
                    $text .= match ($node->localName) { 'br' => "\n", 'tab' => "\t", default => $node->textContent };
                }
                $paragraphs[] = $text;
            }
            $text = implode("\n\n", $paragraphs);
            if (trim($text) === '' || mb_strlen($text) > 1000000) { throw new RuntimeException('invalid_docx'); }
            return ['text' => $text, 'complex' => $xp->query('//w:tbl | //w:drawing | //w:footnoteReference')->length > 0];
        } finally { $zip->close(); }
    }

    /** Generate an OOXML package with genuine w:ins/w:del revisions. */
    public function export(string $original, string $current, string $title): string
    {
        $escape = fn ($value) => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $run = function (string $text, bool $deleted = false) use ($escape): string {
            $tag = $deleted ? 'delText' : 't';
            $xml = '';
            foreach (preg_split('/(\n|\t)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE) as $part) {
                $xml .= match ($part) {
                    "\n" => '<w:r><w:br/></w:r>', "\t" => '<w:r><w:tab/></w:r>',
                    '' => '', default => '<w:r><w:'.$tag.' xml:space="preserve">'.$escape($part).'</w:'.$tag.'></w:r>',
                };
            }
            return $xml;
        };
        $body = '';
        $id = 0;
        $date = gmdate('Y-m-d\TH:i:s\Z');
        // Paragraph-sequence diff preserves real paragraphs; changed paragraphs contain word revisions.
        $old = explode("\n\n", $original);
        $new = explode("\n\n", $current);
        $matcher = new \Jfcherng\Diff\Differ($old, $new, ['context' => \Jfcherng\Diff\Differ::CONTEXT_ALL]);
        $groups = $matcher->getGroupedOpcodes();
        if (!$groups) {
            foreach ($new as $paragraph) { $body .= '<w:p>'.$run($paragraph).'</w:p>'; }
        }
        foreach ($groups as $group) {
            foreach ($group as [$tag, $a1, $a2, $b1, $b2]) {
                if ($tag === \Jfcherng\Diff\SequenceMatcher::OP_EQ) {
                    foreach (array_slice($new, $b1, $b2 - $b1) as $paragraph) {
                        $body .= '<w:p>'.$run($paragraph).'</w:p>';
                    }
                    continue;
                }
                $count = max($a2 - $a1, $b2 - $b1);
                for ($i = 0; $i < $count; $i++) {
                    $before = $i < $a2 - $a1 ? $old[$a1 + $i] : '';
                    $after = $i < $b2 - $b1 ? $new[$b1 + $i] : '';
                    $body .= '<w:p>';
                    if ($i >= $a2 - $a1 || $i >= $b2 - $b1) {
                        $mark = $i >= $a2 - $a1 ? 'ins' : 'del';
                        $body .= '<w:pPr><w:rPr><w:'.$mark.' w:id="'.(++$id).'" w:author="LE Editor" w:date="'.$date.'"/></w:rPr></w:pPr>';
                    }
                    foreach (app(TextEditor::class)->diff($before, $after) as $segment) {
                        if ($segment['type'] === 'equal') { $body .= $run($segment['after']); continue; }
                        foreach (['del' => 'before', 'ins' => 'after'] as $kind => $key) {
                            if ($segment[$key] !== '') {
                                $body .= '<w:'.$kind.' w:id="'.(++$id).'" w:author="LE Editor" w:date="'.$date.'">'
                                    .$run($segment[$key], $kind === 'del').'</w:'.$kind.'>';
                            }
                        }
                    }
                    $body .= '</w:p>';
                }
            }
        }
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
        return $path;
    }
}
