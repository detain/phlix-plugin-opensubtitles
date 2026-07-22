<?php

/**
 * OpenSubtitles subtitle provider plugin for Phlix.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\PluginOpenSubtitles;

use Phlix\Shared\Plugin\ConfigurableInterface;
use Phlix\Shared\Plugin\LifecycleInterface;
use Phlix\Shared\Plugin\Manifest;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;
use Workerman\Http\Client;
use Workerman\Http\Response;

/**
 * OpenSubtitles subtitle provider for Phlix.
 *
 * This plugin integrates with the OpenSubtitles REST API v1 to search
 * and download subtitles for movies and TV shows. It supports search
 * by IMDB ID, filename, and file hash (`moviehash`).
 *
 * ## Boot safety (Workerman/Webman resident memory)
 *
 * The host loads plugins into ~14 long-lived Workerman workers. Blocking I/O
 * in {@see onEnable()} at boot is the exact defect that caused the 2026-07-18
 * production revert, so this plugin is split into:
 *
 * - {@see onEnable()} — the cheap "wire" step. Adopts the host logger and marks
 *   the provider enabled. It performs **NO network I/O and never logs in.**
 * - {@see ensureConnected()} — the lazy "connect" step. The first authenticated
 *   API call (search/download) logs in to obtain a session token, once per
 *   enable cycle. Never at boot.
 *
 * ## Non-blocking HTTP
 *
 * All HTTP goes through {@see httpRequest()}, which uses the non-blocking
 * `workerman/http-client` with the canonical cooperative-wait pattern (see
 * phlix-server `CLAUDE.md`). A closure `$transport` seam lets tests exercise
 * the request/response shaping without an event loop or the network.
 *
 * ## Download quota
 *
 * OpenSubtitles enforces a per-account daily download quota, so fetches are
 * strictly on-demand — this plugin never bulk-downloads or loops over results.
 * Quota exhaustion is surfaced as {@see OpenSubtitlesQuotaExceededException}.
 *
 * ## Dispatch
 *
 * {@see subscribedEvents()} returns an empty array; subtitle lookups are driven
 * by the host's subtitle pipeline (Wave 3 `SubtitleSourceInterface`) rather
 * than via PSR-14 events.
 *
 * @package Phlix\PluginOpenSubtitles
 * @since 0.1.0
 */
final class OpenSubtitlesProvider implements LifecycleInterface, ConfigurableInterface
{
    /**
     * OpenSubtitles API base URL. Keeps a trailing slash; request paths are
     * appended without a leading slash so the `/api/v1` prefix is preserved.
     */
    private const API_BASE = 'https://api.opensubtitles.com/api/v1/';

    /**
     * Default language code when not configured.
     */
    private const DEFAULT_LANGUAGE = 'en';

    /**
     * Preferred subtitle format when not configured.
     */
    private const DEFAULT_FORMAT = 'srt';

    /**
     * HTTP request timeout in seconds.
     */
    private const HTTP_TIMEOUT_SEC = 30;

    /**
     * Chunk size (bytes) read from the head and tail of a media file for the
     * OpenSubtitles `moviehash` — 64 KiB, per the algorithm specification.
     */
    private const HASH_CHUNK_SIZE = 65536;

    /**
     * OpenSubtitles API key (consumer token).
     */
    private string $apiKey;

    /**
     * OpenSubtitles username for logged-in requests.
     */
    private ?string $username;

    /**
     * OpenSubtitles password for logged-in requests.
     */
    private ?string $password;

    /**
     * Default subtitle language (ISO 639-1).
     */
    private string $language;

    /**
     * Preferred subtitle format.
     */
    private string $format;

    /**
     * Shared non-blocking HTTP client, created lazily on first real request.
     */
    private ?Client $httpClient = null;

    /**
     * Test seam replacing the network call. Signature:
     * `fn(string $method, string $url, array<string,string> $headers, ?string $body): array{status:int, body:string}`.
     * Left null in production so the real non-blocking Workerman client is used.
     *
     * @var (\Closure(string, string, array<string,string>, ?string): array{status:int, body:string})|null
     * @internal Tests only.
     */
    private ?\Closure $transport;

    /**
     * Session token obtained via login.
     */
    private ?string $sessionToken = null;

    /**
     * Whether the deferred login has already been attempted this enable cycle.
     * Prevents a failed/absent login from re-hitting the network on every call.
     */
    private bool $loginAttempted = false;

    /**
     * Logger instance.
     */
    private LoggerInterface $logger;

    /**
     * Whether the provider has been enabled (wired).
     */
    private bool $enabled = false;

    /**
     * Cached result of {@see self::pluginVersion()} for the process lifetime.
     */
    private static ?string $cachedPluginVersion = null;

    /**
     * @param string      $apiKey    OpenSubtitles API key.
     * @param string|null $username  OpenSubtitles username (optional).
     * @param string|null $password  OpenSubtitles password (optional).
     * @param string      $language  Default language code (ISO 639-1).
     * @param string      $format    Preferred subtitle format.
     * @param (\Closure(string, string, array<string,string>, ?string): array{status:int, body:string})|null $transport
     *        Test seam replacing the network call; null in production.
     */
    public function __construct(
        string $apiKey = '',
        ?string $username = null,
        ?string $password = null,
        string $language = self::DEFAULT_LANGUAGE,
        string $format = self::DEFAULT_FORMAT,
        ?\Closure $transport = null,
    ) {
        // The constructor MUST stay autowirable — the host loader builds the
        // entry class through its PSR-11 container with no arguments. Real
        // settings arrive via configure() before onEnable(). Nothing here does
        // I/O, so construction at boot is cheap and safe.
        $this->apiKey = $apiKey;
        $this->username = $username;
        $this->password = $password;
        $this->language = $language;
        $this->format = $format;
        $this->transport = $transport;
        $this->logger = new NullLogger();
    }

    /**
     * Receive the plugin's persisted settings from the host.
     *
     * Called once by the loader between construction and {@see onEnable()}.
     * Does NOT perform any network I/O (no login) — configuration only.
     *
     * @param array<string, mixed> $settings Persisted settings (manifest keys:
     *        `api_key`, `username`, `password`, `language`, `format`).
     *
     * @return void
     *
     * @since 0.2.0
     */
    public function configure(array $settings): void
    {
        $this->apiKey = is_string($settings['api_key'] ?? null) ? $settings['api_key'] : '';
        $this->username = self::nonEmptyString($settings['username'] ?? null);
        $this->password = self::nonEmptyString($settings['password'] ?? null);
        $this->language = is_string($settings['language'] ?? null) && $settings['language'] !== ''
            ? $settings['language']
            : self::DEFAULT_LANGUAGE;
        $this->format = is_string($settings['format'] ?? null) && $settings['format'] !== ''
            ? $settings['format']
            : self::DEFAULT_FORMAT;

        // Credentials may have changed — allow a fresh deferred login on next use.
        $this->sessionToken = null;
        $this->loginAttempted = false;
    }

    /**
     * This plugin's own version, read from its `plugin.json` manifest.
     *
     * The result is cached for the process lifetime. Never throws — it runs from
     * inside header construction on every request.
     *
     * @return string The manifest's `version` field, or `'unknown'` if the file
     *         is missing, unreadable, or malformed.
     *
     * @since 0.3.2
     */
    private static function pluginVersion(): string
    {
        if (self::$cachedPluginVersion !== null) {
            return self::$cachedPluginVersion;
        }

        $manifestPath = dirname(__DIR__) . '/plugin.json';
        $version = 'unknown';

        if (is_readable($manifestPath)) {
            $contents = file_get_contents($manifestPath);

            if ($contents !== false) {
                try {
                    $manifest = Manifest::fromJson($contents);

                    if ($manifest->version !== '') {
                        $version = $manifest->version;
                    }
                } catch (RuntimeException) {
                    // Malformed manifest — fall through to the 'unknown' sentinel.
                }
            }
        }

        return self::$cachedPluginVersion = $version;
    }

    /**
     * Coerce a raw settings value to a non-empty string, or null.
     */
    private static function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Loader hook called once when the plugin is enabled — the cheap "wire" step.
     *
     * Adopts the host logger and marks the provider enabled. It performs NO
     * network I/O and does NOT log in: at boot this runs across every worker,
     * and blocking here is the item-5c3 landmine. Authentication is deferred to
     * the first actual use via {@see ensureConnected()}.
     *
     * @param ContainerInterface $container Host PSR-11 container.
     *
     * @return void
     *
     * @since 0.1.0
     */
    public function onEnable(ContainerInterface $container): void
    {
        if ($container->has(LoggerInterface::class)) {
            /** @var LoggerInterface */
            $logger = $container->get(LoggerInterface::class);
            $this->logger = $logger;
        }

        $this->enabled = true;
        $this->loginAttempted = false;
    }

    /**
     * Loader hook called once when the plugin is disabled.
     *
     * Cleans up the session token.
     *
     * @return void
     *
     * @since 0.1.0
     */
    public function onDisable(): void
    {
        $this->sessionToken = null;
        $this->loginAttempted = false;
        $this->enabled = false;
    }

    /**
     * Returns the PSR-14 listener subscriptions this plugin wants.
     *
     * This plugin is invoked directly by the host's subtitle pipeline rather
     * than via the event dispatcher, so it returns an empty array.
     *
     * @return array<class-string, string|callable> Always empty.
     *
     * @since 0.1.0
     */
    public function subscribedEvents(): array
    {
        return [];
    }

    /**
     * Lazily authenticate with OpenSubtitles on first authenticated use.
     *
     * Runs at most once per enable cycle (guarded by {@see $loginAttempted}).
     * Login is OPTIONAL — it merely raises download limits — so a failure is
     * logged and the provider continues anonymously rather than throwing and
     * breaking search/download. This is the deferred "connect" half of the
     * boot-safety split; it is never invoked from {@see onEnable()}.
     *
     * @return void
     *
     * @since 0.4.0
     */
    private function ensureConnected(): void
    {
        if ($this->loginAttempted) {
            return;
        }

        $this->loginAttempted = true;

        if ($this->username === null || $this->password === null) {
            return;
        }

        $this->login();
    }

    /**
     * Authenticate with OpenSubtitles and obtain a session token.
     *
     * Non-throwing: login is optional, so a transport or credential failure is
     * logged and the provider stays anonymous.
     *
     * @return void
     *
     * @since 0.1.0
     */
    private function login(): void
    {
        $body = (string) json_encode([
            'username' => $this->username,
            'password' => $this->password,
        ]);

        try {
            $response = $this->httpRequest('POST', 'login', ['Content-Type' => 'application/json'], $body);
        } catch (OpenSubtitlesException $e) {
            $this->logger->warning('OpenSubtitles login transport failed: ' . $e->getMessage());

            return;
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            $this->logger->warning('OpenSubtitles login failed with HTTP ' . $response['status']);

            return;
        }

        $data = json_decode($response['body'], true);
        if (is_array($data) && isset($data['token']) && is_string($data['token'])) {
            $this->sessionToken = $data['token'];
            $this->logger->info('OpenSubtitles login successful');
        }
    }

    /**
     * Search for subtitles matching the given IMDB ID.
     *
     * @param string $imdbId         IMDB ID (e.g., "tt1234567").
     * @param string $language       Language code (ISO 639-1).
     * @param string $subtitleFormat Preferred format (srt, sub, ass, etc.).
     *
     * @return list<array{
     *     id: string,
     *     language: string,
     *     format: string,
     *     downloads: int,
     *     filename: string,
     *     imdb_id: ?string,
     *     file_id: int,
     *     files: list<array{file_id: int, file_name: ?string, cd_number: int}>
     * }>
     *
     * @throws OpenSubtitlesException When the search fails.
     *
     * @since 0.1.0
     */
    public function searchByImdbId(string $imdbId, string $language = '', string $subtitleFormat = ''): array
    {
        $language = $language ?: $this->language;
        $subtitleFormat = $subtitleFormat ?: $this->format;

        $this->ensureEnabled();
        $this->ensureConnected();

        return $this->searchSubtitles([
            'imdb_id' => $imdbId,
            'languages' => $language,
        ], $subtitleFormat, 'IMDB ID');
    }

    /**
     * Search for subtitles for a media file on disk — the preferred entry point.
     *
     * Computes the OpenSubtitles `moviehash` from the file (see
     * {@see computeMovieHash()}) and searches by hash + filesize, which yields
     * the most accurate matches. If the hash cannot be computed or the hash
     * search returns nothing, it falls back to a filename-based search.
     *
     * This is a single on-demand call chain — it never loops or bulk-fetches,
     * respecting the OpenSubtitles download quota.
     *
     * @param string $filePath       Absolute path to the media file.
     * @param string $language       Language code (ISO 639-1).
     * @param string $subtitleFormat Preferred format (srt, sub, ass, etc.).
     *
     * @return list<array{
     *     id: string,
     *     language: string,
     *     format: string,
     *     downloads: int,
     *     filename: string,
     *     imdb_id: ?string,
     *     file_id: int,
     *     files: list<array{file_id: int, file_name: ?string, cd_number: int}>
     * }>
     *
     * @throws OpenSubtitlesException When the search fails.
     *
     * @since 0.4.0
     */
    public function searchByPath(string $filePath, string $language = '', string $subtitleFormat = ''): array
    {
        $this->ensureEnabled();

        $hash = self::computeMovieHash($filePath);
        $size = is_file($filePath) ? filesize($filePath) : false;

        if ($hash !== '' && $size !== false && $size > 0) {
            $results = $this->searchByHash($hash, $size, $language, $subtitleFormat);
            if ($results !== []) {
                return $results;
            }
        }

        // Fallback: no usable hash, or the hash matched nothing.
        return $this->searchByFilename(basename($filePath), $language, $subtitleFormat);
    }

    /**
     * Search for subtitles matching the given filename (fallback path).
     *
     * @param string $filename       Media filename.
     * @param string $language       Language code (ISO 639-1).
     * @param string $subtitleFormat Preferred format (srt, sub, ass, etc.).
     *
     * @return list<array{
     *     id: string,
     *     language: string,
     *     format: string,
     *     downloads: int,
     *     filename: string,
     *     imdb_id: ?string,
     *     file_id: int,
     *     files: list<array{file_id: int, file_name: ?string, cd_number: int}>
     * }>
     *
     * @throws OpenSubtitlesException When the search fails.
     *
     * @since 0.1.0
     */
    public function searchByFilename(string $filename, string $language = '', string $subtitleFormat = ''): array
    {
        $language = $language ?: $this->language;
        $subtitleFormat = $subtitleFormat ?: $this->format;

        $this->ensureEnabled();
        $this->ensureConnected();

        $mediaInfo = $this->parseFilename($filename);

        $queryParams = ['languages' => $language];

        if ($mediaInfo['imdb_id'] !== null) {
            $queryParams['imdb_id'] = $mediaInfo['imdb_id'];
        }

        // A bare filename query is the weakest signal, but useful when we have
        // neither an IMDB id nor an on-disk file to hash.
        $queryParams['query'] = pathinfo($filename, PATHINFO_FILENAME);

        return $this->searchSubtitles($queryParams, $subtitleFormat, 'filename');
    }

    /**
     * Search for subtitles matching the given `moviehash`.
     *
     * Sends both the hash (`moviehash`) and the file size (`moviebytesize`).
     * The v1 REST API primarily keys on `moviehash`; the byte size is included
     * for disambiguation and legacy-parameter compatibility.
     *
     * @param string $hash           OpenSubtitles `moviehash` (see {@see computeMovieHash()}).
     * @param int    $size           File size in bytes.
     * @param string $language       Language code (ISO 639-1).
     * @param string $subtitleFormat Preferred format (srt, sub, ass, etc.).
     *
     * @return list<array{
     *     id: string,
     *     language: string,
     *     format: string,
     *     downloads: int,
     *     filename: string,
     *     imdb_id: ?string,
     *     file_id: int,
     *     files: list<array{file_id: int, file_name: ?string, cd_number: int}>
     * }>
     *
     * @throws OpenSubtitlesException When the search fails.
     *
     * @since 0.1.0
     */
    public function searchByHash(string $hash, int $size, string $language = '', string $subtitleFormat = ''): array
    {
        $language = $language ?: $this->language;
        $subtitleFormat = $subtitleFormat ?: $this->format;

        $this->ensureEnabled();
        $this->ensureConnected();

        return $this->searchSubtitles([
            'moviehash' => $hash,
            'moviebytesize' => (string) $size,
            'languages' => $language,
        ], $subtitleFormat, 'hash');
    }

    /**
     * Execute a `/subtitles` GET search with the given query parameters and
     * filter the JSON:API results.
     *
     * @param array<string, string> $queryParams     Query parameters.
     * @param string                $subtitleFormat  Preferred format.
     * @param string                $context         Human label for error logs.
     *
     * @return list<array{
     *     id: string,
     *     language: string,
     *     format: string,
     *     downloads: int,
     *     filename: string,
     *     imdb_id: ?string,
     *     file_id: int,
     *     files: list<array{file_id: int, file_name: ?string, cd_number: int}>
     * }>
     *
     * @throws OpenSubtitlesException When the search fails.
     */
    private function searchSubtitles(array $queryParams, string $subtitleFormat, string $context): array
    {
        $url = self::API_BASE . 'subtitles?' . http_build_query($queryParams);

        try {
            $response = $this->httpRequest('GET', $url, $this->authHeaders());
        } catch (OpenSubtitlesException $e) {
            $this->logger->error("OpenSubtitles search by {$context} failed: " . $e->getMessage());

            throw new OpenSubtitlesException("Search by {$context} failed: " . $e->getMessage(), 0, $e);
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            $this->logger->error("OpenSubtitles search by {$context} failed: HTTP " . $response['status']);

            throw new OpenSubtitlesException("Search by {$context} failed: HTTP " . $response['status']);
        }

        $data = json_decode($response['body'], true);

        /** @var list<array<string, mixed>> $subtitles */
        $subtitles = is_array($data) && is_array($data['data'] ?? null) ? array_values($data['data']) : [];

        return $this->filterSubtitles($subtitles, $subtitleFormat);
    }

    /**
     * Download a single subtitle file — strictly on demand (quota-metered).
     *
     * The real OpenSubtitles v1 REST API is a two-step flow:
     *
     * 1. `POST download` with a `file_id` JSON body returns a JSON envelope
     *    containing a temporary `link` URL plus download-quota accounting. This
     *    call itself counts against the account's download quota, so it must
     *    never be retried speculatively or looped.
     * 2. The actual subtitle bytes are fetched with a plain `GET` against that
     *    `link` (a different host, not `API_BASE`).
     *
     * Quota exhaustion is surfaced distinctly as
     * {@see OpenSubtitlesQuotaExceededException} — either from a 4xx `message`
     * body ({@see mapDownloadLinkFailure()}) or from a 200 response reporting
     * `remaining: 0` with no `link`.
     *
     * @param int $fileId OpenSubtitles file ID (`attributes.files[].file_id`).
     *
     * @return SubtitleDownload
     *
     * @throws OpenSubtitlesQuotaExceededException When the download quota is exhausted.
     * @throws OpenSubtitlesException When the download otherwise fails.
     *
     * @since 0.1.0
     */
    public function download(int $fileId): SubtitleDownload
    {
        $this->ensureEnabled();
        $this->ensureConnected();

        $headers = $this->authHeaders();
        $headers['Content-Type'] = 'application/json';

        $body = ['file_id' => $fileId];
        if ($this->format !== '') {
            $body['sub_format'] = $this->format;
        }

        try {
            $linkResponse = $this->httpRequest(
                'POST',
                self::API_BASE . 'download',
                $headers,
                (string) json_encode($body),
            );
        } catch (OpenSubtitlesException $e) {
            $this->logger->error('OpenSubtitles download failed: ' . $e->getMessage());

            throw new OpenSubtitlesException('Download failed: ' . $e->getMessage(), 0, $e);
        }

        $decoded = json_decode($linkResponse['body'], true);
        /** @var array<string, mixed> $data */
        $data = is_array($decoded) ? $decoded : [];

        if ($linkResponse['status'] >= 400) {
            throw $this->mapDownloadLinkFailure($linkResponse['status'], $data);
        }

        $remaining = is_int($data['remaining'] ?? null) ? $data['remaining'] : null;
        $link = is_string($data['link'] ?? null) && $data['link'] !== '' ? $data['link'] : null;

        if ($link === null) {
            if ($remaining === 0) {
                throw self::quotaExceededException($data);
            }

            throw new OpenSubtitlesException(
                'OpenSubtitles download response did not include a download link',
            );
        }

        $fileName = is_string($data['file_name'] ?? null)
            ? $data['file_name']
            : "subtitle.{$this->format}";

        try {
            $contentResponse = $this->httpRequest('GET', $link);
        } catch (OpenSubtitlesException $e) {
            $this->logger->error('OpenSubtitles download failed: ' . $e->getMessage());

            throw new OpenSubtitlesException('Download failed: ' . $e->getMessage(), 0, $e);
        }

        if ($contentResponse['status'] < 200 || $contentResponse['status'] >= 300) {
            $this->logger->error('OpenSubtitles download failed: HTTP ' . $contentResponse['status']);

            throw new OpenSubtitlesException('Download failed: HTTP ' . $contentResponse['status']);
        }

        return new SubtitleDownload(
            content: $contentResponse['body'],
            format: $this->format,
            fileName: $fileName,
            requestsUsed: is_int($data['requests'] ?? null) ? $data['requests'] : null,
            downloadsRemaining: $remaining,
            quotaMessage: is_string($data['message'] ?? null) ? $data['message'] : null,
            resetTime: is_string($data['reset_time'] ?? null) ? $data['reset_time'] : null,
            resetTimeUtc: is_string($data['reset_time_utc'] ?? null) ? $data['reset_time_utc'] : null,
        );
    }

    /**
     * Translate a 4xx failure from the step-1 `POST download` call into either a
     * quota-specific exception or the generic download-failure exception.
     *
     * @param int                  $statusCode The HTTP status of the step-1 response.
     * @param array<string, mixed> $data       Decoded step-1 response body.
     *
     * @return OpenSubtitlesException Ready to be thrown by the caller.
     *
     * @since 0.3.2
     */
    private function mapDownloadLinkFailure(int $statusCode, array $data): OpenSubtitlesException
    {
        $message = is_string($data['message'] ?? null) ? $data['message'] : null;

        if ($statusCode < 500 && $message !== null && self::looksLikeQuotaMessage($message)) {
            $this->logger->warning('OpenSubtitles download quota exceeded: ' . $message);

            return new OpenSubtitlesQuotaExceededException(
                $message,
                resetTime: is_string($data['reset_time'] ?? null) ? $data['reset_time'] : null,
                resetTimeUtc: is_string($data['reset_time_utc'] ?? null) ? $data['reset_time_utc'] : null,
            );
        }

        $detail = $message ?? ('HTTP ' . $statusCode);
        $this->logger->error('OpenSubtitles download failed: ' . $detail);

        return new OpenSubtitlesException('Download failed: ' . $detail);
    }

    /**
     * Build a quota-exceeded exception from a step-1 `POST download` response
     * body that reported `remaining: 0` with no `link`.
     *
     * @param array<string, mixed> $data Decoded step-1 response body.
     *
     * @return OpenSubtitlesQuotaExceededException
     *
     * @since 0.3.2
     */
    private static function quotaExceededException(array $data): OpenSubtitlesQuotaExceededException
    {
        $message = is_string($data['message'] ?? null) && $data['message'] !== ''
            ? $data['message']
            : 'OpenSubtitles download quota exceeded: no downloads remaining';

        return new OpenSubtitlesQuotaExceededException(
            $message,
            resetTime: is_string($data['reset_time'] ?? null) ? $data['reset_time'] : null,
            resetTimeUtc: is_string($data['reset_time_utc'] ?? null) ? $data['reset_time_utc'] : null,
        );
    }

    /**
     * Heuristically decide whether an API error message describes download
     * quota exhaustion.
     *
     * @param string $message API-provided error message text.
     *
     * @return bool True if the message looks like a quota-exceeded notice.
     *
     * @since 0.3.2
     */
    private static function looksLikeQuotaMessage(string $message): bool
    {
        return stripos($message, 'quota') !== false
            || stripos($message, 'download limit') !== false
            || (stripos($message, 'allowed') !== false && stripos($message, 'download') !== false);
    }

    /**
     * Perform a non-blocking HTTP request via `workerman/http-client`.
     *
     * Uses the canonical cooperative-wait pattern documented in phlix-server
     * `CLAUDE.md`: the request callbacks flip a shared `done` flag and the loop
     * yields (`usleep`) to the event loop until the response arrives or the
     * timeout elapses. A closure `$transport` seam short-circuits this for tests.
     *
     * Returns the status code and body for EVERY HTTP response (including 4xx /
     * 5xx) rather than throwing on error status — callers inspect the status.
     * Only genuine transport failures (connection/timeout) throw.
     *
     * @param string                $method  HTTP method.
     * @param string                $url     Absolute URL (or a path relative to API_BASE).
     * @param array<string, string> $headers Request headers.
     * @param string|null           $body    Raw request body (JSON), or null.
     *
     * @return array{status:int, body:string}
     *
     * @throws OpenSubtitlesException On transport/network failure.
     */
    private function httpRequest(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            $url = self::API_BASE . ltrim($url, '/');
        }

        $headers = array_merge($this->defaultHeaders(), $headers);

        if ($this->transport !== null) {
            return ($this->transport)($method, $url, $headers, $body);
        }

        $state = ['status' => 0, 'body' => '', 'error' => null, 'done' => false];

        $options = [
            'method' => $method,
            'headers' => $headers,
            'success' => function (Response $response) use (&$state): void {
                $state['status'] = $response->getStatusCode();
                $state['body'] = $response->getBody()->getContents();
                $state['done'] = true;
            },
            'error' => function (Throwable $e) use (&$state): void {
                $state['error'] = $e;
                $state['done'] = true;
            },
        ];

        if ($body !== null) {
            $options['data'] = $body;
        }

        $this->getHttpClient()->request($url, $options);

        // Cooperative wait — yields to the event loop (Swoole coroutine hooks
        // make usleep yield), allowing other tasks to proceed meanwhile.
        $maxWait = self::HTTP_TIMEOUT_SEC + 1.0;
        $waited = 0.0;
        while (!$state['done'] && $waited < $maxWait) {
            usleep(10_000); // 10ms
            $waited += 0.01;
        }

        if ($state['error'] instanceof Throwable) {
            throw new OpenSubtitlesException(
                'HTTP request failed: ' . $state['error']->getMessage(),
                0,
                $state['error'],
            );
        }

        if (!$state['done']) {
            throw new OpenSubtitlesException('HTTP request timed out after ' . self::HTTP_TIMEOUT_SEC . 's');
        }

        return ['status' => $state['status'], 'body' => $state['body']];
    }

    /**
     * Default headers sent on every request.
     *
     * @return array<string, string>
     */
    private function defaultHeaders(): array
    {
        return [
            'Api-Key' => $this->apiKey,
            'User-Agent' => 'Phlix-Plugin-OpenSubtitles/' . self::pluginVersion(),
            'Accept' => 'application/json',
        ];
    }

    /**
     * Bearer-authorization header when a session token is present.
     *
     * @return array<string, string>
     */
    private function authHeaders(): array
    {
        return $this->sessionToken !== null
            ? ['Authorization' => 'Bearer ' . $this->sessionToken]
            : [];
    }

    /**
     * Get or lazily create the shared non-blocking HTTP client.
     */
    private function getHttpClient(): Client
    {
        if ($this->httpClient === null) {
            $this->httpClient = new Client(['timeout' => self::HTTP_TIMEOUT_SEC]);
        }

        return $this->httpClient;
    }

    /**
     * Parse a filename to extract media information.
     *
     * @param string $filename The media filename to parse.
     *
     * @return array{imdb_id: ?string, hash: ?string, title: ?string, year: ?int}
     *
     * @since 0.1.0
     */
    private function parseFilename(string $filename): array
    {
        $result = [
            'imdb_id' => null,
            'hash' => null,
            'title' => null,
            'year' => null,
        ];

        if (preg_match('/(tt\d{7,8})/i', $filename, $matches)) {
            $result['imdb_id'] = strtolower($matches[1]);
        }

        if (preg_match('/^(.+?)[.\\s]+(\\d{4})/', basename($filename), $yearMatches)) {
            $result['title'] = trim($yearMatches[1], '.[\\s');
            $result['year'] = (int) $yearMatches[2];
        }

        return $result;
    }

    /**
     * Filter subtitles to prefer the requested format.
     *
     * The real OpenSubtitles v1 `/subtitles` search response is JSON:API shaped:
     * `id` is a STRING, file info is under `attributes.files` (an ARRAY, each
     * with `file_id`/`file_name`/`cd_number`), and `imdb_id` is nested under
     * `attributes.feature_details.imdb_id` as a JSON NUMBER — see
     * {@see normalizeImdbId()}. There is no per-file `format` attribute; the
     * only signal is the extension in `file_name` (see {@see formatFromFilename()}).
     *
     * Each result keeps every file in a `files` sub-array plus a top-level
     * `file_id` alias for the first file. Entries with no downloadable file are
     * dropped (there would be no `file_id` to pass to {@see download()}).
     *
     * @param array<array<string, mixed>> $subtitles       Raw subtitles from API.
     * @param string                      $preferredFormat Fallback format when the
     *        file name has no extension to derive one from.
     *
     * @return list<array{
     *     id: string,
     *     language: string,
     *     format: string,
     *     downloads: int,
     *     filename: string,
     *     imdb_id: ?string,
     *     file_id: int,
     *     files: list<array{file_id: int, file_name: ?string, cd_number: int}>
     * }>
     *
     * @since 0.1.0
     */
    private function filterSubtitles(array $subtitles, string $preferredFormat): array
    {
        $results = [];

        foreach ($subtitles as $subtitle) {
            /** @var array<string, mixed> */
            $attributes = is_array($subtitle['attributes'] ?? null) ? $subtitle['attributes'] : [];

            /** @var list<mixed> */
            $rawFiles = is_array($attributes['files'] ?? null) ? array_values($attributes['files']) : [];

            $files = [];
            foreach ($rawFiles as $rawFile) {
                if (!is_array($rawFile) || !is_int($rawFile['file_id'] ?? null)) {
                    continue;
                }

                $files[] = [
                    'file_id' => $rawFile['file_id'],
                    'file_name' => is_string($rawFile['file_name'] ?? null) ? $rawFile['file_name'] : null,
                    'cd_number' => is_int($rawFile['cd_number'] ?? null) ? $rawFile['cd_number'] : 1,
                ];
            }

            if ($files === []) {
                // No file_id to download with — this result would be a dead end.
                continue;
            }

            /** @var array<string, mixed> */
            $featureDetails = is_array($attributes['feature_details'] ?? null) ? $attributes['feature_details'] : [];

            $primaryFile = $files[0];
            $filename = $primaryFile['file_name'] ?? "subtitle.{$preferredFormat}";
            $format = self::formatFromFilename($primaryFile['file_name']) ?? $preferredFormat;
            $languageCode = is_string($attributes['language'] ?? null) ? $attributes['language'] : 'en';
            $downloadCount = is_int($attributes['download_count'] ?? null) ? $attributes['download_count'] : 0;
            $imdbId = self::normalizeImdbId($featureDetails['imdb_id'] ?? null);

            $idRaw = $subtitle['id'] ?? null;
            $subtitleId = match (true) {
                is_string($idRaw) && $idRaw !== '' => $idRaw,
                is_int($idRaw) => (string) $idRaw,
                default => '',
            };

            $results[] = [
                'id' => $subtitleId,
                'language' => $languageCode,
                'format' => $format,
                'downloads' => $downloadCount,
                'filename' => $filename,
                'imdb_id' => $imdbId,
                'file_id' => $primaryFile['file_id'],
                'files' => $files,
            ];
        }

        // Sort by download count descending
        usort($results, static fn (array $a, array $b): int => $b['downloads'] <=> $a['downloads']);

        return $results;
    }

    /**
     * Derive a lowercase subtitle format from a file's extension.
     *
     * @param string|null $filename Subtitle file name (e.g. "Movie.srt"), or null.
     *
     * @return string|null Lowercase extension without the leading dot, or null.
     *
     * @since 0.3.0
     */
    private static function formatFromFilename(?string $filename): ?string
    {
        if ($filename === null || $filename === '') {
            return null;
        }

        $extension = pathinfo($filename, PATHINFO_EXTENSION);

        return $extension !== '' ? strtolower($extension) : null;
    }

    /**
     * Normalize a raw `feature_details.imdb_id` value to this codebase's
     * `tt`-prefixed IMDB id string convention.
     *
     * The real API returns `imdb_id` as a JSON NUMBER (e.g. `133093` for
     * `tt0133093`); it is re-padded to at least 7 digits and `tt`-prefixed.
     *
     * @param mixed $rawImdbId Raw `feature_details.imdb_id` value from the API.
     *
     * @return string|null `tt`-prefixed IMDB id (e.g. "tt1234567"), or null.
     *
     * @since 0.3.1
     */
    private static function normalizeImdbId(mixed $rawImdbId): ?string
    {
        $number = match (true) {
            is_int($rawImdbId) => $rawImdbId,
            is_string($rawImdbId) && ctype_digit($rawImdbId) => (int) $rawImdbId,
            default => null,
        };

        if ($number === null || $number <= 0) {
            return null;
        }

        return 'tt' . str_pad((string) $number, 7, '0', STR_PAD_LEFT);
    }

    /**
     * Compute the OpenSubtitles `moviehash` for a media file.
     *
     * This is the standard OpenSubtitles hash (a.k.a. OSDb hash): a 64-bit
     * value formed by summing, modulo 2^64, the file size with every 8-byte
     * little-endian word of the first 64 KiB and the last 64 KiB of the file.
     * It is rendered as a 16-character, zero-padded, lowercase hex string.
     *
     * It is NOT a cryptographic digest — it deliberately ignores the middle of
     * the file so that two identical media files hash identically without
     * reading gigabytes. Reference & test vectors:
     * https://trac.opensubtitles.org/projects/opensubtitles/wiki/HashSourceCodes
     * (e.g. breakdance.avi, size 12909756 → `8e245d9679d31e12`).
     *
     * 64-bit unsigned arithmetic is done with split 32-bit halves so it is exact
     * on any platform and never promotes to float on overflow.
     *
     * @param string $filePath Absolute path to the media file.
     *
     * @return string 16-char lowercase hex `moviehash`, or '' when the file is
     *         missing, unreadable, or smaller than one 64 KiB chunk.
     *
     * @since 0.4.0
     */
    public static function computeMovieHash(string $filePath): string
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return '';
        }

        $size = filesize($filePath);
        if ($size === false || $size < self::HASH_CHUNK_SIZE) {
            return '';
        }

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            return '';
        }

        // Seed the accumulator with the file size, split into 32-bit halves.
        $low = $size & 0xFFFFFFFF;
        $high = ($size >> 32) & 0xFFFFFFFF;

        $firstChunk = fread($handle, self::HASH_CHUNK_SIZE);
        fseek($handle, max(0, $size - self::HASH_CHUNK_SIZE), SEEK_SET);
        $lastChunk = fread($handle, self::HASH_CHUNK_SIZE);
        fclose($handle);

        if (
            $firstChunk === false || $lastChunk === false
            || strlen($firstChunk) < self::HASH_CHUNK_SIZE
            || strlen($lastChunk) < self::HASH_CHUNK_SIZE
        ) {
            return '';
        }

        foreach ([$firstChunk, $lastChunk] as $chunk) {
            /** @var array<int, int> $words 1-indexed unsigned 32-bit little-endian words. */
            $words = unpack('V*', $chunk);
            $count = count($words);

            for ($i = 1; $i + 1 <= $count; $i += 2) {
                $low += $words[$i];
                $high += $words[$i + 1] + ($low >> 32);
                $low &= 0xFFFFFFFF;
                $high &= 0xFFFFFFFF;
            }
        }

        return sprintf('%08x%08x', $high, $low);
    }

    /**
     * Ensure the provider is enabled before making API calls.
     *
     * @return void
     *
     * @throws OpenSubtitlesException When the provider is not enabled.
     *
     * @since 0.1.0
     */
    private function ensureEnabled(): void
    {
        if (!$this->enabled) {
            throw new OpenSubtitlesException('OpenSubtitles provider is not enabled');
        }
    }

    /**
     * Check if the provider has an active session token.
     *
     * @return bool True if logged in, false otherwise.
     *
     * @since 0.1.0
     */
    public function isLoggedIn(): bool
    {
        return $this->sessionToken !== null;
    }

    /**
     * Get the configured language.
     *
     * @return string Language code.
     *
     * @since 0.1.0
     */
    public function getLanguage(): string
    {
        return $this->language;
    }

    /**
     * Get the configured format.
     *
     * @return string Format string.
     *
     * @since 0.1.0
     */
    public function getFormat(): string
    {
        return $this->format;
    }
}
