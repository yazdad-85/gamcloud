<?php

namespace App\Services\Question;

use App\Models\QuestionModel;
use App\Models\QuestionOptionModel;
use App\Services\Game\Uuid;
use DomainException;
use RuntimeException;
use ZipArchive;

class DocxQuestionImportService
{
    private const MAX_QUESTIONS = 100;
    private const MAX_OPTIONS = 8;
    private const MAX_IMAGE_BYTES = 2097152;
    private const ALLOWED_IMAGE_MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];
    private int $skippedQuestions = 0;

    public function import(string $docxPath, int $teacherId, ?int $topicId = null): array
    {
        $this->skippedQuestions = 0;

        if (! is_file($docxPath) || ! is_readable($docxPath)) {
            throw new DomainException('File DOCX tidak bisa dibaca.');
        }

        $zip = new ZipArchive();
        if ($zip->open($docxPath) !== true) {
            throw new DomainException('File DOCX tidak valid.');
        }

        try {
            $documentXml = $zip->getFromName('word/document.xml');
            if ($documentXml === false) {
                throw new DomainException('Isi DOCX tidak memiliki dokumen utama.');
            }

            $batchUuid = Uuid::v4();
            $relationships = $this->imageRelationships($zip);
            $paragraphs = $this->paragraphs($documentXml, $relationships, $zip, $teacherId, $batchUuid);
            $parsed = $this->parseQuestions($paragraphs);

            return $this->persist($parsed, $teacherId, $batchUuid, $this->skippedQuestions, $topicId);
        } finally {
            $zip->close();
        }
    }

    private function imageRelationships(ZipArchive $zip): array
    {
        $relsXml = $zip->getFromName('word/_rels/document.xml.rels');
        if ($relsXml === false) {
            return [];
        }

        $document = $this->xmlDocument($relsXml);
        $map = [];
        foreach ($document->getElementsByTagName('Relationship') as $relationship) {
            $type = (string) $relationship->getAttribute('Type');
            $target = (string) $relationship->getAttribute('Target');
            if (! str_contains($type, '/image')) {
                continue;
            }

            $path = $this->normalizeWordTarget($target);
            if ($path !== null) {
                $map[(string) $relationship->getAttribute('Id')] = $path;
            }
        }

        return $map;
    }

    private function normalizeWordTarget(string $target): ?string
    {
        $target = str_replace('\\', '/', $target);
        if ($target === '' || str_contains($target, "\0") || str_contains($target, '..')) {
            return null;
        }

        $path = str_starts_with($target, 'word/') ? $target : 'word/' . ltrim($target, '/');

        return str_starts_with($path, 'word/media/') ? $path : null;
    }

    private function paragraphs(string $documentXml, array $relationships, ZipArchive $zip, int $teacherId, string $batchUuid): array
    {
        $document = $this->xmlDocument($documentXml);
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $xpath->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        $paragraphs = [];
        foreach ($xpath->query('//w:p') ?: [] as $paragraph) {
            $text = '';
            foreach ($xpath->query('.//w:t | .//w:tab | .//w:br', $paragraph) ?: [] as $node) {
                $text .= $node->localName === 't' ? $node->textContent : ' ';
            }

            $images = [];
            foreach ($xpath->query('.//@r:embed', $paragraph) ?: [] as $embed) {
                $relationshipId = $embed->nodeValue;
                if (! isset($relationships[$relationshipId])) {
                    continue;
                }

                $url = $this->saveImage($zip, $relationships[$relationshipId], $teacherId, $batchUuid);
                if ($url !== null) {
                    $images[] = $url;
                }
            }

            $text = $this->normalizeText($text);
            if ($text !== '' || $images !== []) {
                $paragraphs[] = [
                    'text' => $text,
                    'images' => array_values(array_unique($images)),
                ];
            }
        }

        return $paragraphs;
    }

    private function parseQuestions(array $paragraphs): array
    {
        $questions = [];
        $current = null;
        $lastOption = null;
        $started = false;

        foreach ($paragraphs as $paragraph) {
            $line = $paragraph['text'];
            $images = $paragraph['images'];
            if ($line === '' && $images !== []) {
                if ($current !== null && $lastOption !== null) {
                    $current['options'][$lastOption]['images'] = array_merge($current['options'][$lastOption]['images'], $images);
                } elseif ($current !== null) {
                    $current['images'] = array_merge($current['images'], $images);
                }
                continue;
            }

            if ($this->isQuestionLine($line, $stem)) {
                $this->pushQuestion($questions, $current);
                $current = $this->newQuestion($stem, $images);
                $lastOption = null;
                $started = true;
                continue;
            }

            if (! $started || $current === null) {
                continue;
            }

            if ($this->isAnswerLine($line, $answer)) {
                $current['answer'] = $this->normalizeAnswer($answer);
                continue;
            }

            if ($this->isDifficultyLine($line, $difficulty)) {
                $current['difficulty'] = $difficulty;
                $current['difficulty_explicit'] = true;
                continue;
            }

            if ($this->isTypeLine($line, $type)) {
                $current['type'] = $type;
                continue;
            }

            if ($this->isOptionLine($line, $option)) {
                if (count($current['options']) < self::MAX_OPTIONS) {
                    $current['options'][$option['label']] = [
                        'label' => $option['label'],
                        'body' => $option['body'],
                        'images' => $images,
                        'is_correct' => $option['is_correct'],
                    ];
                    $lastOption = $option['label'];
                }
                continue;
            }

            if ($lastOption !== null) {
                $current['options'][$lastOption]['body'] = trim($current['options'][$lastOption]['body'] . ' ' . $line);
                $current['options'][$lastOption]['images'] = array_merge($current['options'][$lastOption]['images'], $images);
            } else {
                $current['stem'] = trim($current['stem'] . ' ' . $line);
                $current['images'] = array_merge($current['images'], $images);
            }
        }

        $this->pushQuestion($questions, $current);

        if (count($questions) > self::MAX_QUESTIONS) {
            $this->skippedQuestions += count($questions) - self::MAX_QUESTIONS;
        }

        return array_slice($questions, 0, self::MAX_QUESTIONS);
    }

    private function newQuestion(string $stem, array $images): array
    {
        $type = 'MULTIPLE_CHOICE';
        $difficulty = 'MEDIUM';
        $difficultyExplicit = false;
        $stem = preg_replace_callback('/\[(EASY|MEDIUM|HARD|MUDAH|SEDANG|SULIT|PILGAN|PG|TRUE_FALSE|TRUE\/FALSE|BENAR\s*SALAH)\]/i', static function (array $match) use (&$type, &$difficulty, &$difficultyExplicit): string {
            $token = strtoupper(str_replace(' ', '_', $match[1]));
            if (in_array($token, ['EASY', 'MUDAH'], true)) {
                $difficulty = 'EASY';
                $difficultyExplicit = true;
            } elseif (in_array($token, ['HARD', 'SULIT'], true)) {
                $difficulty = 'HARD';
                $difficultyExplicit = true;
            } elseif (in_array($token, ['MEDIUM', 'SEDANG'], true)) {
                $difficulty = 'MEDIUM';
                $difficultyExplicit = true;
            } elseif (in_array($token, ['TRUE_FALSE', 'TRUE/FALSE', 'BENAR_SALAH'], true)) {
                $type = 'TRUE_FALSE';
            }

            return '';
        }, $stem) ?? $stem;

        return [
            'stem' => trim($stem),
            'type' => $type,
            'difficulty' => $difficulty,
            'difficulty_explicit' => $difficultyExplicit,
            'answer' => null,
            'images' => $images,
            'options' => [],
        ];
    }

    private function pushQuestion(array &$questions, ?array $question): void
    {
        if ($question === null) {
            return;
        }

        $question = $this->finalizeQuestion($question);
        if ($question !== null) {
            $questions[] = $question;

            return;
        }

        $this->skippedQuestions++;
    }

    private function finalizeQuestion(array $question): ?array
    {
        $question['stem'] = trim((string) $question['stem']);
        if ($question['stem'] === '') {
            return null;
        }

        $answer = $question['answer'] ?? null;
        if (in_array($answer, ['BENAR', 'SALAH', 'TRUE', 'FALSE', 'A', 'B'], true) && $question['type'] === 'TRUE_FALSE') {
            $question['type'] = 'TRUE_FALSE';
            $correct = in_array($answer, ['BENAR', 'TRUE', 'A'], true) ? 'A' : 'B';
            if (isset($question['options'][$correct]) && count($question['options']) >= 2) {
                foreach ($question['options'] as $label => $option) {
                    $question['options'][$label]['is_correct'] = $label === $correct;
                }
            } else {
                $question['options'] = [
                    'A' => ['label' => 'A', 'body' => 'Benar', 'images' => [], 'is_correct' => $correct === 'A'],
                    'B' => ['label' => 'B', 'body' => 'Salah', 'images' => [], 'is_correct' => $correct === 'B'],
                ];
            }
        } elseif (in_array($answer, ['BENAR', 'SALAH', 'TRUE', 'FALSE'], true)) {
            $question['type'] = 'TRUE_FALSE';
            $correct = in_array($answer, ['BENAR', 'TRUE'], true) ? 'A' : 'B';
            $question['options'] = [
                'A' => ['label' => 'A', 'body' => 'Benar', 'images' => [], 'is_correct' => $correct === 'A'],
                'B' => ['label' => 'B', 'body' => 'Salah', 'images' => [], 'is_correct' => $correct === 'B'],
            ];
        } elseif ($answer !== null && isset($question['options'][$answer])) {
            foreach ($question['options'] as $label => $option) {
                $question['options'][$label]['is_correct'] = $label === $answer;
            }
        }

        $correctCount = count(array_filter($question['options'], static fn (array $option): bool => (bool) $option['is_correct']));
        if (count($question['options']) < 2 || $correctCount !== 1) {
            return null;
        }

        return $question;
    }

    private function persist(array $questions, int $teacherId, string $batchUuid, int $skipped, ?int $topicId = null): array
    {
        $questionModel = new QuestionModel();
        $optionModel = new QuestionOptionModel();
        $imported = 0;
        $difficultyUnspecified = 0;

        foreach ($questions as $question) {
            $questionId = $questionModel->insert([
                'public_uuid' => Uuid::v4(),
                'owner_teacher_id' => $teacherId,
                'topic_id' => $topicId,
                'source_type' => 'PERSONAL',
                'question_type' => $question['type'],
                'stem' => $question['stem'],
                'difficulty' => $question['difficulty'],
                'status' => 'PUBLISHED',
                'points' => 100,
                'time_limit_seconds' => 30,
                'explanation' => null,
                'meta_json' => json_encode([
                    'import_source' => 'docx',
                    'import_batch_uuid' => $batchUuid,
                    'images' => array_values(array_unique($question['images'])),
                ], JSON_UNESCAPED_SLASHES),
            ], true);

            $sort = 1;
            foreach (array_values($question['options']) as $option) {
                $optionModel->insert([
                    'question_id' => $questionId,
                    'label' => $option['label'],
                    'body' => $option['body'],
                    'media_json' => json_encode([
                        'images' => array_values(array_unique($option['images'])),
                    ], JSON_UNESCAPED_SLASHES),
                    'is_correct' => $option['is_correct'] ? 1 : 0,
                    'sort_order' => $sort++,
                ]);
            }
            $imported++;
            if (empty($question['difficulty_explicit'])) {
                $difficultyUnspecified++;
            }
        }

        return [
            'batch_uuid' => $batchUuid,
            'imported' => $imported,
            'skipped' => $skipped,
            'difficulty_unspecified' => $difficultyUnspecified,
        ];
    }

    private function saveImage(ZipArchive $zip, string $path, int $teacherId, string $batchUuid): ?string
    {
        $data = $zip->getFromName($path);
        if ($data === false || strlen($data) > self::MAX_IMAGE_BYTES || @getimagesizefromstring($data) === false) {
            return null;
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($data);
        if (! is_string($mime) || ! isset(self::ALLOWED_IMAGE_MIME[$mime])) {
            return null;
        }

        $relativeDir = 'uploads/question-imports/' . $teacherId . '/' . $batchUuid;
        $targetDir = rtrim(FCPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);
        if (! is_dir($targetDir) && ! mkdir($targetDir, 0755, true) && ! is_dir($targetDir)) {
            throw new RuntimeException('Folder upload gambar tidak bisa dibuat.');
        }

        $filename = bin2hex(random_bytes(12)) . '.' . self::ALLOWED_IMAGE_MIME[$mime];
        $target = $targetDir . DIRECTORY_SEPARATOR . $filename;
        if (file_put_contents($target, $data, LOCK_EX) === false) {
            throw new RuntimeException('Gambar dari DOCX gagal disimpan.');
        }

        return '/' . $relativeDir . '/' . $filename;
    }

    private function isQuestionLine(string $line, ?string &$stem): bool
    {
        if (preg_match('/^(?:soal\s*)?(\d{1,3})[\.\)]\s+(.+)$/iu', $line, $match) === 1) {
            $stem = trim($match[2]);

            return true;
        }

        if (preg_match('/^soal\s*[:\-]\s*(.+)$/iu', $line, $match) === 1) {
            $stem = trim($match[1]);

            return true;
        }

        return false;
    }

    private function isOptionLine(string $line, ?array &$option): bool
    {
        $isCorrect = false;
        $line = trim($line);
        if (str_starts_with($line, '*')) {
            $isCorrect = true;
            $line = trim(substr($line, 1));
        }

        if (preg_match('/^([A-Ha-h])[\.\):\-]\s*(.+)$/u', $line, $match) !== 1) {
            return false;
        }

        $body = trim($match[2]);
        if (preg_match('/(?:\s|^)(?:\[(?:x|benar|correct)\]|\((?:benar|correct)\)|✓)$/iu', $body) === 1) {
            $isCorrect = true;
            $body = trim(preg_replace('/(?:\s|^)(?:\[(?:x|benar|correct)\]|\((?:benar|correct)\)|✓)$/iu', '', $body) ?? $body);
        }

        $option = [
            'label' => strtoupper($match[1]),
            'body' => $body,
            'is_correct' => $isCorrect,
        ];

        return true;
    }

    private function isAnswerLine(string $line, ?string &$answer): bool
    {
        if (preg_match('/^(?:jawaban|kunci(?:\s+jawaban)?)\s*[:\-]\s*(.+)$/iu', $line, $match) !== 1) {
            return false;
        }

        $answer = $match[1];

        return true;
    }

    private function isDifficultyLine(string $line, ?string &$difficulty): bool
    {
        if (preg_match('/^(?:level|difficulty|tingkat)\s*[:\-]\s*(easy|medium|hard|mudah|sedang|sulit)$/iu', $line, $match) !== 1) {
            return false;
        }

        $difficulty = match (strtolower($match[1])) {
            'easy', 'mudah' => 'EASY',
            'hard', 'sulit' => 'HARD',
            default => 'MEDIUM',
        };

        return true;
    }

    private function isTypeLine(string $line, ?string &$type): bool
    {
        if (preg_match('/^(?:tipe|jenis)\s*[:\-]\s*(.+)$/iu', $line, $match) !== 1) {
            return false;
        }

        $type = $this->normalizeType($match[1]);

        return true;
    }

    private function normalizeAnswer(string $answer): ?string
    {
        $answer = strtoupper(trim($answer));
        $answer = preg_replace('/[^A-Z]/', '', $answer) ?? $answer;

        return match ($answer) {
            'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'BENAR', 'TRUE' => $answer,
            'SALAH', 'FALSE' => $answer,
            default => null,
        };
    }

    private function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));

        return str_contains($type, 'true')
            || str_contains($type, 'false')
            || str_contains($type, 'benar')
            || str_contains($type, 'salah')
            ? 'TRUE_FALSE'
            : 'MULTIPLE_CHOICE';
    }

    private function normalizeText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function xmlDocument(string $xml): \DOMDocument
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new DomainException('XML DOCX tidak valid.');
        }

        return $document;
    }
}
