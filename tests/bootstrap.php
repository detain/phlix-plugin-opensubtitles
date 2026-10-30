<?php

/**
 * Test bootstrap for the plugin PHPUnit suite.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

/**
 * Test bootstrap for the plugin's own PHPUnit suite.
 *
 * The class `Phlix\Shared\Plugin\LifecycleInterface` this plugin implements
 * normally comes from the pinned `detain/phlix-shared` composer dependency
 * and is autoloaded by `vendor/autoload.php` below. As a defensive
 * fallback, `dev-stubs/LifecycleInterface.php` declares a minimal
 * same-shape stub, but ONLY when the real contract is absent from the
 * classpath (e.g. a vendor tree built without phlix-shared): the
 * `interface_exists()` guard runs first, so whenever the real interface
 * is autoloadable it wins and the stub file is never read.
 */

require __DIR__ . '/../vendor/autoload.php';

if (interface_exists(\Phlix\Shared\Plugin\LifecycleInterface::class) === false) {
    require __DIR__.'/../dev-stubs/LifecycleInterface.php';
}
