<?php

namespace App\Services\Security;

use App\Models\GameRoomModel;
use App\Models\QuestionTopicModel;
use App\Models\TeacherModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use DomainException;

class TenantContext
{
    public function isSuperadmin(): bool
    {
        return auth()->loggedIn() && auth()->user()->inGroup('superadmin');
    }

    public function teacherId(): int
    {
        $authUserId = auth()->id();
        if ($authUserId === null) {
            throw new DomainException('Guru harus login.');
        }

        $teacher = (new TeacherModel())->where('auth_user_id', $authUserId)->first();
        if ($teacher === null) {
            throw new DomainException('Akun login belum terhubung ke data guru.');
        }

        return (int) $teacher['id'];
    }

    public function assertRoomOwner(string $roomUuid): array
    {
        $room = (new GameRoomModel())->where('public_uuid', $roomUuid)->first();
        if ($room === null) {
            throw PageNotFoundException::forPageNotFound('Room tidak ditemukan.');
        }

        if ($this->isSuperadmin()) {
            return $room;
        }

        if ((int) $room['teacher_id'] !== $this->teacherId()) {
            throw PageNotFoundException::forPageNotFound('Room tidak ditemukan.');
        }

        return $room;
    }

    public function assertQuestionTopicOwner(string $topicUuid): array
    {
        $topic = (new QuestionTopicModel())->where('public_uuid', $topicUuid)->first();
        if ($topic === null) {
            throw PageNotFoundException::forPageNotFound('Topik tidak ditemukan.');
        }

        if ($this->isSuperadmin()) {
            return $topic;
        }

        if ((int) $topic['owner_teacher_id'] !== $this->teacherId()) {
            throw PageNotFoundException::forPageNotFound('Topik tidak ditemukan.');
        }

        return $topic;
    }
}
