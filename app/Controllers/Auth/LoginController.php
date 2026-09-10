<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Services\Auth\TeacherRegistrationService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Shield\Authentication\Authenticators\Session;
use CodeIgniter\Shield\Controllers\LoginController as ShieldLoginController;

class LoginController extends ShieldLoginController
{
    public function loginView(): RedirectResponse|string
    {
        if (auth()->loggedIn()) {
            $user = auth()->user();
            $pending = $user === null
                ? null
                : (new TeacherRegistrationService())->findPendingByAuthUserId((int) $user->id);

            if ($pending !== null || ($user !== null && ! $user->active)) {
                auth()->logout();

                if ($pending !== null) {
                    return $this->verificationRedirect($pending);
                }

                return redirect()->to('/login')
                    ->with('error', 'Akun Anda belum aktif. Hubungi pengelola aplikasi.');
            }

            return redirect()->to(config('Auth')->loginRedirect())->withCookies();
        }

        /** @var Session $authenticator */
        $authenticator = auth('session')->getAuthenticator();
        if ($authenticator->hasAction()) {
            return redirect()->route('auth-action-show');
        }

        return view(setting('Auth.views')['login']);
    }

    public function loginAction(): RedirectResponse
    {
        $rules = $this->getValidationRules();
        if (! $this->validateData($this->request->getPost(), $rules, [], config('Auth')->DBGroup)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $credentials = $this->request->getPost(setting('Auth.validFields')) ?? [];
        $credentials = array_filter($credentials);
        $credentials['password'] = $this->request->getPost('password');

        /** @var Session $authenticator */
        $authenticator = auth('session')->getAuthenticator();
        $result = $authenticator
            ->remember((bool) $this->request->getPost('remember'))
            ->attempt($credentials);

        if (! $result->isOK()) {
            return redirect()->route('login')->withInput()->with('error', $result->reason());
        }

        $user = $authenticator->getUser();
        $registration = new TeacherRegistrationService();
        $pending = $user === null
            ? $registration->findPendingByEmail((string) ($credentials['email'] ?? ''))
            : $registration->findPendingByAuthUserId((int) $user->id);

        if ($pending !== null || ($user !== null && ! $user->active)) {
            $authenticator->logout();

            if ($pending !== null) {
                return $this->verificationRedirect($pending)->withCookies();
            }

            return redirect()->route('login')
                ->with('error', 'Akun Anda belum aktif. Hubungi pengelola aplikasi.')
                ->withCookies();
        }

        if ($authenticator->hasAction()) {
            return redirect()->route('auth-action-show')->withCookies();
        }

        return redirect()->to(config('Auth')->loginRedirect())->withCookies();
    }

    /** @param array<string, mixed> $registration */
    private function verificationRedirect(array $registration): RedirectResponse
    {
        return redirect()
            ->to('/daftar-guru/verifikasi/' . $registration['public_uuid'])
            ->with(
                'error',
                'Akun Anda belum aktif karena email belum diverifikasi. '
                . 'Masukkan kode verifikasi atau kirim ulang kode untuk dapat login.'
            );
    }
}
