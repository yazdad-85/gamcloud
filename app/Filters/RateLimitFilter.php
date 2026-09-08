<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class RateLimitFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (! $request instanceof IncomingRequest) {
            return null;
        }

        $limit = (int) ($arguments[0] ?? 60);
        $seconds = (int) ($arguments[1] ?? 60);
        $scope = (string) ($arguments[2] ?? 'global');
        $key = hash('sha256', implode('|', [
            $scope,
            $request->getIPAddress(),
            $request->getMethod(),
            $request->getUri()->getPath(),
        ]));

        $throttler = service('throttler');

        if ($throttler->check($key, $limit, $seconds, 1) !== false) {
            return null;
        }

        $message = 'Terlalu banyak permintaan. Coba lagi dalam ' . $throttler->getTokenTime() . ' detik.';

        if (str_starts_with($request->getUri()->getPath(), '/api/')) {
            return service('response')->setStatusCode(429)->setJSON([
                'ok' => false,
                'error' => [
                    'code' => 'RATE_LIMITED',
                    'message' => $message,
                ],
            ]);
        }

        return service('response')->setStatusCode(429)->setBody($message);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
