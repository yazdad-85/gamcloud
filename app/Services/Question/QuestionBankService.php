<?php

namespace App\Services\Question;

use App\Models\QuestionModel;
use App\Models\QuestionOptionModel;
use App\Models\QuestionTopicModel;
use App\Models\TeacherModel;
use App\Services\Game\Uuid;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DomainException;
use RuntimeException;

class QuestionBankService
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function create(int $teacherId, array $input): array
    {
        $data = $this->normalize($teacherId, $input);

        $this->db->transStart();

        $questionId = (new QuestionModel())->insert([
            'public_uuid' => Uuid::v4(),
            'owner_teacher_id' => $teacherId,
            'topic_id' => $data['topic_id'],
            'source_type' => 'PERSONAL',
            'question_type' => $data['question_type'],
            'stem' => $data['stem'],
            'difficulty' => $data['difficulty'],
            'status' => $data['status'],
            'points' => $data['points'],
            'time_limit_seconds' => $data['time_limit_seconds'],
            'explanation' => $data['explanation'],
            'meta_json' => null,
        ], true);

        $optionModel = new QuestionOptionModel();
        foreach ($data['options'] as $sort => $option) {
            $optionModel->insert([
                'question_id' => $questionId,
                'label' => $option['label'],
                'body' => $option['body'],
                'media_json' => null,
                'is_correct' => $option['is_correct'] ? 1 : 0,
                'sort_order' => $sort + 1,
            ]);
        }

        $this->db->transComplete();
        $this->assertTransactionSucceeded();

        return (new QuestionModel())->find($questionId);
    }

    public function update(array $question, array $input): array
    {
        $this->assertNotUsedByActiveRoom((int) $question['id'], 'diedit');
        $data = $this->normalize((int) $question['owner_teacher_id'], $input);

        $optionModel = new QuestionOptionModel();
        $existingOptions = $optionModel->where('question_id', $question['id'])->findAll();
        $existingByLabel = [];
        foreach ($existingOptions as $option) {
            $existingByLabel[$option['label']] = $option;
        }

        $desiredLabels = array_column($data['options'], 'label');
        foreach ($existingByLabel as $label => $option) {
            if (! in_array($label, $desiredLabels, true) && $this->optionHasAnswers((int) $option['id'])) {
                throw new DomainException('Opsi ' . $label . ' sudah tercatat dalam laporan game dan tidak dapat dihapus.');
            }
        }

        $this->db->transStart();

        (new QuestionModel())->update($question['id'], [
            'topic_id' => $data['topic_id'],
            'question_type' => $data['question_type'],
            'stem' => $data['stem'],
            'difficulty' => $data['difficulty'],
            'status' => $data['status'],
            'points' => $data['points'],
            'time_limit_seconds' => $data['time_limit_seconds'],
            'explanation' => $data['explanation'],
        ]);

        foreach ($data['options'] as $sort => $option) {
            $values = [
                'body' => $option['body'],
                'is_correct' => $option['is_correct'] ? 1 : 0,
                'sort_order' => $sort + 1,
            ];

            if (isset($existingByLabel[$option['label']])) {
                $optionModel->update($existingByLabel[$option['label']]['id'], $values);
            } else {
                $optionModel->insert($values + [
                    'question_id' => $question['id'],
                    'label' => $option['label'],
                    'media_json' => null,
                ]);
            }
        }

        foreach ($existingByLabel as $label => $option) {
            if (! in_array($label, $desiredLabels, true)) {
                $optionModel->delete($option['id']);
            }
        }

        $this->db->transComplete();
        $this->assertTransactionSucceeded();

        return (new QuestionModel())->find($question['id']);
    }

    public function delete(array $question): void
    {
        $this->assertNotUsedByActiveRoom((int) $question['id'], 'dihapus');
        (new QuestionModel())->delete($question['id']);
    }

    private function normalize(int $teacherId, array $input): array
    {
        if ($teacherId < 1 || (new TeacherModel())->find($teacherId) === null) {
            throw new DomainException('Guru pemilik soal tidak valid.');
        }

        $topicId = (int) ($input['topic_id'] ?? 0);
        $topic = $topicId > 0 ? (new QuestionTopicModel())->find($topicId) : null;
        if ($topic === null || (int) $topic['owner_teacher_id'] !== $teacherId) {
            throw new DomainException('Pilih topik milik guru sebelum menyimpan soal.');
        }

        $stem = trim((string) ($input['stem'] ?? ''));
        if ($stem === '' || mb_strlen($stem) > 5000) {
            throw new DomainException('Pertanyaan wajib diisi dan maksimal 5.000 karakter.');
        }

        $questionType = strtoupper((string) ($input['question_type'] ?? 'MULTIPLE_CHOICE'));
        if (! in_array($questionType, ['MULTIPLE_CHOICE', 'TRUE_FALSE'], true)) {
            throw new DomainException('Tipe soal tidak valid.');
        }

        $difficulty = strtoupper((string) ($input['difficulty'] ?? 'MEDIUM'));
        if (! in_array($difficulty, ['EASY', 'MEDIUM', 'HARD'], true)) {
            throw new DomainException('Difficulty soal tidak valid.');
        }

        $status = strtoupper((string) ($input['status'] ?? 'PUBLISHED'));
        if (! in_array($status, ['DRAFT', 'PUBLISHED'], true)) {
            throw new DomainException('Status soal tidak valid.');
        }

        $points = (int) ($input['points'] ?? 100);
        if ($points < 10 || $points > 1000) {
            throw new DomainException('Poin harus berada di antara 10 dan 1.000.');
        }

        $timeLimit = (int) ($input['time_limit_seconds'] ?? 30);
        if ($timeLimit < 5 || $timeLimit > 300) {
            throw new DomainException('Waktu menjawab harus berada di antara 5 dan 300 detik.');
        }

        $explanation = trim((string) ($input['explanation'] ?? ''));
        if (mb_strlen($explanation) > 5000) {
            throw new DomainException('Pembahasan maksimal 5.000 karakter.');
        }

        return [
            'topic_id' => $topicId,
            'question_type' => $questionType,
            'stem' => $stem,
            'difficulty' => $difficulty,
            'status' => $status,
            'points' => $points,
            'time_limit_seconds' => $timeLimit,
            'explanation' => $explanation !== '' ? $explanation : null,
            'options' => $this->normalizeOptions($questionType, $input),
        ];
    }

    private function normalizeOptions(string $questionType, array $input): array
    {
        $correctLabel = strtoupper(trim((string) ($input['correct_option'] ?? '')));
        if ($questionType === 'TRUE_FALSE') {
            if (! in_array($correctLabel, ['A', 'B'], true)) {
                throw new DomainException('Pilih jawaban benar untuk soal Benar / Salah.');
            }

            return [
                ['label' => 'A', 'body' => 'Benar', 'is_correct' => $correctLabel === 'A'],
                ['label' => 'B', 'body' => 'Salah', 'is_correct' => $correctLabel === 'B'],
            ];
        }

        $postedOptions = is_array($input['options'] ?? null) ? $input['options'] : [];
        $options = [];
        foreach (range('A', 'E') as $label) {
            $body = trim((string) ($postedOptions[$label] ?? ''));
            if ($body === '') {
                continue;
            }
            if (mb_strlen($body) > 3000) {
                throw new DomainException('Isi opsi ' . $label . ' maksimal 3.000 karakter.');
            }

            $options[] = [
                'label' => $label,
                'body' => $body,
                'is_correct' => $correctLabel === $label,
            ];
        }

        if (count($options) < 2) {
            throw new DomainException('Soal pilihan ganda harus memiliki minimal dua opsi.');
        }
        if (! in_array($correctLabel, array_column($options, 'label'), true)) {
            throw new DomainException('Pilih satu kunci jawaban dari opsi yang terisi.');
        }

        return $options;
    }

    private function assertNotUsedByActiveRoom(int $questionId, string $action): void
    {
        $isUsedByTurn = $this->db->table('game_turns gt')
            ->join('game_rooms gr', 'gr.id = gt.room_id')
            ->where('gt.question_id', $questionId)
            ->whereIn('gr.status', ['PLAYING', 'PAUSED'])
            ->countAllResults() > 0;

        $isUsedByRace = $this->db->table('game_round_questions grq')
            ->join('game_rounds gr', 'gr.id = grq.round_id')
            ->join('game_rooms room', 'room.id = gr.room_id')
            ->where('grq.question_id', $questionId)
            ->whereIn('grq.state', ['QUESTION_ACTIVE', 'QUESTION_RESOLVING'])
            ->whereIn('room.status', ['PLAYING', 'PAUSED'])
            ->countAllResults() > 0;

        if ($isUsedByTurn || $isUsedByRace) {
            throw new DomainException('Soal sedang dipakai dalam game aktif sehingga belum dapat ' . $action . '.');
        }
    }

    private function optionHasAnswers(int $optionId): bool
    {
        return $this->db->table('game_answers')->where('option_id', $optionId)->countAllResults() > 0
            || $this->db->table('game_round_answers')->where('option_id', $optionId)->countAllResults() > 0;
    }

    private function assertTransactionSucceeded(): void
    {
        if (! $this->db->transStatus()) {
            throw new RuntimeException('Perubahan bank soal gagal disimpan.');
        }
    }
}
