<?php

use App\Database\Seeds\DemoGameSeeder;
use App\Models\QuestionModel;
use App\Models\QuestionOptionModel;
use App\Models\QuestionTopicModel;
use App\Services\Game\Uuid;
use App\Services\Game\GameEngine;
use App\Services\Question\QuestionBankService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class QuestionBankServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $seed = DemoGameSeeder::class;

    public function testCreateMultipleChoicePersistsFiveOptionsAndOneKey(): void
    {
        $topicId = $this->topic(1, 'CRUD Pilihan Ganda');

        $question = (new QuestionBankService())->create(1, $this->payload($topicId));
        $options = (new QuestionOptionModel())
            ->where('question_id', $question['id'])
            ->orderBy('sort_order', 'ASC')
            ->findAll();

        $this->assertSame('Pertanyaan CRUD dengan lima opsi?', $question['stem']);
        $this->assertSame($topicId, (int) $question['topic_id']);
        $this->assertCount(5, $options);
        $this->assertSame('E', $options[4]['label']);
        $this->assertSame('B', array_values(array_filter(
            $options,
            static fn (array $option): bool => (int) $option['is_correct'] === 1,
        ))[0]['label']);
    }

    public function testUpdateQuestionKeepsExistingOptionIds(): void
    {
        $topicId = $this->topic(1, 'CRUD Edit');
        $service = new QuestionBankService();
        $question = $service->create(1, $this->payload($topicId));
        $before = array_column(
            (new QuestionOptionModel())->where('question_id', $question['id'])->findAll(),
            'id',
            'label',
        );

        $payload = $this->payload($topicId);
        $payload['stem'] = 'Pertanyaan yang sudah diperbarui?';
        $payload['options']['B'] = 'Jawaban B diperbarui';
        $payload['correct_option'] = 'C';
        $updated = $service->update($question, $payload);
        $after = (new QuestionOptionModel())->where('question_id', $question['id'])->findAll();

        $this->assertSame('Pertanyaan yang sudah diperbarui?', $updated['stem']);
        foreach ($after as $option) {
            $this->assertSame($before[$option['label']], $option['id']);
        }
        $this->assertSame('C', array_values(array_filter(
            $after,
            static fn (array $option): bool => (int) $option['is_correct'] === 1,
        ))[0]['label']);
    }

    public function testCreateRejectsTopicOwnedByAnotherTeacher(): void
    {
        $topicId = $this->topic(999, 'Topik Guru Lain');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Pilih topik milik guru');

        (new QuestionBankService())->create(1, $this->payload($topicId));
    }

    public function testDeleteSoftDeletesQuestionAndKeepsOptionsForReports(): void
    {
        $topicId = $this->topic(1, 'CRUD Hapus');
        $service = new QuestionBankService();
        $question = $service->create(1, $this->payload($topicId));

        $service->delete($question);

        $this->assertNull((new QuestionModel())->find($question['id']));
        $this->assertNotNull((new QuestionModel())->withDeleted()->find($question['id']));
        $this->assertSame(5, (new QuestionOptionModel())->where('question_id', $question['id'])->countAllResults());
    }

    public function testQuestionUsedByActiveRoomCannotBeDeleted(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'CRUD Active Question', [
            'turn_order_mode' => 'join_order',
            'skip_quota' => true,
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim CRUD')['team'];
        $engine->start($room['uuid']);
        $snapshot = $engine->roll($room['uuid'], $team['public_uuid']);
        $question = (new QuestionModel())
            ->where('public_uuid', $snapshot['current_turn']['question']['uuid'])
            ->first();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('sedang dipakai dalam game aktif');

        (new QuestionBankService())->delete($question);
    }

    private function topic(int $teacherId, string $name): int
    {
        return (int) (new QuestionTopicModel())->insert([
            'public_uuid' => Uuid::v4(),
            'owner_teacher_id' => $teacherId,
            'name' => $name,
        ], true);
    }

    private function payload(int $topicId): array
    {
        return [
            'topic_id' => $topicId,
            'question_type' => 'MULTIPLE_CHOICE',
            'stem' => 'Pertanyaan CRUD dengan lima opsi?',
            'difficulty' => 'MEDIUM',
            'status' => 'PUBLISHED',
            'points' => 100,
            'time_limit_seconds' => 30,
            'explanation' => 'Pembahasan pengujian.',
            'correct_option' => 'B',
            'options' => [
                'A' => 'Jawaban A',
                'B' => 'Jawaban B',
                'C' => 'Jawaban C',
                'D' => 'Jawaban D',
                'E' => 'Jawaban E',
            ],
        ];
    }
}
