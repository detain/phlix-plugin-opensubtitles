<?php

/**
 * Minimal stub for LifecycleInterface when phlix-shared is not installed.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Plugin;

use Psr\Container\ContainerInterface;

/**
 * Minimal stub implementing the LifecycleInterface contract for testing
 * when the real phlix-shared package is not installed.
 *
 * @package Phlix\Shared\Plugin
 * @since 0.2.0
 */
interface LifecycleInterface
{
    /**
     * Called by the loader once when the plugin is enabled.
     *
     * @param ContainerInterface $container Host PSR-11 container.
     * @return void
     */
    public function onEnable(ContainerInterface $container): void;

    /**
     * Called by the loader once when the plugin is disabled.
     *
     * @return void
     */
    public function onDisable(): void;

    /**
     * Return the PSR-14 listener subscriptions this plugin wants.
     *
     * @return array<class-string, string|callable>
     */
    public function subscribedEvents(): array;
}
