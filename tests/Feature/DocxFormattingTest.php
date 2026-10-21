<?php
namespace Tests\Feature;

use App\Models\Book;
use App\Models\User;
use App\Services\Docx;
use App\Services\Italics;
use App\Services\SourceFormatting;
use App\Services\TextEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class DocxFormattingTest extends TestCase
{
    use RefreshDatabase;

    private function source(): string
    {
        $path = app(Docx::class)->export('Chapter One' . "\n\n" . 'Bold and italic text.', 'Chapter One' . "\n\n" . 'Bold and italic text.', 'Test');
        $zip = new ZipArchive; $zip->open($path);
        $ns = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $zip->addFromString('word/document.xml', '<w:document xmlns:w="'.$ns.'" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><w:body>'
            .'<w:p><w:pPr><w:pStyle w:val="Heading1"/><w:keepNext/></w:pPr><w:r><w:t>Chapter One</w:t></w:r></w:p>'
            .'<w:tbl><w:tblPr/><w:tr><w:tc><w:tcPr/><w:p><w:pPr><w:spacing w:after="120"/></w:pPr>'
            .'<w:r><w:rPr><w:b/><w:color w:val="336699"/><w:rFonts w:ascii="Georgia"/></w:rPr><w:t>Bold</w:t></w:r>'
            .'<w:r><w:t xml:space="preserve"> and </w:t></w:r>'
            .'<w:r><w:rPr><w:rStyle w:val="Emphasis"/></w:rPr><w:t>italic</w:t></w:r>'
            .'<w:r><w:t xml:space="preserve"> text.</w:t></w:r></w:p></w:tc></w:tr></w:tbl>'
            .'<w:sectPr><w:headerReference w:type="default" r:id="header1"/><w:pgSz w:w="11906" w:h="16838"/></w:sectPr></w:body></w:document>');
        $zip->addFromString('word/styles.xml', '<w:styles xmlns:w="'.$ns.'"><w:style w:type="paragraph" w:styleId="Base"><w:rPr><w:b/></w:rPr></w:style><w:style w:type="paragraph" w:styleId="Heading1"><w:basedOn w:val="Base"/><w:pPr><w:outlineLvl w:val="0"/></w:pPr><w:rPr><w:sz w:val="36"/></w:rPr></w:style><w:style w:type="character" w:styleId="Emphasis"><w:rPr><w:i/></w:rPr></w:style></w:styles>');
        $zip->addFromString('word/header1.xml', '<w:hdr xmlns:w="'.$ns.'"><w:p><w:r><w:t>Running header</w:t></w:r></w:p></w:hdr>');
        $rels=$zip->getFromName('word/_rels/document.xml.rels');
        $rels=str_replace('</Relationships>', '<Relationship Id="header1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header1.xml"/><Relationship Id="styles1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',$rels);
        $zip->addFromString('word/_rels/document.xml.rels',$rels);
        $types=$zip->getFromName('[Content_Types].xml');
        $types=str_replace('</Types>','<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/><Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/></Types>',$types);
        $zip->addFromString('[Content_Types].xml',$types);$zip->close();return $path;
    }

    public function test_export_keeps_extension_namespaces_in_detached_properties(): void
    {
        $path = $this->source(); $output = null;
        try {
            $zip = new ZipArchive; $zip->open($path);
            $xml = $zip->getFromName('word/document.xml');
            $xml = str_replace('<w:document ', '<w:document xmlns:w16du="http://schemas.microsoft.com/office/word/2023/wordml/word16du" ', $xml);
            $xml = str_replace('<w:keepNext/>', '<w:keepNext/><w:rPr><w:ins w:id="9" w16du:dateUtc="2026-10-21T00:00:00Z"/></w:rPr>', $xml);
            $zip->addFromString('word/document.xml', $xml); $zip->close();
            $import = app(Docx::class)->import($path);
            $current = "New paragraph\n\n".$import['text'];
            $output = app(Docx::class)->export($import['text'], $current, 'Test', [[37,43]], $path);
            $this->assertSame($current, app(Docx::class)->import($output)['text']);
        } finally { unlink($path); if ($output) unlink($output); }
    }

    public function test_import_resolves_headings_character_styles_and_direct_formatting(): void
    {
        $path = $this->source();
        try {
            $import = app(Docx::class)->import($path);
            $this->assertSame("Chapter One\n\nBold and italic text.", $import['text']);
            $runs = $import['formatting']['runs'];
            $this->assertSame(1, $runs[0]['attributes']['header']);
            $this->assertTrue($runs[0]['attributes']['bold']);
            $this->assertSame('18pt', $runs[0]['attributes']['size']);
            $this->assertSame('#336699', $runs[1]['attributes']['color']);
            $this->assertTrue($runs[3]['attributes']['italic']);
            $this->assertSame([[22,28]], $import['italics']);
        } finally { unlink($path); }
    }

    public function test_export_preserves_source_package_styles_table_and_header_after_text_edit(): void
    {
        $path = $this->source(); $output = null;
        try {
            $import = app(Docx::class)->import($path);
            $current = str_replace('Bold', 'Strong', $import['text']);
            $output = app(Docx::class)->export($import['text'], $current, 'Test', [[24,30]], $path);
            $zip = new ZipArchive; $zip->open($output); $xml = $zip->getFromName('word/document.xml');
            $this->assertStringContainsString('Running header', $zip->getFromName('word/header1.xml'));
            $this->assertStringContainsString('Emphasis', $zip->getFromName('word/styles.xml'));
            $this->assertStringContainsString('header1.xml', $zip->getFromName('word/_rels/document.xml.rels'));
            $doc = new \DOMDocument; $this->assertTrue($doc->loadXML($xml));
            $xp = new \DOMXPath($doc); $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $this->assertSame(1,$xp->query('//w:pPr/w:pStyle[@w:val="Heading1"]')->length);
            $this->assertSame('Strong',$xp->query('//w:tbl//w:ins//w:r[w:rPr/w:b]/w:t')->item(0)->textContent);
            $this->assertSame('336699',$xp->query('//w:ins//w:rPr/w:color')->item(0)->getAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main','val'));
            $this->assertSame(1,$xp->query('//w:sectPr/w:headerReference')->length);
            $this->assertSame(0,$xp->query('//w:rPrChange')->length, 'Unchanged imported italics are not new formatting revisions.');
            $zip->close();
            $this->assertSame($current, app(Docx::class)->import($output)['text']);
        } finally { unlink($path); if($output)unlink($output); }
    }

    public function test_older_upload_recovers_styles_and_italic_removal_can_be_rejected(): void
    {
        Storage::fake('local'); $path=$this->source(); $import=app(Docx::class)->import($path);
        Storage::put('source.docx',file_get_contents($path));unlink($path);
        $book=Book::create(['user_id'=>User::factory()->create()->id,'title'=>'Test','original_path'=>'source.docx','original_text'=>$import['text'],'text'=>$import['text'],'metadata_status'=>'complete']);
        $this->assertSame($import['formatting'],app(SourceFormatting::class)->forBook($book));
        $this->assertSame([[22,28]],app(Italics::class)->book($book));
        $segments=app(SourceFormatting::class)->annotate(app(TextEditor::class)->diff($import['text'],$import['text']),$import['formatting']['runs']);
        $this->assertSame('equal',$segments[0]['type']);
        $edited=app(Italics::class)->apply($segments,[], 'pending','ai');
        $this->assertSame([[22,28]],app(Italics::class)->ranges($edited,false));
        $this->assertSame([],app(Italics::class)->ranges($edited,true));
        foreach($edited as &$segment)if($segment['type']==='change')$segment['status']='rejected';unset($segment);
        $this->assertSame([[22,28]],app(Italics::class)->ranges($edited));
    }
}
