<?php

use App\Database\Seeds\DemoGameSeeder;
use App\Models\QuestionModel;
use App\Models\QuestionTopicModel;
use App\Services\Game\Uuid;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class QuestionTopicTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $seed = DemoGameSeeder::class;

    public function testCreateTopicPersistsForOwningTeacher(): void
    {
        $topicId = (new QuestionTopicModel())->insert([
            'public_uuid' => Uuid::v4(),
            'owner_teacher_id' => 1,
            'name' => 'Bab 1 - Pecahan',
        ], true);

        $topic = (new QuestionTopicModel())->find($topicId);

        $this->assertSame(1, (int) $topic['owner_teacher_id']);
        $this->assertSame('Bab 1 - Pecahan', $topic['name']);
    }

    public function testDeleteTopicDetachesQuestionsWithoutDeletingThem(): void
    {
        $topicId = (new QuestionTopicModel())->insert([
            'public_uuid' => Uuid::v4(),
            'owner_teacher_id' => 1,
            'name' => 'Topik Dihapus',
        ], true);
        $questionId = (new QuestionModel())->insert([
            'public_uuid' => Uuid::v4(),
            'owner_teacher_id' => 1,
            'topic_id' => $topicId,
            'source_type' => 'PERSONAL',
            'question_type' => 'MULTIPLE_CHOICE',
            'stem' => 'Soal uji hapus topik',
            'difficulty' => 'MEDIUM',
            'status' => 'PUBLISHED',
            'points' => 100,
            'time_limit_seconds' => 30,
        ], true);

        (new QuestionModel())->where('topic_id', $topicId)->set(['topic_id' => null])->update();
        (new QuestionTopicModel())->delete($topicId);

        $this->assertNull((new QuestionTopicModel())->find($topicId));
        $question = (new QuestionModel())->find($questionId);
        $this->assertNotNull($question);
        $this->assertNull($question['topic_id']);
    }
}
