<?php
namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;
use ZipArchive;

class OoxmlFormatting
{
    public const NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private array $styles = [];
    private array $defaults = [];

    public function __construct(ZipArchive $zip)
    {
        $xml = $zip->getFromName('word/styles.xml');
        if (!$xml || strlen($xml) > 5000000 || str_contains($xml, '<!DOCTYPE')) return;
        $doc = new DOMDocument;
        if (!@$doc->loadXML($xml, LIBXML_NONET)) return;
        $xp = new DOMXPath($doc); $xp->registerNamespace('w', self::NS);
        $this->defaults = $this->properties($xp->query('//w:docDefaults/w:rPrDefault/w:rPr')->item(0));
        foreach ($xp->query('//w:style') as $style) {
            $id = $style->getAttributeNS(self::NS, 'styleId');
            $base = $xp->query('./w:basedOn', $style)->item(0)?->getAttributeNS(self::NS, 'val');
            $attrs = $this->properties($xp->query('./w:rPr', $style)->item(0));
            $outline = $xp->query('./w:pPr/w:outlineLvl', $style)->item(0);
            if ($outline && (int) $outline->getAttributeNS(self::NS, 'val') < 6) $attrs['header'] = (int) $outline->getAttributeNS(self::NS, 'val') + 1;
            elseif (preg_match('/^Heading([1-6])$/i', $id, $m)) $attrs['header'] = (int) $m[1];
            $this->styles[$id] = ['base' => $base, 'attributes' => $attrs];
            if ($style->getAttributeNS(self::NS, 'default') === '1' && $style->getAttributeNS(self::NS, 'type') === 'paragraph') $this->defaults = array_merge($this->defaults, $attrs);
        }
    }

    private function style(?string $id, array $seen = []): array
    {
        if (!$id || !isset($this->styles[$id]) || isset($seen[$id])) return [];
        $seen[$id] = true; $style = $this->styles[$id];
        return array_merge($this->style($style['base'], $seen), $style['attributes']);
    }

    private function properties(?DOMElement $properties): array
    {
        $attrs = [];
        if (!$properties) return $attrs;
        foreach ($properties->childNodes as $node) {
            if (!$node instanceof DOMElement || $node->namespaceURI !== self::NS) continue;
            $value = $node->getAttributeNS(self::NS, 'val');
            switch ($node->localName) {
                case 'b': $attrs['bold'] = !in_array($value, ['0', 'false', 'off']); break;
                case 'i': $attrs['italic'] = !in_array($value, ['0', 'false', 'off']); break;
                case 'u': $attrs['underline'] = !in_array($value, ['none', '0', 'false']); break;
                case 'strike': $attrs['strike'] = !in_array($value, ['0', 'false', 'off']); break;
                case 'color': if (preg_match('/^[a-f0-9]{6}$/i', $value)) $attrs['color'] = '#'.$value; break;
                case 'sz': if (is_numeric($value)) $attrs['size'] = ((float) $value / 2).'pt'; break;
                case 'rFonts': $font = $node->getAttributeNS(self::NS, 'ascii'); if ($font) $attrs['font'] = $font; break;
                case 'vertAlign': if (in_array($value, ['superscript', 'subscript'])) $attrs['script'] = $value === 'superscript' ? 'super' : 'sub'; break;
            }
        }
        return $attrs;
    }

    // Importing into a standalone document declares inherited namespaces on the fragment.
    private function standaloneXml(DOMElement $element): string
    {
        $doc = new DOMDocument;
        $copy = $doc->importNode($element, true);
        $doc->appendChild($copy);
        return $doc->saveXML($copy);
    }

    public function paragraph(DOMElement $p, DOMXPath $xp): array
    {
        $pr = $xp->query('./w:pPr', $p)->item(0);
        $style = $xp->query('./w:pPr/w:pStyle', $p)->item(0)?->getAttributeNS(self::NS, 'val');
        $attrs = array_merge($this->defaults, $this->style($style));
        $outline = $xp->query('./w:pPr/w:outlineLvl', $p)->item(0);
        if ($outline) { $level = (int) $outline->getAttributeNS(self::NS, 'val'); $attrs['header'] = $level < 6 ? $level + 1 : false; }
        return ['xml' => $pr ? $this->standaloneXml($pr) : '', 'attributes' => $attrs];
    }

    public function run(DOMElement $node, DOMXPath $xp, array $paragraph): array
    {
        $r = $xp->query('ancestor::w:r[1]', $node)->item(0);
        $pr = $r ? $xp->query('./w:rPr', $r)->item(0) : null;
        $style = $r ? $xp->query('./w:rPr/w:rStyle', $r)->item(0)?->getAttributeNS(self::NS, 'val') : null;
        return ['xml' => $pr ? $this->standaloneXml($pr) : '',
            'attributes' => array_merge($paragraph['attributes'], $this->style($style), $this->properties($pr))];
    }
}
