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
     * @param string      $content            Raw subtitle file content (fetched from the
     *        temporary `link` returned by the OpenSubtitles `/download` endpoint).
     * @param string      $format             Subtitle format (srt, sub, ass, etc.).
     * @param string      $fileName           Original filename from the provider.
     * @param int|null    $requestsUsed       Download requests counted so far in the current
     *        quota window (API field `requests`), or null if the provider didn't report it.
     * @param int|null    $downloadsRemaining Downloads remaining in the current quota window
     *        (API field `remaining`), or null if the provider didn't report it.
     * @param string|null $quotaMessage       Human-readable quota renewal message (API field
     *        `message`), or null if the provider didn't report it.
     * @param string|null $resetTime          Human-readable quota reset time (API field
     *        `reset_time`), or null if the provider didn't report it.
     * @param string|null $resetTimeUtc       ISO-8601 UTC quota reset timestamp (API field
     *        `reset_time_utc`), or null if the provider didn't report it.
     *
     * @since 0.3.0 Added the quota-accounting parameters ($requestsUsed onward). All are
     *        optional and default to null so existing call sites remain source-compatible.
     */
    public function __construct(
        public readonly string $content,
        public readonly string $format,
        public readonly string $fileName,
        public readonly ?int $requestsUsed = null,
        public readonly ?int $downloadsRemaining = null,
        public readonly ?string $quotaMessage = null,
        public readonly ?string $resetTime = null,
        public readonly ?string $resetTimeUtc = null,
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
