<?php

namespace Tests\Unit;

use App\Support\MemberLessonMaterialUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use ZipArchive;

class MemberLessonMaterialUploadTest extends TestCase
{
    public function test_accepts_pdf_txt_csv_and_rtf(): void
    {
        $pdf = UploadedFile::fake()->createWithContent('aula.pdf', "%PDF-1.4\n% test");
        $this->assertSame('pdf', MemberLessonMaterialUpload::assertValid($pdf)['extension']);

        $txt = UploadedFile::fake()->createWithContent('notas.txt', 'Conteudo da aula em texto.');
        $this->assertSame('txt', MemberLessonMaterialUpload::assertValid($txt)['extension']);

        $csv = UploadedFile::fake()->createWithContent('lista.csv', "nome,email\nAna,ana@example.com\n");
        $this->assertSame('csv', MemberLessonMaterialUpload::assertValid($csv)['extension']);

        $rtf = UploadedFile::fake()->createWithContent('texto.rtf', "{\\rtf1\\ansi Conteudo}");
        $this->assertSame('rtf', MemberLessonMaterialUpload::assertValid($rtf)['extension']);
    }

    public function test_accepts_docx_with_required_office_entry(): void
    {
        $path = $this->makeZip([
            '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>',
            'word/document.xml' => '<?xml version="1.0"?><w:document></w:document>',
        ]);

        $file = new UploadedFile($path, 'apostila.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);
        $this->assertSame('docx', MemberLessonMaterialUpload::assertValid($file)['extension']);
    }

    public function test_accepts_odt_when_mimetype_matches(): void
    {
        $path = $this->makeZip([
            'mimetype' => 'application/vnd.oasis.opendocument.text',
            'content.xml' => '<?xml version="1.0"?><office:document-content></office:document-content>',
        ]);

        $file = new UploadedFile($path, 'apostila.odt', 'application/vnd.oasis.opendocument.text', null, true);
        $this->assertSame('odt', MemberLessonMaterialUpload::assertValid($file)['extension']);
    }

    public function test_rejects_html_svg_xml_js_zip_and_legacy_office(): void
    {
        $this->assertRejected(UploadedFile::fake()->createWithContent('page.html', '<html><script>alert(1)</script></html>'));
        $this->assertRejected(UploadedFile::fake()->createWithContent('icon.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'));
        $this->assertRejected(UploadedFile::fake()->createWithContent('data.xml', '<?xml version="1.0"?><root/>'));
        $this->assertRejected(UploadedFile::fake()->createWithContent('app.js', 'alert(1)'));
        $this->assertRejected(UploadedFile::fake()->createWithContent('pack.zip', "PK\x03\x04fake"));
        $this->assertRejected(UploadedFile::fake()->createWithContent('antigo.doc', 'OLE'));
        $this->assertRejected(UploadedFile::fake()->createWithContent('macro.docm', "PK\x03\x04"));
    }

    public function test_rejects_html_disguised_as_txt_or_pdf(): void
    {
        $this->assertRejected(UploadedFile::fake()->createWithContent('notas.txt', '<html><svg onload=alert(1)></svg></html>'));
        $this->assertRejected(UploadedFile::fake()->createWithContent('aula.pdf', '<html><body>fake</body></html>'));
        $this->assertRejected(UploadedFile::fake()->createWithContent('payload.html.pdf', "%PDF-1.4\n% html"));
    }

    public function test_rejects_zip_renamed_as_docx(): void
    {
        $path = $this->makeZip([
            'readme.txt' => 'nao e um documento word',
        ]);
        $file = new UploadedFile($path, 'falso.docx', 'application/zip', null, true);
        $this->assertRejected($file);
    }

    public function test_rejects_docx_with_macro_project(): void
    {
        $path = $this->makeZip([
            'word/document.xml' => '<?xml version="1.0"?><w:document></w:document>',
            'word/vbaProject.bin' => 'macro',
        ]);
        $file = new UploadedFile($path, 'macro.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);
        $this->assertRejected($file);
    }

    public function test_safe_stored_name_uses_canonical_extension_and_random_suffix(): void
    {
        $name = MemberLessonMaterialUpload::safeStoredName('Apostila Final!.PDF', 'pdf');
        $this->assertMatchesRegularExpression('/^Apostila_Final_[a-f0-9]{8}\.pdf$/', $name);
        $this->assertStringEndsWith('.pdf', $name);
    }

    /**
     * @param  array<string, string>  $entries
     */
    private function makeZip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'matzip');
        $zipPath = $path.'.zip';
        @unlink($path);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $this->assertFileExists($zipPath);

        return $zipPath;
    }

    private function assertRejected(UploadedFile $file): void
    {
        try {
            MemberLessonMaterialUpload::assertValid($file);
            $this->fail('Esperava rejeição do arquivo '.$file->getClientOriginalName());
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('file', $e->errors());
        }
    }
}
