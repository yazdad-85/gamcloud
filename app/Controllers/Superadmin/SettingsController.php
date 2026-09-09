<?php

namespace App\Controllers\Superadmin;

use App\Controllers\BaseController;
use App\Services\Platform\PlatformSettingsService;
use DomainException;

class SettingsController extends BaseController
{
    public function edit(): string
    {
        $branding = (new PlatformSettingsService())->branding(true);

        return view('superadmin/settings', [
            'title'    => 'Pengaturan Platform',
            'branding' => $branding,
            'message'  => session()->getFlashdata('message'),
            'error'    => session()->getFlashdata('error'),
        ]);
    }

    public function update()
    {
        $service = new PlatformSettingsService();

        try {
            $service->updateTextSettings([
                PlatformSettingsService::KEY_SITE_NAME       => (string) $this->request->getPost('site_name'),
                PlatformSettingsService::KEY_TAGLINE         => (string) $this->request->getPost('tagline'),
                PlatformSettingsService::KEY_SEO_DESCRIPTION => (string) $this->request->getPost('seo_description'),
            ]);

            $service->storeBrandUpload($this->request->getFile('logo'), PlatformSettingsService::KEY_LOGO_PATH);
            $service->storeBrandUpload($this->request->getFile('favicon'), PlatformSettingsService::KEY_FAVICON_PATH);
            $service->storeBrandUpload($this->request->getFile('og_image'), PlatformSettingsService::KEY_OG_IMAGE_PATH);
        } catch (DomainException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to('/superadmin/settings')->with('message', 'Pengaturan platform disimpan.');
    }
}
