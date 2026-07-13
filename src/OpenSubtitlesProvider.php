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
     * @return list<array{id: int, language: string, format: string, downloads: int, filename: string}>
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
     * @return list<array{id: int, language: string, format: string, downloads: int, filename: string}>
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
     * @return list<array{id: int, language: string, format: string, downloads: int, filename: string}>
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
     * @see https://opensubtitles.stoplight.io/docs/opensubtitles-api/6be7f6ae2d918-download
     *
     * @param int $fileId OpenSubtitles file ID (`attributes.files[].file_id` from a
     *        `/subtitles` search result — NOT the top-level subtitle `id`).
     *
     * @return SubtitleDownload
     *
     * @throws OpenSubtitlesException When the download fails, or the API response
     *         is missing the `link` needed to fetch the file content.
     *
     * @since 0.1.0
     */
    public function download(int $fileId): SubtitleDownload
    {
        $this->ensureEnabled();

        try {
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

            $linkResponse = $this->httpClient->post('download', [
                'headers' => $headers,
                'json' => $body,
            ]);

            /** @var array<string, mixed> */
            $data = json_decode((string) $linkResponse->getBody(), true);

            $link = is_string($data['link'] ?? null) && $data['link'] !== '' ? $data['link'] : null;
            if ($link === null) {
                throw new OpenSubtitlesException(
                    'OpenSubtitles download response did not include a download link',
                );
            }

            $fileName = is_string($data['file_name'] ?? null)
                ? $data['file_name']
                : "subtitle.{$this->format}";

            $contentResponse = $this->httpClient->get($link);
            $content = (string) $contentResponse->getBody();

            return new SubtitleDownload(
                content: $content,
                format: $this->format,
                fileName: $fileName,
                requestsUsed: is_int($data['requests'] ?? null) ? $data['requests'] : null,
                downloadsRemaining: is_int($data['remaining'] ?? null) ? $data['remaining'] : null,
                quotaMessage: is_string($data['message'] ?? null) ? $data['message'] : null,
                resetTime: is_string($data['reset_time'] ?? null) ? $data['reset_time'] : null,
                resetTimeUtc: is_string($data['reset_time_utc'] ?? null) ? $data['reset_time_utc'] : null,
            );
        } catch (GuzzleException $e) {
            $this->logger->error('OpenSubtitles download failed: ' . $e->getMessage());
            throw new OpenSubtitlesException('Download failed: ' . $e->getMessage(), 0, $e);
        }
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
     * @param array<array<string, mixed>> $subtitles       Raw subtitles from API.
     * @param string                      $preferredFormat Preferred format.
     *
     * @return list<array{id: int, language: string, format: string, downloads: int, filename: string}>
     *
     * @since 0.1.0
     */
    private function filterSubtitles(array $subtitles, string $preferredFormat): array
    {
        $results = [];

        foreach ($subtitles as $subtitle) {
            /** @var array<string, mixed> */
            $attributes = is_array($subtitle['attributes'] ?? null) ? $subtitle['attributes'] : [];

            /** @var array<string, mixed> */
            $fileInfo = is_array($attributes['file'] ?? null) ? $attributes['file'] : [];

            $format = is_string($fileInfo['format'] ?? null) ? $fileInfo['format'] : 'srt';
            $languageCode = is_string($attributes['language'] ?? null) ? $attributes['language'] : 'en';
            $downloadCount = is_int($attributes['download_count'] ?? null) ? $attributes['download_count'] : 0;
            $filename = is_string($fileInfo['filename'] ?? null) ? $fileInfo['filename'] : "subtitle.{$format}";
            $imdbId = is_string($attributes['imdb_id'] ?? null) ? $attributes['imdb_id'] : null;
            $subtitleId = is_int($subtitle['id'] ?? null) ? $subtitle['id'] : 0;

            $results[] = [
                'id' => $subtitleId,
                'language' => $languageCode,
                'format' => $format,
                'downloads' => $downloadCount,
                'filename' => $filename,
                'imdb_id' => $imdbId,
            ];
        }

        // Sort by download count descending
        usort($results, static fn (array $a, array $b): int => $b['downloads'] <=> $a['downloads']);

        return $results;
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
