<?php

namespace App\Services\Question;

use RuntimeException;
use ZipArchive;

class DocxQuestionTemplateService
{
    public function build(): string
    {
        $path = tempnam(WRITEPATH . 'cache', 'question_template_');
        if ($path === false) {
            throw new RuntimeException('Template DOCX tidak bisa dibuat.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($path);

            throw new RuntimeException('Template DOCX tidak bisa dibuka.');
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypesXml());
        $zip->addFromString('_rels/.rels', $this->rootRelationshipsXml());
        $zip->addFromString('docProps/core.xml', $this->corePropertiesXml());
        $zip->addFromString('docProps/app.xml', $this->appPropertiesXml());
        $zip->addFromString('word/document.xml', $this->documentXml());
        $zip->addFromString('word/_rels/document.xml.rels', $this->documentRelationshipsXml());
        $zip->close();

        $data = file_get_contents($path);
        @unlink($path);

        if ($data === false || $data === '') {
            throw new RuntimeException('Template DOCX gagal dibaca.');
        }

        return $data;
    }

    private function contentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>
  <Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>
  <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
</Types>';
    }

    private function rootRelationshipsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>
</Relationships>';
    }

    private function corePropertiesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <dc:title>Template Import Bank Soal</dc:title>
  <dc:creator>Ruang Game</dc:creator>
  <cp:lastModifiedBy>Ruang Game</cp:lastModifiedBy>
</cp:coreProperties>';
    }

    private function appPropertiesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">
  <Application>Ruang Game</Application>
</Properties>';
    }

    private function documentRelationshipsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>';
    }

    private function documentXml(): string
    {
        $paragraphs = [
            'Template Import Bank Soal Ruang Game',
            '',
            'Aturan singkat:',
            '- Tulis nomor soal dengan format: 1. Pertanyaan',
            '- Opsi boleh A sampai H.',
            '- Tandai jawaban benar dengan * di depan opsi, atau tulis Jawaban/Kunci di bawah opsi.',
            '- Gunakan [EASY], [MEDIUM], [HARD] untuk difficulty.',
            '- Gunakan [TRUE_FALSE] untuk soal benar/salah.',
            '- Gambar boleh ditempel di bawah soal atau opsi.',
            '',
            '1. [EASY] Planet merah adalah ...',
            'A. Venus',
            '*B. Mars',
            'C. Jupiter',
            'D. Merkurius',
            '',
            '2. [MEDIUM] Ibu kota Indonesia saat ini adalah ...',
            'A. Bandung',
            'B. Surabaya',
            'C. Yogyakarta',
            'D. Jakarta',
            'Jawaban: D',
            '',
            '3. [TRUE_FALSE] Air mendidih pada suhu 100 derajat Celcius.',
            'Jawaban: Benar',
            '',
            '4. [HARD] Tempel gambar di bawah baris soal ini jika soal membutuhkan gambar.',
            'A. Pilihan pertama',
            'B. Pilihan kedua (benar)',
            'C. Pilihan ketiga',
            'D. Pilihan keempat',
        ];

        $body = '';
        foreach ($paragraphs as $paragraph) {
            $body .= $this->paragraph($paragraph);
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:body>'
            . $body
            . '<w:sectPr><w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440"/></w:sectPr>
  </w:body>
</w:document>';
    }

    private function paragraph(string $text): string
    {
        $text = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return '<w:p><w:r><w:t xml:space="preserve">' . $text . '</w:t></w:r></w:p>';
    }
}
