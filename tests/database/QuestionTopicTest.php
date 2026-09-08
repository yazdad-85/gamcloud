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
}
