<?php

namespace App\Services\Game\Modes;

class QuizRaceModeEngine extends PlannedModeEngine
{
    public function __construct()
    {
        parent::__construct('QUIZ_RACE', 'Quiz Race', 'quiz_race_track', 'Balapan cepat berbasis soal tanpa ular dan tangga.');
    }
}
