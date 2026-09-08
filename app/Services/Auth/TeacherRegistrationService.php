<?php

namespace App\Services\Auth;

use App\Models\TeacherModel;
use App\Models\TeacherRegistrationRequestModel;
use App\Services\Game\Uuid;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use DomainException;
use Throwable;

class TeacherRegistrationService
{
    public const STATUS_PENDING_EMAIL = 'PENDING_EMAIL';
    public const STATUS_EMAIL_VERIFIED = 'EMAIL_VERIFIED';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_REJECTED = 'REJECTED';

    private const CODE_TTL_SECONDS = 900;
    private const RESEND_COOLDOWN_SECONDS = 60;
    private const MAX_VERIFY_ATTEMPTS = 5;

    public function __construct(
        private readonly TeacherRegistrationRequestModel $requests = new TeacherRegistrationRequestModel(),
        private readonly TeacherModel $teachers = new TeacherModel(),
    ) {
    }

    public function createRequest(array $payload, string $ipAddress, string $userAgent): array
    {
        $email = strtolower(trim((string) $payload['email']));

        if ($this->teachers->where('email', $email)->first() !== null) {
            throw new DomainException('Email sudah terdaftar atau sedang diproses.');
        }

        $users = model(UserModel::class);
        if ($users->findByCredentials(['email' => $email]) !== null) {
            throw new DomainException('Email sudah terdaftar atau sedang diproses.');
        }

        $existingRequest = $this->requests
            ->where('email', $email)
            ->whereIn('status', [self::STATUS_PENDING_EMAIL, self::STATUS_EMAIL_VERIFIED, self::STATUS_APPROVED])
            ->first();
        if ($existingRequest !== null) {
            throw new DomainException('Email sudah terdaftar atau sedang diproses.');
        }

        $user = new User([
            'username' => $this->uniqueUsername($email),
            'email' => $email,
            'active' => false,
        ]);
        $user->setPassword((string) $payload['password']);
        $users->save($user);
        $user = $users->findById($users->getInsertID());

        if ($user === null) {
            throw new DomainException('Akun belum dapat dibuat. Coba lagi nanti.');
        }

        $code = $this->verificationCode();
        $uuid = Uuid::v4();
        $now = date('Y-m-d H:i:s');

        $requestId = $this->requests->insert([
            'public_uuid' => $uuid,
            'auth_user_id' => $user->id,
            'name' => trim((string) $payload['name']),
            'email' => $email,
            'school_name' => trim((string) ($payload['school_name'] ?? '')) ?: null,
            'status' => self::STATUS_PENDING_EMAIL,
            'verification_code_hash' => password_hash($code, PASSWORD_DEFAULT),
            'verification_expires_at' => date('Y-m-d H:i:s', time() + self::CODE_TTL_SECONDS),
            'verification_attempts' => 0,
            'ip_address' => $ipAddress,
            'user_agent' => substr($userAgent, 0, 255),
            'last_sent_at' => $now,
        ], true);

        $request = $this->requests->find((int) $requestId);
        $sent = $this->sendVerificationCode($request, $code);
        $this->logVerificationCodeForDevelopment($request, $code, $sent);

        return [
            'request' => $request,
            'email_sent' => $sent,
        ];
    }

    public function findByUuid(string $uuid): ?array
    {
        return $this->requests->where('public_uuid', $uuid)->first();
    }

    public function verifyEmail(string $uuid, string $code): array
    {
        $request = $this->requireRequest($uuid);

        if ($request['status'] !== self::STATUS_PENDING_EMAIL) {
            return $request;
        }

        if ((int) $request['verification_attempts'] >= self::MAX_VERIFY_ATTEMPTS) {
            throw new DomainException('Percobaan verifikasi terlalu banyak. Minta kode baru.');
        }

        if (strtotime((string) $request['verification_expires_at']) < time()) {
            throw new DomainException('Kode verifikasi sudah kedaluwarsa. Minta kode baru.');
        }

        if (! password_verify($code, (string) $request['verification_code_hash'])) {
            $this->requests->update($request['id'], [
                'verification_attempts' => ((int) $request['verification_attempts']) + 1,
            ]);

            throw new DomainException('Kode verifikasi tidak sesuai.');
        }

        $this->requests->update($request['id'], [
            'status' => self::STATUS_EMAIL_VERIFIED,
            'verification_code_hash' => null,
            'verified_at' => date('Y-m-d H:i:s'),
        ]);

        $request = $this->requests->find((int) $request['id']);

        return $this->activateTeacherAccount($request);
    }

    public function activateVerifiedRequest(string $uuid): array
    {
        $request = $this->requireRequest($uuid);

        if (! in_array($request['status'], [self::STATUS_EMAIL_VERIFIED, self::STATUS_APPROVED], true)) {
            throw new DomainException('Email guru belum terverifikasi.');
        }

        return $this->activateTeacherAccount($request);
    }

    public function resendCode(string $uuid): array
    {
        $request = $this->requireRequest($uuid);

        if ($request['status'] !== self::STATUS_PENDING_EMAIL) {
            throw new DomainException('Pengajuan ini tidak membutuhkan kode baru.');
        }

        if ($request['last_sent_at'] !== null && strtotime((string) $request['last_sent_at']) > time() - self::RESEND_COOLDOWN_SECONDS) {
            throw new DomainException('Tunggu sebentar sebelum meminta kode baru.');
        }

        $code = $this->verificationCode();
        $this->requests->update($request['id'], [
            'verification_code_hash' => password_hash($code, PASSWORD_DEFAULT),
            'verification_expires_at' => date('Y-m-d H:i:s', time() + self::CODE_TTL_SECONDS),
            'verification_attempts' => 0,
            'last_sent_at' => date('Y-m-d H:i:s'),
        ]);

        $request = $this->requests->find((int) $request['id']);
        $sent = $this->sendVerificationCode($request, $code);
        $this->logVerificationCodeForDevelopment($request, $code, $sent);

        return [
            'request' => $request,
            'email_sent' => $sent,
        ];
    }

    public function approve(string $uuid, int $reviewerUserId): array
    {
        $request = $this->requireRequest($uuid);
        if ($request['status'] !== self::STATUS_EMAIL_VERIFIED) {
            throw new DomainException('Hanya pengajuan dengan email terverifikasi yang dapat disetujui.');
        }

        return $this->activateTeacherAccount($request, $reviewerUserId);
    }

    private function activateTeacherAccount(array $request, ?int $reviewerUserId = null): array
    {
        if (! in_array($request['status'], [self::STATUS_EMAIL_VERIFIED, self::STATUS_APPROVED], true)) {
            throw new DomainException('Email guru belum terverifikasi.');
        }

        $users = model(UserModel::class);
        $user = $users->findById((int) $request['auth_user_id']);
        if ($user === null) {
            throw new DomainException('Akun login pengajuan tidak ditemukan.');
        }

        if ((int) $user->active !== 1) {
            $user->active = 1;
            $users->save($user);
        }

        if (! $user->inGroup('teacher')) {
            $user->addGroup('teacher');
        }

        $teacher = $this->teachers->where('email', $request['email'])->first();
        if ($teacher === null) {
            $this->teachers->insert([
                'public_uuid' => Uuid::v4(),
                'auth_user_id' => $user->id,
                'name' => $request['name'],
                'email' => $request['email'],
                'role' => 'teacher',
            ]);
        } else {
            $this->teachers->update($teacher['id'], [
                'auth_user_id' => $user->id,
                'name' => $request['name'],
                'role' => 'teacher',
            ]);
        }

        $update = [
            'status' => self::STATUS_APPROVED,
            'approved_at' => $request['approved_at'] ?? date('Y-m-d H:i:s'),
        ];
        if ($reviewerUserId !== null) {
            $update['reviewed_by_user_id'] = $reviewerUserId;
        }

        $this->requests->update($request['id'], $update);

        return $this->requests->find((int) $request['id']);
    }

    public function reject(string $uuid, int $reviewerUserId): array
    {
        $request = $this->requireRequest($uuid);
        if (! in_array($request['status'], [self::STATUS_PENDING_EMAIL, self::STATUS_EMAIL_VERIFIED], true)) {
            throw new DomainException('Pengajuan ini tidak dapat ditolak.');
        }

        $this->requests->update($request['id'], [
            'status' => self::STATUS_REJECTED,
            'rejected_at' => date('Y-m-d H:i:s'),
            'reviewed_by_user_id' => $reviewerUserId,
        ]);

        return $this->requests->find((int) $request['id']);
    }

    private function requireRequest(string $uuid): array
    {
        $request = $this->findByUuid($uuid);
        if ($request === null) {
            throw new DomainException('Pengajuan tidak ditemukan.');
        }

        return $request;
    }

    private function uniqueUsername(string $email): string
    {
        $base = strtolower((string) strtok($email, '@'));
        $base = trim(preg_replace('/[^a-z0-9._-]+/', '.', $base) ?: 'guru', '.-_');
        $base = $base !== '' ? $base : 'guru';
        $users = model(UserModel::class);

        for ($i = 0; $i < 8; $i++) {
            $suffix = $i === 0 ? '' : '.' . random_int(1000, 9999);
            $username = substr($base, 0, 40) . $suffix;

            if ($users->where('username', $username)->first() === null) {
                return $username;
            }
        }

        return 'guru.' . bin2hex(random_bytes(4));
    }

    private function verificationCode(): string
    {
        return (string) random_int(100000, 999999);
    }

    private function sendVerificationCode(array $request, string $code): bool
    {
        try {
            $emailConfig = config('Email');
            $email = service('email');
            $email->clear();
            $email->setFrom(
                $emailConfig->fromEmail !== '' ? $emailConfig->fromEmail : 'no-reply@ruangmainguru.local',
                $emailConfig->fromName !== '' ? $emailConfig->fromName : 'Ruang Main Guru'
            );
            $email->setTo($request['email']);
            $email->setSubject('Kode Verifikasi Akun Guru');
            $email->setMessage(
                "Halo {$request['name']},\n\n"
                . "Kode verifikasi akun guru Anda adalah: {$code}\n\n"
                . "Kode ini berlaku 15 menit. Abaikan email ini jika Anda tidak merasa mendaftar.\n\n"
                . "Ruang Main Guru"
            );

            if ($email->send(false)) {
                return true;
            }

            log_message(
                'error',
                'Gagal mengirim kode verifikasi ke ' . $request['email'] . ': ' . strip_tags($email->printDebugger(['headers', 'subject']))
            );

            return false;
        } catch (Throwable $error) {
            log_message('error', 'Gagal mengirim kode verifikasi: ' . $error->getMessage());

            return false;
        }
    }

    private function logVerificationCodeForDevelopment(array $request, string $code, bool $sent): void
    {
        if (ENVIRONMENT === 'production') {
            return;
        }

        $status = $sent ? 'sent' : 'not_sent';
        log_message('info', 'DEV verification code ' . $status . ' for ' . $request['email'] . ': ' . $code);
    }
}
