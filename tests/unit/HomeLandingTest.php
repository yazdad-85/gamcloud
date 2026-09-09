<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class HomeLandingTest extends CIUnitTestCase
{
    public function testLandingSeoAssetsExist(): void
    {
        $this->assertFileExists(APPPATH . 'Views/public/landing.php');
        $this->assertFileExists(APPPATH . 'Views/partials/seo_head.php');
        $this->assertFileExists(FCPATH . 'assets/brand/favicon.svg');
        $this->assertFileExists(FCPATH . 'assets/brand/logo.svg');
        $this->assertFileExists(FCPATH . 'assets/brand/og-default.png');
        $this->assertFileExists(FCPATH . 'robots.txt');
    }

    public function testLandingMatchesLoginShellMarkup(): void
    {
        $html = (string) file_get_contents(APPPATH . 'Views/public/landing.php');

        $this->assertStringContainsString('login-landing', $html);
        $this->assertStringContainsString('game-preview', $html);
        $this->assertStringContainsString('feature-strip', $html);
        $this->assertStringContainsString('landing-actions', $html);
        $this->assertStringContainsString('/daftar-guru', $html);
        $this->assertStringContainsString('/login', $html);
        $this->assertStringContainsString('/join', $html);
        $this->assertStringNotContainsString('landing-board', $html);
    }
}
