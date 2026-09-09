<?php

namespace App\Controllers;

use App\Services\Platform\PlatformSettingsService;

class Home extends BaseController
{
    public function index()
    {
        if (function_exists('auth') && auth()->loggedIn()) {
            if (auth()->user()?->inGroup('superadmin')) {
                return redirect()->to('/superadmin');
            }

            return redirect()->to('/teacher');
        }

        $branding = (new PlatformSettingsService())->branding();

        return view('public/landing', [
            'title'          => $branding['site_name'] . ' — Kuis Kelas Interaktif',
            'seoTitle'       => $branding['site_name'] . ' — Kuis Kelas Interaktif',
            'seoDescription' => $branding['seo_description'],
            'seoPath'        => '/',
        ]);
    }
}
