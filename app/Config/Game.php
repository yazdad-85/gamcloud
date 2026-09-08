<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Game extends BaseConfig
{
    public int $pinTtlMinutes = 180;
    public int $teamSessionTtlMinutes = 240;
    public int $defaultQuestionTime = 30;
    public int $redemptionTime = 10;
    public int $defaultTileCount = 100;
    public int $correctAnswerPoints = 100;
    public int $wrongAnswerPoints = 0;
    public int $maxTimeBonusPoints = 50;
    public int $streakBonusStepPoints = 25;
    public int $maxStreakBonusPoints = 75;
    public int $nearFinishThreshold = 85;
    public int $nearFinishBonusPoints = 25;
    public int $wrongPenaltyPoints = -25;
    public int $timeoutPenaltyPoints = -25;
    public int $freeActiveRoomLimit = 3;
    public int $freeDailyRoomLimit = 10;
    public int $freeMonthlyRoomLimit = 80;
}
