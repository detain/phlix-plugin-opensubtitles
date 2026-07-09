<?php

/**
 * Subtitle download result DTO.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\PluginOpenSubtitles;

/**
 * Immutable value object representing a downloaded subtitle file.
 *
 * @package Phlix\PluginOpenSubtitles
 * @since 0.1.0
 */
final class SubtitleDownload
{
    /**
     * @param string $content   Raw subtitle file content.
     * @param string $format   Subtitle format (srt, sub, ass, etc.).
     * @param string $fileName Original filename from the provider.
     */
    public function __construct(
        public readonly string $content,
        public readonly string $format,
        public readonly string $fileName,
    ) {
    }

    /**
     * Get the content length in bytes.
     *
     * @return int Byte count of the subtitle content.
     *
     * @since 0.1.0
     */
    public function getContentLength(): int
    {
        return strlen($this->content);
    }

    /**
     * Check if the content is empty.
     *
     * @return bool True if content is empty.
     *
     * @since 0.1.0
     */
    public function isEmpty(): bool
    {
        return $this->content === '';
    }
}
