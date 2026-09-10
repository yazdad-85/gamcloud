<?php

namespace App\Filters;

use App\Services\Auth\TeacherRegistrationService;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class TeacherAccessFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (! $request instanceof IncomingRequest) {
            return null;
        }

        $throttler = service('throttler');
        $key = hash('sha256', 'teacher-access|' . $request->getIPAddress() . '|' . $request->getUri()->getPath());
        if ($throttler->check($key, 120, 60, 1) === false) {
            return service('response')->setStatusCode(429)->setBody('Terlalu banyak permintaan. Coba lagi nanti.');
        }

        if (! auth()->loggedIn()) {
            session()->setTempdata('beforeLoginUrl', current_url(), 300);

            return redirect()->to('/login')->with('error', 'Silakan login sebagai guru.');
        }

        $user = auth()->user();
        $pending = $user === null
            ? null
            : (new TeacherRegistrationService())->findPendingByAuthUserId((int) $user->id);

        if ($pending !== null || ($user !== null && ! $user->active)) {
            auth()->logout();

            if ($pending !== null) {
                return redirect()
                    ->to('/daftar-guru/verifikasi/' . $pending['public_uuid'])
                    ->with(
                        'error',
                        'Akun Anda belum aktif karena email belum diverifikasi. '
                        . 'Masukkan kode verifikasi atau kirim ulang kode untuk dapat login.'
                    );
            }

            return redirect()->to('/login')->with('error', 'Akun Anda belum aktif. Hubungi pengelola aplikasi.');
        }

        if ($user === null || (! $user->inGroup('teacher') && ! $user->inGroup('superadmin'))) {
            return service('response')->setStatusCode(403)->setBody('Akses guru diperlukan.');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
