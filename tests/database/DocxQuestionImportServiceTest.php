<?php

use App\Database\Seeds\DemoGameSeeder;
use App\Models\QuestionModel;
use App\Models\QuestionOptionModel;
use App\Models\QuestionTopicModel;
use App\Services\Question\DocxQuestionImportService;
use App\Services\Question\DocxQuestionTemplateService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class DocxQuestionImportServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $seed = DemoGameSeeder::class;
    private array $pathsToClean = [];

    protected function tearDown(): void
    {
        foreach ($this->pathsToClean as $path) {
            $this->removeTree($path);
        }

        $this->pathsToClean = [];

        parent::tearDown();
    }

    public function testImportDocxParsesMultipleChoiceTrueFalseAndImages(): void
    {
        $path = $this->makeDocxFixture();
        $result = (new DocxQuestionImportService())->import($path, 1);
        $this->pathsToClean[] = rtrim(FCPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . 'uploads/question-imports/1/' . $result['batch_uuid'];

        $this->assertSame(3, $result['imported']);
        $this->assertSame(1, $result['skipped']);

        $questionModel = new QuestionModel();
        $multipleChoice = $questionModel->where('stem', 'Gambar ini menunjukkan bangun datar apa?')->first();
        $trueFalse = $questionModel->where('stem', 'Air mendidih pada suhu 100 derajat Celcius.')->first();
        $trueFalseWithOptions = $questionModel->where('stem', 'Pernyataan pada gambar opsi ini salah.')->first();

        $this->assertSame('MULTIPLE_CHOICE', $multipleChoice['question_type']);
        $this->assertSame('EASY', $multipleChoice['difficulty']);
        $this->assertSame('TRUE_FALSE', $trueFalse['question_type']);

        $questionMeta = json_decode((string) $multipleChoice['meta_json'], true);
        $this->assertNotEmpty($questionMeta['images']);
        $this->assertStringStartsWith('/uploads/question-imports/1/', $questionMeta['images'][0]);

        $options = (new QuestionOptionModel())->where('question_id', $multipleChoice['id'])->orderBy('sort_order')->findAll();
        $this->assertCount(4, $options);
        $this->assertSame('B', array_values(array_filter($options, static fn (array $option): bool => (int) $option['is_correct'] === 1))[0]['label']);

        $tfOptions = (new QuestionOptionModel())->where('question_id', $trueFalse['id'])->orderBy('sort_order')->findAll();
        $this->assertCount(2, $tfOptions);
        $this->assertSame('A', array_values(array_filter($tfOptions, static fn (array $option): bool => (int) $option['is_correct'] === 1))[0]['label']);

        $tfOptionsWithMedia = (new QuestionOptionModel())->where('question_id', $trueFalseWithOptions['id'])->orderBy('sort_order')->findAll();
        $this->assertCount(2, $tfOptionsWithMedia);
        $this->assertSame('B', array_values(array_filter($tfOptionsWithMedia, static fn (array $option): bool => (int) $option['is_correct'] === 1))[0]['label']);

        $optionMedia = json_decode((string) $tfOptionsWithMedia[1]['media_json'], true);
        $this->assertNotEmpty($optionMedia['images']);
    }

    public function testImportDocxReportsQuestionsMissingExplicitDifficulty(): void
    {
        $path = $this->makeDocxFixture();
        $result = (new DocxQuestionImportService())->import($path, 1);
        $this->pathsToClean[] = rtrim(FCPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . 'uploads/question-imports/1/' . $result['batch_uuid'];

        // Fixture has 3 imported questions: #1 has [EASY] (explicit),
        // #2 and #3 only have [TRUE_FALSE] (difficulty defaults to MEDIUM, not explicit).
        $this->assertSame(2, $result['difficulty_unspecified']);
    }

    public function testImportDocxTagsQuestionsWithGivenTopicId(): void
    {
        $topicId = (new QuestionTopicModel())->insert([
            'public_uuid' => \App\Services\Game\Uuid::v4(),
            'owner_teacher_id' => 1,
            'name' => 'Topik Uji',
        ], true);

        $path = $this->makeDocxFixture();
        $result = (new DocxQuestionImportService())->import($path, 1, $topicId);
        $this->pathsToClean[] = rtrim(FCPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . 'uploads/question-imports/1/' . $result['batch_uuid'];

        $imported = (new QuestionModel())->where('owner_teacher_id', 1)->findAll();
        $taggedCount = count(array_filter(
            $imported,
            static fn (array $question): bool => (int) ($question['topic_id'] ?? 0) === $topicId,
        ));

        $this->assertSame(3, $taggedCount);
    }

    public function testImportDocxWithoutTopicLeavesQuestionsUntagged(): void
    {
        $path = $this->makeDocxFixture();
        $result = (new DocxQuestionImportService())->import($path, 1);
        $this->pathsToClean[] = rtrim(FCPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . 'uploads/question-imports/1/' . $result['batch_uuid'];

        $imported = (new QuestionModel())->where('owner_teacher_id', 1)->findAll();
        foreach ($imported as $question) {
            $this->assertNull($question['topic_id']);
        }
    }

    public function testTemplateServiceBuildsValidDocx(): void
    {
        $data = (new DocxQuestionTemplateService())->build();
        $this->assertStringContainsString('PK', substr($data, 0, 2));

        $path = tempnam(sys_get_temp_dir(), 'docx_template_');
        $this->assertIsString($path);
        $this->pathsToClean[] = $path;
        $this->assertNotFalse(file_put_contents($path, $data));

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path));

        $documentXml = $zip->getFromName('word/document.xml');
        $zip->close();

        $this->assertIsString($documentXml);
        $this->assertStringContainsString('[TRUE_FALSE]', $documentXml);
        $this->assertStringContainsString('Jawaban: Benar', $documentXml);
    }

    public function testGeneratedTemplateCanBeImportedBack(): void
    {
        $data = (new DocxQuestionTemplateService())->build();
        $path = tempnam(sys_get_temp_dir(), 'docx_template_import_');
        $this->assertIsString($path);
        $this->pathsToClean[] = $path;
        $this->assertNotFalse(file_put_contents($path, $data));

        $result = (new DocxQuestionImportService())->import($path, 1);
        $this->pathsToClean[] = rtrim(FCPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . 'uploads/question-imports/1/' . $result['batch_uuid'];

        $this->assertSame(4, $result['imported']);
        $this->assertSame(0, $result['skipped']);
    }

    public function testImportDocxSupportsWordAutomaticNumberedLists(): void
    {
        $path = $this->makeNumberedListDocxFixture();
        $result = (new DocxQuestionImportService())->import($path, 1);
        $this->pathsToClean[] = rtrim(FCPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . 'uploads/question-imports/1/' . $result['batch_uuid'];

        $this->assertSame(2, $result['imported']);
        $this->assertSame(0, $result['skipped']);

        $planet = (new QuestionModel())->where('stem', 'Planet merah adalah ...')->first();
        $this->assertNotNull($planet);

        $options = (new QuestionOptionModel())->where('question_id', $planet['id'])->orderBy('sort_order')->findAll();
        $this->assertCount(3, $options);
        $this->assertSame('B', array_values(array_filter($options, static fn (array $option): bool => (int) $option['is_correct'] === 1))[0]['label']);
        $this->assertSame('Mars', $options[1]['body']);
    }

    private function makeDocxFixture(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'docx_import_');
        $this->pathsToClean[] = $path;

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Default Extension="png" ContentType="image/png"/>
  <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>');
        $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId5" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image1.png"/>
</Relationships>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">
  <w:body>
    <w:p><w:r><w:t>Template ini punya teks pembuka.</w:t></w:r></w:p>
    <w:p><w:r><w:t>- Baris ini harus diabaikan importer.</w:t></w:r></w:p>
    <w:p><w:r><w:t>1. [EASY] Gambar ini menunjukkan bangun datar apa?</w:t></w:r><w:r><w:drawing><a:blip r:embed="rId5"/></w:drawing></w:r></w:p>
    <w:p><w:r><w:t>A. Segitiga</w:t></w:r></w:p>
    <w:p><w:r><w:t>*B. Persegi</w:t></w:r></w:p>
    <w:p><w:r><w:t>C. Lingkaran</w:t></w:r></w:p>
    <w:p><w:r><w:t>D. Trapesium</w:t></w:r></w:p>
    <w:p><w:r><w:t>2. [TRUE_FALSE] Air mendidih pada suhu 100 derajat Celcius.</w:t></w:r></w:p>
    <w:p><w:r><w:t>Jawaban: Benar</w:t></w:r></w:p>
    <w:p><w:r><w:t>3. [TRUE_FALSE] Pernyataan pada gambar opsi ini salah.</w:t></w:r></w:p>
    <w:p><w:r><w:t>A. Benar</w:t></w:r></w:p>
    <w:p><w:r><w:t>B. Salah</w:t></w:r><w:r><w:drawing><a:blip r:embed="rId5"/></w:drawing></w:r></w:p>
    <w:p><w:r><w:t>Jawaban: Salah</w:t></w:r></w:p>
    <w:p><w:r><w:t>4. Soal ini belum punya opsi dan kunci.</w:t></w:r></w:p>
  </w:body>
</w:document>');
        $zip->addFromString('word/media/image1.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='));
        $zip->close();

        return $path;
    }

    private function makeNumberedListDocxFixture(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'docx_numbered_import_');
        $this->pathsToClean[] = $path;

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
  <Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/>
</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>');
        $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rIdNum" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering" Target="numbering.xml"/>
</Relationships>');
        $zip->addFromString('word/numbering.xml', '<?xml version="1.0" encoding="UTF-8"?>
<w:numbering xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:abstractNum w:abstractNumId="1">
    <w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="decimal"/><w:lvlText w:val="%1."/></w:lvl>
  </w:abstractNum>
  <w:abstractNum w:abstractNumId="2">
    <w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="upperLetter"/><w:lvlText w:val="%1."/></w:lvl>
  </w:abstractNum>
  <w:num w:numId="10"><w:abstractNumId w:val="1"/></w:num>
  <w:num w:numId="20"><w:abstractNumId w:val="2"/></w:num>
  <w:num w:numId="21"><w:abstractNumId w:val="2"/></w:num>
</w:numbering>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:body>
    <w:p><w:r><w:t>Template dengan numbering otomatis Word.</w:t></w:r></w:p>
    <w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="10"/></w:numPr></w:pPr><w:r><w:t>[EASY] Planet merah adalah ...</w:t></w:r></w:p>
    <w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="20"/></w:numPr></w:pPr><w:r><w:t>Venus</w:t></w:r></w:p>
    <w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="20"/></w:numPr></w:pPr><w:r><w:t>Mars (benar)</w:t></w:r></w:p>
    <w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="20"/></w:numPr></w:pPr><w:r><w:t>Jupiter</w:t></w:r></w:p>
    <w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="10"/></w:numPr></w:pPr><w:r><w:t>Ibu kota Indonesia saat ini adalah ...</w:t></w:r></w:p>
    <w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="21"/></w:numPr></w:pPr><w:r><w:t>Bandung</w:t></w:r></w:p>
    <w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="21"/></w:numPr></w:pPr><w:r><w:t>Jakarta</w:t></w:r></w:p>
    <w:p><w:r><w:t>Kunci: B</w:t></w:r></w:p>
  </w:body>
</w:document>');
        $zip->close();

        return $path;
    }

    private function removeTree(string $path): void
    {
        if ($path === '' || ! file_exists($path)) {
            return;
        }

        if (is_file($path)) {
            @unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $this->removeTree($path . DIRECTORY_SEPARATOR . $entry);
        }

        @rmdir($path);
    }
}
