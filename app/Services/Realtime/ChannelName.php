<?php

namespace App\Services\Realtime;

final class ChannelName
{
    public static function room(string $roomUuid): string
    {
        return 'presence-room-' . $roomUuid;
    }

    public static function team(string $teamUuid): string
    {
        return 'private-team-' . $teamUuid;
    }

    public static function teacher(string $teacherUuid): string
    {
        return 'private-teacher-' . $teacherUuid;
    }
}
