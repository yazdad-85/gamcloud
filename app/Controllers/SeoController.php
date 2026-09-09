<?php

namespace App\Controllers;

class SeoController extends BaseController
{
    public function sitemap()
    {
        $base = rtrim((string) config('App')->baseURL, '/');
        $urls = [
            ['loc' => $base . '/', 'priority' => '1.0'],
            ['loc' => $base . '/login', 'priority' => '0.8'],
            ['loc' => $base . '/join', 'priority' => '0.8'],
            ['loc' => $base . '/daftar-guru', 'priority' => '0.7'],
        ];

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $url) {
            $xml .= "  <url>\n";
            $xml .= '    <loc>' . esc($url['loc']) . "</loc>\n";
            $xml .= '    <changefreq>weekly</changefreq>' . "\n";
            $xml .= '    <priority>' . esc($url['priority']) . "</priority>\n";
            $xml .= "  </url>\n";
        }
        $xml .= '</urlset>';

        return $this->response
            ->setHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->setBody($xml);
    }
}
