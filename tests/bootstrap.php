<?php

declare(strict_types=1);

/**
 * Worktree-safe PHPUnit bootstrap.
 *
 * vendor/ is often a symlink to the main repo's vendor. Composer then resolves
 * App\ / Config\ to the main tree. Remap those namespaces to this worktree
 * after CodeIgniter boots (APPPATH/TESTPATH already point here via HOMEPATH).
 */

require dirname(__DIR__) . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';

$loader = require COMPOSER_PATH;
if ($loader instanceof Composer\Autoload\ClassLoader) {
    $loader->setPsr4('App\\', [APPPATH]);
    $loader->setPsr4('Config\\', [APPPATH . 'Config' . DIRECTORY_SEPARATOR]);
    $loader->setPsr4('Tests\\Support\\', [TESTPATH . '_support' . DIRECTORY_SEPARATOR]);
}
