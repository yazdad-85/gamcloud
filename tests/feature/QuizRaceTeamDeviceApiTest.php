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
        $result->assertHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $body = json_decode($result->getJSON(), true);
        $this->assertTrue($body['ok']);
        $this->assertIsArray($body['data']);
        $this->assertSame($fixture['room']['uuid'], $body['data']['room']['uuid']);
        $this->assertIsInt($body['data']['server_epoch_ms']);
        $this->assertGreaterThan(0, $body['data']['server_epoch_ms']);
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

    public function testLastTeamAnswerReturnsResolvedRaceOutcomeAndMovement(): void
    {
        $fixture = $this->startRace(2);
        $correct = $this->correctOption($fixture['question']);
        $wrong = $this->wrongOption($fixture['question']);

        $first = $this->withSession($this->teamSession($fixture['room']['uuid'], $fixture['teams'][0], $fixture['joinTokens'][0]))
            ->withHeaders(['Idempotency-Key' => 'feature-test-race-result-1'])
            ->withBodyFormat('json')
            ->post('/api/v1/rooms/' . $fixture['room']['uuid'] . '/race-question/answer', [
                'team_uuid' => $fixture['teams'][0]['public_uuid'],
                'option_id' => (int) $correct['id'],
            ]);
        $second = $this->withSession($this->teamSession($fixture['room']['uuid'], $fixture['teams'][1], $fixture['joinTokens'][1]))
            ->withHeaders(['Idempotency-Key' => 'feature-test-race-result-2'])
            ->withBodyFormat('json')
            ->post('/api/v1/rooms/' . $fixture['room']['uuid'] . '/race-question/answer', [
                'team_uuid' => $fixture['teams'][1]['public_uuid'],
                'option_id' => (int) $wrong['id'],
            ]);

        $first->assertStatus(200);
        $second->assertStatus(200);
        $body = json_decode($second->getJSON(), true);
        $question = $body['data']['current_round']['current_question'];
        $this->assertSame('QUESTION_RESOLVED', $question['state']);

        $movementByTeam = [];
        foreach ($question['movement'] as $movement) {
            $movementByTeam[$movement['team_uuid']] = $movement;
        }

        $correctMovement = $movementByTeam[$fixture['teams'][0]['public_uuid']];
        $wrongMovement = $movementByTeam[$fixture['teams'][1]['public_uuid']];
        $this->assertSame('CORRECT', $correctMovement['outcome']);
        $this->assertGreaterThan(0, $correctMovement['steps']);
        $this->assertGreaterThan(0, $correctMovement['score_delta']);
        $this->assertSame('WRONG', $wrongMovement['outcome']);
        $this->assertSame(0, $wrongMovement['steps']);
        $this->assertSame(0, $wrongMovement['score_delta']);

        $teamsByUuid = [];
        foreach ($body['data']['teams'] as $team) {
            $teamsByUuid[$team['uuid']] = $team;
        }
        $this->assertGreaterThan(1, $teamsByUuid[$fixture['teams'][0]['public_uuid']]['position']);
        $this->assertSame(1, $teamsByUuid[$fixture['teams'][1]['public_uuid']]['position']);

        $projectorState = $this->withSession([])
            ->get('/api/v1/rooms/' . $fixture['room']['uuid'] . '/state?t=' . $fixture['room']['projector_token']);
        $projectorState->assertStatus(200);
        $projectorState->assertHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $projectorBody = json_decode($projectorState->getJSON(), true);
        $teacherSnapshot = (new GameEngine())->snapshot($fixture['room']['uuid'], null, true);

        $this->assertSame(
            $question['movement'],
            $projectorBody['data']['current_round']['current_question']['movement'],
            'Projector harus menerima hasil Quiz Race yang sama dengan device tim.'
        );
        $this->assertSame(
            $question['movement'],
            $teacherSnapshot['current_round']['current_question']['movement'],
            'Control guru harus menerima hasil Quiz Race yang sama dengan device tim.'
        );
        $this->assertSame($body['data']['teams'], $projectorBody['data']['teams']);
        $this->assertSame($body['data']['teams'], $teacherSnapshot['teams']);
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

    public function testOwnerContinuesCompletedRaceRoundFromCheckpoint(): void
    {
        $fixture = $this->startRace(1);
        (new GameRoundModel())->update($fixture['round']['id'], ['question_target_count' => 1]);
        $option = $this->correctOption($fixture['question']);

        $this->withSession($this->teamSession($fixture['room']['uuid'], $fixture['teams'][0], $fixture['joinTokens'][0]))
            ->withBodyFormat('json')
            ->post('/api/v1/rooms/' . $fixture['room']['uuid'] . '/race-question/answer', [
                'team_uuid' => $fixture['teams'][0]['public_uuid'],
                'option_id' => (int) $option['id'],
            ])
            ->assertStatus(200);
        (new GameRoundQuestionModel())->update($fixture['question']['id'], [
            'reveal_until_epoch_ms' => 0,
            'reveal_until' => '2000-01-01 00:00:00',
        ]);

        $checkpoint = $this->withSession([])
            ->get('/api/v1/rooms/' . $fixture['room']['uuid'] . '/state');
        $checkpoint->assertStatus(200);
        $checkpointBody = json_decode($checkpoint->getJSON(), true);
        $this->assertNull($checkpointBody['data']['current_round']);
        $this->assertSame('ROUND_COMPLETED', $checkpointBody['data']['last_completed_round']['state']);

        $continued = $this->withSession($this->actingAsTeacherOwner(1))
            ->withBodyFormat('json')
            ->post('/api/v1/rooms/' . $fixture['room']['uuid'] . '/race-round/continue', []);

        $continued->assertStatus(200);
        $body = json_decode($continued->getJSON(), true);
        $this->assertSame(2, $body['data']['current_round']['round_number']);
        $this->assertSame('QUESTION_ACTIVE', $body['data']['current_round']['current_question']['state']);
        $this->assertSame('ROUND_CLOSED', $body['data']['last_completed_round']['state']);
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

    private function wrongOption(array $question): array
    {
        return (new QuestionOptionModel())
            ->where('question_id', $question['question_id'])
            ->where('is_correct', 0)
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
