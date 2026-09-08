<?php

declare(strict_types=1);

/**
 * Worktree-safe PHPUnit bootstrap.
 *
 * vendor/ is often a symlink to the main repo's vendor. Composer then resolves
 * App\ / Config\ to the main tree via an optimized classmap. Strip those entries
 * and remap PSR-4 to this worktree after CodeIgniter boots.
 */

require dirname(__DIR__) . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';

$loader = require COMPOSER_PATH;
if ($loader instanceof Composer\Autoload\ClassLoader) {
    $loader->setClassMapAuthoritative(false);

    $classMapProperty = new ReflectionProperty(Composer\Autoload\ClassLoader::class, 'classMap');
    $classMapProperty->setAccessible(true);
    $classMap = $classMapProperty->getValue($loader);
    foreach (array_keys($classMap) as $class) {
        if (str_starts_with($class, 'App\\') || str_starts_with($class, 'Config\\')) {
            unset($classMap[$class]);
        }
    }
    $classMapProperty->setValue($loader, $classMap);

    $loader->setPsr4('App\\', [APPPATH]);
    $loader->setPsr4('Config\\', [APPPATH . 'Config' . DIRECTORY_SEPARATOR]);
    $loader->setPsr4('Tests\\Support\\', [TESTPATH . '_support' . DIRECTORY_SEPARATOR]);
}
