<?php

use App\Services\Game\GameRoomPresenter;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class GameRoomPresenterTest extends CIUnitTestCase
{
    public function testModeLabelReturnsReadableModeNames(): void
    {
        $this->assertSame('Quiz Race', GameRoomPresenter::modeLabel('QUIZ_RACE'));
        $this->assertSame('Ular Tangga Kuis', GameRoomPresenter::modeLabel('SNAKES_LADDERS'));
    }

    public function testDefaultTitleUsesQuizRacePrefixForQuizRaceRooms(): void
    {
        $this->assertSame('Quiz Race 10/09 23:27', GameRoomPresenter::defaultTitle('QUIZ_RACE', strtotime('2026-09-10 23:27:00')));
        $this->assertSame('Game Ular Tangga 10/09 23:27', GameRoomPresenter::defaultTitle('SNAKES_LADDERS', strtotime('2026-09-10 23:27:00')));
    }

    public function testDisplayTitleNormalizesOldDefaultTitleForQuizRaceOnly(): void
    {
        $this->assertSame('Quiz Race 10/09 23:27', GameRoomPresenter::displayTitle([
            'title' => 'Game Ular Tangga 10/09 23:27',
            'game_mode' => 'QUIZ_RACE',
        ]));
        $this->assertSame('Game Ular Tangga 10/09 23:27', GameRoomPresenter::displayTitle([
            'title' => 'Game Ular Tangga 10/09 23:27',
            'game_mode' => 'SNAKES_LADDERS',
        ]));
        $this->assertSame('Latihan Bab Fikih', GameRoomPresenter::displayTitle([
            'title' => 'Latihan Bab Fikih',
            'game_mode' => 'QUIZ_RACE',
        ]));
    }
}
