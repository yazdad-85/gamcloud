<?php

namespace App\Services\Auth;

use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use DomainException;

class ProfilePasswordService
{
    public function changePassword(User $user, string $currentPassword, string $newPassword, string $confirmPassword): void
    {
        if ($newPassword === '' && $confirmPassword === '' && $currentPassword === '') {
            return;
        }

        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            throw new DomainException('Untuk ganti password, isi password saat ini, password baru, dan konfirmasi.');
        }

        if (strlen($newPassword) < 10 || strlen($newPassword) > 128) {
            throw new DomainException('Password baru harus 10–128 karakter.');
        }

        if ($newPassword !== $confirmPassword) {
            throw new DomainException('Konfirmasi password baru tidak cocok.');
        }

        $email = (string) $user->getEmail();
        if ($email === '' || ! auth()->check(['email' => $email, 'password' => $currentPassword])->isOK()) {
            throw new DomainException('Password saat ini tidak benar.');
        }

        $user->setPassword($newPassword);
        $users = model(UserModel::class);
        $users->save($user);
    }
}
