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
 * The plugin's runtime dependency is the Phlix server, which provides
 * `Phlix\Shared\Plugin\LifecycleInterface`. In an installed plugin
 * (`var/plugins/phlix-plugin-opensubtitles/`) that interface is resolved by
 * the host application's autoloader. When the plugin is tested in
 * isolation — `composer install && vendor/bin/phpunit` from this
 * repo — the host isn't on the classpath, so we declare a minimal stub
 * here that matches the published shape.
 *
 * The stub is only registered when the real interface is absent, so
 * downstream integration tests (e.g. the server-side
 * `SamplePluginSmokeTest` in `detain/phlix`) still resolve the real
 * contract.
 */

require __DIR__ . '/../vendor/autoload.php';

if (!interface_exists(\Phlix\Shared\Plugin\LifecycleInterface::class)) {
    require __DIR__ . '/../dev-stubs/LifecycleInterface.php';
}
