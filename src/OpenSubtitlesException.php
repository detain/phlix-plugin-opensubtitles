<?php

/**
 * OpenSubtitles exception class.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\PluginOpenSubtitles;

/**
 * Exception thrown when OpenSubtitles API operations fail.
 *
 * @package Phlix\PluginOpenSubtitles
 * @since 0.1.0
 */
class OpenSubtitlesException extends \RuntimeException
{
    /**
     * Constructs the exception with a message and optional code and previous exception.
     *
     * @param string          $message  The exception message.
     * @param int             $code     The exception code (defaults to 0).
     * @param \Throwable|null $previous The previous throwable for chaining (defaults to null).
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
