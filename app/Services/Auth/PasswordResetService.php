<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Closure;
use CodeIgniter\I18n\Time;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserIdentityModel;
use CodeIgniter\Shield\Models\UserModel;
use DomainException;
use Throwable;

class PasswordResetService
{
    private const IDENTITY_TYPE = 'teacher_password_reset';
    private const TOKEN_TTL_SECONDS = 3600;

    public function __construct(
        private readonly ?Closure $emailSender = null,
    ) {
    }

    public function sendResetLink(string $email): bool
    {
        $users = model(UserModel::class);
        $user = $users->findByCredentials(['email' => strtolower(trim($email))]);

        if (! $user instanceof User || ! $user->active) {
            return true;
        }

        $identities = model(UserIdentityModel::class);
        $identities->deleteIdentitiesByType($user, self::IDENTITY_TYPE);

        $rawToken = bin2hex(random_bytes(32));
        $identities->insert([
            'user_id' => $user->id,
            'type' => self::IDENTITY_TYPE,
            'secret' => hash('sha256', $rawToken),
            'expires' => Time::now()->addSeconds(self::TOKEN_TTL_SECONDS),
        ]);

        $url = site_url('lupa-password/reset') . '?token=' . rawurlencode($rawToken);
        $sent = $this->emailSender !== null
            ? ($this->emailSender)($user, $url)
            : $this->sendEmail($user, $url);

        if (! $sent) {
            $identities->deleteIdentitiesByType($user, self::IDENTITY_TYPE);
        }

        return $sent;
    }

    public function hasValidToken(string $rawToken): bool
    {
        return $this->validIdentity($rawToken) !== null;
    }

    public function resetPassword(string $rawToken, string $password, string $confirmation): void
    {
        if (strlen($password) < 10 || strlen($password) > 128) {
            throw new DomainException('Password baru harus 10-128 karakter.');
        }

        if ($password !== $confirmation) {
            throw new DomainException('Konfirmasi password baru tidak cocok.');
        }

        $identity = $this->validIdentity($rawToken);
        if ($identity === null) {
            throw new DomainException('Tautan pemulihan tidak valid atau sudah kedaluwarsa.');
        }

        $users = model(UserModel::class);
        $user = $users->findById((int) $identity->user_id);
        if (! $user instanceof User || ! $user->active) {
            model(UserIdentityModel::class)->delete((int) $identity->id);
            throw new DomainException('Akun belum aktif. Selesaikan verifikasi email terlebih dahulu.');
        }

        $user->setPassword($password);
        $users->save($user);
        model(UserIdentityModel::class)->delete((int) $identity->id);
    }

    private function validIdentity(string $rawToken): ?object
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $rawToken)) {
            return null;
        }

        $identity = model(UserIdentityModel::class)
            ->where('type', self::IDENTITY_TYPE)
            ->where('secret', hash('sha256', $rawToken))
            ->first();

        if ($identity === null || $identity->expires === null || Time::now()->isAfter($identity->expires)) {
            if ($identity !== null) {
                model(UserIdentityModel::class)->delete((int) $identity->id);
            }

            return null;
        }

        return $identity;
    }

    private function sendEmail(User $user, string $url): bool
    {
        try {
            $emailConfig = config('Email');
            $email = service('email');
            $email->clear();
            $email->setFrom(
                $emailConfig->fromEmail !== '' ? $emailConfig->fromEmail : 'no-reply@ruangmainguru.local',
                $emailConfig->fromName !== '' ? $emailConfig->fromName : 'Ruang Main Guru'
            );
            $email->setTo((string) $user->getEmail());
            $email->setSubject('Pemulihan Password Akun Guru');
            $email->setMessage(
                "Kami menerima permintaan pemulihan password akun guru Anda.\n\n"
                . "Buka tautan berikut untuk membuat password baru:\n{$url}\n\n"
                . "Tautan berlaku 1 jam dan hanya dapat digunakan sekali. "
                . "Abaikan email ini jika Anda tidak meminta pemulihan password.\n\n"
                . "Ruang Main Guru"
            );

            if ($email->send(false)) {
                return true;
            }

            log_message('error', 'Gagal mengirim tautan pemulihan password: ' . strip_tags($email->printDebugger(['headers', 'subject'])));

            return false;
        } catch (Throwable $error) {
            log_message('error', 'Gagal mengirim tautan pemulihan password: ' . $error->getMessage());

            return false;
        }
    }
}
