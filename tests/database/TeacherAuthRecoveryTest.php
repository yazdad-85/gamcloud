<?php

declare(strict_types=1);

use App\Models\TeacherRegistrationRequestModel;
use App\Services\Auth\PasswordResetService;
use App\Services\Auth\ProfilePasswordService;
use App\Services\Auth\TeacherRegistrationService;
use App\Services\Game\Uuid;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class TeacherAuthRecoveryTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AuthenticationTesting;

    protected $namespace = ['App', 'CodeIgniter\Shield', 'CodeIgniter\Settings'];

    protected function tearDown(): void
    {
        auth('session')->logout();
        parent::tearDown();
    }

    public function testPendingRegistrationCanBeRecoveredByEmailAndUserId(): void
    {
        [$user, $registration] = $this->createPendingRegistration('belum-verifikasi@example.test');
        $service = new TeacherRegistrationService();

        $this->assertSame($registration['public_uuid'], $service->findPendingByEmail(' BELUM-VERIFIKASI@example.test ')['public_uuid']);
        $this->assertSame($registration['public_uuid'], $service->findPendingByAuthUserId((int) $user->id)['public_uuid']);
    }

    public function testCorrectLoginForPendingTeacherReturnsToVerificationAndClearsSession(): void
    {
        [, $registration] = $this->createPendingRegistration('login-pending@example.test', 'password-guru-aman');

        $response = $this->postWithCsrf('/login', [
            'email' => 'login-pending@example.test',
            'password' => 'password-guru-aman',
        ]);

        $response->assertRedirectTo('/daftar-guru/verifikasi/' . $registration['public_uuid']);
        $this->assertFalse(auth('session')->loggedIn());
    }

    public function testVerificationLookupRedirectsPendingTeacherToExistingRequest(): void
    {
        [, $registration] = $this->createPendingRegistration('lanjut-verifikasi@example.test');

        $response = $this->postWithCsrf('/daftar-guru/verifikasi', [
            'email' => 'lanjut-verifikasi@example.test',
        ]);

        $response->assertRedirectTo('/daftar-guru/verifikasi/' . $registration['public_uuid']);
    }

    public function testExistingInactiveSessionIsReleasedFromTeacherAccess(): void
    {
        [$user, $registration] = $this->createPendingRegistration('sesi-pending@example.test');
        $this->actingAs($user);

        $response = $this->get('/teacher');

        $response->assertRedirectTo('/daftar-guru/verifikasi/' . $registration['public_uuid']);
        $this->assertFalse(auth('session')->loggedIn());
    }

    public function testPasswordResetChangesPasswordForActiveAccount(): void
    {
        $user = $this->createUser('reset-password@example.test', 'password-lama-aman', true);
        $resetUrl = null;
        $service = new PasswordResetService(
            static function (User $recipient, string $url) use (&$resetUrl): bool {
                $resetUrl = $url;

                return true;
            }
        );

        $this->assertTrue($service->sendResetLink('reset-password@example.test'));
        $this->assertNotNull($resetUrl);
        parse_str((string) parse_url($resetUrl, PHP_URL_QUERY), $query);

        $service->resetPassword((string) ($query['token'] ?? ''), 'password-baru-aman', 'password-baru-aman');

        $this->assertFalse($service->hasValidToken((string) ($query['token'] ?? '')));
        $this->assertFalse(auth()->check(['email' => 'reset-password@example.test', 'password' => 'password-lama-aman'])->isOK());
        $this->assertTrue(auth()->check(['email' => 'reset-password@example.test', 'password' => 'password-baru-aman'])->isOK());
        $this->assertNotNull($user->id);
    }

    public function testForgotPasswordForPendingAccountReturnsToVerification(): void
    {
        [, $registration] = $this->createPendingRegistration('reset-pending@example.test');

        $response = $this->postWithCsrf('/lupa-password', [
            'email' => 'reset-pending@example.test',
        ]);

        $response->assertRedirectTo('/daftar-guru/verifikasi/' . $registration['public_uuid']);
    }

    public function testProfilePasswordChangeRejectsWrongCurrentPassword(): void
    {
        $user = $this->createUser('profile-password@example.test', 'password-lama-aman', true);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Password saat ini tidak benar.');

        (new ProfilePasswordService())->changePassword(
            $user,
            'password-yang-salah',
            'password-baru-aman',
            'password-baru-aman'
        );
    }

    /** @return array{0: User, 1: array<string, mixed>} */
    private function createPendingRegistration(string $email, string $password = 'password-guru-aman'): array
    {
        $user = $this->createUser($email, $password, false);
        $id = (new TeacherRegistrationRequestModel())->insert([
            'public_uuid' => Uuid::v4(),
            'auth_user_id' => $user->id,
            'name' => 'Guru Belum Verifikasi',
            'email' => strtolower($email),
            'status' => TeacherRegistrationService::STATUS_PENDING_EMAIL,
            'verification_code_hash' => password_hash('123456', PASSWORD_DEFAULT),
            'verification_expires_at' => date('Y-m-d H:i:s', time() + 900),
            'verification_attempts' => 0,
            'last_sent_at' => date('Y-m-d H:i:s', time() - 120),
        ], true);

        return [$user, (new TeacherRegistrationRequestModel())->find((int) $id)];
    }

    private function createUser(string $email, string $password, bool $active): User
    {
        $users = model(UserModel::class);
        $user = new User([
            'username' => 'guru.' . bin2hex(random_bytes(5)),
            'email' => strtolower($email),
            'active' => $active,
        ]);
        $user->setPassword($password);
        $users->save($user);

        return $users->findById($users->getInsertID());
    }

    /** @param array<string, string> $data */
    private function postWithCsrf(string $path, array $data)
    {
        $data[csrf_token()] = csrf_hash();

        return $this->post($path, $data);
    }
}
