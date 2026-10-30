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
use Phlix\Shared\Subtitle\Exception\QuotaExceeded;
use Phlix\Shared\Subtitle\SubtitleCandidate;
use Phlix\Shared\Subtitle\SubtitleFile;
use Phlix\Shared\Subtitle\SubtitleSourceInterface;
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
 *   API call (search/download) logs in to obtain a session token; never at
 *   boot. Afterwards the token lives until its advertised `expires_in`
 *   deadline passes or the server revokes it (an HTTP 401 on a business call
 *   triggers exactly one refresh-and-retry), and a confirmed credential
 *   rejection suppresses further attempts for the enable cycle. Configured
 *   without username/password the provider stays anonymous — the consumer
 *   key alone works search at lower download limits.
 *
 * ## Non-blocking HTTP
 *
 * All HTTP goes through {@see httpRequest()}, which uses the non-blocking
 * `workerman/http-client` with the canonical cooperative-wait pattern (see
 * phlix-server `CLAUDE.md`). Every connection is dialed with an explicit
 * strict TLS verification context ({@see strictTlsContext()}); the vendored
 * client's verification-off defaults are overridden, never inherited. A
 * closure `$transport` seam lets tests exercise the request/response shaping
 * without an event loop or the network.
 *
 * ## Download quota
 *
 * OpenSubtitles enforces a per-account daily download quota, so fetches are
 * strictly on-demand — this plugin never bulk-downloads or loops over results.
 * Quota exhaustion surfaces at two distinct boundaries: the raw public API
 * ({@see downloadRaw()}) throws {@see OpenSubtitlesQuotaExceededException},
 * while the shared-contract entry {@see download()} translates it to
 * {@see \Phlix\Shared\Subtitle\Exception\QuotaExceeded} (which extends
 * `RuntimeException`, NOT `OpenSubtitlesException`) carrying the remaining
 * allowance and reset time — pipeline callers catch the shared type. Once an
 * exhausted window has been reported by the API with a concrete future
 * reset, further raw/contract downloads fail fast locally with the same
 * boundary types until that instant — no network re-dial into a window the
 * vendor declared dead.
 *
 * ## Dispatch
 *
 * {@see subscribedEvents()} returns an empty array; subtitle lookups are driven
 * by the host's subtitle pipeline (Wave 3 `SubtitleSourceInterface`) rather
 * than via PSR-14 events.
 *
 * ## Shared subtitle-source contract (Wave 3 F3)
 *
 * This entry class implements {@see SubtitleSourceInterface} (phlix-shared
 * v0.42.0) directly, so the host's subtitle-source registry detects it on the
 * entry instance (mirroring how metadata plugins carry
 * {@see \Phlix\Shared\Metadata\MetadataSourceInterface}) and can register /
 * deregister it on plugin enable/disable. The contract methods ({@see getName()},
 * {@see getPriority()}, {@see searchByPath()}, {@see searchByHash()},
 * {@see searchByImdbId()}, {@see download()}) are the host-facing façade; they
 * delegate to the low-level `*Raw` wire methods and map the OpenSubtitles v1
 * JSON:API responses into shared {@see SubtitleCandidate} / {@see SubtitleFile}
 * value objects, translating {@see OpenSubtitlesQuotaExceededException} into the
 * shared {@see QuotaExceeded}.
 *
 * @package Phlix\PluginOpenSubtitles
 * @since 0.1.0
 */
final class OpenSubtitlesProvider implements LifecycleInterface, ConfigurableInterface, SubtitleSourceInterface
{
    /**
     * Canonical, stable source name — the identity the host keys this source on
     * and the value carried in every {@see SubtitleCandidate::$provider}. It must
     * match the name used in the host `subtitles.provider_priority` ordering.
     */
    private const SOURCE_NAME = 'opensubtitles';

    /**
     * Default ranking weight when the admin has not configured one. Lower runs
     * first (0 = highest priority), mirroring middleware-style weights.
     */
    private const DEFAULT_PRIORITY = 10;

    /**
     * API host the plugin's consumer credentials belong to. Used to build
     * {@see API_BASE} and to scope credential headers to their origin.
     */
    private const API_HOST = 'api.opensubtitles.com';

    /**
     * OpenSubtitles API base URL. Keeps a trailing slash; request paths are
     * appended without a leading slash so the `/api/v1` prefix is preserved.
     */
    private const API_BASE = 'https://' . self::API_HOST . '/api/v1/';

    /**
     * Default language code when not configured.
     */
    private const DEFAULT_LANGUAGE = 'en';

    /**
     * Exact aliases from estate BCP-47-style codes to the OpenSubtitles
     * language domain. OpenSubtitles filters by its own set (ISO 639-1 plus
     * legacy specials such as `pb` for Brazilian Portuguese); a raw `pt-BR`
     * filter silently matches nothing there.
     *
     * @var array<string, string>
     */
    private const LANGUAGE_ALIASES = ['pt-BR' => 'pb'];

    /**
     * Preferred subtitle format when not configured.
     */
    private const DEFAULT_FORMAT = 'srt';

    /**
     * HTTP request timeout in seconds.
     */
    private const HTTP_TIMEOUT_SEC = 30;

    /**
     * Hard cap (bytes) on any single HTTP response body — search/login/link
     * JSON and downloaded subtitle content alike.
     *
     * Realistic subtitle files range from sub-MiB SRT to a few MiB of
     * style-heavy ASS; 10 MiB sits an order of magnitude above legitimate
     * payloads and far below what threatens a resident worker's memory.
     * An over-cap response fails loud as {@see OpenSubtitlesException} —
     * never truncated, never delivered as a partial or empty "success".
     * On the real transport the `progress` guard in {@see httpRequest()}
     * aborts the stream the moment the cap is crossed, so the oversized body
     * is never fully buffered by the plugin (the vendored workerman client
     * itself has no size option; the throw rides its error path and closes
     * the connection).
     */
    private const MAX_RESPONSE_BYTES = 10_485_760;

    /**
     * Chunk size (bytes) read from the head and tail of a media file for the
     * OpenSubtitles `moviehash` — 64 KiB, per the algorithm specification.
     */
    private const HASH_CHUNK_SIZE = 65536;

    /**
     * Contact identity appended to the outgoing User-Agent.
     *
     * OpenSubtitles' API terms require a descriptive User-Agent that carries a
     * contact route for third-party integrations; non-descriptive or
     * contact-less agents are rate-limited and can get the consumer key — and
     * with it every Phlix instance sharing the build — banned. The project
     * repository (issue tracker reachable by the vendor) is the stable
     * contact route; forks and derivatives MUST replace it with their own
     * (see the README "User-Agent & ToS" section).
     */
    private const USER_AGENT_CONTACT_URL = 'https://github.com/detain/phlix-plugin-opensubtitles';

    /**
     * Seconds subtracted from a login-advertised `expires_in` when computing
     * the local token deadline, so a token is refreshed slightly BEFORE the
     * server would start rejecting it (clock drift + in-flight requests).
     */
    private const TOKEN_EXPIRY_SAFETY_SEC = 60;

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
     * Ranking priority of this source relative to its peers (lower runs first).
     */
    private int $priority;

    /**
     * Shared non-blocking HTTP clients, created lazily on first real request,
     * keyed by target host. One client per host because the vendored
     * workerman/http-client pool merges client-level ssl options wholesale —
     * a per-host context is the only way to pin strict verification plus the
     * right `peer_name`/SNI identity for every host this plugin dials
     * (see {@see httpClientFor()}).
     *
     * @var array<string,Client>
     */
    private array $httpClients = [];

    /**
     * Test seam replacing the network call. Signature:
     * `fn(string $method, string $url, array<string,string> $headers, ?string $body)`
     * returning
     * `array{status:int, body:string, headers?:array<string,string|list<string>>}`.
     * Left null in production so the real non-blocking Workerman client is used.
     *
     * @var (\Closure(
     *     string,
     *     string,
     *     array<string, string>,
     *     ?string
     * ): array{status: int, body: string, headers?: array<string, string|list<string>>})|null
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
     * Outcome-classified: a confirmed credential rejection stays latched, a
     * transient failure unlatches for a retry on the next call.
     */
    private bool $loginAttempted = false;

    /**
     * Unix timestamp after which {@see $sessionToken} must be treated as dead
     * (derived from the login response's `expires_in` minus the safety
     * margin), or null when the response advertised no usable deadline.
     */
    private ?int $tokenExpiresAt = null;

    /**
     * True while a login round-trip is being pumped, so concurrent callers
     * wait for its outcome instead of firing a parallel second login.
     */
    private bool $loginInFlight = false;

    /**
     * Unix timestamp until which step-1 download POSTs are REFUSED LOCALLY
     * (no network) because the API reported `remaining: 0` with a concrete
     * future `reset_time_utc` — the orchestrator's multi-file loop must not
     * storm an already-exhausted quota window.
     */
    private ?int $quotaBlockedUntil = null;

    /**
     * Unix timestamp until which API calls are refused locally because a
     * 429 carried a parsable `Retry-After`.
     */
    private ?int $rateLimitedUntil = null;

    /**
     * Quota-window context remembered for the local refusal so the surfaced
     * exception keeps the API's own fields ({@see OpenSubtitlesQuotaExceededException}).
     */
    private ?string $quotaMessage = null;

    private ?string $quotaResetTime = null;

    private ?string $quotaResetTimeUtc = null;

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
     * @param int         $priority  Ranking weight; lower runs first (0 = highest).
     * @param (\Closure(string, string, array<string,string>, ?string): array{status:int, body:string})|null $transport
     *        Test seam replacing the network call; null in production.
     */
    public function __construct(
        string $apiKey = '',
        ?string $username = null,
        ?string $password = null,
        string $language = self::DEFAULT_LANGUAGE,
        string $format = self::DEFAULT_FORMAT,
        int $priority = self::DEFAULT_PRIORITY,
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
        $this->priority = $priority;
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
     *        `api_key`, `username`, `password`, `language`, `format`, `priority`).
     *
     * @return void
     *
     * @since 0.2.0
     */
    public function configure(array $settings): void
    {
        $apiKey = $settings['api_key'] ?? null;
        $this->apiKey = is_string($apiKey) ? $apiKey : '';

        $this->username = self::nonEmptyString($settings['username'] ?? null);
        $this->password = self::nonEmptyString($settings['password'] ?? null);

        $language = $settings['language'] ?? null;
        if (is_string($language) && $language !== '') {
            $this->language = $language;
        } else {
            $this->language = self::DEFAULT_LANGUAGE;
        }

        $format = $settings['format'] ?? null;
        if (is_string($format) && $format !== '') {
            $this->format = $format;
        } else {
            $this->format = self::DEFAULT_FORMAT;
        }

        $this->priority = self::coercePriority($settings['priority'] ?? null);

        // Credentials may have changed — drop the session and any remembered
        // quota/rate windows, and allow a fresh deferred login on next use.
        $this->resetTransientState();
    }

    /**
     * Clear all per-session runtime state: the auth lifecycle (token, latch,
     * expiry, in-flight) and the remembered budget windows. Called from
     * {@see configure()}, {@see onEnable()} and {@see onDisable()} so nothing
     * survives a reconfiguration or an enable cycle boundary.
     *
     * @return void
     */
    private function resetTransientState(): void
    {
        $this->sessionToken = null;
        $this->loginAttempted = false;
        $this->tokenExpiresAt = null;
        $this->loginInFlight = false;
        $this->quotaBlockedUntil = null;
        $this->rateLimitedUntil = null;
        $this->quotaMessage = null;
        $this->quotaResetTime = null;
        $this->quotaResetTimeUtc = null;
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
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
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
        $this->resetTransientState();
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
        $this->resetTransientState();
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
     * The deferred login runs at most once at a time (guarded by
     * {@see $loginInFlight}) and its latch is outcome-classified: a confirmed
     * credential rejection (4xx except 429) stays latched for the rest of the
     * enable cycle, while transient failures (transport errors, 429, 5xx)
     * unlatch so the next call retries — throttled to one login attempt per
     * business call. A session token whose advertised `expires_in` deadline
     * has passed is invalidated and refreshed on next use; callers arriving
     * while a login round-trip is still pumping wait for its outcome instead
     * of firing a parallel second login.
     *
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
        if ($this->loginInFlight) {
            $this->awaitLoginInFlight();

            return;
        }

        if ($this->loginAttempted) {
            if ($this->sessionToken === null || !$this->sessionTokenIsExpired()) {
                return;
            }

            // Token carried by the server as dead — refresh it.
            $this->invalidateSession();
        }

        $this->loginAttempted = true;

        if ($this->username === null || $this->password === null) {
            return;
        }

        $this->loginInFlight = true;

        try {
            $this->login();
        } finally {
            $this->loginInFlight = false;
        }
    }

    /**
     * Bounded cooperative wait while another call's login is pumping (the
     * event loop only settles the pending login while we yield).
     *
     * Mirrors the wait idiom in {@see httpRequest()}. On timeout the caller
     * proceeds without a token — login is optional by contract, and looping
     * forever is never an option in a resident worker.
     *
     * @return void
     */
    private function awaitLoginInFlight(): void
    {
        $maxWait = self::HTTP_TIMEOUT_SEC + 1.0;
        $waited = 0.0;

        while ($this->loginInFlight && $waited < $maxWait) {
            usleep(10_000); // 10ms
            $waited += 0.01;
        }

        if ($this->loginInFlight) {
            $this->logger->warning(
                'OpenSubtitles login did not settle within the bounded wait; proceeding without a session token',
            );
        }
    }

    /**
     * Whether the captured session token is past its local expiry deadline.
     */
    private function sessionTokenIsExpired(): bool
    {
        return $this->tokenExpiresAt !== null && time() >= $this->tokenExpiresAt;
    }

    /**
     * Drop the session so the next {@see ensureConnected()} re-authenticates.
     *
     * @return void
     */
    private function invalidateSession(): void
    {
        $this->sessionToken = null;
        $this->tokenExpiresAt = null;
        $this->loginAttempted = false;
    }

    /**
     * Forced re-auth after the API rejected our session token (HTTP 401 on a
     * business call). Only meaningful when a token WAS sent — anonymous 401s
     * (missing key etc.) have nothing to refresh.
     *
     * @return bool Whether a fresh session token is now available.
     */
    private function attemptReauthAfter401(): bool
    {
        if ($this->sessionToken === null) {
            return false;
        }

        $this->logger->info('OpenSubtitles rejected the session token (HTTP 401); attempting one re-authentication');
        $this->invalidateSession();
        $this->ensureConnected();

        return $this->sessionToken !== null;
    }

    /**
     * Local expiry deadline for a freshly obtained token: `expires_in`
     * seconds minus the {@see TOKEN_EXPIRY_SAFETY_SEC} margin. A missing,
     * non-numeric, negative, or already-insane value yields null (no local
     * deadline — the token simply lives until the enable cycle resets it),
     * and a remaining lifetime at or below the margin yields "now" so the
     * very next call refreshes.
     */
    private static function tokenDeadlineFrom(mixed $expiresIn): ?int
    {
        $seconds = is_int($expiresIn) ? $expiresIn : null;

        if ($seconds === null && is_string($expiresIn) && is_numeric($expiresIn)) {
            $seconds = (int) $expiresIn;
        }

        if ($seconds === null || $seconds < 0) {
            return null;
        }

        if ($seconds <= self::TOKEN_EXPIRY_SAFETY_SEC) {
            return time();
        }

        return time() + ($seconds - self::TOKEN_EXPIRY_SAFETY_SEC);
    }

    /**
     * Authenticate with OpenSubtitles and obtain a session token.
     *
     * Non-throwing: login is optional, so a transport or credential failure is
     * logged and the provider stays anonymous. The {@see $loginAttempted}
     * latch is classified by outcome here — transient failures (transport,
     * 429, 5xx, token-less 2xx) unlatch it for a retry on the next call,
     * while a confirmed 4xx credential rejection keeps it closed.
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
            $this->loginAttempted = false;
            $this->logger->warning('OpenSubtitles login transport failed: ' . $e->getMessage());

            return;
        }

        $status = $response['status'];

        if ($status < 200 || $status >= 300) {
            if ($status === 429 || $status >= 500) {
                $this->loginAttempted = false;
            }

            $this->logger->warning('OpenSubtitles login failed with HTTP ' . $status);

            return;
        }

        $data = json_decode($response['body'], true);
        if (is_array($data) && isset($data['token']) && is_string($data['token'])) {
            $this->sessionToken = $data['token'];
            $this->tokenExpiresAt = self::tokenDeadlineFrom($data['expires_in'] ?? null);
            $this->logger->info('OpenSubtitles login successful');
        } else {
            $this->loginAttempted = false;
            // The class contract is "a failure is logged and the provider stays
            // anonymous" — a 2xx without a usable token was previously silent.
            // Keep it non-throwing (login is optional by design) but loud.
            $this->logger->warning('OpenSubtitles login response did not include a usable session token');
        }
    }

    // ---------------------------------------------------------------------
    // Shared SubtitleSourceInterface façade (Wave 3 F3)
    // ---------------------------------------------------------------------

    /**
     * The canonical, stable source name of this subtitle source.
     *
     * @return non-empty-string Always {@see SOURCE_NAME} (`opensubtitles`).
     *
     * @since 0.5.0
     */
    public function getName(): string
    {
        return self::SOURCE_NAME;
    }

    /**
     * The default ranking priority of this source (lower runs first).
     *
     * @return int Configured priority, or {@see DEFAULT_PRIORITY}.
     *
     * @since 0.5.0
     */
    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * Contract search by local media file path — computes the OSDb moviehash and
     * searches by hash+size (MATCH_HASH), falling back to a filename heuristic
     * (MATCH_NAME) when the file cannot be hashed or the hash matched nothing.
     *
     * Does NOT consume download quota.
     *
     * @param string       $path      Absolute path to the local media file.
     * @param list<string> $languages ISO 639 / BCP-47-style codes to filter
     *                                (mapped to the OpenSubtitles language
     *                                domain; empty = no filter).
     *
     * @return list<SubtitleCandidate> Candidates, best-match first (API first
     *                                  page only — see the searchSubtitles()
     *                                  pagination law).
     *
     * @throws OpenSubtitlesException When the search transport/HTTP fails.
     *
     * @since 0.5.0
     */
    public function searchByPath(string $path, array $languages): array
    {
        $this->ensureEnabled();
        $this->ensureConnected();

        $languageCsv = self::languageFilter($languages);

        $hash = self::computeMovieHash($path);
        $size = is_file($path) ? filesize($path) : false;

        if ($hash !== '' && $size !== false && $size > 0) {
            $raw = $this->searchSubtitles(
                self::withLanguages(['moviehash' => $hash, 'moviebytesize' => (string) $size], $languageCsv),
                $this->format,
                'hash',
            );

            if ($raw !== []) {
                return $this->mapCandidates($raw, SubtitleCandidate::MATCH_HASH);
            }
        }

        // Fallback: no usable hash, or the hash matched nothing.
        $raw = $this->searchSubtitles(
            $this->filenameQueryParams(basename($path), $languageCsv),
            $this->format,
            'filename',
        );

        return $this->mapCandidates($raw, SubtitleCandidate::MATCH_NAME);
    }

    /**
     * Contract hash search by an OSDb movie hash + byte size (MATCH_HASH).
     *
     * Does NOT consume download quota.
     *
     * @param string       $movieHash OSDb movie hash (16 hex chars).
     * @param int          $byteSize  Exact byte size the hash was computed from.
     * @param list<string> $languages ISO 639 / BCP-47-style codes to filter
     *                                (mapped to the OpenSubtitles language
     *                                domain; empty = no filter).
     *
     * @return list<SubtitleCandidate> Candidates, best-match first (API first
     *                                  page only — see the searchSubtitles()
     *                                  pagination law).
     *
     * @throws OpenSubtitlesException When the search transport/HTTP fails.
     *
     * @since 0.5.0
     */
    public function searchByHash(string $movieHash, int $byteSize, array $languages): array
    {
        $this->ensureEnabled();
        $this->ensureConnected();

        $raw = $this->searchSubtitles(
            self::withLanguages(
                ['moviehash' => $movieHash, 'moviebytesize' => (string) $byteSize],
                self::languageFilter($languages),
            ),
            $this->format,
            'hash',
        );

        return $this->mapCandidates($raw, SubtitleCandidate::MATCH_HASH);
    }

    /**
     * Contract search by IMDb id (MATCH_IMDB). Does NOT consume download quota.
     *
     * @param string       $imdbId    IMDb id, with or without the `tt` prefix.
     * @param list<string> $languages ISO 639 / BCP-47-style codes to filter
     *                                (mapped to the OpenSubtitles language
     *                                domain; empty = no filter).
     *
     * @return list<SubtitleCandidate> Candidates, best-match first (API first
     *                                  page only — see the searchSubtitles()
     *                                  pagination law).
     *
     * @throws OpenSubtitlesException When the search transport/HTTP fails.
     *
     * @since 0.5.0
     */
    public function searchByImdbId(string $imdbId, array $languages): array
    {
        $this->ensureEnabled();
        $this->ensureConnected();

        $raw = $this->searchSubtitles(
            self::withLanguages(['imdb_id' => $imdbId], self::languageFilter($languages)),
            $this->format,
            'IMDB ID',
        );

        return $this->mapCandidates($raw, SubtitleCandidate::MATCH_IMDB);
    }

    /**
     * Contract on-demand download of ONE chosen candidate's subtitle file.
     *
     * Uses {@see SubtitleCandidate::$downloadId} (the OpenSubtitles `file_id`) to
     * run the two-step download and returns a shared {@see SubtitleFile}. THIS is
     * the quota-consuming step: a provider quota exhaustion is surfaced as the
     * shared {@see QuotaExceeded}, carrying the remaining allowance and reset
     * time when OpenSubtitles reports them. The returned file's `encoding`
     * claim is verified: bytes that are not valid UTF-8 fail loud rather than
     * being labelled as such.
     *
     * @param SubtitleCandidate $candidate The chosen candidate to download.
     *
     * @return SubtitleFile The downloaded subtitle, ready to persist and attach.
     *
     * @throws QuotaExceeded           When the download quota is exhausted.
     * @throws OpenSubtitlesException  When the download otherwise fails.
     *
     * @since 0.5.0
     */
    public function download(SubtitleCandidate $candidate): SubtitleFile
    {
        $this->ensureEnabled();

        try {
            $raw = $this->downloadRaw((int) $candidate->downloadId);
        } catch (OpenSubtitlesQuotaExceededException $e) {
            throw new QuotaExceeded(
                $e->getMessage(),
                downloadsRemaining: $e->downloadsRemaining,
                resetTimeUtc: $e->resetTimeUtc,
                previous: $e,
            );
        }

        // $candidate->language / ->format are contract non-empty-strings; the raw
        // download reports its own resolved format, preferred when present.
        $format = $raw->format !== '' ? $raw->format : $candidate->format;
        $language = $candidate->language;
        $releaseName = $candidate->releaseName !== '' ? $candidate->releaseName : null;

        // SubtitleFile carries an `encoding` claim the host trusts, so only a
        // validated UTF-8 byte sequence may be labelled as such. Binary or
        // foreign-encoded payloads (mis-uploads, a compressed body that
        // slipped past the `identity` request) fail loud here rather than
        // persist downstream as "UTF-8" subtitle garbage.
        if (!mb_check_encoding($raw->content, 'UTF-8')) {
            $this->logger->error('OpenSubtitles download failed: content is not valid UTF-8');

            throw new OpenSubtitlesException('Downloaded subtitle content is not valid UTF-8');
        }

        return new SubtitleFile(
            language: $language,
            format: $format,
            content: $raw->content,
            provider: self::SOURCE_NAME,
            suggestedFilename: self::safeFilename($raw->fileName, $releaseName, $language, $format),
            encoding: 'UTF-8',
            releaseName: $releaseName,
            hearingImpaired: $candidate->hearingImpaired,
        );
    }

    // ---------------------------------------------------------------------
    // Low-level wire API (`*Raw`) — returns provider-shaped arrays / DTOs
    // ---------------------------------------------------------------------

    /**
     * Low-level search for subtitles matching the given IMDB ID.
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
     *     rating: ?float,
     *     hearing_impaired: bool,
     *     fps: ?float,
     *     files: list<array{file_id: int, file_name: ?string, cd_number: int}>
     * }>
     *
     * Results carry the API first page only (see the searchSubtitles() pagination law).
     *
     * @throws OpenSubtitlesException When the search fails.
     *
     * @since 0.1.0
     */
    public function searchByImdbIdRaw(string $imdbId, string $language = '', string $subtitleFormat = ''): array
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
     * Low-level search for subtitles for a media file on disk.
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
     *     rating: ?float,
     *     hearing_impaired: bool,
     *     fps: ?float,
     *     files: list<array{file_id: int, file_name: ?string, cd_number: int}>
     * }>
     *
     * Results carry the API first page only (see the searchSubtitles() pagination law).
     *
     * @throws OpenSubtitlesException When the search fails.
     *
     * @since 0.4.0
     */
    public function searchByPathRaw(string $filePath, string $language = '', string $subtitleFormat = ''): array
    {
        $this->ensureEnabled();

        $hash = self::computeMovieHash($filePath);
        $size = is_file($filePath) ? filesize($filePath) : false;

        if ($hash !== '' && $size !== false && $size > 0) {
            $results = $this->searchByHashRaw($hash, $size, $language, $subtitleFormat);
            if ($results !== []) {
                return $results;
            }
        }

        // Fallback: no usable hash, or the hash matched nothing.
        return $this->searchByFilenameRaw(basename($filePath), $language, $subtitleFormat);
    }

    /**
     * Low-level search for subtitles matching the given filename (fallback path).
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
     *     rating: ?float,
     *     hearing_impaired: bool,
     *     fps: ?float,
     *     files: list<array{file_id: int, file_name: ?string, cd_number: int}>
     * }>
     *
     * Results carry the API first page only (see the searchSubtitles() pagination law).
     *
     * @throws OpenSubtitlesException When the search fails.
     *
     * @since 0.1.0
     */
    public function searchByFilenameRaw(string $filename, string $language = '', string $subtitleFormat = ''): array
    {
        $language = $language ?: $this->language;
        $subtitleFormat = $subtitleFormat ?: $this->format;

        $this->ensureEnabled();
        $this->ensureConnected();

        return $this->searchSubtitles(
            $this->filenameQueryParams($filename, $language),
            $subtitleFormat,
            'filename',
        );
    }

    /**
     * Build the `/subtitles` query params for a filename-based search.
     *
     * A bare filename query is the weakest signal, but useful when we have
     * neither an IMDB id nor an on-disk file to hash. When a `tt…` id is present
     * in the filename it is added for a stronger match.
     *
     * @param string      $filename    Media filename.
     * @param string|null $languageCsv Comma-separated language filter, or null for none.
     *
     * @return array<string, string>
     *
     * @since 0.5.0
     */
    private function filenameQueryParams(string $filename, ?string $languageCsv): array
    {
        $mediaInfo = $this->parseFilename($filename);

        $queryParams = self::withLanguages([], $languageCsv);

        if ($mediaInfo['imdb_id'] !== null) {
            $queryParams['imdb_id'] = $mediaInfo['imdb_id'];
        }

        $queryParams['query'] = pathinfo($filename, PATHINFO_FILENAME);

        return $queryParams;
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
     *     rating: ?float,
     *     hearing_impaired: bool,
     *     fps: ?float,
     *     files: list<array{file_id: int, file_name: ?string, cd_number: int}>
     * }>
     *
     * Results carry the API first page only (see the searchSubtitles() pagination law).
     *
     * @throws OpenSubtitlesException When the search fails.
     *
     * @since 0.1.0
     */
    public function searchByHashRaw(string $hash, int $size, string $language = '', string $subtitleFormat = ''): array
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
     * @param bool                  $allowAuthRetry  Internal: whether an HTTP
     *        401 may consume the single forced re-auth + retry budget (false
     *        on the retry leg itself, so the budget can never loop).
     *
     * @return list<array{
     *     id: string,
     *     language: string,
     *     format: string,
     *     downloads: int,
     *     filename: string,
     *     imdb_id: ?string,
     *     file_id: int,
     *     rating: ?float,
     *     hearing_impaired: bool,
     *     fps: ?float,
     *     files: list<array{file_id: int, file_name: ?string, cd_number: int}>
     * }>
     *
     * Pagination law: this issues exactly ONE API request and returns its
     * first page. The REST v1 envelope reports `meta.total_pages`, but pages
     * are deliberately never followed: walking pagination would multiply the
     * API calls per search against a rate-limited gateway, while the
     * language/format filters already narrow the set and every caller ranks
     * within the returned page. A caller that needs deeper results must
     * re-query with a narrower filter, not expect exhaustion of the catalog.
     *
     * @throws OpenSubtitlesException When the search fails — including a 2xx
     *         response whose body is not valid JSON or lacks the expected
     *         `data` envelope (never collapsed to an empty result).
     */
    private function searchSubtitles(
        array $queryParams,
        string $subtitleFormat,
        string $context,
        bool $allowAuthRetry = true,
    ): array {
        $this->guardSearchNotDeferred();

        $url = self::API_BASE . 'subtitles?' . http_build_query($queryParams);

        try {
            $response = $this->httpRequest('GET', $url, $this->authHeaders());
        } catch (OpenSubtitlesException $e) {
            $this->logger->error("OpenSubtitles search by {$context} failed: " . $e->getMessage());

            throw new OpenSubtitlesException("Search by {$context} failed: " . $e->getMessage(), 0, $e);
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            // A 401 on a request that carried a session token means the token
            // died server-side: refresh once and retry the search. The retry
            // leg runs with the budget spent, so this can never loop.
            if ($allowAuthRetry && $response['status'] === 401 && $this->attemptReauthAfter401()) {
                return $this->searchSubtitles($queryParams, $subtitleFormat, $context, false);
            }

            // A 429's Retry-After is a server-imposed pause; honor it locally
            // for subsequent calls (no dials inside the window).
            $this->rememberRateWindow($response);

            $this->logger->error("OpenSubtitles search by {$context} failed: HTTP " . $response['status']);

            throw new OpenSubtitlesException("Search by {$context} failed: HTTP " . $response['status']);
        }

        $data = json_decode($response['body'], true);

        // Fail-vs-empty separation (review opensubtitles__srphp C2): `[]` is
        // reserved for a parsed, valid, genuinely-empty `data` envelope. A 2xx
        // body that is not decodable JSON, or decodes without the expected
        // `data` list (gateway HTML page, truncated stream, error-in-200), is a
        // FAILURE and must surface as one — callers (and their empty-result
        // caching) must never see a broken call reported as "no subtitles".
        // Response content is deliberately NOT interpolated into the message.
        if (!is_array($data)) {
            $reason = 'response body was not a valid JSON envelope';

            $this->logger->error("OpenSubtitles search by {$context} failed: {$reason}");

            throw new OpenSubtitlesException("Search by {$context} failed: {$reason}");
        }

        if (!is_array($data['data'] ?? null)) {
            $reason = 'response envelope did not include a data list';

            $this->logger->error("OpenSubtitles search by {$context} failed: {$reason}");

            throw new OpenSubtitlesException("Search by {$context} failed: {$reason}");
        }

        /** @var list<array<string, mixed>> $subtitles */
        $subtitles = array_values($data['data']);

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
     *    never be retried speculatively or looped. The ONE sanctioned re-issue
     *    is after an HTTP 401 session-token rejection — a rejected request
     *    consumed no quota, so refreshing the token and re-posting once is
     *    state recovery, not speculation ({@see attemptReauthAfter401()}).
     * 2. The actual subtitle bytes are fetched with a plain `GET` against that
     *    `link` (a different host, not `API_BASE`). The link must be HTTPS and
     *    is fetched WITHOUT the consumer credentials — the API origin is the
     *    only host that ever sees the `Api-Key` or the session token.
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
    public function downloadRaw(int $fileId): SubtitleDownload
    {
        $this->ensureEnabled();

        // Local fail-fast BEFORE any dial (login included): storming a window
        // the API already declared dead only deepens the hole.
        $this->guardDownloadNotDeferred();

        $this->ensureConnected();

        $contentType = ['Content-Type' => 'application/json'];

        $body = ['file_id' => $fileId];
        if ($this->format !== '') {
            $body['sub_format'] = $this->format;
        }

        try {
            $linkResponse = $this->httpRequest(
                'POST',
                self::API_BASE . 'download',
                array_merge($contentType, $this->authHeaders()),
                (string) json_encode($body),
            );

            // A 401 here means our session token died server-side. The
            // rejected request consumed no quota (auth failed before the
            // download was registered), so refreshing the token and re-issuing
            // step-1 once is a bounded, non-speculative retry — the budget is
            // this single re-issue; a second 401 falls through to the failure
            // mapper below.
            if ($linkResponse['status'] === 401 && $this->attemptReauthAfter401()) {
                $linkResponse = $this->httpRequest(
                    'POST',
                    self::API_BASE . 'download',
                    array_merge($contentType, $this->authHeaders()),
                    (string) json_encode($body),
                );
            }
        } catch (OpenSubtitlesException $e) {
            $this->logger->error('OpenSubtitles download failed: ' . $e->getMessage());

            throw new OpenSubtitlesException('Download failed: ' . $e->getMessage(), 0, $e);
        }

        $decoded = json_decode($linkResponse['body'], true);
        /** @var array<string, mixed> $data */
        $data = is_array($decoded) ? $decoded : [];

        // Remember the window BEFORE surfacing the failure, so the caller's
        // next attempt (the orchestrator may be looping files) is refused
        // locally instead of re-dialing a declared-dead quota/rate window.
        $this->rememberQuotaWindow($data);
        $this->rememberRateWindow($linkResponse);

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

        // The link is a raw, response-supplied URL to a foreign host. Refuse
        // to fetch it in the clear, and never send consumer credentials
        // cross-origin ({@see foreignOriginHeaders()}).
        if (!str_starts_with($link, 'https://')) {
            $this->logger->error('OpenSubtitles download link rejected: not an HTTPS URL');

            throw new OpenSubtitlesException(
                'OpenSubtitles download link is not an HTTPS URL; refusing an in-the-clear fetch',
            );
        }

        try {
            // Make the wire contract explicit: this plugin does not decode
            // Content-Encoding, and download() only asserts UTF-8 for bytes
            // it validates as text — so ask the link host for neither
            // compression nor (by failing validation) mislabelled payloads.
            $contentResponse = $this->httpRequest('GET', $link, ['Accept-Encoding' => 'identity']);
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
                downloadsRemaining: is_int($data['remaining'] ?? null) ? $data['remaining'] : null,
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
            downloadsRemaining: is_int($data['remaining'] ?? null) ? $data['remaining'] : null,
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
     * Only genuine transport failures (connection/timeout) and over-cap
     * response bodies throw; see {@see MAX_RESPONSE_BYTES}.
     *
     * @param string                $method  HTTP method.
     * @param string                $url     Absolute URL (or a path relative to API_BASE).
     * @param array<string, string> $headers Request headers.
     * @param string|null           $body    Raw request body (JSON), or null.
     *
     * @return array{status:int, body:string, headers?:array<string,string|list<string>>}
     *         `headers` is the response-header map (always present on the real
     *         transport; a seam may omit it when the scenario needs none).
     *
     * @throws OpenSubtitlesException On transport/network failure or an over-cap response.
     */
    private function httpRequest(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            $url = self::API_BASE . ltrim($url, '/');
        }

        $headers = array_merge(
            self::isApiOrigin($url) ? $this->defaultHeaders() : $this->foreignOriginHeaders(),
            $headers,
        );

        if ($this->transport !== null) {
            return self::assertWithinResponseCap(($this->transport)($method, $url, $headers, $body));
        }

        $state = ['status' => 0, 'body' => '', 'headers' => [], 'error' => null, 'done' => false];

        // Running total of streamed response bytes (the client emits each
        // body chunk through `progress` before writing it to its buffer).
        $receivedBytes = 0;

        $options = [
            'method' => $method,
            'headers' => $headers,
            // Abort mid-flight once the cap is crossed: throwing here rides
            // the client's error path, which closes the connection and
            // records $state['error'] — an over-cap body is never delivered.
            'progress' => function (string $chunk) use (&$receivedBytes): void {
                $receivedBytes += strlen($chunk);
                if ($receivedBytes > self::MAX_RESPONSE_BYTES) {
                    throw new OpenSubtitlesException(self::responseCapExceededMessage());
                }
            },
            'success' => function (Response $response) use (&$state): void {
                $state['status'] = $response->getStatusCode();
                $state['body'] = $response->getBody()->getContents();
                // PSR-7 shape: array<string, list<string>>. The 429 Retry-After
                // and future header consumers read it through headerValue().
                $state['headers'] = $response->getHeaders();
                $state['done'] = true;
            },
            'error' => function (Throwable $error) use (&$state): void {
                $state['error'] = $error;
                $state['done'] = true;
            },
        ];

        if ($body !== null) {
            $options['data'] = $body;
        }

        $this->httpClientFor($url)->request($url, $options);

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

        // Authoritative single choke point: covers the real transport result
        // exactly as it covers the seam above. $state['headers'] is provably
        // either the PSR-7 getHeaders() map (string header names) or the
        // initial empty array.
        /** @var array<string, list<string>> $responseHeaders */
        $responseHeaders = $state['headers'];

        return self::assertWithinResponseCap([
            'status' => $state['status'],
            'body' => $state['body'],
            'headers' => $responseHeaders,
        ]);
    }

    /**
     * The canonical over-cap failure message.
     *
     * @return string
     *
     * @since 0.6.1
     */
    private static function responseCapExceededMessage(): string
    {
        return 'HTTP response exceeded the ' . self::MAX_RESPONSE_BYTES . '-byte cap';
    }

    /**
     * Guard a response envelope against {@see MAX_RESPONSE_BYTES}.
     *
     * Early-exit on the healthy path; an over-cap body never reaches a caller
     * as a return value (fail loud, never partial or empty "success").
     *
     * @param array{status:int, body:string, headers?:array<string,string|list<string>>} $response
     *
     * @return array{status:int, body:string, headers?:array<string,string|list<string>>} The unchanged response.
     *
     * @throws OpenSubtitlesException When the body exceeds the cap.
     *
     * @since 0.6.1
     */
    private static function assertWithinResponseCap(array $response): array
    {
        if (strlen($response['body']) <= self::MAX_RESPONSE_BYTES) {
            return $response;
        }

        throw new OpenSubtitlesException(self::responseCapExceededMessage());
    }

    /**
     * True when `$url`'s host is the OpenSubtitles API origin — the only host
     * allowed to see the consumer `Api-Key` and the session token
     * ({@see defaultHeaders()}). Download links point at a different,
     * response-supplied host; they get identity ({@see foreignOriginHeaders()})
     * but never credentials — closing the credential-exfiltration-by-response
     * vector where a single malformed or malicious link value would otherwise
     * ship the long-lived consumer key cross-origin.
     */
    private static function isApiOrigin(string $url): bool
    {
        return parse_url($url, PHP_URL_HOST) === self::API_HOST;
    }

    /**
     * The descriptive, contact-bearing User-Agent required by OpenSubtitles'
     * ToS. It is identification, not a credential, so it rides EVERY request,
     * API origin and foreign hosts alike (see {@see USER_AGENT_CONTACT_URL}).
     */
    private static function userAgent(): string
    {
        return 'Phlix-Plugin-OpenSubtitles/' . self::pluginVersion()
            . ' (+' . self::USER_AGENT_CONTACT_URL . ')';
    }

    /**
     * Headers sent on requests to the API origin ({@see isApiOrigin()}).
     *
     * @return array<string, string>
     */
    private function defaultHeaders(): array
    {
        return [
            'Api-Key' => $this->apiKey,
            'User-Agent' => self::userAgent(),
            'Accept' => 'application/json',
        ];
    }

    /**
     * Headers sent to any host OTHER than the API origin (the step-2
     * download-link fetch). Deliberately credential-free: the consumer key
     * and session token belong to {@see API_HOST} alone.
     *
     * @return array<string, string>
     */
    private function foreignOriginHeaders(): array
    {
        return [
            'User-Agent' => self::userAgent(),
        ];
    }

    /**
     * Read one response header case-insensitively. Real transports deliver
     * PSR-7 `list<string>` values; seams may carry a plain string. Anything
     * else (missing, empty list, non-scalar) reads as null — no window.
     *
     * @param array<array-key, mixed> $headers
     */
    private static function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (!is_string($key) || strcasecmp($key, $name) !== 0) {
                continue;
            }

            if (is_array($value)) {
                $value = reset($value);
            }

            if (is_string($value) || is_numeric($value)) {
                return (string) $value;
            }

            return null;
        }

        return null;
    }

    /**
     * Remember a server-imposed HTTP 429 `Retry-After` window (delta-seconds
     * or HTTP-date) so subsequent calls refuse locally until it passes. A
     * missing or unparsable header latches nothing — honest: without a
     * promised window the next call goes to the network as it always has.
     *
     * @param array{status:int, body:string, headers?:array<string,string|list<string>>} $response
     *
     * @return void
     */
    private function rememberRateWindow(array $response): void
    {
        if ($response['status'] !== 429) {
            return;
        }

        $retryAfter = self::headerValue($response['headers'] ?? [], 'Retry-After');
        if ($retryAfter === null) {
            return;
        }

        $until = ctype_digit($retryAfter) ? time() + (int) $retryAfter : strtotime($retryAfter);

        if (!is_int($until) || $until <= time()) {
            return;
        }

        $this->rateLimitedUntil = $until;
        $this->logger->warning(
            'OpenSubtitles rate-limited (HTTP 429); requests deferred locally until ' . gmdate('c', $until),
        );
    }

    /**
     * Remember an exhausted download window (`remaining: 0` with a concrete
     * future `reset_time_utc`) so the caller's file loop cannot storm the
     * quota-consuming step-1 POST inside a window the API already declared
     * dead. A missing, unparsable, or past reset advertises no trustworthy
     * window: nothing latches, the next call re-dials and takes its honest
     * answer from the API.
     *
     * @param array<string, mixed> $data Decoded step-1 response body.
     *
     * @return void
     */
    private function rememberQuotaWindow(array $data): void
    {
        $remaining = $data['remaining'] ?? null;
        if (!is_int($remaining) || $remaining !== 0) {
            return;
        }

        $resetUtc = $data['reset_time_utc'] ?? null;
        if (!is_string($resetUtc) || $resetUtc === '') {
            return;
        }

        $until = strtotime($resetUtc);
        if ($until === false || $until <= time()) {
            return;
        }

        $this->quotaBlockedUntil = $until;
        $this->quotaResetTimeUtc = $resetUtc;
        $this->quotaResetTime = is_string($data['reset_time'] ?? null) ? $data['reset_time'] : null;

        $message = $data['message'] ?? null;
        $this->quotaMessage = is_string($message) && $message !== ''
            ? $message
            : 'OpenSubtitles download quota exhausted.';

        $this->logger->warning(
            'OpenSubtitles download quota exhausted until ' . $resetUtc
                . '; further downloads refused locally until the window resets',
        );
    }

    /**
     * Fail-fast guard for searches: refuse locally (zero network) inside a
     * remembered 429 window; a passed window clears itself.
     *
     * @throws OpenSubtitlesException While the rate window is active.
     */
    private function guardSearchNotDeferred(): void
    {
        if ($this->rateLimitedUntil === null) {
            return;
        }

        if ($this->rateLimitedUntil > time()) {
            throw new OpenSubtitlesException(
                'Search deferred: OpenSubtitles rate-limited window active until '
                    . gmdate('c', $this->rateLimitedUntil),
            );
        }

        $this->rateLimitedUntil = null;
    }

    /**
     * Fail-fast guard for downloads: refuse locally (zero network) inside a
     * remembered 429 window, and surface the API's own quota context as the
     * familiar {@see OpenSubtitlesQuotaExceededException} inside a remembered
     * exhausted window (download() translates it to the shared QuotaExceeded
     * exactly as it translates a network-surfaced one).
     *
     * @throws OpenSubtitlesException While a rate window is active.
     * @throws OpenSubtitlesQuotaExceededException While the quota window is exhausted.
     */
    private function guardDownloadNotDeferred(): void
    {
        if ($this->rateLimitedUntil !== null) {
            if ($this->rateLimitedUntil > time()) {
                throw new OpenSubtitlesException(
                    'Download deferred: OpenSubtitles rate-limited window active until '
                        . gmdate('c', $this->rateLimitedUntil),
                );
            }

            $this->rateLimitedUntil = null;
        }

        if ($this->quotaBlockedUntil === null) {
            return;
        }

        if ($this->quotaBlockedUntil > time()) {
            $this->logger->warning(
                'OpenSubtitles download refused locally: quota exhausted until ' . (string) $this->quotaResetTimeUtc,
            );

            throw new OpenSubtitlesQuotaExceededException(
                (string) $this->quotaMessage . ' (not retried locally until the quota window resets)',
                resetTime: $this->quotaResetTime,
                resetTimeUtc: $this->quotaResetTimeUtc,
                downloadsRemaining: 0,
            );
        }

        $this->quotaBlockedUntil = null;
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
     * The explicit strict TLS verification context for one peer host.
     *
     * The vendored `workerman/http-client` v3.1.3 `ConnectionPool::create()`
     * defaults to `verify_peer => false`, `verify_peer_name => false`,
     * `allow_self_signed => true` — verification is OFF unless configured —
     * on the exact socket stack that carries the account password
     * (`POST login`) and the consumer Api-Key on every request. This estate
     * already learned that lesson the hard way on the same connection layer
     * (phlix-hub `519c0c0`), so the hardening ships as configuration and is
     * never left to library charity.
     *
     * The pool merges client-level `context` options over its own base with a
     * SHALLOW `array_merge`, replacing the whole `ssl` key — including the
     * per-address `peer_name` the pool had just derived. The context must
     * therefore carry its own identity, which is why clients are built per
     * target host ({@see httpClientFor()}) instead of sharing one.
     *
     * @return array{verify_peer:bool,verify_peer_name:bool,allow_self_signed:bool,peer_name:string,server_name:string}
     */
    private static function strictTlsContext(string $peerHost): array
    {
        return [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            // Certificate name verification target ...
            'peer_name' => $peerHost,
            // ... and the SNI identity sent in the ClientHello.
            'server_name' => $peerHost,
        ];
    }

    /**
     * The shared non-blocking HTTP client for the target host of `$url`,
     * created lazily with strict TLS verification ({@see strictTlsContext()}).
     *
     * @throws OpenSubtitlesException When the URL carries no host to verify.
     */
    private function httpClientFor(string $url): Client
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new OpenSubtitlesException('HTTP request URL carries no host to verify: ' . $url);
        }

        if (!isset($this->httpClients[$host])) {
            $this->httpClients[$host] = new Client([
                'timeout' => self::HTTP_TIMEOUT_SEC,
                'context' => ['ssl' => self::strictTlsContext($host)],
            ]);
        }

        return $this->httpClients[$host];
    }

    /**
     * Extract the channels a filename search can actually use.
     *
     * Only the embedded IMDb id is consumed downstream — the search query
     * itself is the bare pathinfo filename ({@see filenameQueryParams()}) and
     * the moviehash channel is populated from release metadata, never from a
     * filename guess. The former title/year extraction was therefore dead
     * output (and its trim charlist `'.[\\s'` was a literal set, not a regex
     * class — it stripped '.', '[', '\' and 's'); it is removed rather than
     * repaired, so the parse boundary carries only what has a consumer.
     *
     * @param string $filename The media filename to parse.
     *
     * @return array{imdb_id: ?string}
     *
     * @since 0.1.0
     */
    private function parseFilename(string $filename): array
    {
        if (preg_match('/(tt\d{7,8})/i', $filename, $matches)) {
            return ['imdb_id' => strtolower($matches[1])];
        }

        return ['imdb_id' => null];
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
     *     rating: ?float,
     *     hearing_impaired: bool,
     *     fps: ?float,
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
            $languageRaw = $attributes['language'] ?? null;
            if (!is_string($languageRaw) || trim($languageRaw) === '') {
                // Parse-boundary fail-fast: a result without a usable language
                // is skipped, never relabeled a default — a fabricated 'en'
                // would pass host language filters with wrong-language text.
                continue;
            }

            $languageCode = $languageRaw;
            $downloadCount = is_int($attributes['download_count'] ?? null) ? $attributes['download_count'] : 0;
            $imdbId = self::normalizeImdbId($featureDetails['imdb_id'] ?? null);
            $rating = self::floatOrNull($attributes['ratings'] ?? null);
            $hearingImpaired = self::truthy($attributes['hearing_impaired'] ?? null);
            $fps = self::floatOrNull($attributes['fps'] ?? null);

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
                'rating' => $rating,
                'hearing_impaired' => $hearingImpaired,
                'fps' => $fps,
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
     * Map low-level `*Raw` search results into shared {@see SubtitleCandidate}
     * value objects, stamping how each was matched.
     *
     * @param list<array{
     *     id: string, language: string, format: string, downloads: int,
     *     filename: string, imdb_id: ?string, file_id: int, rating: ?float,
     *     hearing_impaired: bool, fps: ?float,
     *     files: list<array{file_id: int, file_name: ?string, cd_number: int}>
     * }> $raw
     * @param SubtitleCandidate::MATCH_* $matchedBy How the candidates were matched.
     *
     * @return list<SubtitleCandidate>
     *
     * @since 0.5.0
     */
    private function mapCandidates(array $raw, string $matchedBy): array
    {
        $candidates = [];

        foreach ($raw as $row) {
            if ($row['file_id'] <= 0) {
                // No usable download token — a candidate we could never fetch.
                continue;
            }

            $downloadId = (string) $row['file_id'];

            $language = $row['language'] !== '' ? $row['language'] : self::DEFAULT_LANGUAGE;
            $format = $row['format'] !== '' ? $row['format'] : self::DEFAULT_FORMAT;

            $candidates[] = new SubtitleCandidate(
                provider: self::SOURCE_NAME,
                language: $language,
                downloadId: $downloadId,
                releaseName: $row['filename'],
                format: $format,
                matchedBy: $matchedBy,
                rating: $row['rating'],
                downloadCount: $row['downloads'],
                hearingImpaired: $row['hearing_impaired'],
                fps: $row['fps'],
            );
        }

        return $candidates;
    }

    /**
     * Reduce a language list to the OpenSubtitles comma-separated `languages`
     * filter value, or null when no filter should be applied (empty list).
     *
     * Host-side codes (estate BCP-47-style, e.g. `en-US`, `pt-BR`) are mapped
     * into the OpenSubtitles language domain before they are sent: an exact
     * {@see LANGUAGE_ALIASES} hit wins (so `pt-BR` becomes the legacy `pb`),
     * otherwise a region subtag is stripped (`en-US` queries `en`). Plain
     * codes pass through untouched. The filter the host asked for is thus
     * honored in the provider's own vocabulary instead of silently matching
     * nothing.
     *
     * @param list<string> $languages ISO 639 / BCP-47-style codes.
     *
     * @return string|null
     *
     * @since 0.5.0
     */
    private static function languageFilter(array $languages): ?string
    {
        $clean = [];
        foreach ($languages as $language) {
            $language = trim($language);
            if ($language === '') {
                continue;
            }

            $normalized = self::LANGUAGE_ALIASES[$language] ?? $language;
            if ($normalized === $language && preg_match('/^[a-zA-Z]{2,3}-/', $language) === 1) {
                $normalized = substr($language, 0, (int) strpos($language, '-'));
            }

            $clean[$normalized] = true;
        }

        return $clean === [] ? null : implode(',', array_keys($clean));
    }

    /**
     * Attach the `languages` query param when a non-null filter is present.
     *
     * @param array<string, string> $params      Base query params.
     * @param string|null           $languageCsv Comma-separated filter, or null.
     *
     * @return array<string, string>
     *
     * @since 0.5.0
     */
    private static function withLanguages(array $params, ?string $languageCsv): array
    {
        if ($languageCsv !== null) {
            $params['languages'] = $languageCsv;
        }

        return $params;
    }

    /**
     * Derive a safe base filename (no directory component) for a downloaded
     * subtitle, ensuring it carries the subtitle format extension.
     *
     * @param string      $providerName Provider-reported file name (may be empty).
     * @param string|null $releaseName  Candidate release name fallback.
     * @param string      $language     Subtitle language code.
     * @param string      $format       Subtitle format extension (no dot).
     *
     * @return non-empty-string
     *
     * @since 0.5.0
     */
    private static function safeFilename(
        string $providerName,
        ?string $releaseName,
        string $language,
        string $format,
    ): string {
        // Base name only — strip any directory components and control chars.
        $base = basename(trim($providerName));
        $base = (string) preg_replace('#[\x00-\x1F/\\\\]+#', '', $base);

        if ($base === '' || $base === '.') {
            $stem = pathinfo((string) $releaseName, PATHINFO_FILENAME);
            if ($stem === '') {
                $stem = 'subtitle' . ($language !== '' ? '.' . $language : '');
            }
            $base = $stem . '.' . ($format !== '' ? $format : self::DEFAULT_FORMAT);
        }

        return $base;
    }

    /**
     * Coerce a raw `priority` setting value to an int, or the default.
     *
     * @param mixed $value Raw setting value.
     *
     * @return int
     *
     * @since 0.5.0
     */
    private static function coercePriority(mixed $value): int
    {
        return match (true) {
            is_int($value) => $value,
            is_string($value) && $value !== '' && preg_match('/^-?\d+$/', $value) === 1 => (int) $value,
            default => self::DEFAULT_PRIORITY,
        };
    }

    /**
     * Coerce a raw API value to a float, or null when absent/non-numeric.
     *
     * @param mixed $value Raw value.
     *
     * @return float|null
     *
     * @since 0.5.0
     */
    private static function floatOrNull(mixed $value): ?float
    {
        return match (true) {
            is_int($value) || is_float($value) => (float) $value,
            is_string($value) && is_numeric($value) => (float) $value,
            default => null,
        };
    }

    /**
     * Coerce a raw API value to a bool (accepts the API's 1/0 and "1"/"0").
     *
     * @param mixed $value Raw value.
     *
     * @return bool
     *
     * @since 0.5.0
     */
    private static function truthy(mixed $value): bool
    {
        return match (true) {
            is_bool($value) => $value,
            is_int($value) => $value === 1,
            is_string($value) => $value === '1' || strtolower($value) === 'true',
            default => false,
        };
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
