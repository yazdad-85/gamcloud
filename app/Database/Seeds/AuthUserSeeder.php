<?php

namespace App\Database\Seeds;

use App\Models\TeacherModel;
use App\Services\Game\Uuid;
use CodeIgniter\Database\Seeder;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;

class AuthUserSeeder extends Seeder
{
    public function run(): void
    {
        $superadmin = $this->upsertShieldUser('superadmin', 'superadmin@example.test', 'Admin!8394MVP', 'superadmin');
        $teacher = $this->upsertShieldUser('guru.demo', 'guru@example.test', 'Kuat!7284MVP', 'teacher');

        $teacherModel = new TeacherModel();
        $row = $teacherModel->where('email', 'guru@example.test')->first();
        if ($row === null) {
            $teacherModel->insert([
                'public_uuid' => Uuid::v4(),
                'auth_user_id' => $teacher->id,
                'name' => 'Guru Demo',
                'email' => 'guru@example.test',
                'role' => 'teacher',
            ]);
        } else {
            $teacherModel->update($row['id'], [
                'auth_user_id' => $teacher->id,
                'role' => 'teacher',
            ]);
        }

        unset($superadmin);
    }

    private function upsertShieldUser(string $username, string $email, string $password, string $group): User
    {
        $users = model(UserModel::class);
        $user = $users->findByCredentials(['email' => $email]);

        if ($user === null) {
            $user = new User([
                'username' => $username,
                'email' => $email,
                'active' => true,
            ]);
            $user->setPassword($password);
            $users->save($user);
            $user = $users->findById($users->getInsertID());
        }

        if (! $user->isActivated()) {
            $users->activate($user);
        }

        if (! $user->inGroup($group)) {
            $user->addGroup($group);
        }

        return $user;
    }
}
