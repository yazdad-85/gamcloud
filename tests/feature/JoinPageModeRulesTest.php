<?php

use App\Services\Game\GameEngine;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class JoinPageModeRulesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = ['App', 'CodeIgniter\Shield', 'CodeIgniter\Settings'];
    protected $seed = App\Database\Seeds\DemoGameSeeder::class;

    public function testJoinPageShowsQuizRaceTeamDeviceRulesForRaceRoom(): void
    {
        $room = (new GameEngine())->createRoom(1, 'Join Quiz Race Rules', [
            'game_mode' => 'QUIZ_RACE',
            'participation_mode' => 'TEAM_DEVICE',
        ])['room'];

        $result = $this->get('/join/' . $room['pin']);

        $result->assertStatus(200);
        $body = $result->getBody();
        $this->assertStringContainsString('Aturan Quiz Race', $body);
        $this->assertStringContainsString('Balapan kuis serentak', $body);
        $this->assertStringContainsString('Tim tercepat di antara jawaban benar', $body);
        $this->assertStringNotContainsString('Mendarat di ular', $body);
    }

    public function testJoinPageShowsNoDeviceRulesForCentralizedRaceRoom(): void
    {
        $room = (new GameEngine())->createRoom(1, 'Join Quiz Race Centralized Rules', [
            'game_mode' => 'QUIZ_RACE',
            'participation_mode' => 'TEACHER_CENTRALIZED',
        ])['room'];

        $result = $this->get('/join/' . $room['pin']);

        $result->assertStatus(200);
        $body = $result->getBody();
        $this->assertStringContainsString('Aturan Quiz Race Tanpa Device', $body);
        $this->assertStringContainsString('tim tidak perlu masuk lewat halaman join', $body);
        $this->assertStringContainsString('Ikuti dari layar guru', $body);
    }

    public function testJoinPageKeepsSnakesLaddersRulesForClassicRoom(): void
    {
        $room = (new GameEngine())->createRoom(1, 'Join Ular Tangga Rules', [
            'game_mode' => 'SNAKES_LADDERS',
        ])['room'];

        $result = $this->get('/join/' . $room['pin']);

        $result->assertStatus(200);
        $body = $result->getBody();
        $this->assertStringContainsString('Aturan Ular Tangga Kuis', $body);
        $this->assertStringContainsString('Lempar dadu lalu jawab soal', $body);
        $this->assertStringContainsString('Mendarat di ular', $body);
    }
}
