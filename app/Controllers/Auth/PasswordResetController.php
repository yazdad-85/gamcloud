<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Controllers\BaseController;
use App\Services\Auth\PasswordResetService;
use App\Services\Auth\TeacherRegistrationService;
use CodeIgniter\HTTP\RedirectResponse;
use DomainException;

class PasswordResetController extends BaseController
{
    public function requestForm(): RedirectResponse|string
    {
        if (auth()->loggedIn()) {
            return redirect()->to(config('Auth')->loginRedirect());
        }

        return view('auth/forgot_password');
    }

    public function requestLink(): RedirectResponse
    {
        $email = strtolower(trim((string) $this->request->getPost('email')));
        if (! $this->validateData(['email' => $email], [
            'email' => 'required|valid_email|max_length[190]',
        ])) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $pending = (new TeacherRegistrationService())->findPendingByEmail($email);
        if ($pending !== null) {
            return redirect()
                ->to('/daftar-guru/verifikasi/' . $pending['public_uuid'])
                ->with('error', 'Email belum diverifikasi. Selesaikan verifikasi sebelum memulihkan password.');
        }

        if (! (new PasswordResetService())->sendResetLink($email)) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Tautan pemulihan belum berhasil dikirim. Silakan coba lagi beberapa saat.');
        }

        return redirect()->to('/lupa-password')
            ->with('message', 'Jika email terdaftar dan aktif, tautan pemulihan password telah dikirim.');
    }

    public function resetForm(): string
    {
        $token = trim((string) $this->request->getGet('token'));

        return view('auth/reset_password', [
            'token' => $token,
            'tokenValid' => (new PasswordResetService())->hasValidToken($token),
        ]);
    }

    public function reset(): RedirectResponse
    {
        $token = trim((string) $this->request->getPost('token'));

        try {
            (new PasswordResetService())->resetPassword(
                $token,
                (string) $this->request->getPost('password'),
                (string) $this->request->getPost('password_confirm')
            );

            auth()->logout();

            return redirect()->to('/login')
                ->with('message', 'Password berhasil diperbarui. Silakan login dengan password baru.');
        } catch (DomainException $error) {
            return redirect()
                ->to('/lupa-password/reset?token=' . rawurlencode($token))
                ->withInput()
                ->with('error', $error->getMessage());
        }
    }
}
