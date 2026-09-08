<?php

namespace App\Filters;

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

        if (! auth()->user()->inGroup('teacher') && ! auth()->user()->inGroup('superadmin')) {
            return service('response')->setStatusCode(403)->setBody('Akses guru diperlukan.');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
