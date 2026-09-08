<?php

namespace App\Controllers\Public;

use App\Controllers\BaseController;
use App\Services\Auth\TeacherRegistrationService;
use CodeIgniter\HTTP\RedirectResponse;
use DomainException;

class TeacherRegistrationController extends BaseController
{
    public function create(): string
    {
        return view('public/teacher_register');
    }

    public function store()
    {
        $rules = [
            'name' => 'required|min_length[3]|max_length[140]',
            'email' => 'required|valid_email|max_length[190]',
            'school_name' => 'permit_empty|max_length[190]',
            'password' => [
                'rules' => 'required|min_length[10]|max_length[128]',
                'errors' => [
                    'min_length' => 'Password minimal 10 karakter.',
                    'max_length' => 'Password maksimal 128 karakter.',
                ],
            ],
            'password_confirm' => [
                'rules' => 'required|matches[password]',
                'errors' => [
                    'matches' => 'Ulangi password harus sama dengan password.',
                ],
            ],
        ];

        if (! $this->validateData($this->request->getPost(), $rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        try {
            $result = (new TeacherRegistrationService())->createRequest(
                $this->request->getPost(),
                $this->request->getIPAddress(),
                $this->request->getUserAgent()->getAgentString()
            );

            $message = $result['email_sent']
                ? 'Kode verifikasi sudah dikirim ke email Anda.'
                : 'Pengajuan dibuat, tetapi kode belum berhasil dikirim. Silakan coba Kirim Ulang Kode atau hubungi pengelola aplikasi.';

            return redirect()
                ->to('/daftar-guru/verifikasi/' . $result['request']['public_uuid'])
                ->with('message', $message);
        } catch (DomainException $error) {
            return redirect()->back()->withInput()->with('error', $error->getMessage());
        }
    }

    public function verifyForm(string $uuid): string|RedirectResponse
    {
        $service = new TeacherRegistrationService();
        $request = $service->findByUuid($uuid);

        if ($request !== null && $request['status'] === TeacherRegistrationService::STATUS_EMAIL_VERIFIED) {
            $service->activateVerifiedRequest($uuid);

            return redirect()
                ->to('/login')
                ->with('message', 'Email sudah berhasil diverifikasi. Akun guru Anda sudah aktif, silakan login.');
        }

        if ($request !== null && $request['status'] === TeacherRegistrationService::STATUS_APPROVED) {
            $service->activateVerifiedRequest($uuid);

            return redirect()
                ->to('/login')
                ->with('message', 'Akun sudah aktif. Silakan login dengan email dan password yang Anda daftarkan.');
        }

        return view('public/teacher_verify', [
            'request' => $request,
            'uuid' => $uuid,
        ]);
    }

    public function verify(string $uuid)
    {
        $code = trim((string) $this->request->getPost('code'));
        if (! preg_match('/^\d{6}$/', $code)) {
            return redirect()->back()->withInput()->with('error', 'Kode verifikasi harus 6 digit.');
        }

        try {
            (new TeacherRegistrationService())->verifyEmail($uuid, $code);

            return redirect()
                ->to('/login')
                ->with('message', 'Email berhasil diverifikasi. Akun guru Anda sudah aktif, silakan login.');
        } catch (DomainException $error) {
            return redirect()->back()->withInput()->with('error', $error->getMessage());
        }
    }

    public function resend(string $uuid)
    {
        try {
            $result = (new TeacherRegistrationService())->resendCode($uuid);
            $message = $result['email_sent']
                ? 'Kode baru sudah dikirim ke email Anda.'
                : 'Kode baru dibuat, tetapi email belum berhasil dikirim. Silakan coba beberapa saat lagi atau hubungi pengelola aplikasi.';

            return redirect()->back()->with('message', $message);
        } catch (DomainException $error) {
            return redirect()->back()->with('error', $error->getMessage());
        }
    }
}
