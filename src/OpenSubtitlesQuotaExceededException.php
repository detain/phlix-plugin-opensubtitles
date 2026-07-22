<?php

/**
 * OpenSubtitles download-quota-exceeded exception class.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\PluginOpenSubtitles;

/**
 * Thrown when {@see OpenSubtitlesProvider::download()} cannot complete because
 * the account's OpenSubtitles download quota has been exhausted.
 *
 * A caller (e.g. a subtitle-fetch orchestrator that tries several files or
 * falls back to another provider) can catch this specifically rather than a
 * generic {@see OpenSubtitlesException} to react to quota exhaustion — stop
 * retrying immediately, surface a friendly "try again later" message, or fall
 * back — without having to string-match the generic exception's message.
 *
 * Because it extends {@see OpenSubtitlesException}, existing call sites that
 * only catch the parent type keep working unchanged.
 *
 * @package Phlix\PluginOpenSubtitles
 * @since 0.3.2
 */
class OpenSubtitlesQuotaExceededException extends OpenSubtitlesException
{
    /**
     * @param string      $message      Human-readable quota-exceeded message (the
     *        API's own `message` field when available, e.g. "You have downloaded
     *        your allowed 20 subtitles for 24h.").
     * @param string|null $resetTime          Human-readable quota reset time (API field
     *        `reset_time`), if the API reported one.
     * @param string|null $resetTimeUtc       ISO-8601 UTC quota reset timestamp (API
     *        field `reset_time_utc`), if the API reported one.
     * @param int|null    $downloadsRemaining Downloads remaining in the current quota
     *        window (API field `remaining`), if the API reported one. Usually 0 when
     *        the quota is exhausted; null when the provider did not report it.
     */
    public function __construct(
        string $message,
        public readonly ?string $resetTime = null,
        public readonly ?string $resetTimeUtc = null,
        public readonly ?int $downloadsRemaining = null,
    ) {
        parent::__construct($message);
    }
}
