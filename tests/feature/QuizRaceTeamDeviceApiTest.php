<?php

use App\Models\GameRoomModel;
use App\Models\GameRoundModel;
use App\Models\GameRoundQuestionModel;
use App\Models\QuestionOptionModel;
use App\Models\TeacherModel;
use App\Services\Game\GameEngine;
use App\Services\Game\Uuid;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class QuizRaceTeamDeviceApiTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AuthenticationTesting;

    protected $namespace = ['App', 'CodeIgniter\Shield', 'CodeIgniter\Settings'];
    protected $seed = App\Database\Seeds\DemoGameSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        service('cache')->clean();
    }

    public function testValidTeamAnswerReturnsStandardEnvelope(): void
    {
        $fixture = $this->startRace(2);
        $option = $this->correctOption($fixture['question']);

        $result = $this->withSession($this->teamSession($fixture['room']['uuid'], $fixture['teams'][0], $fixture['joinTokens'][0]))
            ->withBodyFormat('json')
            ->post('/api/v1/rooms/' . $fixture['room']['uuid'] . '/race-question/answer', [
                'team_uuid' => $fixture['teams'][0]['public_uuid'],
                'option_id' => (int) $option['id'],
            ]);

        $result->assertStatus(200);
        $body = json_decode($result->getJSON(), true);
        $this->assertTrue($body['ok']);
        $this->assertIsArray($body['data']);
        $this->assertSame($fixture['room']['uuid'], $body['data']['room']['uuid']);
        $this->assertArrayHasKey('request_id', $body['meta']);
    }

    public function testCrossTeamAnswerIsRejected(): void
    {
        $fixture = $this->startRace(2);
        $option = $this->correctOption($fixture['question']);

        $result = $this->withSession($this->teamSession($fixture['room']['uuid'], $fixture['teams'][0], $fixture['joinTokens'][0]))
            ->withBodyFormat('json')
            ->post('/api/v1/rooms/' . $fixture['room']['uuid'] . '/race-question/answer', [
                'team_uuid' => $fixture['teams'][1]['public_uuid'],
                'option_id' => (int) $option['id'],
            ]);

        $result->assertStatus(422);
        $body = json_decode($result->getJSON(), true);
        $this->assertFalse($body['ok']);
        $this->assertSame('DOMAIN_RULE_FAILED', $body['error']['code']);
        $this->assertStringContainsString('Session tim tidak valid', $body['error']['message']);
    }

    public function testAnonymousAnswerIsRejectedWithStandardErrorEnvelope(): void
    {
        $fixture = $this->startRace(1);
        $option = $this->correctOption($fixture['question']);

        $result = $this->withSession([])
            ->withBodyFormat('json')
            ->post('/api/v1/rooms/' . $fixture['room']['uuid'] . '/race-question/answer', [
                'team_uuid' => $fixture['teams'][0]['public_uuid'],
                'option_id' => (int) $option['id'],
            ]);

        $result->assertStatus(422);
        $body = json_decode($result->getJSON(), true);
        $this->assertFalse($body['ok']);
        $this->assertSame('DOMAIN_RULE_FAILED', $body['error']['code']);
    }

    public function testDuplicateSubmitWithIdempotencyKeyReturnsSameResponse(): void
    {
        $fixture = $this->startRace(2);
        $option = $this->correctOption($fixture['question']);
        $session = $this->teamSession($fixture['room']['uuid'], $fixture['teams'][0], $fixture['joinTokens'][0]);
        $body = [
            'team_uuid' => $fixture['teams'][0]['public_uuid'],
            'option_id' => (int) $option['id'],
        ];

        $first = $this->withSession($session)
            ->withHeaders(['Idempotency-Key' => 'feature-test-key-1'])
            ->withBodyFormat('json')
            ->post('/api/v1/rooms/' . $fixture['room']['uuid'] . '/race-question/answer', $body);
        $second = $this->withSession($session)
            ->withHeaders(['Idempotency-Key' => 'feature-test-key-1'])
            ->withBodyFormat('json')
            ->post('/api/v1/rooms/' . $fixture['room']['uuid'] . '/race-question/answer', $body);

        $first->assertStatus(200);
        $second->assertStatus(200);
        $firstBody = json_decode($first->getJSON(), true);
        $secondBody = json_decode($second->getJSON(), true);
        $this->assertSame($firstBody['data'], $secondBody['data']);
        $this->assertSame(1, (new GameRoundQuestionModel())->find($fixture['question']['id'])['answer_count']);
    }

    public function testResolveRejectsAnonymousRequest(): void
    {
        $fixture = $this->startRace(2);

        $result = $this->withSession([])
            ->withBodyFormat('json')
            ->post('/api/v1/rooms/' . $fixture['room']['uuid'] . '/race-question/resolve', ['force' => true]);

        $result->assertStatus(422);
        $body = json_decode($result->getJSON(), true);
        $this->assertFalse($body['ok']);
        $this->assertSame('DOMAIN_RULE_FAILED', $body['error']['code']);
    }

    public function testResolveRequiresForceTrueEvenForOwner(): void
    {
        $fixture = $this->startRace(2);
        $ownerSession = $this->actingAsTeacherOwner(1);

        $withoutForce = $this->withSession($ownerSession)
            ->withBodyFormat('json')
            ->post('/api/v1/rooms/' . $fixture['room']['uuid'] . '/race-question/resolve', ['force' => false]);

        $withoutForce->assertStatus(422);
        $withoutForceBody = json_decode($withoutForce->getJSON(), true);
        $this->assertFalse($withoutForceBody['ok']);
        $this->assertStringContainsString('force', $withoutForceBody['error']['message']);

        $option = $this->correctOption($fixture['question']);
        $this->withSession($this->teamSession($fixture['room']['uuid'], $fixture['teams'][0], $fixture['joinTokens'][0]))
            ->withBodyFormat('json')
            ->post('/api/v1/rooms/' . $fixture['room']['uuid'] . '/race-question/answer', [
                'team_uuid' => $fixture['teams'][0]['public_uuid'],
                'option_id' => (int) $option['id'],
            ]);

        $withForce = $this->withSession($ownerSession)
            ->withBodyFormat('json')
            ->post('/api/v1/rooms/' . $fixture['room']['uuid'] . '/race-question/resolve', ['force' => true]);

        $withForce->assertStatus(200);
        $withForceBody = json_decode($withForce->getJSON(), true);
        $this->assertTrue($withForceBody['ok']);
        $question = (new GameRoundQuestionModel())->find($fixture['question']['id']);
        $this->assertSame('QUESTION_RESOLVED', $question['state']);
    }

    public function testResolveRejectsNonOwnerTeacher(): void
    {
        $fixture = $this->startRace(2);
        $otherTeacherId = (new TeacherModel())->insert([
            'public_uuid' => Uuid::v4(),
            'name' => 'Guru Lain',
            'email' => 'guru-lain-' . bin2hex(random_bytes(4)) . '@example.test',
            'role' => 'teacher',
        ], true);
        $otherOwnerSession = $this->actingAsTeacherOwner((int) $otherTeacherId);

        $result = $this->withSession($otherOwnerSession)
            ->withBodyFormat('json')
            ->post('/api/v1/rooms/' . $fixture['room']['uuid'] . '/race-question/resolve', ['force' => true]);

        $result->assertStatus(404);
    }

    public function testRaceQuestionRoutesAreRateLimited(): void
    {
        service('cache')->clean();
        $fixture = $this->startRace(1);
        $anonymousSession = [];

        $lastResult = null;
        for ($attempt = 1; $attempt <= 31; $attempt++) {
            $lastResult = $this->withSession($anonymousSession)
                ->withBodyFormat('json')
                ->post('/api/v1/rooms/' . $fixture['room']['uuid'] . '/race-question/resolve', ['force' => true]);
        }

        $lastResult->assertStatus(429);
        $body = json_decode($lastResult->getJSON(), true);
        $this->assertFalse($body['ok']);
        $this->assertSame('RATE_LIMITED', $body['error']['code']);
    }

    /** @return array<string, mixed> */
    private function startRace(int $teamCount): array
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Quiz Race API Test ' . bin2hex(random_bytes(4)), [
            'game_mode' => 'QUIZ_RACE',
            'participation_mode' => 'TEAM_DEVICE',
        ])['room'];
        $teams = [];
        $joinTokens = [];
        for ($index = 1; $index <= $teamCount; $index++) {
            $joined = $engine->joinByPin($room['pin'], 'Tim API ' . $index);
            $teams[] = $joined['team'];
            $joinTokens[] = $joined['token'];
        }
        $engine->start($room['uuid']);
        $storedRoom = (new GameRoomModel())->where('public_uuid', $room['uuid'])->first();
        $round = (new GameRoundModel())->where('room_id', $storedRoom['id'])->where('state', 'ROUND_ACTIVE')->first();
        $question = (new GameRoundQuestionModel())->where('round_id', $round['id'])->where('state', 'QUESTION_ACTIVE')->first();

        return compact('room', 'teams', 'joinTokens', 'round', 'question');
    }

    private function correctOption(array $question): array
    {
        return (new QuestionOptionModel())
            ->where('question_id', $question['question_id'])
            ->where('is_correct', 1)
            ->first();
    }

    /** @return array<string, mixed> */
    private function teamSession(string $roomUuid, array $team, string $token): array
    {
        return [
            'team_' . $roomUuid => [
                'team_uuid' => $team['public_uuid'],
                'token' => $token,
                'issued_at' => time(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function actingAsTeacherOwner(int $teacherId): array
    {
        $users = model(UserModel::class);
        $suffix = bin2hex(random_bytes(4));
        $user = new User([
            'username' => 'test-teacher-' . $teacherId . '-' . $suffix,
            'email' => 'test-teacher-' . $teacherId . '-' . $suffix . '@example.test',
            'active' => true,
        ]);
        $user->setPassword('TestPassword123!');
        $users->save($user);
        $user = $users->findById($users->getInsertID());
        $user->addGroup('teacher');

        (new TeacherModel())->update($teacherId, ['auth_user_id' => $user->id]);

        $_SESSION = [];
        $this->actingAs($user);

        $session = $_SESSION;
        $_SESSION = [];

        return $session;
    }
}
