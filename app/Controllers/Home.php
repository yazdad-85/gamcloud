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
        $siteName = (string) $branding['site_name'];

        return view('public/landing', [
            'title'          => $siteName . ' — Platform Game Edukatif untuk Kelas',
            'seoTitle'       => $siteName . ' — Platform Game Edukatif untuk Kelas',
            'seoDescription' => $branding['seo_description'],
            'seoPath'        => '/',
        ]);
    }
}
