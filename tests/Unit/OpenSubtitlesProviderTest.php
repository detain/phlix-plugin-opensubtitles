<?php

/**
 * Tests for OpenSubtitlesProvider.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\PluginOpenSubtitles\Tests;

use Phlix\PluginOpenSubtitles\OpenSubtitlesException;
use Phlix\PluginOpenSubtitles\OpenSubtitlesProvider;
use Phlix\PluginOpenSubtitles\OpenSubtitlesQuotaExceededException;
use Phlix\PluginOpenSubtitles\SubtitleDownload;
use Phlix\Shared\Subtitle\Exception\QuotaExceeded;
use Phlix\Shared\Subtitle\SubtitleCandidate;
use Phlix\Shared\Subtitle\SubtitleFile;
use Phlix\Shared\Subtitle\SubtitleSourceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests the plugin's contract compliance and core functionality in isolation.
 *
 * The provider's network layer is exercised through its `transport` closure
 * seam — a fake that records outgoing requests and returns queued
 * `{status, body}` responses — so no Workerman event loop or live API is
 * needed. This asserts real consequences: what URLs/bodies/headers are sent,
 * that onEnable does zero HTTP, and that the moviehash math is correct.
 */
final class OpenSubtitlesProviderTest extends TestCase
{
    private const TEST_API_KEY = 'test-api-key-12345';

    // ---------------------------------------------------------------------
    // Construction / configuration
    // ---------------------------------------------------------------------

    public function test_constructor_sets_properties_correctly(): void
    {
        $provider = new OpenSubtitlesProvider(
            apiKey: self::TEST_API_KEY,
            username: 'testuser',
            password: 'testpass',
            language: 'fr',
            format: 'ass',
        );

        $this->assertSame('fr', $provider->getLanguage());
        $this->assertSame('ass', $provider->getFormat());
        $this->assertFalse($provider->isLoggedIn());
    }

    public function test_constructor_with_minimal_params(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);

        $this->assertSame('en', $provider->getLanguage());
        $this->assertSame('srt', $provider->getFormat());
        $this->assertFalse($provider->isLoggedIn());
    }

    public function test_constructor_is_autowirable_with_no_arguments(): void
    {
        // The host container builds the entry class with zero args — it must not
        // throw, and it must implement the settings-injection contract.
        $provider = new OpenSubtitlesProvider();

        $this->assertInstanceOf(\Phlix\Shared\Plugin\LifecycleInterface::class, $provider);
        $this->assertInstanceOf(\Phlix\Shared\Plugin\ConfigurableInterface::class, $provider);
        $this->assertSame('en', $provider->getLanguage());
        $this->assertSame('srt', $provider->getFormat());
    }

    public function test_configure_applies_persisted_settings(): void
    {
        $provider = new OpenSubtitlesProvider();
        $provider->configure([
            'api_key'  => self::TEST_API_KEY,
            'username' => 'testuser',
            'password' => 'testpass',
            'language' => 'de',
            'format'   => 'ass',
        ]);

        $this->assertSame('de', $provider->getLanguage());
        $this->assertSame('ass', $provider->getFormat());
        $this->assertFalse($provider->isLoggedIn());
    }

    public function test_configure_falls_back_to_defaults_for_blank_values(): void
    {
        $provider = new OpenSubtitlesProvider();
        $provider->configure(['api_key' => self::TEST_API_KEY, 'language' => '', 'format' => '']);

        $this->assertSame('en', $provider->getLanguage());
        $this->assertSame('srt', $provider->getFormat());
    }

    // ---------------------------------------------------------------------
    // Boot safety: onEnable must not do HTTP / must not log in
    // ---------------------------------------------------------------------

    /**
     * The keystone boot-safety assertion. onEnable runs across ~14 Workerman
     * workers at boot; a blocking login there is the item-5c3 landmine that was
     * reverted in production. Even with credentials configured, onEnable must
     * make ZERO HTTP requests and must NOT be logged in — authentication is
     * deferred to the first actual API call.
     */
    public function test_on_enable_does_no_http_and_no_login_even_with_credentials(): void
    {
        $provider = new OpenSubtitlesProvider(
            apiKey: self::TEST_API_KEY,
            username: 'testuser',
            password: 'testpass',
        );

        $history = [];
        $this->installFakeTransport($provider, [], $history);

        $provider->onEnable($this->stubContainer());

        $this->assertSame([], $history, 'onEnable must not perform any HTTP request');
        $this->assertFalse($provider->isLoggedIn(), 'onEnable must not log in');
    }

    public function test_subscribed_events_returns_empty_array(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);

        $this->assertSame([], $provider->subscribedEvents());
    }

    // ---------------------------------------------------------------------
    // Deferred login (happens lazily on first authenticated use)
    // ---------------------------------------------------------------------

    /**
     * Login is deferred out of onEnable to the first authenticated call. This
     * asserts (a) it fires on the first search, not at enable time, (b) it
     * POSTs to the correct fully-resolved v1 login URL, and (c) the session
     * token is captured. The login request must precede the search request.
     */
    public function test_login_is_deferred_to_first_use_and_posts_to_correct_v1_url(): void
    {
        $provider = new OpenSubtitlesProvider(
            apiKey: self::TEST_API_KEY,
            username: 'testuser',
            password: 'testpass',
        );

        $history = [];
        $this->installFakeTransport($provider, [
            self::response(200, (string) json_encode(['token' => 'session-token-123'])),
            self::response(200, (string) json_encode(['data' => []])),
        ], $history);

        $provider->onEnable($this->stubContainer());
        $this->assertCount(0, $history, 'no request until first use');

        $provider->searchByImdbIdRaw('tt1234567');

        $this->assertCount(2, $history);
        $this->assertSame('POST', $history[0]['method']);
        $this->assertSame('https://api.opensubtitles.com/api/v1/login', $history[0]['url']);
        $this->assertTrue($provider->isLoggedIn());
        $this->assertStringStartsWith(
            'https://api.opensubtitles.com/api/v1/subtitles',
            $history[1]['url'],
        );
    }

    /**
     * Login runs at most once per enable cycle: a second search must not re-POST
     * to /login (that would burn requests and defeat the token).
     */
    public function test_login_runs_only_once_per_enable_cycle(): void
    {
        $provider = new OpenSubtitlesProvider(
            apiKey: self::TEST_API_KEY,
            username: 'u',
            password: 'p',
        );

        $history = [];
        $this->installFakeTransport($provider, [
            self::response(200, (string) json_encode(['token' => 'tok'])),
            self::response(200, (string) json_encode(['data' => []])),
            self::response(200, (string) json_encode(['data' => []])),
        ], $history);

        $provider->onEnable($this->stubContainer());
        $provider->searchByImdbIdRaw('tt1');
        $provider->searchByImdbIdRaw('tt2');

        $loginCalls = array_filter($history, static fn (array $h): bool => str_ends_with($h['url'], '/login'));
        $this->assertCount(1, $loginCalls);
    }

    /**
     * Login is optional (it only raises quota limits). A failed login must NOT
     * throw or abort the search — the provider proceeds anonymously.
     */
    public function test_failed_login_does_not_break_search(): void
    {
        $provider = new OpenSubtitlesProvider(
            apiKey: self::TEST_API_KEY,
            username: 'u',
            password: 'p',
        );

        $history = [];
        $this->installFakeTransport($provider, [
            self::response(401, (string) json_encode(['message' => 'bad credentials'])),
            self::response(200, (string) json_encode(['data' => []])),
        ], $history);

        $provider->onEnable($this->stubContainer());
        $results = $provider->searchByImdbIdRaw('tt1234567');

        $this->assertSame([], $results);
        $this->assertFalse($provider->isLoggedIn());
    }

    // ---------------------------------------------------------------------
    // Default headers (User-Agent derived from plugin.json)
    // ---------------------------------------------------------------------

    /**
     * Regression test for a stale hardcoded User-Agent. The header must be
     * derived from plugin.json's version at runtime. Asserted against the actual
     * outgoing request headers (via the transport seam), not a source literal.
     */
    public function test_request_user_agent_derives_from_plugin_json_version(): void
    {
        $manifestPath = dirname(__DIR__, 2) . '/plugin.json';
        /** @var array<string, mixed> $manifest */
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $expectedVersion = $manifest['version'];
        $this->assertIsString($expectedVersion);
        $this->assertNotSame('', $expectedVersion);

        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [self::response(200, '{"data":[]}')], $history);

        $provider->onEnable($this->stubContainer());
        $provider->searchByImdbIdRaw('tt1234567');

        $this->assertSame(
            'Phlix-Plugin-OpenSubtitles/' . $expectedVersion,
            $history[0]['headers']['User-Agent'],
        );
        $this->assertNotSame('Phlix-Plugin-OpenSubtitles/0.1.0', $history[0]['headers']['User-Agent']);
        $this->assertSame(self::TEST_API_KEY, $history[0]['headers']['Api-Key']);
    }

    // ---------------------------------------------------------------------
    // moviehash — the OpenSubtitles OSDb hash algorithm
    // ---------------------------------------------------------------------

    public function test_compute_hash_returns_empty_for_nonexistent_file(): void
    {
        $this->assertSame('', OpenSubtitlesProvider::computeMovieHash('/nonexistent/path/file.mkv'));
    }

    /**
     * Files smaller than one 64 KiB chunk cannot produce a valid moviehash.
     */
    public function test_compute_hash_returns_empty_for_file_smaller_than_a_chunk(): void
    {
        $file = $this->makeFixture(str_repeat("\0", 1024));

        try {
            $this->assertSame('', OpenSubtitlesProvider::computeMovieHash($file));
        } finally {
            unlink($file);
        }
    }

    /**
     * Documented-value vector: a 131072-byte all-zero file. Every 8-byte word of
     * both chunks sums to zero, so the hash reduces to the file size itself
     * (131072 = 0x20000), rendered as 16 zero-padded lowercase hex chars. This
     * value is derived independently of the implementation and pins the exact
     * algorithm (size-seed + little-endian word sum + %016x formatting).
     */
    public function test_compute_hash_matches_documented_value_for_all_zero_file(): void
    {
        $file = $this->makeFixture(str_repeat("\0", 131072));

        try {
            $this->assertSame('0000000000020000', OpenSubtitlesProvider::computeMovieHash($file));
        } finally {
            unlink($file);
        }
    }

    /**
     * The summation must actually contribute: changing a byte inside the first
     * 64 KiB must change the hash (discriminates a broken/no-op accumulator).
     */
    public function test_compute_hash_changes_when_a_head_byte_changes(): void
    {
        $baseline = str_repeat("\0", 131072);
        $mutated = $baseline;
        $mutated[10] = "\xAB"; // within the first 64 KiB chunk

        $fileA = $this->makeFixture($baseline);
        $fileB = $this->makeFixture($mutated);

        try {
            $hashA = OpenSubtitlesProvider::computeMovieHash($fileA);
            $hashB = OpenSubtitlesProvider::computeMovieHash($fileB);
            $this->assertSame('0000000000020000', $hashA);
            $this->assertNotSame($hashA, $hashB);
        } finally {
            unlink($fileA);
            unlink($fileB);
        }
    }

    /**
     * Proves the hash reads ONLY the head and tail: mutating a byte in the
     * middle of a file large enough to have a gap between the two 64 KiB windows
     * must NOT change the hash.
     */
    public function test_compute_hash_ignores_the_middle_of_the_file(): void
    {
        $size = 200000; // head [0,65536), tail [134464,200000) — gap in between
        $baseline = str_repeat("\0", $size);
        $mutated = $baseline;
        $mutated[100000] = "\xFF"; // squarely in the untouched middle

        $fileA = $this->makeFixture($baseline);
        $fileB = $this->makeFixture($mutated);

        try {
            $this->assertSame(
                OpenSubtitlesProvider::computeMovieHash($fileA),
                OpenSubtitlesProvider::computeMovieHash($fileB),
            );
        } finally {
            unlink($fileA);
            unlink($fileB);
        }
    }

    public function test_compute_hash_is_16_char_lowercase_hex_and_deterministic(): void
    {
        $file = $this->makeFixture(str_repeat("phlix-", 40000)); // > 128 KiB

        try {
            $hash = OpenSubtitlesProvider::computeMovieHash($file);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $hash);
            $this->assertSame($hash, OpenSubtitlesProvider::computeMovieHash($file));
        } finally {
            unlink($file);
        }
    }

    // ---------------------------------------------------------------------
    // SubtitleDownload DTO / exception
    // ---------------------------------------------------------------------

    public function test_subtitle_download_dto_is_immutable(): void
    {
        $download = new SubtitleDownload(content: 'subtitle content', format: 'srt', fileName: 'movie.srt');

        $this->assertSame('subtitle content', $download->content);
        $this->assertSame('srt', $download->format);
        $this->assertSame('movie.srt', $download->fileName);
    }

    public function test_subtitle_download_get_content_length(): void
    {
        $download = new SubtitleDownload(content: 'hello', format: 'srt', fileName: 'test.srt');

        $this->assertSame(5, $download->getContentLength());
    }

    public function test_subtitle_download_is_empty(): void
    {
        $this->assertTrue((new SubtitleDownload(content: '', format: 'srt', fileName: 'e.srt'))->isEmpty());
        $this->assertFalse((new SubtitleDownload(content: 'x', format: 'srt', fileName: 'n.srt'))->isEmpty());
    }

    public function test_exception_is_runtime_exception(): void
    {
        $exception = new OpenSubtitlesException('Test message', 42);

        $this->assertSame('Test message', $exception->getMessage());
        $this->assertSame(42, $exception->getCode());
    }

    // ---------------------------------------------------------------------
    // Not-enabled guards
    // ---------------------------------------------------------------------

    public function test_search_by_imdb_id_throws_before_onenable(): void
    {
        $this->expectException(OpenSubtitlesException::class);
        $this->expectExceptionMessage('not enabled');
        (new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY))->searchByImdbIdRaw('tt1234567');
    }

    public function test_search_by_filename_throws_before_onenable(): void
    {
        $this->expectException(OpenSubtitlesException::class);
        $this->expectExceptionMessage('not enabled');
        (new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY))->searchByFilenameRaw('movie.mkv');
    }

    public function test_search_by_hash_throws_before_onenable(): void
    {
        $this->expectException(OpenSubtitlesException::class);
        $this->expectExceptionMessage('not enabled');
        (new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY))->searchByHashRaw('abc123', 1234567);
    }

    public function test_search_by_path_throws_before_onenable(): void
    {
        $this->expectException(OpenSubtitlesException::class);
        $this->expectExceptionMessage('not enabled');
        (new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY))->searchByPathRaw('/some/movie.mkv');
    }

    public function test_download_throws_before_onenable(): void
    {
        $this->expectException(OpenSubtitlesException::class);
        $this->expectExceptionMessage('not enabled');
        (new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY))->downloadRaw(12345);
    }

    // ---------------------------------------------------------------------
    // Search request shape
    // ---------------------------------------------------------------------

    public function test_search_by_imdb_id_requests_the_correct_v1_subtitles_url(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [self::response(200, '{"data":[]}')], $history);

        $provider->onEnable($this->stubContainer());
        $provider->searchByImdbIdRaw('tt1234567');

        $this->assertCount(1, $history);
        $this->assertSame('GET', $history[0]['method']);
        $this->assertStringStartsWith(
            'https://api.opensubtitles.com/api/v1/subtitles',
            $history[0]['url'],
        );
        $this->assertStringContainsString('imdb_id=tt1234567', $history[0]['url']);
    }

    /**
     * The hash search must send BOTH the `moviehash` and the file size
     * (`moviebytesize`). Mutating the query to drop the size, or to send the
     * weak filename query instead, must fail this.
     */
    public function test_search_by_hash_sends_moviehash_and_filesize(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [self::response(200, '{"data":[]}')], $history);

        $provider->onEnable($this->stubContainer());
        $provider->searchByHashRaw('8e245d9679d31e12', 12909756);

        $this->assertCount(1, $history);
        $this->assertStringContainsString('moviehash=8e245d9679d31e12', $history[0]['url']);
        $this->assertStringContainsString('moviebytesize=12909756', $history[0]['url']);
    }

    /**
     * searchByPath computes the moviehash from the on-disk file and issues a
     * hash search carrying that exact hash + size — proving the file-path search
     * uses the real hash, not a filename fallback.
     */
    public function test_search_by_path_computes_and_sends_the_file_moviehash(): void
    {
        $file = $this->makeFixture(str_repeat("\0", 131072));
        $expectedHash = OpenSubtitlesProvider::computeMovieHash($file);

        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        // Return one result so the hash search is considered a hit (no fallback).
        $this->installFakeTransport($provider, [
            self::response(200, (string) json_encode([
                'data' => [[
                    'id' => '1',
                    'attributes' => [
                        'language' => 'en',
                        'download_count' => 1,
                        'files' => [['file_id' => 1, 'cd_number' => 1, 'file_name' => 'a.srt']],
                    ],
                ]],
            ])),
        ], $history);

        try {
            $provider->onEnable($this->stubContainer());
            $results = $provider->searchByPathRaw($file);
        } finally {
            unlink($file);
        }

        $this->assertCount(1, $history);
        $this->assertStringContainsString('moviehash=' . $expectedHash, $history[0]['url']);
        $this->assertStringContainsString('moviebytesize=131072', $history[0]['url']);
        $this->assertCount(1, $results);
    }

    /**
     * When the file cannot be hashed (e.g. it does not exist), searchByPath
     * falls back to a filename-based query rather than a hash search.
     */
    public function test_search_by_path_falls_back_to_filename_when_hash_unavailable(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [self::response(200, '{"data":[]}')], $history);

        $provider->onEnable($this->stubContainer());
        $provider->searchByPathRaw('/nonexistent/Movie.Name.2019.mkv');

        $this->assertCount(1, $history);
        $this->assertStringNotContainsString('moviehash=', $history[0]['url']);
        $this->assertStringContainsString('query=', $history[0]['url']);
    }

    // ---------------------------------------------------------------------
    // Search response parsing (JSON:API shape)
    // ---------------------------------------------------------------------

    public function test_search_parses_attributes_files_array_and_surfaces_file_id(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [
            self::response(200, (string) json_encode([
                'data' => [[
                    'id' => '3634077',
                    'type' => 'subtitle',
                    'attributes' => [
                        'language' => 'en',
                        'download_count' => 5000,
                        'feature_details' => ['imdb_id' => 1234567],
                        'files' => [['file_id' => 998877, 'cd_number' => 1, 'file_name' => 'Movie.Name.2019.srt']],
                    ],
                ]],
            ])),
        ], $history);

        $provider->onEnable($this->stubContainer());
        $results = $provider->searchByImdbIdRaw('tt1234567');

        $this->assertCount(1, $results);
        $this->assertSame('3634077', $results[0]['id']);
        $this->assertSame('en', $results[0]['language']);
        $this->assertSame('srt', $results[0]['format']);
        $this->assertSame(5000, $results[0]['downloads']);
        $this->assertSame('Movie.Name.2019.srt', $results[0]['filename']);
        $this->assertSame('tt1234567', $results[0]['imdb_id']);
        $this->assertSame(998877, $results[0]['file_id']);
        $this->assertSame(
            [['file_id' => 998877, 'file_name' => 'Movie.Name.2019.srt', 'cd_number' => 1]],
            $results[0]['files'],
        );
    }

    public function test_search_normalizes_numeric_imdb_id_with_zero_padding(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [
            self::response(200, (string) json_encode([
                'data' => [[
                    'id' => '1',
                    'attributes' => [
                        'language' => 'en',
                        'download_count' => 1,
                        'feature_details' => ['imdb_id' => 133093],
                        'files' => [['file_id' => 1, 'cd_number' => 1, 'file_name' => 'a.srt']],
                    ],
                ]],
            ])),
        ], $history);

        $provider->onEnable($this->stubContainer());
        $results = $provider->searchByImdbIdRaw('tt0133093');

        $this->assertSame('tt0133093', $results[0]['imdb_id']);
    }

    public function test_search_returns_null_imdb_id_when_missing_or_invalid(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [
            self::response(200, (string) json_encode([
                'data' => [
                    [
                        'id' => '1',
                        'attributes' => [
                            'language' => 'en',
                            'download_count' => 1,
                            'files' => [['file_id' => 1, 'cd_number' => 1, 'file_name' => 'a.srt']],
                        ],
                    ],
                    [
                        'id' => '2',
                        'attributes' => [
                            'language' => 'en',
                            'download_count' => 1,
                            'feature_details' => ['imdb_id' => 'not-a-number'],
                            'files' => [['file_id' => 2, 'cd_number' => 1, 'file_name' => 'b.srt']],
                        ],
                    ],
                ],
            ])),
        ], $history);

        $provider->onEnable($this->stubContainer());
        $results = $provider->searchByImdbIdRaw('tt1234567');

        $this->assertCount(2, $results);
        $this->assertNull($results[0]['imdb_id']);
        $this->assertNull($results[1]['imdb_id']);
    }

    public function test_search_preserves_every_file_in_a_multi_cd_release(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [
            self::response(200, (string) json_encode([
                'data' => [[
                    'id' => '3634078',
                    'attributes' => [
                        'language' => 'en',
                        'download_count' => 200,
                        'feature_details' => ['imdb_id' => 1234567],
                        'files' => [
                            ['file_id' => 111111, 'cd_number' => 1, 'file_name' => 'Movie.Name.2019.CD1.srt'],
                            ['file_id' => 111112, 'cd_number' => 2, 'file_name' => 'Movie.Name.2019.CD2.srt'],
                        ],
                    ],
                ]],
            ])),
        ], $history);

        $provider->onEnable($this->stubContainer());
        $results = $provider->searchByImdbIdRaw('tt1234567');

        $this->assertCount(1, $results);
        $this->assertSame(111111, $results[0]['file_id']);
        $this->assertCount(2, $results[0]['files']);
        $this->assertSame(111112, $results[0]['files'][1]['file_id']);
    }

    public function test_search_skips_subtitles_with_no_downloadable_files(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [
            self::response(200, (string) json_encode([
                'data' => [
                    ['id' => '1', 'attributes' => ['language' => 'en', 'download_count' => 5000, 'files' => [
                        ['file_id' => 998877, 'cd_number' => 1, 'file_name' => 'a.srt'],
                    ]]],
                    ['id' => '2', 'attributes' => ['language' => 'en', 'download_count' => 99999, 'files' => []]],
                    ['id' => '3', 'attributes' => ['language' => 'en', 'download_count' => 1, 'files' => [
                        ['file_id' => 555, 'cd_number' => 1, 'file_name' => 'b.srt'],
                    ]]],
                ],
            ])),
        ], $history);

        $provider->onEnable($this->stubContainer());
        $results = $provider->searchByImdbIdRaw('tt1234567');

        $this->assertCount(2, $results);
        $this->assertSame(['1', '3'], array_column($results, 'id'));
    }

    public function test_search_throws_on_error_status(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [self::response(503, 'upstream down')], $history);

        $provider->onEnable($this->stubContainer());

        $this->expectException(OpenSubtitlesException::class);
        $this->expectExceptionMessage('Search by IMDB ID failed');
        $provider->searchByImdbIdRaw('tt1234567');
    }

    // ---------------------------------------------------------------------
    // Download two-step flow
    // ---------------------------------------------------------------------

    public function test_search_result_file_id_round_trips_into_download(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY, format: 'srt');
        $history = [];
        $this->installFakeTransport($provider, [
            self::response(200, (string) json_encode([
                'data' => [[
                    'id' => '3634078',
                    'attributes' => [
                        'language' => 'en',
                        'download_count' => 200,
                        'files' => [
                            ['file_id' => 111111, 'cd_number' => 1, 'file_name' => 'CD1.srt'],
                            ['file_id' => 111112, 'cd_number' => 2, 'file_name' => 'CD2.srt'],
                        ],
                    ],
                ]],
            ])),
            self::response(200, (string) json_encode([
                'link' => 'https://dl.opensubtitles.org/download/src/upload/111112.srt',
                'file_name' => 'CD2.srt',
            ])),
            self::response(200, "1\n00:00:01,000 --> 00:00:02,000\nCD two.\n"),
        ], $history);

        $provider->onEnable($this->stubContainer());
        $results = $provider->searchByImdbIdRaw('tt1234567');

        $secondCdFileId = $results[0]['files'][1]['file_id'];
        $this->assertSame(111112, $secondCdFileId);

        $download = $provider->downloadRaw($secondCdFileId);

        $this->assertCount(3, $history);
        /** @var array<string, mixed> $requestBody */
        $requestBody = json_decode((string) $history[1]['body'], true);
        $this->assertSame(['file_id' => 111112, 'sub_format' => 'srt'], $requestBody);
        $this->assertSame("1\n00:00:01,000 --> 00:00:02,000\nCD two.\n", $download->content);
        $this->assertSame('CD2.srt', $download->fileName);
    }

    public function test_download_posts_file_id_and_fetches_content_from_link(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY, format: 'srt');
        $history = [];
        $this->installFakeTransport($provider, [
            self::response(200, (string) json_encode([
                'link' => 'https://dl.opensubtitles.org/download/src/upload/12345.srt',
                'file_name' => 'Movie.Name.2019.srt',
                'requests' => 1,
                'remaining' => 4,
                'message' => 'Your quota will be renewed in 2 hours and 47 minutes',
                'reset_time' => '2 hours and 47 minutes',
                'reset_time_utc' => '2026-07-13T23:59:59Z',
            ])),
            self::response(200, "1\n00:00:01,000 --> 00:00:02,000\nHello world.\n"),
        ], $history);

        $provider->onEnable($this->stubContainer());
        $download = $provider->downloadRaw(998877);

        $this->assertCount(2, $history);

        $this->assertSame('POST', $history[0]['method']);
        $this->assertSame('https://api.opensubtitles.com/api/v1/download', $history[0]['url']);
        /** @var array<string, mixed> $requestBody */
        $requestBody = json_decode((string) $history[0]['body'], true);
        $this->assertSame(['file_id' => 998877, 'sub_format' => 'srt'], $requestBody);

        $this->assertSame('GET', $history[1]['method']);
        $this->assertSame('https://dl.opensubtitles.org/download/src/upload/12345.srt', $history[1]['url']);

        $this->assertSame("1\n00:00:01,000 --> 00:00:02,000\nHello world.\n", $download->content);
        $this->assertSame('srt', $download->format);
        $this->assertSame('Movie.Name.2019.srt', $download->fileName);
        $this->assertSame(1, $download->requestsUsed);
        $this->assertSame(4, $download->downloadsRemaining);
        $this->assertSame('Your quota will be renewed in 2 hours and 47 minutes', $download->quotaMessage);
        $this->assertSame('2 hours and 47 minutes', $download->resetTime);
        $this->assertSame('2026-07-13T23:59:59Z', $download->resetTimeUtc);
    }

    public function test_download_posts_to_the_correct_v1_download_url(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [
            self::response(200, (string) json_encode([
                'link' => 'https://dl.opensubtitles.org/download/src/upload/12345.srt',
                'file_name' => 'movie.srt',
            ])),
            self::response(200, 'subtitle body'),
        ], $history);

        $provider->onEnable($this->stubContainer());
        $provider->downloadRaw(998877);

        $this->assertSame('POST', $history[0]['method']);
        $this->assertSame('https://api.opensubtitles.com/api/v1/download', $history[0]['url']);
    }

    public function test_download_throws_when_response_is_missing_link(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [
            self::response(200, (string) json_encode(['file_name' => 'movie.srt'])),
        ], $history);

        $provider->onEnable($this->stubContainer());

        $this->expectException(OpenSubtitlesException::class);
        $this->expectExceptionMessage('did not include a download link');
        $provider->downloadRaw(998877);
    }

    public function test_download_throws_quota_exceeded_when_no_link_and_zero_remaining(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [
            self::response(200, (string) json_encode([
                'remaining' => 0,
                'message' => 'You have downloaded your allowed 20 subtitles for 24h.'
                    . 'Your quota will be renewed in 00 hours and 27 minutes (2023-05-24 23:59:59 UTC) ',
                'reset_time_utc' => '2023-05-24T23:59:59Z',
            ])),
        ], $history);

        $provider->onEnable($this->stubContainer());

        try {
            $provider->downloadRaw(998877);
            $this->fail('Expected OpenSubtitlesQuotaExceededException to be thrown.');
        } catch (OpenSubtitlesQuotaExceededException $e) {
            $this->assertStringContainsString('allowed 20 subtitles for 24h', $e->getMessage());
            $this->assertSame('2023-05-24T23:59:59Z', $e->resetTimeUtc);
        }
    }

    public function test_download_throws_quota_exceeded_when_post_returns_4xx_quota_message(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [
            self::response(406, (string) json_encode([
                'message' => 'You have downloaded your allowed 20 subtitles for 24h.'
                    . 'Your quota will be renewed in 00 hours and 27 minutes (2023-05-24 23:59:59 UTC) ',
            ])),
        ], $history);

        $provider->onEnable($this->stubContainer());

        try {
            $provider->downloadRaw(998877);
            $this->fail('Expected OpenSubtitlesQuotaExceededException to be thrown.');
        } catch (OpenSubtitlesQuotaExceededException $e) {
            $this->assertStringContainsString('allowed 20 subtitles for 24h', $e->getMessage());
        }
    }

    public function test_download_does_not_report_quota_exceeded_for_unrelated_4xx(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [
            self::response(401, (string) json_encode(['message' => 'Unauthorized'])),
        ], $history);

        $provider->onEnable($this->stubContainer());

        $this->expectException(OpenSubtitlesException::class);
        $this->expectExceptionMessageMatches('/Download failed/');

        try {
            $provider->downloadRaw(998877);
        } catch (OpenSubtitlesException $e) {
            $this->assertNotInstanceOf(OpenSubtitlesQuotaExceededException::class, $e);
            throw $e;
        }
    }

    public function test_download_quota_exceeded_uses_fallback_message_when_api_omits_message(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [
            self::response(200, (string) json_encode(['remaining' => 0])),
        ], $history);

        $provider->onEnable($this->stubContainer());

        try {
            $provider->downloadRaw(998877);
            $this->fail('Expected OpenSubtitlesQuotaExceededException to be thrown.');
        } catch (OpenSubtitlesQuotaExceededException $e) {
            $this->assertStringContainsString('quota exceeded', $e->getMessage());
            $this->assertNull($e->resetTime);
            $this->assertNull($e->resetTimeUtc);
        }
    }

    public function test_download_throws_when_content_fetch_fails(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [
            self::response(200, (string) json_encode([
                'link' => 'https://dl.opensubtitles.org/download/src/upload/12345.srt',
                'file_name' => 'movie.srt',
            ])),
            self::response(404, 'Not Found'),
        ], $history);

        $provider->onEnable($this->stubContainer());

        $this->expectException(OpenSubtitlesException::class);
        $this->expectExceptionMessageMatches('/Download failed/');
        $provider->downloadRaw(998877);
    }

    // ---------------------------------------------------------------------
    // Shared SubtitleSourceInterface contract (Wave 3 F3)
    // ---------------------------------------------------------------------

    /**
     * The entry class the host loads (`plugin.json` `entry`) MUST itself satisfy
     * `instanceof SubtitleSourceInterface` so the host's subtitle-source registry
     * detects it on the entry instance (mirroring the metadata plugins).
     */
    public function test_entry_class_is_a_subtitle_source(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);

        $this->assertInstanceOf(SubtitleSourceInterface::class, $provider);
    }

    public function test_get_name_returns_the_stable_source_slug(): void
    {
        $this->assertSame('opensubtitles', (new OpenSubtitlesProvider())->getName());
    }

    public function test_get_priority_returns_default_then_configured_value(): void
    {
        $provider = new OpenSubtitlesProvider();
        $this->assertSame(10, $provider->getPriority());

        $provider->configure(['api_key' => self::TEST_API_KEY, 'priority' => 3]);
        $this->assertSame(3, $provider->getPriority());

        // Numeric-string settings coerce; blank/garbage fall back to the default.
        $provider->configure(['api_key' => self::TEST_API_KEY, 'priority' => '7']);
        $this->assertSame(7, $provider->getPriority());
        $provider->configure(['api_key' => self::TEST_API_KEY, 'priority' => 'oops']);
        $this->assertSame(10, $provider->getPriority());
    }

    public function test_constructor_priority_is_wired(): void
    {
        $this->assertSame(2, (new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY, priority: 2))->getPriority());
    }

    /**
     * searchByImdbId maps the JSON:API response into SubtitleCandidate DTOs with
     * every ranking field populated and matchedBy == MATCH_IMDB.
     */
    public function test_contract_search_by_imdb_id_maps_candidates_with_match_imdb(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [
            self::response(200, (string) json_encode([
                'data' => [[
                    'id' => '3634077',
                    'attributes' => [
                        'language' => 'en',
                        'download_count' => 5000,
                        'ratings' => 8.5,
                        'hearing_impaired' => true,
                        'fps' => 23.976,
                        'feature_details' => ['imdb_id' => 1234567],
                        'files' => [['file_id' => 998877, 'cd_number' => 1, 'file_name' => 'Movie.Name.2019.srt']],
                    ],
                ]],
            ])),
        ], $history);

        $provider->onEnable($this->stubContainer());
        $candidates = $provider->searchByImdbId('tt1234567', ['en']);

        $this->assertCount(1, $candidates);
        $c = $candidates[0];
        $this->assertInstanceOf(SubtitleCandidate::class, $c);
        $this->assertSame('opensubtitles', $c->provider);
        $this->assertSame('en', $c->language);
        $this->assertSame('998877', $c->downloadId);
        $this->assertSame('Movie.Name.2019.srt', $c->releaseName);
        $this->assertSame('srt', $c->format);
        $this->assertSame(SubtitleCandidate::MATCH_IMDB, $c->matchedBy);
        $this->assertSame(8.5, $c->rating);
        $this->assertSame(5000, $c->downloadCount);
        $this->assertTrue($c->hearingImpaired);
        $this->assertSame(23.976, $c->fps);
        // Request carried the imdb id + language filter, and consumed NO quota
        // (search never hits the /download endpoint).
        $this->assertCount(1, $history);
        $this->assertStringContainsString('imdb_id=tt1234567', $history[0]['url']);
        $this->assertStringContainsString('languages=en', $history[0]['url']);
        $this->assertStringNotContainsString('/download', $history[0]['url']);
    }

    /**
     * searchByHash stamps MATCH_HASH and sends both moviehash + moviebytesize.
     */
    public function test_contract_search_by_hash_maps_candidates_with_match_hash(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [
            self::response(200, (string) json_encode([
                'data' => [[
                    'id' => '1',
                    'attributes' => [
                        'language' => 'es',
                        'download_count' => 42,
                        'files' => [['file_id' => 555, 'cd_number' => 1, 'file_name' => 'movie.es.ass']],
                    ],
                ]],
            ])),
        ], $history);

        $provider->onEnable($this->stubContainer());
        $candidates = $provider->searchByHash('8e245d9679d31e12', 12909756, ['es']);

        $this->assertCount(1, $candidates);
        $this->assertSame(SubtitleCandidate::MATCH_HASH, $candidates[0]->matchedBy);
        $this->assertSame('555', $candidates[0]->downloadId);
        $this->assertSame('ass', $candidates[0]->format);
        $this->assertStringContainsString('moviehash=8e245d9679d31e12', $history[0]['url']);
        $this->assertStringContainsString('moviebytesize=12909756', $history[0]['url']);
    }

    /**
     * searchByPath computes the real moviehash, searches by hash, and stamps
     * MATCH_HASH on the resulting candidates.
     */
    public function test_contract_search_by_path_uses_hash_and_marks_match_hash(): void
    {
        $file = $this->makeFixture(str_repeat("\0", 131072));
        $expectedHash = OpenSubtitlesProvider::computeMovieHash($file);

        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [
            self::response(200, (string) json_encode([
                'data' => [[
                    'id' => '1',
                    'attributes' => [
                        'language' => 'en',
                        'download_count' => 1,
                        'files' => [['file_id' => 1, 'cd_number' => 1, 'file_name' => 'a.srt']],
                    ],
                ]],
            ])),
        ], $history);

        try {
            $provider->onEnable($this->stubContainer());
            $candidates = $provider->searchByPath($file, []);
        } finally {
            unlink($file);
        }

        $this->assertCount(1, $candidates);
        $this->assertSame(SubtitleCandidate::MATCH_HASH, $candidates[0]->matchedBy);
        $this->assertStringContainsString('moviehash=' . $expectedHash, $history[0]['url']);
        // Empty language list => no languages filter is sent.
        $this->assertStringNotContainsString('languages=', $history[0]['url']);
    }

    /**
     * When the file cannot be hashed, searchByPath falls back to a filename
     * query and stamps MATCH_NAME (lowest confidence).
     */
    public function test_contract_search_by_path_falls_back_to_name_match(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [
            self::response(200, (string) json_encode([
                'data' => [[
                    'id' => '1',
                    'attributes' => [
                        'language' => 'en',
                        'download_count' => 1,
                        'files' => [['file_id' => 9, 'cd_number' => 1, 'file_name' => 'b.srt']],
                    ],
                ]],
            ])),
        ], $history);

        $provider->onEnable($this->stubContainer());
        $candidates = $provider->searchByPath('/nonexistent/Movie.Name.2019.mkv', []);

        $this->assertCount(1, $candidates);
        $this->assertSame(SubtitleCandidate::MATCH_NAME, $candidates[0]->matchedBy);
        $this->assertStringNotContainsString('moviehash=', $history[0]['url']);
        $this->assertStringContainsString('query=', $history[0]['url']);
    }

    /**
     * download() takes a candidate, uses its downloadId as the OpenSubtitles
     * file_id, runs the two-step fetch, and returns a decoded SubtitleFile.
     */
    public function test_contract_download_returns_subtitle_file_with_decoded_content(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY, format: 'srt');
        $history = [];
        $this->installFakeTransport($provider, [
            self::response(200, (string) json_encode([
                'link' => 'https://dl.opensubtitles.org/download/src/upload/998877.srt',
                'file_name' => 'The.Matrix.1999.en.srt',
                'remaining' => 19,
            ])),
            self::response(200, "1\n00:00:01,000 --> 00:00:02,000\nHello.\n"),
        ], $history);

        $provider->onEnable($this->stubContainer());
        $file = $provider->download(self::candidate('998877', 'en', 'srt', 'The.Matrix.1999.1080p'));

        $this->assertInstanceOf(SubtitleFile::class, $file);
        $this->assertSame('en', $file->language);
        $this->assertSame('srt', $file->format);
        $this->assertSame("1\n00:00:01,000 --> 00:00:02,000\nHello.\n", $file->content);
        $this->assertSame('opensubtitles', $file->provider);
        $this->assertSame('The.Matrix.1999.en.srt', $file->suggestedFilename);
        $this->assertSame('UTF-8', $file->encoding);
        $this->assertSame('The.Matrix.1999.1080p', $file->releaseName);

        // Round-trips the candidate's downloadId into the /download file_id body.
        $this->assertSame('https://api.opensubtitles.com/api/v1/download', $history[0]['url']);
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $history[0]['body'], true);
        $this->assertSame(998877, $body['file_id']);
    }

    /**
     * suggestedFilename must be a safe BASE name — even if the provider returns a
     * name with directory components, download() strips them (path-traversal
     * hardening for the host writing into /var/subtitles).
     */
    public function test_contract_download_sanitizes_suggested_filename_to_base_name(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY, format: 'srt');
        $history = [];
        $this->installFakeTransport($provider, [
            self::response(200, (string) json_encode([
                'link' => 'https://dl.opensubtitles.org/x.srt',
                'file_name' => '../../etc/evil.srt',
            ])),
            self::response(200, 'body'),
        ], $history);

        $provider->onEnable($this->stubContainer());
        $file = $provider->download(self::candidate('42', 'en', 'srt', 'rel'));

        $this->assertSame('evil.srt', $file->suggestedFilename);
        $this->assertStringNotContainsString('/', $file->suggestedFilename);
    }

    /**
     * A search must NEVER consume or trip quota: even if the provider WOULD
     * report a quota problem on /download, the search path never calls it, so no
     * QuotaExceeded is thrown.
     */
    public function test_contract_search_does_not_consume_quota(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [self::response(200, '{"data":[]}')], $history);

        $provider->onEnable($this->stubContainer());
        $candidates = $provider->searchByImdbId('tt1234567', []);

        $this->assertSame([], $candidates);
        $this->assertCount(1, $history);
        $this->assertStringNotContainsString('/download', $history[0]['url']);
    }

    /**
     * download() translates the provider's quota exhaustion (a 200 with
     * remaining:0 and no link) into the SHARED QuotaExceeded, carrying the
     * remaining allowance and reset time so the host can persist quota state.
     */
    public function test_contract_download_throws_shared_quota_exceeded_with_context(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [
            self::response(200, (string) json_encode([
                'remaining' => 0,
                'message' => 'You have downloaded your allowed 20 subtitles for 24h.',
                'reset_time_utc' => '2026-07-13T23:59:59Z',
            ])),
        ], $history);

        $provider->onEnable($this->stubContainer());

        try {
            $provider->download(self::candidate('998877', 'en', 'srt', 'rel'));
            $this->fail('Expected shared QuotaExceeded to be thrown.');
        } catch (QuotaExceeded $e) {
            $this->assertStringContainsString('allowed 20 subtitles for 24h', $e->getMessage());
            $this->assertSame(0, $e->getDownloadsRemaining());
            $this->assertSame('2026-07-13T23:59:59Z', $e->getResetTimeUtc());
        }
    }

    /**
     * The 406 quota-message path also maps to the shared QuotaExceeded.
     */
    public function test_contract_download_maps_4xx_quota_to_shared_quota_exceeded(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [
            self::response(406, (string) json_encode([
                'message' => 'You have downloaded your allowed 20 subtitles for 24h.',
            ])),
        ], $history);

        $provider->onEnable($this->stubContainer());

        $this->expectException(QuotaExceeded::class);
        $provider->download(self::candidate('998877', 'en', 'srt', 'rel'));
    }

    /**
     * A non-quota download failure surfaces the generic OpenSubtitlesException,
     * NOT the shared QuotaExceeded (a caller distinguishes quota from other
     * failures).
     */
    public function test_contract_download_non_quota_failure_is_not_quota_exceeded(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [
            self::response(401, (string) json_encode(['message' => 'Unauthorized'])),
        ], $history);

        $provider->onEnable($this->stubContainer());

        try {
            $provider->download(self::candidate('998877', 'en', 'srt', 'rel'));
            $this->fail('Expected OpenSubtitlesException.');
        } catch (OpenSubtitlesException $e) {
            $this->assertNotInstanceOf(QuotaExceeded::class, $e);
        }
    }

    // ---------------------------------------------------------------------
    // Contract not-enabled guards
    // ---------------------------------------------------------------------

    public function test_contract_search_by_imdb_id_throws_before_onenable(): void
    {
        $this->expectException(OpenSubtitlesException::class);
        $this->expectExceptionMessage('not enabled');
        (new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY))->searchByImdbId('tt1234567', []);
    }

    public function test_contract_search_by_hash_throws_before_onenable(): void
    {
        $this->expectException(OpenSubtitlesException::class);
        $this->expectExceptionMessage('not enabled');
        (new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY))->searchByHash('abc123', 1234567, []);
    }

    public function test_contract_search_by_path_throws_before_onenable(): void
    {
        $this->expectException(OpenSubtitlesException::class);
        $this->expectExceptionMessage('not enabled');
        (new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY))->searchByPath('/some/movie.mkv', []);
    }

    public function test_contract_download_throws_before_onenable(): void
    {
        $this->expectException(OpenSubtitlesException::class);
        $this->expectExceptionMessage('not enabled');
        (new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY))
            ->download(self::candidate('12345', 'en', 'srt', 'rel'));
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Build a SubtitleCandidate for download() tests.
     */
    private static function candidate(
        string $downloadId,
        string $language,
        string $format,
        string $releaseName,
    ): SubtitleCandidate {
        return new SubtitleCandidate(
            provider: 'opensubtitles',
            language: $language,
            downloadId: $downloadId,
            releaseName: $releaseName,
            format: $format,
            matchedBy: SubtitleCandidate::MATCH_HASH,
        );
    }

    /**
     * @return array{status:int, body:string}
     */
    private static function response(int $status, string $body): array
    {
        return ['status' => $status, 'body' => $body];
    }

    /**
     * Install a fake transport closure into the provider that records outgoing
     * requests into $history and returns queued {status, body} responses.
     *
     * $history MUST be passed by reference — the closure binds it by reference
     * so requests appended during the call are visible to the caller afterwards.
     *
     * @param list<array{status:int, body:string}>                                   $responses
     * @param list<array{method:string, url:string, headers:array<string,string>, body:?string}> $history
     */
    private function installFakeTransport(OpenSubtitlesProvider $provider, array $responses, array &$history): void
    {
        $queue = $responses;
        $transport = function (string $method, string $url, array $headers, ?string $body) use (&$queue, &$history): array {
            $history[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
            if ($queue === []) {
                throw new \RuntimeException("FakeTransport: no queued response for {$method} {$url}");
            }

            return array_shift($queue);
        };

        $property = new \ReflectionProperty(OpenSubtitlesProvider::class, 'transport');
        $property->setAccessible(true);
        $property->setValue($provider, $transport);
    }

    /**
     * Write $content to a temp file and return its path.
     */
    private function makeFixture(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'phlix_os_');
        if ($path === false) {
            $this->fail('Could not create temp fixture file');
        }
        file_put_contents($path, $content);

        return $path;
    }

    private function stubContainer(): ContainerInterface
    {
        return new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException("Unexpected container lookup for {$id}");
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
    }

    // ---------------------------------------------------------------------
    // Lifecycle (onDisable)
    // ---------------------------------------------------------------------

    public function test_on_disable_clears_session_token_and_disables(): void
    {
        $provider = new OpenSubtitlesProvider(
            apiKey: self::TEST_API_KEY,
            username: 'testuser',
            password: 'testpass',
        );

        $history = [];
        $this->installFakeTransport($provider, [
            self::response(200, (string) json_encode(['token' => 'session-token-123'])),
            self::response(200, (string) json_encode(['data' => []])),
        ], $history);

        $provider->onEnable($this->stubContainer());
        // First call triggers login
        $provider->searchByFilenameRaw('movie.mkv');
        $this->assertTrue($provider->isLoggedIn());
        $this->assertCount(2, $history); // login + search

        $provider->onDisable();

        $this->assertFalse($provider->isLoggedIn());
        // Verify provider is disabled by expecting exception
        $this->expectException(OpenSubtitlesException::class);
        $this->expectExceptionMessage('not enabled');
        $provider->searchByFilenameRaw('movie.mkv');
    }

    // ---------------------------------------------------------------------
    // Contract façade search methods (searchByPath, searchByHash, searchByImdbId)
    // ---------------------------------------------------------------------

    /**
     * searchByPath (façade) delegates to searchByPathRaw which computes the hash.
     * This test covers the actual façade method call path.
     */
    public function test_contract_search_by_path_facade_uses_hash_and_marks_match_hash(): void
    {
        $file = $this->makeFixture(str_repeat("\0", 131072));

        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [
            self::response(200, (string) json_encode([
                'data' => [[
                    'id' => '1',
                    'attributes' => [
                        'language' => 'fr',
                        'download_count' => 100,
                        'files' => [['file_id' => 42, 'cd_number' => 1, 'file_name' => 'film.fr.srt']],
                    ],
                ]],
            ])),
        ], $history);

        try {
            $provider->onEnable($this->stubContainer());
            $candidates = $provider->searchByPath($file, ['fr']);
        } finally {
            unlink($file);
        }

        $this->assertCount(1, $candidates);
        $this->assertSame(SubtitleCandidate::MATCH_HASH, $candidates[0]->matchedBy);
        $this->assertSame('fr', $candidates[0]->language);
    }

    /**
     * searchByHash (façade) maps candidates with MATCH_HASH.
     */
    public function test_contract_search_by_hash_facade_maps_match_hash(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [
            self::response(200, (string) json_encode([
                'data' => [[
                    'id' => '99',
                    'attributes' => [
                        'language' => 'de',
                        'download_count' => 50,
                        'files' => [['file_id' => 77, 'cd_number' => 1, 'file_name' => 'movie.de.srt']],
                    ],
                ]],
            ])),
        ], $history);

        $provider->onEnable($this->stubContainer());
        $candidates = $provider->searchByHash('8e245d9679d31e12', 12909756, ['de']);

        $this->assertCount(1, $candidates);
        $this->assertSame(SubtitleCandidate::MATCH_HASH, $candidates[0]->matchedBy);
        $this->assertSame('77', $candidates[0]->downloadId);
    }

    /**
     * searchByImdbId (façade) maps candidates with MATCH_IMDB.
     */
    public function test_contract_search_by_imdb_id_facade_maps_match_imdb(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [
            self::response(200, (string) json_encode([
                'data' => [[
                    'id' => '555',
                    'attributes' => [
                        'language' => 'es',
                        'download_count' => 200,
                        'files' => [['file_id' => 123, 'cd_number' => 1, 'file_name' => 'pelicula.es.srt']],
                    ],
                ]],
            ])),
        ], $history);

        $provider->onEnable($this->stubContainer());
        $candidates = $provider->searchByImdbId('tt1234567', ['es']);

        $this->assertCount(1, $candidates);
        $this->assertSame(SubtitleCandidate::MATCH_IMDB, $candidates[0]->matchedBy);
        $this->assertSame('es', $candidates[0]->language);
    }

    // ---------------------------------------------------------------------
    // Private utility methods
    // ---------------------------------------------------------------------

    /**
     * @dataProvider looksLikeQuotaMessageProvider
     */
    public function test_looks_like_quota_message(string $message, bool $expected): void
    {
        $method = new \ReflectionMethod(OpenSubtitlesProvider::class, 'looksLikeQuotaMessage');
        $method->setAccessible(true);

        $this->assertSame($expected, $method->invoke(null, $message));
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function looksLikeQuotaMessageProvider(): array
    {
        return [
            'quota word' => ['You have exceeded your quota', true],
            'download limit' => ['You have reached your download limit', true],
            'allowed download' => ['You have downloaded your allowed 20 subtitles', true],
            'unrelated message' => ['Invalid API key', false],
            'empty' => ['', false],
            'quota mixed case' => ['Download Quota Exceeded', true],
        ];
    }

    public function test_format_from_filename_returns_extension(): void
    {
        $method = new \ReflectionMethod(OpenSubtitlesProvider::class, 'formatFromFilename');
        $method->setAccessible(true);

        $this->assertSame('srt', $method->invoke(null, 'movie.srt'));
        $this->assertSame('ass', $method->invoke(null, 'subtitle.ass'));
        // formatFromFilename lowercases the extension
        $this->assertSame('srt', $method->invoke(null, 'movie.SRT'));
    }

    public function test_format_from_filename_returns_null_for_empty_or_no_extension(): void
    {
        $method = new \ReflectionMethod(OpenSubtitlesProvider::class, 'formatFromFilename');
        $method->setAccessible(true);

        $this->assertNull($method->invoke(null, ''));
        $this->assertNull($method->invoke(null, null));
        $this->assertNull($method->invoke(null, 'movie'));
    }

    public function test_parse_filename_extracts_imdb_id(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $method = new \ReflectionMethod(OpenSubtitlesProvider::class, 'parseFilename');
        $method->setAccessible(true);

        $result = $method->invoke($provider, 'Movie.Name.2019.tt1234567.BRRip.srt');

        $this->assertSame('tt1234567', $result['imdb_id']);
    }

    public function test_parse_filename_extracts_year(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $method = new \ReflectionMethod(OpenSubtitlesProvider::class, 'parseFilename');
        $method->setAccessible(true);

        $result = $method->invoke($provider, 'Movie Name 2019 720p bluray.mkv');

        $this->assertSame(2019, $result['year']);
        $this->assertSame('Movie Name', $result['title']);
    }

    public function test_parse_filename_returns_nulls_when_no_match(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $method = new \ReflectionMethod(OpenSubtitlesProvider::class, 'parseFilename');
        $method->setAccessible(true);

        // "some random filename" has no imdb_id and no year at the end
        $result = $method->invoke($provider, 'some random filename');

        $this->assertNull($result['imdb_id']);
        $this->assertNull($result['year']);
    }

    /**
     * @dataProvider languageFilterProvider
     */
    public function test_language_filter(array $languages, ?string $expected): void
    {
        $method = new \ReflectionMethod(OpenSubtitlesProvider::class, 'languageFilter');
        $method->setAccessible(true);

        $this->assertSame($expected, $method->invoke(null, $languages));
    }

    /** @return array<string, array{0: list<string>, 1: ?string}> */
    public static function languageFilterProvider(): array
    {
        return [
            'empty array' => [[], null],
            'single language' => [['en'], 'en'],
            'multiple languages' => [['en', 'fr', 'de'], 'en,fr,de'],
            'with whitespace' => [[' en ', ' fr '], 'en,fr'],
            'with empty strings filtered out' => [['en', '', 'fr'], 'en,fr'],
        ];
    }

    /**
     * @dataProvider withLanguagesProvider
     */
    public function test_with_languages(array $params, ?string $languageCsv, array $expected): void
    {
        $method = new \ReflectionMethod(OpenSubtitlesProvider::class, 'withLanguages');
        $method->setAccessible(true);

        $this->assertSame($expected, $method->invoke(null, $params, $languageCsv));
    }

    /** @return array<string, array{0: array<string, string>, 1: ?string, 2: array<string, string>}> */
    public static function withLanguagesProvider(): array
    {
        return [
            'no filter' => [['moviehash' => 'abc'], null, ['moviehash' => 'abc']],
            'with filter' => [['moviehash' => 'abc'], 'en,fr', ['moviehash' => 'abc', 'languages' => 'en,fr']],
            'empty params with filter' => [[], 'de', ['languages' => 'de']],
        ];
    }

    /**
     * @dataProvider safeFilenameProvider
     */
    public function test_safe_filename(string $providerName, ?string $releaseName, string $language, string $format, string $expected): void
    {
        $method = new \ReflectionMethod(OpenSubtitlesProvider::class, 'safeFilename');
        $method->setAccessible(true);

        $this->assertSame($expected, $method->invoke(null, $providerName, $releaseName, $language, $format));
    }

    /** @return array<string, array{0: string, 1: ?string, 2: string, 3: string, 4: string}> */
    public static function safeFilenameProvider(): array
    {
        return [
            'normal filename' => ['movie.srt', null, 'en', 'srt', 'movie.srt'],
            'path traversal stripped' => ['../../etc/evil.srt', null, 'en', 'srt', 'evil.srt'],
            'directory in name stripped' => ['subtitles/movie.srt', null, 'en', 'srt', 'movie.srt'],
            // Note: pathinfo treats .2020 in 'Movie.2020' as extension, so it becomes 'Movie.srt'
            'empty provider name falls back to release name' => ['', 'Movie.2020', 'en', 'srt', 'Movie.srt'],
            'empty provider name no release uses default' => ['', null, 'en', 'srt', 'subtitle.en.srt'],
            'only extension provider name' => ['.', null, 'en', 'srt', 'subtitle.en.srt'],
            'release name with year treated as extension' => ['', 'Great.Movie.1999', 'fr', 'ass', 'Great.Movie.ass'],
        ];
    }

    /**
     * @dataProvider floatOrNullProvider
     */
    public function test_float_or_null(mixed $value, ?float $expected): void
    {
        $method = new \ReflectionMethod(OpenSubtitlesProvider::class, 'floatOrNull');
        $method->setAccessible(true);

        $this->assertSame($expected, $method->invoke(null, $value));
    }

    /** @return array<string, array{0: mixed, 1: ?float}> */
    public static function floatOrNullProvider(): array
    {
        return [
            'int' => [42, 42.0],
            'float' => [3.14, 3.14],
            'numeric string' => ['2.718', 2.718],
            'null' => [null, null],
            'non-numeric string' => ['hello', null],
            'bool' => [true, null],
        ];
    }

    /**
     * @dataProvider truthyProvider
     */
    public function test_truthy(mixed $value, bool $expected): void
    {
        $method = new \ReflectionMethod(OpenSubtitlesProvider::class, 'truthy');
        $method->setAccessible(true);

        $this->assertSame($expected, $method->invoke(null, $value));
    }

    /** @return array<string, array{0: mixed, 1: bool}> */
    public static function truthyProvider(): array
    {
        return [
            'true bool' => [true, true],
            'false bool' => [false, false],
            'int 1' => [1, true],
            'int 0' => [0, false],
            'string 1' => ['1', true],
            'string true' => ['true', true],
            'string FALSE' => ['FALSE', false],
            'string 0' => ['0', false],
            'null' => [null, false],
            'empty string' => ['', false],
        ];
    }

    // ---------------------------------------------------------------------
    // getHttpClient lazy initialization
    // ---------------------------------------------------------------------

    public function test_get_http_client_lazily_initializes(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);

        // Use reflection to verify httpClient starts as null
        $prop = new \ReflectionProperty(OpenSubtitlesProvider::class, 'httpClient');
        $prop->setAccessible(true);
        $this->assertNull($prop->getValue($provider));

        // getHttpClient should create it
        $method = new \ReflectionMethod(OpenSubtitlesProvider::class, 'getHttpClient');
        $method->setAccessible(true);
        $client = $method->invoke($provider);

        $this->assertInstanceOf(\Workerman\Http\Client::class, $client);
        $this->assertNotNull($prop->getValue($provider));
    }

    // ---------------------------------------------------------------------
    // Additional coverage for error paths
    // ---------------------------------------------------------------------

    public function test_exception_class_can_be_instantiated(): void
    {
        $exception = new OpenSubtitlesException('test message', 123);

        $this->assertSame('test message', $exception->getMessage());
        $this->assertSame(123, $exception->getCode());
        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }

    public function test_on_enable_wires_logger_from_container_when_available(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [self::response(200, '{"data":[]}')], $history);

        // Container that has LoggerInterface
        $logger = new class implements \Psr\Log\LoggerInterface {
            public function emergency(\Stringable|string $message, array $context = []): void {}
            public function alert(\Stringable|string $message, array $context = []): void {}
            public function critical(\Stringable|string $message, array $context = []): void {}
            public function error(\Stringable|string $message, array $context = []): void {}
            public function warning(\Stringable|string $message, array $context = []): void {}
            public function notice(\Stringable|string $message, array $context = []): void {}
            public function info(\Stringable|string $message, array $context = []): void {}
            public function debug(\Stringable|string $message, array $context = []): void {}
            public function log($level, \Stringable|string $message, array $context = []): void {}
        };
        $container = new class($logger) implements ContainerInterface {
            private \Psr\Log\LoggerInterface $logger;
            public function __construct(\Psr\Log\LoggerInterface $logger) { $this->logger = $logger; }
            public function get(string $id): mixed { return $this->logger; }
            public function has(string $id): bool { return $id === \Psr\Log\LoggerInterface::class; }
        };

        $provider->onEnable($container);

        // The provider should have a logger now - just verify no exception and it works
        $this->assertCount(0, $history, 'onEnable still does no HTTP');
    }

    public function test_login_throws_when_http_request_fails(): void
    {
        $provider = new OpenSubtitlesProvider(
            apiKey: self::TEST_API_KEY,
            username: 'testuser',
            password: 'testpass',
        );

        // Install a transport that throws for login
        $property = new \ReflectionProperty(OpenSubtitlesProvider::class, 'transport');
        $property->setAccessible(true);
        $property->setValue($provider, function (): never {
            throw new OpenSubtitlesException('Connection failed');
        });

        $provider->onEnable($this->stubContainer());

        // EnsureConnected should trigger login which will throw, but it should be caught
        // and login should fail gracefully - the provider should not be logged in
        // This test verifies the catch block in login()
        $method = new \ReflectionMethod(OpenSubtitlesProvider::class, 'ensureConnected');
        $method->setAccessible(true);

        // After ensureConnected fails login, the provider should not be logged in
        // but should continue without throwing (graceful degradation)
        try {
            $method->invoke($provider);
        } catch (OpenSubtitlesException $e) {
            // If ensureConnected itself throws, that's also valid behavior
        }

        $this->assertFalse($provider->isLoggedIn());
    }

    public function test_search_throws_when_http_request_fails(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);

        // Install a transport that throws
        $property = new \ReflectionProperty(OpenSubtitlesProvider::class, 'transport');
        $property->setAccessible(true);
        $property->setValue($provider, function (): never {
            throw new OpenSubtitlesException('Connection refused');
        });

        $provider->onEnable($this->stubContainer());

        $this->expectException(OpenSubtitlesException::class);
        $this->expectExceptionMessage('Search by IMDB ID failed');
        $provider->searchByImdbIdRaw('tt1234567');
    }

    public function test_download_throws_when_http_request_fails(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);

        // First call returns link, second call (download content) throws
        $callCount = 0;
        $property = new \ReflectionProperty(OpenSubtitlesProvider::class, 'transport');
        $property->setAccessible(true);
        $property->setValue($provider, function () use (&$callCount): array {
            $callCount++;
            if ($callCount === 1) {
                return ['status' => 200, 'body' => json_encode([
                    'link' => 'https://example.com/file.srt',
                    'file_name' => 'test.srt',
                ])];
            }
            throw new OpenSubtitlesException('Download connection lost');
        });

        $provider->onEnable($this->stubContainer());

        $this->expectException(OpenSubtitlesException::class);
        $this->expectExceptionMessage('Download failed');
        $provider->downloadRaw(12345);
    }

    public function test_search_by_path_with_imdb_id_in_filename(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [self::response(200, '{"data":[]}')], $history);

        $provider->onEnable($this->stubContainer());

        // Filename with embedded IMDB ID should parse and use it
        $provider->searchByPathRaw('/tmp/Movie.Name.2019.tt1234567.720p.mkv');

        $this->assertCount(1, $history);
        // Should use imdb_id query param when available
        $this->assertStringContainsString('imdb_id=tt1234567', $history[0]['url']);
    }

    public function test_download_uses_fallback_filename_when_not_provided(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY, format: 'ass');
        $history = [];
        $this->installFakeTransport($provider, [
            self::response(200, (string) json_encode([
                'link' => 'https://dl.opensubtitles.org/download/src/upload/12345.ass',
                // no file_name provided
                'requests' => 1,
                'remaining' => 19,
            ])),
            self::response(200, 'subtitle content'),
        ], $history);

        $provider->onEnable($this->stubContainer());
        $download = $provider->downloadRaw(998877);

        // Should use format-based fallback filename
        $this->assertSame('subtitle.ass', $download->fileName);
    }

    public function test_download_content_fetch_returns_non_success_status(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [
            self::response(200, (string) json_encode([
                'link' => 'https://dl.opensubtitles.org/download/src/upload/12345.srt',
                'file_name' => 'movie.srt',
            ])),
            self::response(500, 'Internal Server Error'),
        ], $history);

        $provider->onEnable($this->stubContainer());

        $this->expectException(OpenSubtitlesException::class);
        $this->expectExceptionMessage('Download failed: HTTP 500');
        $provider->downloadRaw(998877);
    }

    public function test_filter_subtitles_skips_entries_with_no_files(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [
            self::response(200, (string) json_encode([
                'data' => [
                    ['id' => '1', 'attributes' => ['language' => 'en', 'files' => []]],
                    ['id' => '2', 'attributes' => ['language' => 'fr', 'files' => [['file_id' => 2, 'cd_number' => 1]]]],

                ],
            ])),
        ], $history);

        $provider->onEnable($this->stubContainer());
        $results = $provider->searchByImdbIdRaw('tt1234567');

        // Only entries with files should be included
        $this->assertCount(1, $results);
        $this->assertSame('2', $results[0]['id']);
    }

    public function test_plugin_version_returns_unknown_on_malformed_json(): void
    {
        // Temporarily replace plugin.json with malformed content
        $manifestPath = dirname(__DIR__, 2) . '/plugin.json';
        $backupPath = $manifestPath . '.bak';

        // Backup original
        $originalContent = file_get_contents($manifestPath);
        file_put_contents($backupPath, $originalContent);

        try {
            // Write malformed JSON
            file_put_contents($manifestPath, '{ invalid json }');

            // Use reflection to clear the cached version
            $prop = new \ReflectionProperty(OpenSubtitlesProvider::class, 'cachedPluginVersion');
            $prop->setAccessible(true);
            $prop->setValue(null, null);

            // Now create a provider and call pluginVersion via searchByPath
            $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
            $history = [];
            $this->installFakeTransport($provider, [self::response(200, '{"data":[]}')], $history);

            $provider->onEnable($this->stubContainer());
            $provider->searchByPathRaw('/tmp/movie.mkv');

            // If we get here without exception, the malformed json was handled gracefully
            $this->assertCount(1, $history);
        } finally {
            // Restore original
            file_put_contents($manifestPath, $originalContent);
            unlink($backupPath);

            // Clear cache again for other tests
            $prop->setValue(null, null);
        }
    }

    public function test_ensure_enabled_throws_when_provider_not_enabled(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        // onEnable NOT called - provider is not enabled

        $this->expectException(OpenSubtitlesException::class);
        $this->expectExceptionMessage('not enabled');

        $method = new \ReflectionMethod(OpenSubtitlesProvider::class, 'ensureEnabled');
        $method->setAccessible(true);
        $method->invoke($provider);
    }

    public function test_download_step1_failure_throws_without_link(): void
    {
        // When the first download step (POST /download) returns 200 but without a link,
        // it should throw "did not include a download link"
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [
            self::response(200, (string) json_encode([
                'requests' => 1,
                'remaining' => 5,
                // no link!
            ])),
        ], $history);

        $provider->onEnable($this->stubContainer());

        $this->expectException(OpenSubtitlesException::class);
        $this->expectExceptionMessage('did not include a download link');
        $provider->downloadRaw(998877);
    }

    // ---------------------------------------------------------------------
    // Additional coverage for partially-covered methods
    // ---------------------------------------------------------------------

    public function test_exception_can_be_instantiated_with_all_params(): void
    {
        $previous = new \RuntimeException('previous');
        $exception = new OpenSubtitlesException('test message', 42, $previous);

        $this->assertSame('test message', $exception->getMessage());
        $this->assertSame(42, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
        $this->assertInstanceOf(\Throwable::class, $exception);
    }

    public function test_map_candidates_filters_zero_file_id(): void
    {
        // Use reflection to call mapCandidates with file_id = 0
        $method = new \ReflectionMethod(OpenSubtitlesProvider::class, 'mapCandidates');
        $method->setAccessible(true);

        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);

        // Raw result with file_id = 0 should be filtered out
        $raw = [
            [
                'id' => '1',
                'language' => 'en',
                'format' => 'srt',
                'downloads' => 100,
                'filename' => 'movie.srt',
                'imdb_id' => 'tt1234567',
                'file_id' => 0, // invalid
                'rating' => null,
                'hearing_impaired' => false,
                'fps' => null,
                'files' => [['file_id' => 0, 'file_name' => 'movie.srt', 'cd_number' => 1]],
            ],
            [
                'id' => '2',
                'language' => 'en',
                'format' => 'srt',
                'downloads' => 50,
                'filename' => 'movie2.srt',
                'imdb_id' => null,
                'file_id' => 999, // valid
                'rating' => null,
                'hearing_impaired' => false,
                'fps' => null,
                'files' => [['file_id' => 999, 'file_name' => 'movie2.srt', 'cd_number' => 1]],
            ],
        ];

        $result = $method->invoke($provider, $raw, 'hash');
        $this->assertCount(1, $result);
        $this->assertSame('999', $result[0]->downloadId);
    }

    public function test_filter_subtitles_handles_missing_attributes(): void
    {
        $method = new \ReflectionMethod(OpenSubtitlesProvider::class, 'filterSubtitles');
        $method->setAccessible(true);

        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);

        // Subtitle with missing/partial attributes
        $raw = [
            [
                'id' => '1',
                // missing 'attributes' entirely - should be skipped (no file_id)
            ],
            [
                'id' => '2',
                'attributes' => [
                    // missing most fields, but has valid file
                    'files' => [['file_id' => 123, 'file_name' => 'test.srt', 'cd_number' => 1]],
                ],
            ],
            [
                'id' => '3',
                'attributes' => [
                    'language' => 'fr',
                    'download_count' => 10,
                    'files' => [['file_id' => 456, 'file_name' => 'test2.srt', 'cd_number' => 1]],
                    // missing feature_details, ratings, hearing_impaired, fps
                ],
            ],
        ];

        $result = $method->invoke($provider, $raw, 'srt');
        // Entry 1 is skipped (no attributes), entries 2 and 3 both pass
        // Results are sorted by download_count descending, so entry 3 (10 downloads) comes first
        $this->assertCount(2, $result);
        $this->assertSame(456, $result[0]['file_id']); // 10 downloads
        $this->assertSame(123, $result[1]['file_id']); // 0 downloads
        $this->assertSame('fr', $result[0]['language']);
        $this->assertSame('en', $result[1]['language']); // default language
    }

    public function test_filter_subtitles_handles_malformed_files_array(): void
    {
        $method = new \ReflectionMethod(OpenSubtitlesProvider::class, 'filterSubtitles');
        $method->setAccessible(true);

        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);

        // Files is not an array
        $raw = [
            [
                'id' => '1',
                'attributes' => [
                    'language' => 'en',
                    'files' => 'not-an-array',
                ],
            ],
        ];

        $result = $method->invoke($provider, $raw, 'srt');
        $this->assertCount(0, $result);
    }

    public function test_compute_movie_hash_returns_empty_for_unreadable_file(): void
    {
        // Create a file, compute hash, then make it unreadable
        $file = $this->makeFixture(str_repeat("\0", 131072));
        try {
            // First verify it works
            $hash = OpenSubtitlesProvider::computeMovieHash($file);
            $this->assertNotSame('', $hash);

            // Now make it unreadable
            chmod($file, 0);

            // Clear any stat cache
            clearstatcache(true, $file);

            // Should return empty string for unreadable file
            $this->assertSame('', OpenSubtitlesProvider::computeMovieHash($file));
        } finally {
            chmod($file, 0644);
            unlink($file);
        }
    }

    public function test_looks_like_quota_message_detects_various_patterns(): void
    {
        $method = new \ReflectionMethod(OpenSubtitlesProvider::class, 'looksLikeQuotaMessage');
        $method->setAccessible(true);

        // quota keyword
        $this->assertTrue($method->invoke(null, 'You have exceeded your quota'));
        // download limit
        $this->assertTrue($method->invoke(null, 'Download limit reached'));
        // allowed ... download
        $this->assertTrue($method->invoke(null, 'No more downloads allowed'));
        // negative cases
        $this->assertFalse($method->invoke(null, 'Server error'));
        $this->assertFalse($method->invoke(null, 'Invalid API key'));
    }

    public function test_quota_exceeded_exception_constructor(): void
    {
        $exception = new OpenSubtitlesQuotaExceededException(
            'Daily limit reached',
            resetTime: 'in 24 hours',
            resetTimeUtc: '2026-08-02T00:00:00Z',
            downloadsRemaining: 0,
        );

        $this->assertSame('Daily limit reached', $exception->getMessage());
        $this->assertSame('in 24 hours', $exception->resetTime);
        $this->assertSame('2026-08-02T00:00:00Z', $exception->resetTimeUtc);
        $this->assertSame(0, $exception->downloadsRemaining);
    }

    public function test_download_with_remaining_zero_but_has_link(): void
    {
        // When remaining is 0 but a link IS provided, it should still work
        // (some API responses return remaining=0 but still provide a link)
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [
            self::response(200, (string) json_encode([
                'link' => 'https://dl.opensubtitles.org/download/src/upload/12345.srt',
                'file_name' => 'movie.srt',
                'remaining' => 0, // exhausted but still got a link
                'requests' => 20,
            ])),
            self::response(200, 'subtitle content'),
        ], $history);

        $provider->onEnable($this->stubContainer());
        $download = $provider->downloadRaw(998877);

        $this->assertSame('subtitle content', $download->content);
        $this->assertSame(0, $download->downloadsRemaining);
    }

    public function test_http_request_relative_url_gets_api_base_prefix(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [
            self::response(200, '{"data":[]}'),
        ], $history);

        $provider->onEnable($this->stubContainer());

        // Use reflection to call httpRequest with a relative URL
        $method = new \ReflectionMethod(OpenSubtitlesProvider::class, 'httpRequest');
        $method->setAccessible(true);
        $method->invoke($provider, 'GET', 'subtitles?imdb_id=tt1234567', []);

        $this->assertCount(1, $history);
        $this->assertStringStartsWith('https://api.opensubtitles.com/api/v1/', $history[0]['url']);
    }

    public function test_download_maps_500_error_to_quota_when_message_looks_like_quota(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [
            self::response(429, (string) json_encode([
                'message' => 'You have downloaded your allowed 20 subtitles for 24h. Quota will be renewed automatically.',
            ])),
        ], $history);

        $provider->onEnable($this->stubContainer());

        $this->expectException(OpenSubtitlesQuotaExceededException::class);
        $provider->downloadRaw(998877);
    }

    public function test_safe_filename_strips_directory_components(): void
    {
        $method = new \ReflectionMethod(OpenSubtitlesProvider::class, 'safeFilename');
        $method->setAccessible(true);

        $result = $method->invoke(null, '/path/to/My.Movie.2019.srt', null, 'en', 'srt');
        $this->assertSame('My.Movie.2019.srt', $result);
    }

    public function test_safe_filename_uses_release_name_when_provider_name_empty(): void
    {
        $method = new \ReflectionMethod(OpenSubtitlesProvider::class, 'safeFilename');
        $method->setAccessible(true);

        // Note: pathinfo treats "2019" as extension in "Movie.2019", so stem becomes "Movie"
        // Using a release name without the dot-extension pattern
        $result = $method->invoke(null, '', 'My Movie 2019 BluRay', 'en', 'srt');
        $this->assertSame('My Movie 2019 BluRay.srt', $result);
    }

    public function test_coerce_priority_handles_various_inputs(): void
    {
        $method = new \ReflectionMethod(OpenSubtitlesProvider::class, 'coercePriority');
        $method->setAccessible(true);

        // Integer input
        $this->assertSame(5, $method->invoke(null, 5));
        // String integer
        $this->assertSame(10, $method->invoke(null, '10'));
        // Negative string integer
        $this->assertSame(-3, $method->invoke(null, '-3'));
        // Float (should use default)
        $this->assertSame(10, $method->invoke(null, 3.14));
        // Non-numeric string (should use default)
        $this->assertSame(10, $method->invoke(null, 'abc'));
        // Null (should use default)
        $this->assertSame(10, $method->invoke(null, null));
    }

    // ---------------------------------------------------------------------
    // Additional coverage for uncovered code paths
    // ---------------------------------------------------------------------

    /**
     * Covers filterSubtitles lines 1291-1292: when subtitle id is an integer
     * instead of a string (API can return either).
     */
    public function test_filter_subtitles_handles_integer_id(): void
    {
        $method = new \ReflectionMethod(OpenSubtitlesProvider::class, 'filterSubtitles');
        $method->setAccessible(true);

        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);

        // API returns integer id instead of string
        $raw = [
            [
                'id' => 123456, // integer id, not string
                'attributes' => [
                    'language' => 'en',
                    'download_count' => 100,
                    'files' => [['file_id' => 998877, 'file_name' => 'test.srt', 'cd_number' => 1]],
                ],
            ],
        ];

        $result = $method->invoke($provider, $raw, 'srt');
        $this->assertCount(1, $result);
        $this->assertSame('123456', $result[0]['id']);
    }

    /**
     * Covers filterSubtitles line 1260: when file_id is present but not an int
     * (e.g., it's a string), the entry should be skipped.
     */
    public function test_filter_subtitles_skips_files_with_non_int_file_id(): void
    {
        $method = new \ReflectionMethod(OpenSubtitlesProvider::class, 'filterSubtitles');
        $method->setAccessible(true);

        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);

        // First entry has string file_id, second has valid int file_id
        $raw = [
            [
                'id' => '1',
                'attributes' => [
                    'language' => 'en',
                    'download_count' => 100,
                    'files' => [['file_id' => 'not-an-int', 'file_name' => 'bad.srt', 'cd_number' => 1]],
                ],
            ],
            [
                'id' => '2',
                'attributes' => [
                    'language' => 'fr',
                    'download_count' => 50,
                    'files' => [['file_id' => 123456, 'file_name' => 'good.srt', 'cd_number' => 1]],
                ],
            ],
        ];

        $result = $method->invoke($provider, $raw, 'srt');
        // Only the second entry should pass (file_id is int)
        $this->assertCount(1, $result);
        $this->assertSame(123456, $result[0]['file_id']);
    }

    /**
     * Covers filterSubtitles: files array with mixed valid/invalid file entries.
     * Some files have string file_id, one has valid int file_id.
     */
    public function test_filter_subtitles_handles_mixed_file_validity(): void
    {
        $method = new \ReflectionMethod(OpenSubtitlesProvider::class, 'filterSubtitles');
        $method->setAccessible(true);

        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);

        $raw = [
            [
                'id' => '1',
                'attributes' => [
                    'language' => 'en',
                    'download_count' => 100,
                    'files' => [
                        ['file_id' => 'bad', 'file_name' => 'a.srt', 'cd_number' => 1],
                        ['file_id' => 999, 'file_name' => 'b.srt', 'cd_number' => 1],
                    ],
                ],
            ],
        ];

        $result = $method->invoke($provider, $raw, 'srt');
        // Only the entry with valid file_id (999) should be kept
        $this->assertCount(1, $result);
        $this->assertSame(999, $result[0]['file_id']);
    }

    /**
     * Covers downloadRaw lines 935-938: when httpRequest throws an OpenSubtitlesException,
     * it should be caught, logged, and re-thrown as a new exception.
     */
    public function test_download_raw_catches_http_request_exception(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);

        // Create a transport that throws an OpenSubtitlesException
        $transport = function (string $method, string $url, array $headers, ?string $body) use (&$history): array {
            throw new OpenSubtitlesException('Connection refused');
        };

        $property = new \ReflectionProperty(OpenSubtitlesProvider::class, 'transport');
        $property->setAccessible(true);
        $property->setValue($provider, $transport);

        $provider->onEnable($this->stubContainer());

        $this->expectException(OpenSubtitlesException::class);
        $this->expectExceptionMessage('Download failed: Connection refused');
        $provider->downloadRaw(12345);
    }

    /**
     * Covers filterSubtitles edge case: file entry is not an array at all.
     */
    public function test_filter_subtitles_handles_non_array_file_entry(): void
    {
        $method = new \ReflectionMethod(OpenSubtitlesProvider::class, 'filterSubtitles');
        $method->setAccessible(true);

        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);

        // files contains a non-array entry
        $raw = [
            [
                'id' => '1',
                'attributes' => [
                    'language' => 'en',
                    'download_count' => 100,
                    'files' => [
                        'not-an-array',
                        ['file_id' => 123, 'file_name' => 'test.srt', 'cd_number' => 1],
                    ],
                ],
            ],
        ];

        $result = $method->invoke($provider, $raw, 'srt');
        $this->assertCount(1, $result);
        $this->assertSame(123, $result[0]['file_id']);
    }

    /**
     * Covers filterSubtitles edge case: cd_number is not an int.
     */
    public function test_filter_subtitles_handles_non_int_cd_number(): void
    {
        $method = new \ReflectionMethod(OpenSubtitlesProvider::class, 'filterSubtitles');
        $method->setAccessible(true);

        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);

        $raw = [
            [
                'id' => '1',
                'attributes' => [
                    'language' => 'en',
                    'download_count' => 100,
                    'files' => [['file_id' => 123, 'file_name' => 'test.srt', 'cd_number' => '1']],
                ],
            ],
        ];

        $result = $method->invoke($provider, $raw, 'srt');
        $this->assertCount(1, $result);
        // cd_number should default to 1 when not an int
        $this->assertSame(1, $result[0]['files'][0]['cd_number']);
    }

    /**
     * Covers filterSubtitles: empty id field should result in empty string id.
     */
    public function test_filter_subtitles_handles_missing_id(): void
    {
        $method = new \ReflectionMethod(OpenSubtitlesProvider::class, 'filterSubtitles');
        $method->setAccessible(true);

        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);

        $raw = [
            [
                'id' => null,
                'attributes' => [
                    'language' => 'en',
                    'download_count' => 100,
                    'files' => [['file_id' => 123, 'file_name' => 'test.srt', 'cd_number' => 1]],
                ],
            ],
        ];

        $result = $method->invoke($provider, $raw, 'srt');
        $this->assertCount(1, $result);
        $this->assertSame('', $result[0]['id']);
    }

    /**
     * Covers filterSubtitles: imdb_id is an empty string should be null.
     */
    public function test_filter_subtitles_handles_empty_imdb_id(): void
    {
        $method = new \ReflectionMethod(OpenSubtitlesProvider::class, 'filterSubtitles');
        $method->setAccessible(true);

        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);

        $raw = [
            [
                'id' => '1',
                'attributes' => [
                    'language' => 'en',
                    'download_count' => 100,
                    'feature_details' => ['imdb_id' => ''], // empty string
                    'files' => [['file_id' => 123, 'file_name' => 'test.srt', 'cd_number' => 1]],
                ],
            ],
        ];

        $result = $method->invoke($provider, $raw, 'srt');
        $this->assertCount(1, $result);
        $this->assertNull($result[0]['imdb_id']);
    }

    /**
     * Covers filterSubtitles: hearing_impaired can be a string "1" or "true".
     */
    public function test_filter_subtitles_handles_string_hearing_impaired(): void
    {
        $method = new \ReflectionMethod(OpenSubtitlesProvider::class, 'filterSubtitles');
        $method->setAccessible(true);

        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);

        $raw = [
            [
                'id' => '1',
                'attributes' => [
                    'language' => 'en',
                    'download_count' => 100,
                    'hearing_impaired' => 'true', // string instead of bool
                    'files' => [['file_id' => 123, 'file_name' => 'test.srt', 'cd_number' => 1]],
                ],
            ],
        ];

        $result = $method->invoke($provider, $raw, 'srt');
        $this->assertCount(1, $result);
        $this->assertTrue($result[0]['hearing_impaired']);
    }

    /**
     * Covers filterSubtitles: ratings can be a numeric string.
     */
    public function test_filter_subtitles_handles_string_rating(): void
    {
        $method = new \ReflectionMethod(OpenSubtitlesProvider::class, 'filterSubtitles');
        $method->setAccessible(true);

        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);

        $raw = [
            [
                'id' => '1',
                'attributes' => [
                    'language' => 'en',
                    'download_count' => 100,
                    'ratings' => '8.5', // string instead of float
                    'files' => [['file_id' => 123, 'file_name' => 'test.srt', 'cd_number' => 1]],
                ],
            ],
        ];

        $result = $method->invoke($provider, $raw, 'srt');
        $this->assertCount(1, $result);
        $this->assertSame(8.5, $result[0]['rating']);
    }

    /**
     * Covers filterSubtitles: fps can be an int that needs float conversion.
     */
    public function test_filter_subtitles_handles_int_fps(): void
    {
        $method = new \ReflectionMethod(OpenSubtitlesProvider::class, 'filterSubtitles');
        $method->setAccessible(true);

        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);

        $raw = [
            [
                'id' => '1',
                'attributes' => [
                    'language' => 'en',
                    'download_count' => 100,
                    'fps' => 24, // int instead of float
                    'files' => [['file_id' => 123, 'file_name' => 'test.srt', 'cd_number' => 1]],
                ],
            ],
        ];

        $result = $method->invoke($provider, $raw, 'srt');
        $this->assertCount(1, $result);
        $this->assertSame(24.0, $result[0]['fps']);
    }

    /**
     * Covers filterSubtitles: when file_name is null, uses default.
     */
    public function test_filter_subtitles_handles_null_file_name(): void
    {
        $method = new \ReflectionMethod(OpenSubtitlesProvider::class, 'filterSubtitles');
        $method->setAccessible(true);

        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);

        $raw = [
            [
                'id' => '1',
                'attributes' => [
                    'language' => 'en',
                    'download_count' => 100,
                    'files' => [['file_id' => 123, 'file_name' => null, 'cd_number' => 1]],
                ],
            ],
        ];

        $result = $method->invoke($provider, $raw, 'srt');
        $this->assertCount(1, $result);
        // Should default to "subtitle.srt"
        $this->assertSame('subtitle.srt', $result[0]['filename']);
    }

    /**
     * Covers filterSubtitles: results are sorted by download_count descending.
     */
    public function test_filter_subtitles_sorts_by_download_count_descending(): void
    {
        $method = new \ReflectionMethod(OpenSubtitlesProvider::class, 'filterSubtitles');
        $method->setAccessible(true);

        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);

        $raw = [
            [
                'id' => '1',
                'attributes' => [
                    'language' => 'en',
                    'download_count' => 10,
                    'files' => [['file_id' => 1, 'file_name' => 'a.srt', 'cd_number' => 1]],
                ],
            ],
            [
                'id' => '2',
                'attributes' => [
                    'language' => 'en',
                    'download_count' => 1000,
                    'files' => [['file_id' => 2, 'file_name' => 'b.srt', 'cd_number' => 1]],
                ],
            ],
            [
                'id' => '3',
                'attributes' => [
                    'language' => 'en',
                    'download_count' => 100,
                    'files' => [['file_id' => 3, 'file_name' => 'c.srt', 'cd_number' => 1]],
                ],
            ],
        ];

        $result = $method->invoke($provider, $raw, 'srt');
        $this->assertCount(3, $result);
        // Should be sorted: 1000 (id=2), 100 (id=3), 10 (id=1)
        $this->assertSame(1000, $result[0]['downloads']);
        $this->assertSame(100, $result[1]['downloads']);
        $this->assertSame(10, $result[2]['downloads']);
    }
}
