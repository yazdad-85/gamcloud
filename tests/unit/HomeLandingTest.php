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
}
