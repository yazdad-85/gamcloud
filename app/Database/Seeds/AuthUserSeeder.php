<?php

namespace App\Database\Seeds;

use App\Models\TeacherModel;
use App\Services\Game\Uuid;
use CodeIgniter\Database\Seeder;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use RuntimeException;

/**
 * Seeds bootstrap accounts. Passwords MUST come from .env — never hardcode
 * credentials in the repository (especially public remotes).
 *
 * Required:
 *   SEED_SUPERADMIN_PASSWORD
 *   SEED_TEACHER_PASSWORD
 */
class AuthUserSeeder extends Seeder
{
    public function run(): void
    {
        $superPassword  = trim((string) env('SEED_SUPERADMIN_PASSWORD', ''));
        $teacherPassword = trim((string) env('SEED_TEACHER_PASSWORD', ''));

        if ($superPassword === '' || $teacherPassword === '') {
            throw new RuntimeException(
                'AuthUserSeeder requires SEED_SUPERADMIN_PASSWORD and SEED_TEACHER_PASSWORD in .env. '
                . 'Do not commit real passwords to git.'
            );
        }

        if (strlen($superPassword) < 10 || strlen($teacherPassword) < 10) {
            throw new RuntimeException('Seed passwords must be at least 10 characters.');
        }

        $superadmin = $this->upsertShieldUser('superadmin', 'superadmin@example.test', $superPassword, 'superadmin');
        $teacher    = $this->upsertShieldUser('guru.demo', 'guru@example.test', $teacherPassword, 'teacher');

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
        } else {
            // Rotate hash when re-seeding with a new env password (e.g. after credential leak).
            $user->setPassword($password);
            $users->save($user);
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
