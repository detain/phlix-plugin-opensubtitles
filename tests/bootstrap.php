<?php

/**
 * Test bootstrap for the plugin PHPUnit suite.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

/*
 * The provider implements the phlix-shared contracts (LifecycleInterface,
 * ConfigurableInterface, Manifest, SubtitleSourceInterface, SubtitleCandidate,
 * SubtitleFile, QuotaExceeded); every one of them is autoloaded from the pinned
 * `detain/phlix-shared` dependency by vendor/autoload.php below. A former
 * no-vendor fallback required a single hand-written stub covering just
 * LifecycleInterface — the provider class fataled the instant it loaded on the
 * other six, so the guard never actually rescued anything. It is retired: the
 * whole test toolchain (phpunit/phpstan/phpcs) already lives under vendor/, so
 * a tree without phlix-shared cannot run this suite regardless, and the missing
 * dependency is now allowed to fail loudly and here, naming the fix, rather
 * than behind a decorative `interface_exists()` branch.
 */

require __DIR__ . '/../vendor/autoload.php';

if (!interface_exists(\Phlix\Shared\Plugin\LifecycleInterface::class)) {
    throw new RuntimeException(
        'phlix-shared is not installed; run `composer install` before the test suite.',
    );
}
