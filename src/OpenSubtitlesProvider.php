<?php

/**
 * OpenSubtitles subtitle provider plugin for Phlix.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\PluginOpenSubtitles;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Phlix\Shared\Plugin\ConfigurableInterface;
use Phlix\Shared\Plugin\LifecycleInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * OpenSubtitles subtitle provider for Phlix.
 *
 * This plugin integrates with the OpenSubtitles REST API v1 to search
 * and download subtitles for movies and TV shows. It supports search
 * by IMDB ID, filename, and file hash.
 *
 * ## Lifecycle notes
 *
 * - {@see onEnable()} initialises the HTTP client and optionally logs
 *   in to OpenSubtitles to obtain a session token.
 * - {@see subscribedEvents()} returns an empty array; subtitle lookups
 *   are triggered by the host's subtitle pipeline directly rather than
 *   via PSR-14 events.
 *
 * ## Provenance
 *
 * The OpenSubtitles API is documented at https://www.opensubtitles.com/.
 *
 * @package Phlix\PluginOpenSubtitles
 * @since 0.1.0
 */
final class OpenSubtitlesProvider implements LifecycleInterface, ConfigurableInterface
{
    /**
     * OpenSubtitles API base URL.
     *
     * MUST keep the trailing slash: Guzzle resolves relative request paths
     * against `base_uri` using RFC 3986 §5.3 reference resolution. A
     * leading-slash request path (e.g. `/login`) is treated as an
     * absolute-path reference and REPLACES the entire path component of
     * `base_uri` (dropping `/api/v1` and leaving only the scheme+host) —
     * that mismatch previously produced 404s such as
     * `https://api.opensubtitles.com/v2/uselogin`. With a trailing slash on
     * the base and no leading slash on request paths, RFC 3986 merge
     * resolution correctly appends the path (e.g. `/api/v1/login`).
     *
     * @see https://opensubtitles.stoplight.io/docs/opensubtitles-api/73acf79accc0a-login
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
     * OpenSubtitles API key (user agent token).
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
     * HTTP client for API requests.
     */
    private Client $httpClient;

    /**
     * Session token obtained via login.
     */
    private ?string $sessionToken = null;

    /**
     * Logger instance.
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Whether the provider has been enabled.
     */
    private bool $enabled = false;

    /**
     * @param string      $apiKey   OpenSubtitles API key.
     * @param string|null $username OpenSubtitles username (optional).
     * @param string|null $password OpenSubtitles password (optional).
     * @param string      $language Default language code (ISO 639-1).
     * @param string      $format   Preferred subtitle format.
     */
    public function __construct(
        string $apiKey = '',
        ?string $username = null,
        ?string $password = null,
        string $language = self::DEFAULT_LANGUAGE,
        string $format = self::DEFAULT_FORMAT,
    ) {
        // The constructor MUST stay autowirable — the host loader builds the
        // entry class through its PSR-11 container, which cannot guess a
        // required `string $apiKey`. So the key defaults to '' here and the real
        // settings arrive via configure() before onEnable().
        $this->apiKey = $apiKey;
        $this->username = $username;
        $this->password = $password;
        $this->language = $language;
        $this->format = $format;
        $this->rebuildHttpClient();
        $this->logger = new NullLogger();
    }

    /**
     * Receive the plugin's persisted settings from the host.
     *
     * Called once by the loader between construction and {@see onEnable()}, so
     * onEnable() authenticates with the configured API key/credentials.
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
        $this->rebuildHttpClient();
    }

    /**
     * (Re)build the API HTTP client so it carries the current `Api-Key` header.
     * Called from the constructor and whenever settings change via
     * {@see configure()}.
     */
    private function rebuildHttpClient(): void
    {
        $this->httpClient = new Client([
            'base_uri' => self::API_BASE,
            'timeout' => 30,
            'headers' => [
                'Api-Key' => $this->apiKey,
                'User-Agent' => 'Phlix-Plugin-OpenSubtitles/0.1.0',
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * Coerce a raw settings value to a non-empty string, or null.
     */
    private static function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Loader hook called once when the plugin is enabled.
     *
     * Authenticates with OpenSubtitles if credentials are configured.
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

        if ($this->username !== null && $this->password !== null) {
            $this->login();
        }

        $this->enabled = true;
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
        $this->enabled = false;
    }

    /**
     * Returns the PSR-14 listener subscriptions this plugin wants.
     *
     * This plugin is invoked directly by the host's subtitle pipeline
     * rather than via the event dispatcher, so it returns an empty
     * array.
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
     * Authenticate with OpenSubtitles and obtain a session token.
     *
     * @return void
     *
     * @throws OpenSubtitlesException When login fails.
     *
     * @since 0.1.0
     */
    private function login(): void
    {
        try {
            $response = $this->httpClient->post('login', [
                'json' => [
                    'username' => $this->username,
                    'password' => $this->password,
                ],
            ]);

            /** @var array<string, mixed> */
            $data = json_decode((string) $response->getBody(), true);

            if (isset($data['token']) && is_string($data['token'])) {
                $this->sessionToken = $data['token'];
                $this->logger->info('OpenSubtitles login successful');
            }
        } catch (GuzzleException $e) {
            $this->logger->warning('OpenSubtitles login failed: ' . $e->getMessage());
            throw new OpenSubtitlesException('Login failed: ' . $e->getMessage(), 0, $e);
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
     * }> Each result's `file_id` (and `files[].file_id`) can be passed straight into
     *    {@see download()}. See {@see filterSubtitles()} for the shape rationale.
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

        try {
            $queryParams = [
                'imdb_id' => $imdbId,
                'languages' => $language,
            ];

            $headers = [];
            if ($this->sessionToken !== null) {
                $headers['Authorization'] = 'Bearer ' . $this->sessionToken;
            }

            $response = $this->httpClient->get('subtitles', [
                'query' => $queryParams,
                'headers' => $headers,
            ]);

            /** @var array<string, mixed> */
            $data = json_decode((string) $response->getBody(), true);

            /** @var list<array<string, mixed>> */
            $subtitles = is_array($data['data'] ?? null) ? $data['data'] : [];

            return $this->filterSubtitles($subtitles, $subtitleFormat);
        } catch (GuzzleException $e) {
            $this->logger->error('OpenSubtitles search by IMDB ID failed: ' . $e->getMessage());
            throw new OpenSubtitlesException('Search by IMDB ID failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Search for subtitles matching the given filename.
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
     * }> Each result's `file_id` (and `files[].file_id`) can be passed straight into
     *    {@see download()}. See {@see filterSubtitles()} for the shape rationale.
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

        // Extract media info from filename and compute hash if possible
        $mediaInfo = $this->parseFilename($filename);

        try {
            $queryParams = [
                'languages' => $language,
            ];

            if ($mediaInfo['imdb_id'] !== null) {
                $queryParams['imdb_id'] = $mediaInfo['imdb_id'];
            }

            if ($mediaInfo['hash'] !== null) {
                $queryParams['hash'] = $mediaInfo['hash'];
            }

            if (isset($queryParams['imdb_id']) || isset($queryParams['hash'])) {
                $headers = [];
                if ($this->sessionToken !== null) {
                    $headers['Authorization'] = 'Bearer ' . $this->sessionToken;
                }

                $response = $this->httpClient->get('subtitles', [
                    'query' => $queryParams,
                    'headers' => $headers,
                ]);

                /** @var array<string, mixed> */
                $data = json_decode((string) $response->getBody(), true);

                /** @var list<array<string, mixed>> */
                $subtitles = is_array($data['data'] ?? null) ? $data['data'] : [];

                return $this->filterSubtitles($subtitles, $subtitleFormat);
            }

            return [];
        } catch (GuzzleException $e) {
            $this->logger->error('OpenSubtitles search by filename failed: ' . $e->getMessage());
            throw new OpenSubtitlesException('Search by filename failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Search for subtitles matching the given file hash.
     *
     * @param string $hash           OpenSubtitles hash (first 64KB + last 64KB of file).
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
     * }> Each result's `file_id` (and `files[].file_id`) can be passed straight into
     *    {@see download()}. See {@see filterSubtitles()} for the shape rationale.
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

        try {
            $queryParams = [
                'hash' => $hash,
                'languages' => $language,
            ];

            $headers = [];
            if ($this->sessionToken !== null) {
                $headers['Authorization'] = 'Bearer ' . $this->sessionToken;
            }

            $response = $this->httpClient->get('subtitles', [
                'query' => $queryParams,
                'headers' => $headers,
            ]);

            /** @var array<string, mixed> */
            $data = json_decode((string) $response->getBody(), true);

            /** @var list<array<string, mixed>> */
            $subtitles = is_array($data['data'] ?? null) ? $data['data'] : [];

            return $this->filterSubtitles($subtitles, $subtitleFormat);
        } catch (GuzzleException $e) {
            $this->logger->error('OpenSubtitles search by hash failed: ' . $e->getMessage());
            throw new OpenSubtitlesException('Search by hash failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Download a subtitle file.
     *
     * The real OpenSubtitles v1 REST API is a two-step flow — it does NOT return
     * subtitle content directly:
     *
     * 1. `POST download` with a `file_id` JSON body returns a JSON envelope
     *    containing a temporary `link` URL plus download-quota accounting
     *    (`requests`, `remaining`, `message`, `reset_time`, `reset_time_utc`).
     *    This call itself counts against the account's download quota, so it
     *    must not be retried speculatively.
     * 2. The actual subtitle file bytes are then fetched with a plain `GET`
     *    against that `link` URL (a different host, not `API_BASE`).
     *
     * Quota exhaustion is surfaced distinctly (as a
     * {@see OpenSubtitlesQuotaExceededException}, a subtype of
     * {@see OpenSubtitlesException} so existing catch sites keep working)
     * rather than as a generic failure, in two ways — confirmed against real
     * reports (e.g. https://github.com/morpheus65535/bazarr/issues/2153) since
     * the OpenAPI spec itself does not document an explicit quota-exceeded
     * error shape:
     *
     * - The step-1 `POST` can itself fail with a 4xx status whose JSON body is
     *   just `{"message": "You have downloaded your allowed N subtitles for
     *   24h. Your quota will be renewed in ..."}` — this is the shape actually
     *   observed in production. {@see mapDownloadLinkFailure()} inspects that
     *   body and re-throws a quota-specific exception when the message text
     *   looks like a quota notice, rather than the generic
     *   `'Download failed: ' . $e->getMessage()` every other failure gets.
     * - Defensively, if the step-1 call instead returns HTTP 200 with
     *   `remaining: 0` and no `link` at all, that is also treated as quota
     *   exhaustion rather than the generic "missing download link" error.
     *
     * @see https://opensubtitles.stoplight.io/docs/opensubtitles-api/6be7f6ae2d918-download
     *
     * @param int $fileId OpenSubtitles file ID (`attributes.files[].file_id` from a
     *        `/subtitles` search result — NOT the top-level subtitle `id`).
     *
     * @return SubtitleDownload
     *
     * @throws OpenSubtitlesQuotaExceededException When the account's download quota
     *         is exhausted.
     * @throws OpenSubtitlesException When the download otherwise fails, or the API
     *         response is missing the `link` needed to fetch the file content.
     *
     * @since 0.1.0
     */
    public function download(int $fileId): SubtitleDownload
    {
        $this->ensureEnabled();

        $headers = [
            'Accept' => 'application/json',
        ];

        if ($this->sessionToken !== null) {
            $headers['Authorization'] = 'Bearer ' . $this->sessionToken;
        }

        $body = ['file_id' => $fileId];
        if ($this->format !== '') {
            $body['sub_format'] = $this->format;
        }

        try {
            $linkResponse = $this->httpClient->post('download', [
                'headers' => $headers,
                'json' => $body,
            ]);
        } catch (GuzzleException $e) {
            throw $this->mapDownloadLinkFailure($e);
        }

        /** @var array<string, mixed> */
        $data = json_decode((string) $linkResponse->getBody(), true);

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
            $contentResponse = $this->httpClient->get($link);
        } catch (GuzzleException $e) {
            $this->logger->error('OpenSubtitles download failed: ' . $e->getMessage());
            throw new OpenSubtitlesException('Download failed: ' . $e->getMessage(), 0, $e);
        }

        $content = (string) $contentResponse->getBody();

        return new SubtitleDownload(
            content: $content,
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
     * Translate a failure from the step-1 `POST download` call into either a
     * quota-specific exception or the generic download-failure exception.
     *
     * The real API's documented quota-exceeded shape (see {@see download()}'s
     * docblock) is an HTTP 4xx response whose JSON body is just a `message`
     * string — there is no dedicated error code to switch on, so the message
     * text itself is inspected via {@see looksLikeQuotaMessage()}.
     *
     * @param GuzzleException $e The exception thrown by the step-1 POST call.
     *
     * @return OpenSubtitlesException Ready to be thrown by the caller.
     *
     * @since 0.3.2
     */
    private function mapDownloadLinkFailure(GuzzleException $e): OpenSubtitlesException
    {
        $response = $e instanceof RequestException ? $e->getResponse() : null;

        if ($response !== null) {
            $statusCode = $response->getStatusCode();

            if ($statusCode >= 400 && $statusCode < 500) {
                $decoded = json_decode((string) $response->getBody(), true);
                $responseData = is_array($decoded) ? $decoded : [];
                $message = is_string($responseData['message'] ?? null) ? $responseData['message'] : null;

                if ($message !== null && self::looksLikeQuotaMessage($message)) {
                    $this->logger->warning('OpenSubtitles download quota exceeded: ' . $message);

                    return new OpenSubtitlesQuotaExceededException(
                        $message,
                        resetTime: is_string($responseData['reset_time'] ?? null)
                            ? $responseData['reset_time']
                            : null,
                        resetTimeUtc: is_string($responseData['reset_time_utc'] ?? null)
                            ? $responseData['reset_time_utc']
                            : null,
                    );
                }
            }
        }

        $this->logger->error('OpenSubtitles download failed: ' . $e->getMessage());

        return new OpenSubtitlesException('Download failed: ' . $e->getMessage(), 0, $e);
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
     * The OpenSubtitles v1 API has no dedicated error code for this (see
     * {@see download()}'s docblock), only a free-text `message` — observed in
     * production as e.g. "You have downloaded your allowed 20 subtitles for
     * 24h.Your quota will be renewed in ...". Matching loosely on
     * "quota"/"download limit" (mirroring the wording the
     * `dusking/opensubtitles-com` reference client itself uses: "Download
     * limit reached") avoids mis-detecting an unrelated 4xx (e.g. a stale
     * token) as quota exhaustion while still catching the real message.
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
     * Parse a filename to extract media information.
     *
     * Attempts to extract IMDB ID from common naming patterns.
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

        // Try to match IMDB ID patterns like:
        // - Movie.Name.2019.1080p.BluRay.x264-RARBG (no imdb)
        // - Movie.Name.2019.1080p.BluRay.x264-RARBG.nfo (may have .nfo)
        // - tt1234567 (direct IMDB ID format)
        // We don't compute hash from here - that requires actual file access

        // Match direct IMDB ID
        if (preg_match('/(tt\d{7,8})/i', $filename, $matches)) {
            $result['imdb_id'] = strtoupper($matches[1]);
        }

        // Extract title and year if possible
        if (preg_match('/^(.+?)[.\\s]+(\\d{4})/', basename($filename), $yearMatches)) {
            $result['title'] = trim($yearMatches[1], '.[\\s');
            $result['year'] = (int) $yearMatches[2];
        }

        return $result;
    }

    /**
     * Filter subtitles to prefer the requested format.
     *
     * The real OpenSubtitles v1 `/subtitles` search response is JSON:API shaped.
     * Independently re-verified (not just trusting the prior investigation) against
     * https://github.com/dusking/opensubtitles-com (`src/opensubtitlescom/responses.py`,
     * the `Subtitle` class), https://apidog.com/blog/opensubtitles-api/, and the
     * `odwrtw/opensubtitles` Go client's `File` struct — all three agree on the shape:
     *
     * ```json
     * {
     *   "id": "3634077",
     *   "type": "subtitle",
     *   "attributes": {
     *     "language": "en",
     *     "download_count": 5000,
     *     "feature_details": { "imdb_id": 1234567 },
     *     "files": [
     *       { "file_id": 998877, "cd_number": 1, "file_name": "Movie.Name.2019.srt" }
     *     ]
     *   }
     * }
     * ```
     *
     * This fixes three latent shape mismatches, all in this one method:
     *
     * 1. `attributes.file` (singular) does not exist — the real key is
     *    `attributes.files` (plural), an ARRAY. A single subtitle listing can carry
     *    multiple files (e.g. one file per CD for old multi-CD releases), each with
     *    its own `file_id`. The old code always saw an empty `$fileInfo` and silently
     *    fabricated a generic filename/format for every result.
     * 2. `download()` requires a `file_id` (see its docblock), which was never
     *    extracted at all — nothing wired a real file_id from search into download,
     *    so the search→download pipeline was dead end-to-end for every caller.
     * 3. `id` is a JSON:API resource id: always a STRING (confirmed via the Go
     *    client's `ID string \`json:"id"\`` and the apidog example `"id": "1234567"`),
     *    never an int — the old `is_int($subtitle['id'])` check was always false
     *    against the real API, so every result's `id` silently collapsed to `0`.
     *    `imdb_id` is nested under `attributes.feature_details.imdb_id`, not
     *    `attributes.imdb_id` directly — the old flat read always saw `null`.
     * 4. `feature_details.imdb_id` is a JSON NUMBER (confirmed against the official
     *    OpenSubtitles OpenAPI spec's `"imdb_id": {"type": "number"}`, the
     *    `odwrtw/opensubtitles` Go client's `FeatureDetails.ImdbID int`, and the
     *    official `opensubtitles/vlsub-opensubtitles-com` VLC plugin, which wraps it
     *    in `tostring(details.imdb_id or "")` before display) — never a string. The
     *    path fix in point 3 above still used `is_string()`, so `imdb_id` remained
     *    `null` for every real response even after the path was corrected. See
     *    {@see normalizeImdbId()}, which accepts the real numeric shape and
     *    normalizes it to this codebase's `tt`-prefixed string convention (the shape
     *    used everywhere else in phlix-server, e.g. `ImdbLookup`, `TmdbProvider`,
     *    `MovieMetadataResolver`).
     *
     * There is no per-subtitle or per-file `format` attribute in the real API at
     * all (verified absent from every reference client above); the only reliable
     * signal is the file extension embedded in `file_name`, via
     * {@see formatFromFilename()}.
     *
     * Multi-file (multi-CD) handling: rather than silently taking `files[0]` and
     * discarding the rest (which would misrepresent a multi-part release as a
     * single downloadable file) or exploding one API result into N synthetic
     * search results (which would duplicate language/downloads/id across entries
     * for what is really one release), each result keeps a `files` sub-array with
     * every file's `file_id`/`file_name`/`cd_number`, plus a top-level `file_id`
     * convenience alias for the first file — so a caller that only wants "the"
     * file can use `file_id` directly, while a caller that needs to fetch every
     * CD can iterate `files`.
     *
     * A subtitle entry with no usable file entries can never be downloaded (there
     * is no `file_id` to pass to {@see download()}), so it is dropped rather than
     * surfaced with a fake sentinel `file_id`.
     *
     * @param array<array<string, mixed>> $subtitles       Raw subtitles from API.
     * @param string                      $preferredFormat Preferred format, used as a
     *        fallback when the file name has no extension to derive one from.
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
     * The OpenSubtitles v1 search response has no per-subtitle or per-file
     * `format` attribute (independently verified absent from the reference
     * clients cited on {@see filterSubtitles()}) — the file extension embedded in
     * `attributes.files[].file_name` is the only signal available.
     *
     * @param string|null $filename Subtitle file name (e.g. "Movie.srt"), or null.
     *
     * @return string|null Lowercase extension without the leading dot, or null if
     *         the filename is null/empty or has no extension.
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
     * The real OpenSubtitles v1 API returns `imdb_id` as a JSON NUMBER (e.g.
     * `1234567`), confirmed against the official OpenAPI spec
     * (`"imdb_id": {"type": "number"}`), the `odwrtw/opensubtitles` Go client
     * (`FeatureDetails.ImdbID int`), and the official
     * `opensubtitles/vlsub-opensubtitles-com` VLC plugin (which itself has to
     * `tostring()` the value before display). A prior fix corrected the JSON
     * *path* to `feature_details.imdb_id` but kept an `is_string()` check, so
     * the field silently stayed `null` for every real (numeric) response.
     *
     * The bare number has no `tt` prefix and drops any leading zeros (e.g.
     * `133093` for `tt0133093`), so it is re-padded to at least 7 digits and
     * prefixed here to match the `tt\d{7,}` shape used everywhere else in
     * Phlix (see `phlix-server`'s `ImdbLookup`, `TmdbProvider`,
     * `MovieMetadataResolver`, etc. — all represent IMDB ids as a `tt`-prefixed
     * string, never a bare number).
     *
     * A numeric string is also accepted, in case some API responses vary from
     * the documented number type; anything else (missing, non-numeric, zero,
     * or negative) is treated as "no IMDB id".
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
     * Compute the OpenSubtitles hash for a file.
     *
     * The hash is computed from the first 64KB and last 64KB of the file,
     * combined with the file size.
     *
     * @param string $filePath Absolute path to the media file.
     *
     * @return string Hex-encoded hash string, or empty string on error.
     *
     * @since 0.1.0
     */
    public static function computeHash(string $filePath): string
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return '';
        }

        $size = filesize($filePath);
        if ($size === false || $size <= 0) {
            return '';
        }

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            return '';
        }

        // Read first 64KB
        $firstChunk = fread($handle, 65536);
        fseek($handle, max(0, $size - 65536));

        // Read last 64KB
        $lastChunk = fread($handle, 65536);
        fclose($handle);

        if ($firstChunk === false || $lastChunk === false) {
            return '';
        }

        // Compute hash: size + first_chunk + last_chunk
        $hash = hash('sha256', $size . $firstChunk . $lastChunk, true);

        return bin2hex($hash);
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
